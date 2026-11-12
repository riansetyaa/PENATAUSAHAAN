<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Aklap\Concerns;

/**
 * Helper bersama modul AKLAP — validasi tanggal ala legacy
 * (`tanggal_valid()` pada peta.simtkd.com/api/akuntansi.php).
 *
 * Tanggal yang tidak lolos TIDAK memicu galat: nilainya dikembalikan sebagai
 * string kosong sehingga filter tanggal dianggap tidak dikirim — persis
 * seperti perilaku legacy.
 */
trait TanggalValid
{
    /** Tanggal valid = format Y-m-d DAN tanggalnya nyata (mis. 2026-13-99 ditolak). */
    protected function tanggalValid(string $tanggal): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) !== 1) {
            return '';
        }

        [$tahun, $bulan, $hari] = array_map('intval', explode('-', $tanggal));

        return checkdate($bulan, $hari, $tahun) ? $tanggal : '';
    }
}
