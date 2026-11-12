<?php

declare(strict_types=1);

/**
 * Differential Test Harness — SIM-TKD
 * ====================================
 *
 * Membandingkan API LAMA (PHP vanilla) dengan API BARU (Laravel) pada
 * basis data yang sama. API lama menjadi GOLDEN MASTER: apa pun yang
 * dikembalikan legacy dianggap benar, dan API baru wajib mengulang
 * hasil yang sama.
 *
 * Fungsi:
 *   1. Mencegah regresi logika bisnis (terutama perhitungan akuntansi)
 *      selama porting ke Laravel.
 *   2. Menyediakan CHECKLIST porting yang hidup: skenario yang endpoint
 *      barunya belum ada dilaporkan sebagai PENDING.
 *
 * Kebutuhan:
 *   - Server legacy  : php -S 127.0.0.1:8899 -t <repo-root>
 *   - Server Laravel : php artisan serve (port 8291)
 *   - Keduanya membaca basis data yang sama.
 *
 * Pemakaian:
 *   php laravel/tools/difftest/run.php
 *   php laravel/tools/difftest/run.php --filter=auth
 *   php laravel/tools/difftest/run.php --pending-only
 *   php laravel/tools/difftest/run.php --verbose
 *
 * Variabel environment:
 *   SIMTKD_LEGACY_BASE  default http://127.0.0.1:8899
 *   SIMTKD_NEW_BASE     default http://127.0.0.1:8291
 *   SIMTKD_DIFTEST_KEEP=1 untuk tidak menghapus akun uji setelah selesai
 */

require_once __DIR__ . '/Client.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Normalizer.php';
require_once __DIR__ . '/ScenarioResult.php';
require_once __DIR__ . '/Runner.php';

use Simtkd\Difftest\Client;
use Simtkd\Difftest\Runner;
use Simtkd\Difftest\ScenarioResult;

// ---------------------------------------------------------------------------
// Argumen CLI
// ---------------------------------------------------------------------------
$argvOptions = getopt('', ['filter::', 'verbose', 'pending-only', 'keep', 'no-setup']);

$filter     = (string) ($argvOptions['filter'] ?? '');
$verbose    = array_key_exists('verbose', $argvOptions);
$pendingOnly = array_key_exists('pending-only', $argvOptions);
$keep       = array_key_exists('keep', $argvOptions) || getenv('SIMTKD_DIFTEST_KEEP') === '1';

$legacyBase = rtrim((string) (getenv('SIMTKD_LEGACY_BASE') ?: 'http://127.0.0.1:8899'), '/');
$newBase    = rtrim((string) (getenv('SIMTKD_NEW_BASE') ?: 'http://127.0.0.1:8291'), '/');

// ---------------------------------------------------------------------------
// Koneksi database (untuk menyiapkan akun uji)
// ---------------------------------------------------------------------------
$repoRoot   = dirname(__DIR__, 3);
$laravelDir = dirname(__DIR__, 2);

$env = static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? getenv($key);

    return ($value === false || $value === '') ? $default : (string) $value;
};

$dbHost = $env('DB_HOST', '127.0.0.1');
$dbPort = $env('DB_PORT', '3306');
$dbName = $env('DB_DATABASE', 'simtkd');
$dbUser = $env('DB_USERNAME', 'root');
$dbPass = $env('DB_PASSWORD', 'Root12345!');

// Credential ada di .env Laravel maupun config/credentials.php legacy.
// Ambil dari .env Laravel sebagai sumber tunggal.
$envFile = $laravelDir . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim(trim($v), "\"'");
        if (in_array($k, ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'], true)) {
            $_ENV[$k] = $v;
        }
    }
}

