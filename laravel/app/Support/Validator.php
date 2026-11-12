<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validasi input yang meniru aturan sistem legacy.
 *
 * Diporting dari includes/functions.php agar frontend dan backend
 * memberikan pesan error yang sama (dibutuhkan oleh differential test
 * harness yang membandingkan API lama vs API baru).
 */
final class Validator
{
    /** Nama lengkap minimal 3 karakter (isValidNama). */
    public static function nama(string $nama): bool
    {
        return mb_strlen($nama) >= 3;
    }

    /** Username 3-20 karakter: huruf, angka, titik, underscore, strip (isValidUsername). */
    public static function username(string $username): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9._-]{3,20}$/', $username);
    }

    /** Format email (isValidEmail). */
    public static function email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Tahun anggaran dalam rentang wajar (isValidTahun). */
    public static function tahun(mixed $tahun): bool
    {
        return is_numeric($tahun) && (int) $tahun >= 2000 && (int) $tahun <= 2100;
    }

    /** Tanggal format YYYY-MM-DD (isValidTanggal). */
    public static function tanggal(string $tgl): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
            return false;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $tgl));

        return checkdate($m, $d, $y);
    }

    /**
     * Samarkan email untuk ditampilkan (maskEmail).
     * Contoh: "budi@polban.ac.id" -> "bu****@polban.ac.id".
     */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return $email;
        }

        $user   = substr($email, 0, $at);
        $domain = substr($email, $at);
        $awal   = substr($user, 0, min(2, strlen($user)));

        return $awal . str_repeat('*', max(1, strlen($user) - strlen($awal))) . $domain;
    }

    /**
     * Domain email yang diizinkan untuk pendaftaran.
     * Legacy: hanya @polban.ac.id.
     */
    public static function emailDomainDiizinkan(string $email): bool
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        return $domain === 'polban.ac.id';
    }
}
