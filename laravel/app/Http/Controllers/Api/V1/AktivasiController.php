<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\OtpNotification;
use App\Services\OtpService;
use App\Support\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * API Aktivasi Akun (verifikasi email via kode OTP).
 *
 * Diporting dari api/aktivasi.php pada sistem legacy, dengan tiga aksi:
 *   1. action=cek        -> cek email, tampilkan nama akun & instansi
 *   2. action=kirim_otp  -> bangkitkan kode OTP + kirim ke email pengguna
 *   3. action=verifikasi -> kode OTP benar -> status akun menjadi 'aktif'
 *
 * Rute dipisah (bukan satu endpoint dengan parameter `action`) karena
 * bentuk JSON, status code, dan aturan rate limit tiap aksi berbeda.
 * Nonetheless respons tetap identik dengan legacy agar frontend
 * (aktivasi.html + js/aktivasi.js) tidak perlu diubah.
 */
class AktivasiController extends Controller
{
    public function __construct(private readonly OtpService $otp)
    {
    }

    /**
     * POST /api/v1/aktivasi/cek
     */
    public function cek(Request $request)
    {
        $kunci = strtolower(trim((string) $request->input('email', '')));
        $ip    = $request->ip() ?? '-';

        if ($kunci === '') {
            return $this->fail('Email wajib diisi.', ['field' => 'email'], 422);
        }

        if (! Validator::email($kunci) && ! Validator::username($kunci)) {
            return $this->fail('Format email tidak valid.', ['field' => 'email'], 422);
        }

        if (RateLimiter::tooManyAttempts('aktivasi_cek_' . $ip, 30)) {
            return $this->fail('Terlalu banyak percobaan. Coba lagi dalam beberapa menit.', [], 429);
        }
        RateLimiter::hit('aktivasi_cek_' . $ip, 300);

        $user = $this->cariUser($kunci);

        if (! $user) {
            return $this->fail(
                'Email belum terdaftar. Pastikan email yang Anda masukkan sama dengan email saat mendaftar.',
                ['field' => 'email'],
                404
            );
        }

        if ($user->status === User::STATUS_AKTIF) {
            return $this->ok('Akun Anda sudah aktif. Silakan login menggunakan email atau username Anda.', [
                'status'   => User::STATUS_AKTIF,
                'redirect' => 'login.html',
                'user'     => $this->dataUser($user),
            ]);
        }

        if ($user->status === User::STATUS_NONAKTIF) {
            return $this->fail(
                'Akun Anda dinonaktifkan oleh administrator. Hubungi administrator SIM-TKD untuk bantuan.',
                ['status' => User::STATUS_NONAKTIF],
                403
            );
        }

        $sisa = $this->otp->sisaDetik($user);

        return $this->ok(
            'Data akun ditemukan. Periksa nama akun dan instansi berikut, lalu klik Konfirmasi untuk menerima kode OTP.',
            [
                'status'         => User::STATUS_PENDING,
                'user'           => $this->dataUser($user),
                'otp_aktif'      => $sisa > 0,
                'otp_sisa_detik' => $sisa,
            ]
        );
    }