$pdo = new PDO(
    "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
    $env('DB_USERNAME', 'root'),
    $env('DB_PASSWORD', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// ---------------------------------------------------------------------------
// Akun uji
// ---------------------------------------------------------------------------
$suffix   = (string) random_int(100000, 999999);
$password = 'DifftestKuat123';

$users = [
    'aktif' => [
        'username' => 'dif_aktif_' . $suffix,
        'email'    => 'difftest_aktif_' . $suffix . '@polban.ac.id',
        'status'   => 'aktif',
        'peran'    => 'Bendahara',
        'instansi' => 'Dinas Uji Difftest',
    ],
    'pending' => [
        'username' => 'dif_pending_' . $suffix,
        'email'    => 'difftest_pending_' . $suffix . '@polban.ac.id',
        'status'   => 'pending',
        'peran'    => 'Bendahara',
        'instansi' => 'Dinas Uji Difftest',
    ],
    'nonaktif' => [
        'username' => 'dif_nonaktif_' . $suffix,
        'email'    => 'difftest_nonaktif_' . $suffix . '@polban.ac.id',
        'status'   => 'nonaktif',
        'peran'    => 'Bendahara',
        'instansi' => 'Dinas Uji Difftest',
    ],
    'baru' => [
        'username' => 'dif_baru_' . $suffix,
        'email'    => 'difftest_baru_' . $suffix . '@polban.ac.id',
        'email_gmail' => 'difftest_baru_' . $suffix . '@gmail.com',
        'status'   => 'pending',
        'peran'    => 'Operator',
        'instansi' => 'Dinas Uji Difftest',
    ],
];

/**
 * Siapkan akun uji di database (idempoten).
 */
$setupAccounts = static function () use ($pdo, $users, $password): void {
    $hash = password_hash($password, PASSWORD_DEFAULT);

    foreach ($users as $u) {
        $st = $pdo->prepare(
            'INSERT INTO users (nama_lengkap, username, email, password, instansi, kota, provinsi, peran, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE password = VALUES(password), status = VALUES(status), instansi = VALUES(instansi)'
        );
        $st->execute([
            'Uji Difftest',
            $u['username'],
            $u['email'],
            $hash,
            $u['instansi'],
            'Bandung',
            'Jawa Barat',
            $u['peran'],
            $u['status'],
        ]);
    }
};

$cleanupAccounts = static function () use ($pdo, $users): void {
    $st = $pdo->prepare('DELETE FROM users WHERE username = ?');
    foreach ($users as $u) {
        $st->execute([$u['username']]);
    }
};

/**
 * Bersihkan data domain milik instansi uji `Dinas Uji Difftest`.
 *
 * Skenario AKLAP (`jurnal_khusus*`, `rekonsiliasi_bank*`, `kas_harian*`,
 * `buku_kas*`, `register_ppkd*`) memeriksa keadaan awal "belum tersimpan",
 * sehingga sisa data run sebelumnya membuat hasil tidak deterministik.
 * Instansi ini murni milik harness (lihat $users di atas), jadi aman dihapus.
 */
$bersihkanDataUji = static function () use ($pdo, $users): void {
    $instansi = (string) ($users['aktif']['instansi'] ?? 'Dinas Uji Difftest');

    $tabel = [
        'jurnal_khusus_baris', 'jurnal_khusus',
        'jurnal_umum_detail', 'jurnal_umum',
        'kas_harian_item', 'kas_harian',
        'rekonsiliasi_bank_item', 'rekonsiliasi_bank',
        'register_ppkd', 'buku_kas', 'neraca_awal', 'anggaran_lra', 'akun_master',
        'dokumen_ttd', 'dokumen',
    ];

    foreach ($tabel as $t) {
        try {
            $pdo->prepare("DELETE FROM {$t} WHERE skpd = ?")->execute([$instansi]);
        } catch (Throwable) {
            // Tabel belum ada pada instalasi tertentu -> lewati.
        }
    }
};

/**
 * Reset pembatas laju (rate limiter) milik akun uji saja.
 *
 * Laravel menyimpan hitungan RateLimiter di cache (`sim-tkd-cache-...`).
 * Karena sisi "legacy" pada pengujian adalah PROKSI yang meneruskan ke
 * Laravel, kedua sisi memakai backend yang sama — sehingga hitungan
 * percobaan bertambah dua kali lebih cepat dan skenario
 * `profil.ganti_password.*` bisa berakhir 429 pada salah satu sisi saja
 * (artefak harness, bukan perbedaan perilaku aplikasi).
 *
 * Reset dibatasi pada kunci yang memuat id akun uji, jadi pengguna lain
 * pada basis data yang sama tidak terpengaruh.
 *
 * @param  array<string, array<string, mixed>>  $users
 */
$bersihkanLimiter = static function (array $users) use ($pdo): void {
    try {
        $ada = (bool) $pdo->query("SHOW TABLES LIKE 'cache'")->fetch();
    } catch (Throwable) {
        return;   // cache store bukan database
    }

    if (! $ada) {
        return;
    }

    $cari = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $hapus = $pdo->prepare('DELETE FROM cache WHERE `key` LIKE ?');

    foreach ($users as $u) {
        $cari->execute([$u['username']]);
        $id = (int) ($cari->fetchColumn() ?: 0);

        if ($id <= 0) {
            continue;
        }

        foreach (['profil_pw_', 'profil_simpan_', 'login_', 'aktivasi_'] as $awalan) {
            $hapus->execute(['%' . $awalan . $id . '%']);
            $hapus->execute(['%' . $awalan . $id . ':timer%']);
        }
    }
};

if (! array_key_exists('no-setup', $argvOptions)) {
    $setupAccounts();
    $bersihkanDataUji();
    $bersihkanLimiter($users);
}

// ---------------------------------------------------------------------------
// Cek ketersediaan server
// ---------------------------------------------------------------------------
function ping(string $url): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // curl_close() deprecated sejak PHP 8.5 (tidak lagi berpengaruh).
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch);
    }

    return $code > 0;
}

