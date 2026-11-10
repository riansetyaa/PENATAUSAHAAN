<?php
/**
 * Generator artefak skema: Live MySQL -> Laravel Migration + Eloquent Model
 * =========================================================================
 * Tool pengembangan (bukan bagian runtime aplikasi).
 *
 * Aplikasi SIM-TKD yang dimigrasikan ke Laravel memakai basis data yang
 * SUDAH ADA dan berisi data legacy. Karena itu migration yang dihasilkan
 * tool ini bersifat "mirror": ia hanya membuat tabel bila tabel belum ada,
 * dan TIDAK pernah mengubah atau menghapus tabel yang sudah ada.
 *
 * Menjalankan ulang tool ini aman: file yang sudah ada dilewati.
 *
 * Pemakaian:
 *   php laravel/tools/generate-schema-artifacts.php
 *
 * Sumber kebenaran adalah skema live (information_schema), bukan file .sql
 * di database/ yang bisa sudah usang.
 */

declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1')
    . ';port=' . (getenv('DB_PORT') ?: '3306')
    . ';dbname=' . (getenv('DB_DATABASE') ?: 'simtkd')
    . ';charset=utf8mb4',
    getenv('DB_USERNAME') ?: 'root',
    getenv('DB_PASSWORD') ?: 'Root12345!',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$base    = dirname(__DIR__);
$migDir  = $base . '/database/migrations';
$modDir  = $base . '/app/Models';

foreach ([$migDir, $modDir] as $d) {
    if (!is_dir($d)) {
        mkdir($d, 0755, true);
    }
}

// ---------------------------------------------------------------------------
// Ambil daftar tabel
// ---------------------------------------------------------------------------
$tables = $pdo->query(
    "SELECT table_name FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
      ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

// Tabel milik framework Laravel / paket (bukan bagian domain SIM-TKD).
// Tabel-tabel ini punya migrationnya sendiri dan TIDAK boleh di-mirror.
$skip = [
    // Framework
    'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions',
    'password_reset_tokens', 'password_resets', 'migrations',
    // Sanctum
    'personal_access_tokens',
    // Spatie Permission
    'roles', 'permissions', 'model_has_roles', 'model_has_permissions',
    'role_has_permissions',
];

$created = 0;
$skipped = 0;

foreach ($tables as $table) {
    if (in_array($table, $skip, true)) {
        $skipped++;
        continue;
    }

    $cols = $pdo->prepare(
        "SELECT column_name, column_type, data_type, is_nullable, column_default, extra, character_maximum_length
           FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ?
          ORDER BY ordinal_position"
    );
    $cols->execute([$table]);
    $columns = $cols->fetchAll();

    // MySQL 8+ mengembalikan nama kolom information_schema dalam huruf besar,
    // sedangkan MariaDB memakai huruf kecil. Normalisasi ke huruf kecil agar
    // generator bekerja pada keduanya.
    $columns = array_map(static function (array $row): array {
        return array_change_key_case($row, CASE_LOWER);
    }, $columns);

    if (!$columns) {
        $skipped++;
        continue;
    }

    // Index
    $idx = $pdo->prepare(
        "SELECT index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index) cols
           FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = ?
          GROUP BY index_name, non_unique
          ORDER BY index_name"
    );
    $idx->execute([$table]);
    $indexes = array_map(static function (array $row): array {
        return array_change_key_case($row, CASE_LOWER);
    }, $idx->fetchAll());

    $primary = null;
    foreach ($indexes as $i) {
        if ($i['index_name'] === 'PRIMARY') {
            $primary = explode(',', $i['cols']);
        }
    }

    $model      = studly($table);
    $modelFile  = $modDir . '/' . $model . '.php';
    $migFile    = $migDir . '/2026_01_01_000000_mirror_' . $table . '.php';

    if (!file_exists($migFile)) {
        file_put_contents($migFile, renderMigration($table, $columns, $indexes, $primary));
        $created++;
    }
    if (!file_exists($modelFile)) {
        file_put_contents($modelFile, renderModel($table, $columns, $primary, $model));
        $created++;
    }
}

echo "Tabel diproses : " . count($tables) . "\n";
echo "Artefak dibuat : $created\n";
echo "Dilewati       : $skipped\n";

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------
function studly(string $v): string
{
    return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $v)));
}