    /**
     * POST /api/v1/aktivasi/kirim-otp
     */
    public function kirimOtp(Request $request)
    {
        $kunci = strtolower(trim((string) $request->input('email', '')));

        if ($kunci === '') {
            return $this->fail('Email wajib diisi.', ['field' => 'email'], 422);
        }

        // Maks 5 kali kirim per 15 menit untuk satu email
        if (RateLimiter::tooManyAttempts('aktivasi_kirim_' . $kunci, 5)) {
            return $this->fail('Permintaan kode OTP terlalu sering. Coba lagi dalam beberapa menit.', [], 429);
        }

        $user = $this->cariUser($kunci);

        if (! $user) {
            return $this->fail('Email belum terdaftar. Pastikan email yang Anda masukkan benar.', ['field' => 'email'], 404);
        }

        if ($user->status === User::STATUS_AKTIF) {
            return $this->ok('Akun Anda sudah aktif. Silakan login.', [
                'status'   => User::STATUS_AKTIF,
                'redirect' => 'login.html',
            ]);
        }

        if ($user->status === User::STATUS_NONAKTIF) {
            return $this->fail('Akun Anda dinonaktifkan oleh administrator. Hubungi administrator SIM-TKD.', [], 403);
        }

        // Jeda minimal 60 detik antar pengiriman
        $tunggu = $this->otp->sisaJedaKirim($user);
        if ($tunggu > 0) {
            return $this->fail(
                "Kode OTP baru saja dikirim. Tunggu {$tunggu} detik lagi untuk mengirim ulang.",
                ['tunggu_detik' => $tunggu],
                429
            );
        }

        $otp = $this->otp->generate();

        try {
            $this->otp->issue($user, $otp);
        } catch (\Throwable $e) {
            report($e);

            return $this->fail('Server gagal menyimpan kode OTP. Coba lagi beberapa saat lagi.', [], 500);
        }

        RateLimiter::hit('aktivasi_kirim_' . $kunci, 900);

        // Kirim kode OTP ke email terdaftar
        $terkirim = $this->kirimOtpKeEmail($user, $otp);
        $menit    = (int) ceil(OtpService::BERLAKU_DETIK / 60);

        // Mode uji (SIMTKD_DEBUG_OTP=true) -> kode ikut dikembalikan di
        // respons. Padanan fitur `debug_otp` pada config/mail.php legacy.
        // JANGAN aktifkan di produksi.
        $debugOtp = (bool) env('SIMTKD_DEBUG_OTP', false);

        if (! $terkirim) {
            // Server email belum siap. Kode tetap tercatat di log server agar
            // administrator bisa membantu pengguna (perilaku sama dgn legacy).
            Log::error('[SIM-TKD] Gagal mengirim OTP ke ' . $user->email . ' (kode: ' . $otp . ')');

            $extra = ['email_samar' => Validator::maskEmail((string) $user->email)];
            if ($debugOtp) {
                $extra['dev_otp'] = $otp;
            }

            return $this->fail(
                'Kode OTP gagal dikirim ke email ' . Validator::maskEmail((string) $user->email)
                    . '. Hubungi administrator SIM-TKD (kode tercatat di log server).',
                $extra,
                500
            );
        }

        $extra = [
            'email_samar'   => Validator::maskEmail((string) $user->email),
            'berlaku_menit' => $menit,
            'panjang_otp'   => OtpService::PANJANG,
        ];

        if ($debugOtp) {
            $extra['dev_otp'] = $otp;
        }

        return $this->ok(
            'Kode OTP telah dikirim ke email ' . Validator::maskEmail((string) $user->email)
                . ". Kode berlaku {$menit} menit.",
            $extra
        );
    }