echo "SIM-TKD — Differential Test (legacy vs Laravel)\n";
echo str_repeat('=', 62) . "\n";
echo "Legacy : {$legacyBase}\n";
echo "Baru   : {$newBase}\n\n";

if (! ping($legacyBase . '/api/session.php')) {
    fwrite(STDERR, "ERROR: server legacy tidak merespons di {$legacyBase}\n");
    fwrite(STDERR, "       Jalankan: php -S 127.0.0.1:8899 -t {$repoRoot}\n");
    exit(1);
}

if (! ping($newBase . '/up')) {
    fwrite(STDERR, "ERROR: server Laravel tidak merespons di {$newBase}\n");
    fwrite(STDERR, "       Jalankan: cd {$laravelDir} && php artisan serve --port=8291\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Klien
// ---------------------------------------------------------------------------
$legacy = new Client($legacyBase);
$baru   = new Client($newBase);

// Login kedua API dengan akun uji 'aktif'.
// - Legacy: memakai session cookie.
// - Laravel: memakai session cookie + token Sanctum (Bearer).
$loginLegacy = $legacy->request('POST', '/api/login.php', [
    'username' => $users['aktif']['username'],
    'password' => $password,
]);

$loginBaru = $baru->request('POST', '/api/v1/auth/login', [
    'username' => $users['aktif']['username'],
    'password' => $password,
]);

if (! $loginLegacy->isJson() || ! ($loginLegacy->get('success') === true)) {
    fwrite(STDERR, "ERROR: login ke API legacy gagal: " . substr($loginLegacy->raw, 0, 300) . "\n");
    exit(1);
}

if (! $loginBaru->isJson() || ! ($loginBaru->get('success') === true)) {
    fwrite(STDERR, "ERROR: login ke API Laravel gagal: " . substr($loginBaru->raw, 0, 300) . "\n");
    exit(1);
}

$tokenBaru = $loginBaru->get('token');
$baru->withToken(is_string($tokenBaru) ? $tokenBaru : null);

// ---------------------------------------------------------------------------
// Token API legacy untuk modul Dokumen & TTD (doc.simtkd.com)
// ---------------------------------------------------------------------------
// API legacy memverifikasi `?token=` terhadap HASH SHA-256 pada kolom
// users.api_token. Login legacy merotasi token itu tetapi TIDAK
// mengembalikannya, sehingga harness menulis sendiri hash-nya ke database
// (dilakukan SETELAH login agar tidak dirotasi ulang) lalu memakai token
// mentahnya pada skenario modul dokumen lewat placeholder
// {{user.aktif.token}}. API baru memakai Sanctum (Bearer) sehingga tidak
// memerlukan token ini.
$tokenLegacyDoc = bin2hex(random_bytes(16));
$pdo->prepare('UPDATE users SET api_token = ? WHERE username = ?')
    ->execute([hash('sha256', $tokenLegacyDoc), $users['aktif']['username']]);

$users['aktif']['token'] = $tokenLegacyDoc;

echo "Login OK untuk kedua API (user: {$users['aktif']['username']})\n\n";

// ---------------------------------------------------------------------------
// Jalankan skenario
// ---------------------------------------------------------------------------
$scenarios = require __DIR__ . '/scenarios.php';

if ($filter !== '') {
    $scenarios = array_values(array_filter(
        $scenarios,
        static fn (array $s): bool => str_contains((string) ($s['name'] ?? ''), $filter)
    ));
}

if ($pendingOnly) {
    $scenarios = array_values(array_filter(
        $scenarios,
        static fn (array $s): bool => ! isset($s['baru']) || $s['baru'] === null
    ));
}

$vars = ['user' => $users + ['password' => $password]];

// Reset pembatas laju akun uji SEBELUM setiap skenario.
//
// Bila server "legacy" pada pengujian adalah PROKSI cutover, kedua sisi
// memanggil backend Laravel yang sama sehingga hitungan percobaan
// `profil_pw_<uid>` bertambah dua kali per skenario. Tanpa reset, skenario
// `profil.ganti_password.*` bisa 429 pada satu sisi saja — artefak topologi
// pengujian, bukan perbedaan perilaku aplikasi.
$resetLimiter = static function () use ($pdo, $users): void {
    try {
        if (! $pdo->query("SHOW TABLES LIKE 'cache'")->fetch()) {
            return;
        }

        $cari = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $hapus = $pdo->prepare('DELETE FROM cache WHERE `key` LIKE ?');

        foreach ($users as $u) {
            $cari->execute([$u['username']]);
            $id = (int) ($cari->fetchColumn() ?: 0);

            if ($id <= 0) {
                continue;
            }

            foreach (['profil_pw_', 'profil_simpan_'] as $awalan) {
                $hapus->execute(['%' . $awalan . $id . '%']);
            }
        }
    } catch (Throwable) {
        // Cache bukan database / tabel belum ada -> tidak perlu reset.
    }
};

$runner = new Runner($legacy, $baru, $verbose, $vars, $resetLimiter);
$results = $runner->run($scenarios);

// ---------------------------------------------------------------------------
// Laporan
// ---------------------------------------------------------------------------
$counts = [
    ScenarioResult::PASS    => 0,
    ScenarioResult::FAIL    => 0,
    ScenarioResult::PENDING => 0,
    ScenarioResult::ERROR   => 0,
    ScenarioResult::KNOWN   => 0,
];

$symbols = [
    ScenarioResult::PASS    => "\033[32mPASS\033[0m",
    ScenarioResult::FAIL    => "\033[31mFAIL\033[0m",
    ScenarioResult::PENDING => "\033[33mPEND\033[0m",
    ScenarioResult::ERROR   => "\033[35mERR \033[0m",
    ScenarioResult::KNOWN   => "\033[36mKNWN\033[0m",
];

foreach ($results as $r) {
    $counts[$r->status]++;
    printf("  %-6s %s\n", $symbols[$r->status], $r->name);

    if ($r->status === ScenarioResult::KNOWN) {
        echo "         \033[36mⓘ perbedaan disengaja: {$r->knownReason}\033[0m\n";
        if ($r->note !== '') {
            echo "         \033[36m  {$r->note}\033[0m\n";
        }
        foreach ($r->diffs as $d) {
            echo "         \033[36m- {$d}\033[0m\n";
        }
    } elseif ($r->status === ScenarioResult::FAIL || $r->status === ScenarioResult::ERROR) {
        foreach ($r->diffs as $d) {
            echo "         \033[31m- {$d}\033[0m\n";
        }
        if ($r->note !== '') {
            echo "         \033[35m- {$r->note}\033[0m\n";
        }
    } elseif ($verbose && $r->note !== '') {
        echo "         \033[33m· {$r->note}\033[0m\n";
    }
}

$total = count($results);

echo "\n" . str_repeat('=', 62) . "\n";
printf(
    "TOTAL %d   \033[32mPASS %d\033[0m   \033[31mFAIL %d\033[0m   \033[33mPENDING %d\033[0m   \033[35mERROR %d\033[0m   \033[36mKNOWN %d\033[0m\n",
    $total,
    $counts[ScenarioResult::PASS],
    $counts[ScenarioResult::FAIL],
    $counts[ScenarioResult::PENDING],
    $counts[ScenarioResult::ERROR],
    $counts[ScenarioResult::KNOWN]
);

$done = $counts[ScenarioResult::PASS] + $counts[ScenarioResult::FAIL] + $counts[ScenarioResult::KNOWN];
if ($done > 0) {
    printf("Endpoint yang sudah dimigrasi: %d/%d (%.0f%%)\n",
        $done,
        $total,
        ($done / $total) * 100
    );
}

if (! $keep) {
    $cleanupAccounts();
    echo "Akun uji dibersihkan.\n";
} else {
    echo "Akun uji DIPERTAHANKAN (suffix {$suffix}).\n";
}

// Exit code: 0 bila tidak ada FAIL/ERROR.
exit(($counts[ScenarioResult::FAIL] + $counts[ScenarioResult::ERROR]) > 0 ? 1 : 0);