/** Konversi tipe MySQL -> pemanggilan method Laravel Blueprint. */
function colDef(array $c, string $table): string
{
    $name = $c['column_name'];
    $type = $c['column_type'];
    $data = $c['data_type'];
    $len  = (int) $c['character_maximum_length'];

    $nullable = $c['is_nullable'] === 'YES';
    $unsigned = str_contains($type, 'unsigned');

    $def = '';

    switch ($data) {
        case 'int':
            $def = $unsigned ? "unsignedInteger('" . $name . "')" : "integer('" . $name . "')";
            break;
        case 'bigint':
            $def = $unsigned ? "unsignedBigInteger('" . $name . "')" : "bigInteger('" . $name . "')";
            break;
        case 'smallint':
            $def = "smallInteger('" . $name . "')";
            break;
        case 'tinyint':
            // tinyint(1) -> boolean; tinyint lainnya -> unsignedTinyInteger
            $def = str_contains($type, '(1)')
                ? 'boolean(\'' . $name . '\')'
                : 'unsignedTinyInteger(\'' . $name . '\')';
            break;
        case 'mediumint':
            $def = "mediumInteger('" . $name . "')";
            break;
        case 'varchar':
            $def = 'string(\'' . $name . '\', ' . $len . ')';
            break;
        case 'char':
            $def = 'char(\'' . $name . '\', ' . $len . ')';
            break;
        case 'text':
            $def = 'text(\'' . $name . '\')';
            break;
        case 'mediumtext':
            $def = 'mediumText(\'' . $name . '\')';
            break;
        case 'longtext':
            $def = 'longText(\'' . $name . '\')';
            break;
        case 'blob':
            $def = 'binary(\'' . $name . '\')';
            break;
        case 'decimal':
            preg_match('/decimal\((\d+),(\d+)\)/', $type, $m);
            $def = 'decimal(\'' . $name . '\', ' . ($m[1] ?? 18) . ', ' . ($m[2] ?? 2) . ')';
            break;
        case 'double':
            $def = 'double(\'' . $name . '\')';
            break;
        case 'float':
            $def = 'float(\'' . $name . '\')';
            break;
        case 'date':
            $def = 'date(\'' . $name . '\')';
            break;
        case 'datetime':
            $def = 'dateTime(\'' . $name . '\')';
            break;
        case 'timestamp':
            $def = 'timestamp(\'' . $name . '\')';
            break;
        case 'time':
            $def = 'time(\'' . $name . '\')';
            break;
        case 'year':
            $def = 'year(\'' . $name . '\')';
            break;
        case 'enum':
            preg_match("/enum\((.*)\)/", $type, $m);
            $vals = array_map(fn ($v) => "'" . trim($v, "' ") . "'", explode(',', $m[1] ?? ''));
            $def = 'enum(\'' . $name . '\', [' . implode(', ', $vals) . '])';
            break;
        case 'json':
            $def = 'json(\'' . $name . '\')';
            break;
        default:
            $def = 'string(\'' . $name . '\')';
    }

    if ($nullable) {
        $def .= '->nullable()';
    }

    // Default: hanya yang eksplisit & sederhana (angka / string / CURRENT_TIMESTAMP).
    $deflt = $c['column_default'];
    if ($deflt !== null && $deflt !== 'NULL') {
        $up = strtoupper((string) $deflt);
        if ($up === 'CURRENT_TIMESTAMP') {
            $def .= '->useCurrent()';
        } elseif ($data === 'enum') {
            $def .= '->default(' . "'" . addslashes((string) $deflt) . "'" . ')';
        } elseif ($data === 'tinyint' && str_contains($type, '(1)')) {
            // tinyint(1) = boolean
            $def .= '->default(' . ((string) $deflt === '0' ? 'false' : 'true') . ')';
        } elseif (is_numeric($deflt)) {
            $def .= '->default(' . $deflt . ')';
        } elseif (in_array($data, ['varchar', 'char', 'text', 'datetime', 'date'], true)) {
            $def .= '->default(' . "'" . addslashes((string) $deflt) . "'" . ')';
        }
    }

    return '$table->' . $def;
}

