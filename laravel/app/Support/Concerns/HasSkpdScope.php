<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope multi-tenant berbasis kolom `skpd`.
 *
 * Menggantikan pola lama pada sistem PHP vanilla:
 *   - `requireInstansi()` yang memanggil jsonResponse(403) bila session
 *     instansi kosong, lalu
 *   - filter `WHERE skpd = ?` yang ditulis manual di tiap query.
 *
 * Dengan scope ini, setiap query pada tabel yang punya kolom `skpd`
 * otomatis dibatasi ke instansi milik user yang sedang login — termasuk
 * query di relasi, `find()`, `count()`, dan agregat. Nilai SKPD diambil dari
 * {@see Tenant::skpd()}.
 *
 * Sifatnya fail-closed: bila session tidak punya instansi, Tenant::skpd()
 * mengembalikan string kosong sehingga query tidak pernah bocor ke data
 * instansi lain. Endpoint yang benar-benar butuh sesi login dijaga
 * middleware `tenant` (EnsureInstansi).
 *
 * Untuk operasi lintas tenant (mis. sinkronisasi antar dinas, laporan
 * konsolidator), gunakan `withoutGlobalScope(HasSkpdScope::class)`.
 */
class HasSkpdScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Tabel tanpa kolom tenant (mis. `users`, `kegiatan`) tidak boleh
        // difilter — menambahkan WHERE skpd = ? akan menyebabkan galat
        // "Unknown column".
        if (! $this->supports($model)) {
            return;
        }

        $column = $this->column($model);

        $builder->where($model->qualifyColumn($column), Tenant::skpd());
    }

    /**
     * Apakah model ini memakai kolom tenant?
     */
    public function supports(Model $model): bool
    {
        if (method_exists($model, 'hasTenantColumn')) {
            return (bool) $model->hasTenantColumn();
        }

        return true;
    }

    /**
     * Nama kolom penyimpan identitas tenant.
     * Default `skpd`; dapat dioverride per-model dengan properti
     * `$tenantColumn`.
     */
    public function column(Model $model): string
    {
        $custom = $model->tenantColumn ?? null;

        return is_string($custom) && $custom !== '' ? $custom : 'skpd';
    }
}
