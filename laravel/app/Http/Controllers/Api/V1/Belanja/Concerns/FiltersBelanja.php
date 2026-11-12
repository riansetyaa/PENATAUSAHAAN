<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Belanja\Concerns;

use App\Support\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Helper daftar modul Belanja.
 *
 * Penting: daftar modul Belanja dikembalikan APA ADANYA (baris tabel) seperti
 * legacy, sehingga tipe nilai pada JSON harus sama dengan hasil PDO legacy
 * (int tetap int, DECIMAL tetap string). Karena itu seluruh query memakai
 * Query Builder — bukan Eloquent — agar casting otomatis model tidak
 * mengubah tipe nilai.
 */
trait FiltersBelanja
{
    /** SKPD (instansi) user yang login; fail-closed bila kosong. */
    protected function skpd(): string
    {
        return Tenant::requireSkpd();
    }

    /**
     * Filter status bila diisi. Nilai '' atau 'semua' berarti tanpa filter,
     * sama seperti legacy.
     */
    protected function filterStatus(Builder $query, string $kolom, string $status): void
    {
        if ($status !== '' && $status !== 'semua') {
            $query->where($kolom, $status);
        }
    }

    /** Status yang diminta (query string), default '' seperti legacy. */
    protected function status(Request $request): string
    {
        return (string) $request->query('status', '');
    }

    /**
     * Pola workflow legacy: UPDATE ... WHERE id = ? AND status = ? AND skpd = ?
     *
     * Mengembalikan false bila tidak ada baris yang cocok — legacy
     * memperlakukannya sebagai 404 dengan pesan spesifik per aksi.
     *
     * @param  array<string, mixed>  $tambahan  kolom lain yang ikut diubah
     */
    protected function ubahStatus(
        string $tabel,
        int $id,
        string $skpd,
        string $dari,
        string $ke,
        array $tambahan = []
    ): bool {
        return DB::table($tabel)
            ->where('id', $id)
            ->where('status', $dari)
            ->where('skpd', $skpd)
            ->update(array_merge(['status' => $ke], $tambahan)) > 0;
    }

    /**
     * Nomor dokumen otomatis: PREFIX-Ymd-XXXX (4 heksa huruf besar),
     * identik dengan genNomor() pada legacy.
     */
    protected function nomorDokumen(string $prefix): string
    {
        return $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }
}