function renderMigration(string $table, array $columns, array $indexes, ?array $primary): string
{
    $lines = [];
    foreach ($columns as $c) {
        if (($c['extra'] ?? '') !== '' && str_contains($c['extra'], 'auto_increment')) {
            $lines[] = "            \$table->increments('" . $c['column_name'] . "');";
            continue;
        }

        // Kolom waktu dibuat eksplisit (bukan $table->timestamps()) supaya
        // default CURRENT_TIMESTAMP dan ON UPDATE CURRENT_TIMESTAMP pada
        // skema legacy ikut tercermin persis.
        $col = $c['column_name'];
        if ($col === 'created_at' || $col === 'updated_at') {
            $def = "\$table->timestamp('" . $col . "')->nullable()->useCurrent()";
            if ($col === 'updated_at' && str_contains((string) ($c['extra'] ?? ''), 'on update')) {
                $def .= '->useCurrentOnUpdate()';
            }
            $lines[] = '            ' . $def . ';';
            continue;
        }

        $lines[] = '            ' . colDef($c, $table) . ';';
    }

    // Index unik (skip PRIMARY)
    foreach ($indexes as $i) {
        if ($i['index_name'] === 'PRIMARY' || $i['non_unique'] == 1) {
            continue;
        }
        $cols = array_map(fn ($c) => "'" . trim($c) . "'", explode(',', $i['cols']));
        $lines[] = "            \$table->unique([" . implode(', ', $cols) . "], '" . $i['index_name'] . "');";
    }

    // Index biasa
    foreach ($indexes as $i) {
        if ($i['index_name'] === 'PRIMARY' || $i['non_unique'] == 0) {
            continue;
        }
        $cols = array_map(fn ($c) => "'" . trim($c) . "'", explode(',', $i['cols']));
        $lines[] = "            \$table->index([" . implode(', ', $cols) . "], '" . $i['index_name'] . "');";
    }

    $body = implode("\n", $lines);

    return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror skema tabel `{$table}` dari sistem SIM-TKD legacy (PHP vanilla).
 *
 * Migration ini bersifat NON-DESTRUKTIF: tabel hanya dibuat bila belum ada.
 * Pada instalasi yang sudah berjalan, tabel legacy berisi data sehingga
 * TIDAK boleh diubah maupun dihapus oleh migration ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('{$table}')) {
            return;
        }

        Schema::create('{$table}', function (Blueprint \$table) {
{$body}
        });
    }

    public function down(): void
    {
        // Sengaja TIDAK menghapus tabel. Pada instalasi existing, tabel ini
        // berisi data legacy. Hapus manual bila memang diperlukan.
    }
};

PHP;
}

function renderModel(string $table, array $columns, ?array $primary, string $model): string
{
    $names = array_column($columns, 'column_name');

    $fillable = array_values(array_diff($names, ['id', 'created_at', 'updated_at', 'deleted_at']));

    $casts = [];
    foreach ($columns as $c) {
        $data = $c['data_type'];
        $n    = $c['column_name'];
        if ($data === 'decimal') {
            $casts[] = "'" . $n . "' => 'decimal:2'";
        } elseif ($data === 'tinyint' && str_contains($c['column_type'], '(1)')) {
            $casts[] = "'" . $n . "' => 'boolean'";
        } elseif (in_array($data, ['int', 'bigint', 'smallint', 'mediumint', 'tinyint'], true)) {
            $casts[] = "'" . $n . "' => 'integer'";
        } elseif (in_array($data, ['datetime', 'timestamp'], true)) {
            $casts[] = "'" . $n . "' => 'datetime'";
        } elseif ($data === 'date') {
            $casts[] = "'" . $n . "' => 'date'";
        } elseif (in_array($data, ['text', 'mediumtext', 'longtext', 'json'], true)) {
            $casts[] = "'" . $n . "' => 'string'";
        }
    }

    $hasCreated = in_array('created_at', $names, true);
    $hasUpdated = in_array('updated_at', $names, true);

    $fillableStr = implode(', ', array_map(fn ($c) => "'" . $c . "'", $fillable));
    $castsStr    = implode(",\n        ", $casts);

    // Tabel dengan kolom skpd -> global scope multi-tenant diterapkan
    // otomatis oleh App\Models\Model (base class), bukan per-model.
    $hasSkpd = in_array('skpd', $names, true);

    $fillableProp = $fillableStr === '' ? 'protected $fillable = [];' : "protected \$fillable = [\n        " . $fillableStr . ",\n    ];";
    $castsProp    = $castsStr === '' ? '' : "\n    protected \$casts = [\n        " . $castsStr . ",\n    ];\n";

    $tsValue = ($hasCreated && $hasUpdated) ? 'true' : 'false';

    // Catatan tenant disisipkan DI DALAM docblock.
    $skpdDoc = $hasSkpd
        ? " *\n * Catatan: tabel ini punya kolom `skpd` sehingga otomatis ter-filter\n * per instansi/SKPD lewat global scope yang dipasang App\\Models\\Model."
        : '';

    $doc = "/**\n * Model untuk tabel `{$table}` (skema legacy SIM-TKD).\n *\n * Dibuat oleh tools/generate-schema-artifacts.php dari skema live.\n{$skpdDoc}\n */";

    return <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

{$doc}
class {$model} extends Model
{
    use HasFactory;

    protected \$table = '{$table}';

    public \$timestamps = {$tsValue};

    {$fillableProp}
{$castsProp}
}

PHP;
}