    /**
     * POST /api/v1/aktivasi/verifikasi
     */
    public function verifikasi(Request $request)
    {
        $kunci = strtolower(trim((string) $request->input('email', '')));
        $otp   = preg_replace('/\D/', '', (string) $request->input('otp', ''));
        $ip    = $request->ip() ?? '-';

        if ($otp === '') {
            return $this->fail('Kode OTP wajib diisi.', ['field' => 'otp'], 422);
        }

        if (strlen($otp) !== OtpService::PANJANG) {
            return $this->fail('Kode OTP terdiri dari ' . OtpService::PANJANG . ' angka.', ['field' => 'otp'], 422);
        }

        // Batas percobaan tebak kode (per email + IP)
        $rlKey = 'aktivasi_verif_' . $kunci . '_' . $ip;
        if (RateLimiter::tooManyAttempts($rlKey, 15)) {
            return $this->fail('Terlalu banyak percobaan verifikasi. Minta kode OTP baru atau coba lagi nanti.', [], 429);
        }
        RateLimiter::hit($rlKey, 900);

        $user = $this->cariUser($kunci);

        if (! $user) {
            return $this->fail('Email belum terdaftar.', ['field' => 'email'], 404);
        }

        if ($user->status === User::STATUS_AKTIF) {
            return $this->ok('Akun Anda sudah aktif. Silakan login.', [
                'status'   => User::STATUS_AKTIF,
                'redirect' => 'login.html',
            ]);
        }

        if ($user->status === User::STATUS_NONAKTIF) {
            return $this->fail('Akun Anda dinonaktifkan oleh administrator. Hubungi administrator SIM-TKD.', [], 403);
        }

        if (empty($user->otp_hash)) {
            return $this->fail(
                'Kode OTP belum dibuat. Klik Konfirmasi untuk menerima kode OTP lebih dulu.',
                ['field' => 'otp'],
                422
            );
        }

        if ($this->otp->sisaDetik($user) <= 0) {
            // Kode kedaluwarsa -> hapus agar tidak bisa dipakai lagi
            $this->otp->clear($user);

            return $this->fail(
                'Kode OTP sudah kedaluwarsa. Klik "Kirim Ulang Kode" untuk meminta kode baru.',
                ['kedaluwarsa' => true],
                422
            );
        }

        if ((int) $user->otp_percobaan >= OtpService::TEBAK_MAKSIMAL) {
            return $this->fail(
                'Terlalu banyak kode salah untuk kode OTP ini. Klik "Kirim Ulang Kode" untuk meminta kode baru.',
                ['harus_kirim_ulang' => true],
                429
            );
        }

        if (! $this->otp->verify($user, $otp)) {
            $sisa = $this->otp->sisaPercobaan($user);

            return $this->fail(
                'Kode OTP salah. Sisa percobaan: ' . $sisa . '.',
                ['field' => 'otp', 'sisa_percobaan' => $sisa],
                401
            );
        }

        // Kode benar -> aktifkan akun & bersihkan kolom OTP
        try {
            $user->forceFill([
                'status'            => User::STATUS_AKTIF,
                'otp_verifikasi_at' => now(),
            ])->save();

            $this->otp->clear($user);
        } catch (\Throwable $e) {
            report($e);

            return $this->fail('Server gagal mengaktifkan akun. Coba lagi beberapa saat lagi.', [], 500);
        }

        RateLimiter::clear($rlKey);

        return $this->ok(
            'Akun Anda berhasil diaktifkan! Silakan login menggunakan username atau email Anda.',
            [
                'status'   => User::STATUS_AKTIF,
                'redirect' => 'login.html',
                'user'     => $this->dataUser($user->fresh()),
            ]
        );
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    /** Cari user berdasarkan email ATAU username (key dicocokkan lowercase). */
    private function cariUser(string $kunci): ?User
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [$kunci])
            ->orWhereRaw('LOWER(username) = ?', [$kunci])
            ->first();
    }

    /** Data user yang aman dikirim ke halaman aktivasi (email disamarkan). */
    private function dataUser(?User $u): array
    {
        if (! $u) {
            return [];
        }

        return [
            'nama_lengkap' => (string) $u->nama_lengkap,
            'username'     => (string) $u->username,
            'email_samar'  => Validator::maskEmail((string) $u->email),
            'instansi'     => (string) ($u->instansi ?? ''),
            'kota'         => (string) ($u->kota ?? ''),
            'provinsi'     => (string) ($u->provinsi ?? ''),
            'peran'        => (string) ($u->peran ?? ''),
            'status'       => (string) $u->status,
        ];
    }

    /**
     * Kirim OTP via email. Mengembalikan false bila mailer gagal.
     */
    private function kirimOtpKeEmail(User $user, string $otp): bool
    {
        try {
            // Kirim sinkron (bukan antrean) supaya hasil pengiriman diketahui
            // pada respons yang sama dengan perilaku legacy.
            $user->notifyNow(new OtpNotification(
                $otp,
                (string) $user->nama_lengkap,
                (string) ($user->instansi ?? ''),
                (int) ceil(OtpService::BERLAKU_DETIK / 60),
            ));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
