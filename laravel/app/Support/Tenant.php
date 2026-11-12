<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Sumber identitas tenant (SKPD / instansi) untuk seluruh request.
 *
 * Menggantikan pembacaan `$_SESSION['instansi']` pada sistem legacy.
 * Nilai diambil dari user yang terautentikasi. Bila belum login, hasilnya
 * string kosong — pemanggil WAJIB menolak request (fail-closed).
 */
final class Tenant
{
    /** SKPD milik user yang sedang login (string kosong bila belum login). */
    public static function skpd(): string
    {
        $user = Auth::user();

        if ($user === null) {
            return '';
        }

        return trim((string) ($user->instansi ?? ''));
    }

    /** True bila user punya instansi yang terisi. */
    public static function valid(): bool
    {
        return self::skpd() !== '';
    }

    /**
     * Ambil SKPD atau hentikan request dengan 403.
     *
     * Mirip requireInstansi() pada sistem legacy: bila instansi kosong,
     * akses DITOLAK (bukan berarti melewati filter dan melihat semua data).
     */
    public static function requireSkpd(): string
    {
        $skpd = self::skpd();

        if ($skpd === '') {
            abort(403, 'Instansi akun belum diatur. Hubungi administrator.');
        }

        return $skpd;
    }
}
