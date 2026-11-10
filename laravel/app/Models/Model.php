<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasSkpdScope;
use Illuminate\Database\Eloquent\Model as BaseModel;

/**
 * Base model untuk seluruh tabel domain SIM-TKD.
 *
 * Menambahkan satu hal penting: global scope multi-tenant otomatis.
 *
 * Setiap tabel yang punya kolom `skpd` langsung ter-filter per instansi
 * milik user yang sedang login — tanpa perlu menulis `WHERE skpd = ?`
 * manual di tiap query (pola lama pada sistem PHP vanilla).
 *
 * Untuk tabel tanpa kolom `skpd` (mis. `users`, `kegiatan`), scope tidak
 * dipasang sama sekali.
 *
 * Contoh:
 *   // Otomatis terfilter ke SKPD user yang login
 *   Sts::query()->latest()->get();
 *
 *   //_cross-tenant_ (hanya untuk operasi konsolidator/admin)
 *   Sts::withoutGlobalScope(HasSkpdScope::class)->get();
 */
abstract class Model extends BaseModel
{
    /**
     * Nama kolom yang menyimpan identitas tenant.
     * Default: 'skpd'. Override per-model bila perlu.
     */
    protected ?string $tenantColumn = null;

    protected static function booted(): void
    {
        static::addGlobalScope(new HasSkpdScope());
    }

    /**
     * Apakah tabel ini punya kolom tenant sehingga perlu di-scope?
     */
    public function hasTenantColumn(): bool
    {
        $column = $this->tenantColumn ?? 'skpd';

        // Cache per-kelas: pengecekan skema tidak perlu diulang tiap request.
        static $cache = [];

        $key = static::class . '|' . $column;

        if (!array_key_exists($key, $cache)) {
            try {
                $cache[$key] = $this->getConnection()
                    ->getSchemaBuilder()
                    ->hasColumn($this->getTable(), $column);
            } catch (\Throwable) {
                $cache[$key] = false;
            }
        }

        return $cache[$key];
    }
}
