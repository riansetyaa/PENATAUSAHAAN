<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Layanan kode OTP untuk aktivasi akun.
 *
 * Diporting dari logika OTP pada sistem legacy (includes/functions.php +
 * api/aktivasi.php) dengan aturan yang sama:
 *
 *  - Kode 6 angka dari CSPRNG (`random_int`), bukan `rand`.
 *  - DISIMPAN SEBAGAI HASH bcrypt, tidak pernah mentah. Bila database bocor,
 *    kode OTP 6 angka tidak bisa dibalik secara instan.
 *  - Berlaku 10 menit.
 *  - Maksimal 5 kali tebakan salah per kode.
 *  - Jeda 60 detik antar pengiriman ulang.
 *  - Timestamp dihitung di sisi PHP (`now()`), bukan `NOW()` MySQL, agar
 *    tidak terpengaruh perbedaan zona waktu server database.
 */
class OtpService
{
    /** Panjang kode OTP. */
    public const PANJANG = 6;

    /** Masa berlaku kode (detik). */
    public const BERLAKU_DETIK = 600; // 10 menit

    /** Maksimal percobaan tebakan salah per kode. */
    public const TEBAK_MAKSIMAL = 5;

    /** Jeda minimum antar pengiriman ulang (detik). */
    public const JEDA_KIRIM_Ulang = 60;

    /**
     * Bangkitkan kode OTP numerik memakai CSPRNG.
     */
    public function generate(int $length = self::PANJANG): string
    {
        $otp = '';

        for ($i = 0; $i < $length; $i++) {
            $otp .= (string) random_int(0, 9);
        }

        return $otp;
    }

    /**
     * Simpan kode baru (hash) untuk user dan reset penghitung percobaan.
     */
    public function issue(User $user, string $otp): void
    {
        $now = now();

        $user->forceFill([
            'otp_hash'       => Hash::make($otp),
            'otp_expired_at' => $now->copy()->addSeconds(self::BERLAKU_DETIK),
            'otp_terkirim_at' => $now,
            'otp_percobaan'  => 0,
        ])->save();
    }

    /**
     * Sisa detik berlakunya kode OTP (0 bila tidak ada / kedaluwarsa).
     */
    public function sisaDetik(User $user): int
    {
        if (empty($user->otp_hash) || empty($user->otp_expired_at)) {
            return 0;
        }

        $sisa = $user->otp_expired_at->getTimestamp() - now()->getTimestamp();

        return $sisa > 0 ? $sisa : 0;
    }

    /**
     * Apakah ada kode aktif yang belum kedaluwarsa?
     */
    public function adaKodeAktif(User $user): bool
    {
        return $this->sisaDetik($user) > 0;
    }

    /**
     * Berapa detik lagi pengguna harus menunggu sebelum meminta kode baru.
     * 0 bila boleh mengirim sekarang.
     */
    public function sisaJedaKirim(User $user): int
    {
        if (empty($user->otp_terkirim_at) || ! $this->adaKodeAktif($user)) {
            return 0;
        }

        $lewat = now()->getTimestamp() - $user->otp_terkirim_at->getTimestamp();

        if ($lewat < 0 || $lewat >= self::JEDA_KIRIM_Ulang) {
            return 0;
        }

        return self::JEDA_KIRIM_Ulang - $lewat;
    }

    /**
     * Cocokkan kode OTP dengan hash yang tersimpan.
     *
     * Mengembalikan true bila cocok. Bila salah, penghitung percobaan
     * dinaikkan sehingga maksimum 5 tebakan.
     */
    public function verify(User $user, string $otp): bool
    {
        if (empty($user->otp_hash)) {
            return false;
        }

        if (Hash::check($otp, (string) $user->otp_hash)) {
            return true;
        }

        $user->forceFill([
            'otp_percobaan' => (int) $user->otp_percobaan + 1,
        ])->save();

        return false;
    }

    /**
     * Sisa jumlah percobaan yang masih boleh dilakukan.
     *
     * Dihitung dari nilai `otp_percobaan` SETELAH increment (nilai di DB
     * incremented oleh verify() saat kode salah). Setara dengan rumus
     * legacy: `otpTebakMaksimal() - ($percobaan + 1)` yang dihitung
     * SEBELUM increment.
     */
    public function sisaPercobaan(User $user): int
    {
        return max(0, self::TEBAK_MAKSIMAL - (int) $user->otp_percobaan);
    }

    /**
     * Bersihkan kolom OTP (setelah verifikasi berhasil atau kode kedaluwarsa).
     */
    public function clear(User $user): void
    {
        $user->forceFill([
            'otp_hash'        => null,
            'otp_expired_at'  => null,
            'otp_percobaan'   => 0,
        ])->save();
    }
}
