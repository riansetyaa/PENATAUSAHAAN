<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\OtpNotification;
use App\Services\OtpService;
use App\Support\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * API Lupa Password (reset kata sandi via kode OTP).
 *
 * Diporting dari api/lupa_password.php pada sistem legacy.
 *
 * Alur: cek -> kirim_otp -> reset.
 */
class LupaPasswordController extends Controller
{
    public function __construct(private readonly OtpService $otp)
    {
    }

    /**
     * POST /api/v1/lupa-password/cek
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

        if (RateLimiter::tooManyAttempts('reset_cek_' . $ip, 30)) {
            return $this->fail('Terlalu banyak percobaan. Coba lagi dalam beberapa menit.', [], 429);
        }
        RateLimiter::hit('reset_cek_' . $ip, 300);

        $user = $this->cariUser($kunci);

        if (! $user) {
            return $this->fail(
                'Email belum terdaftar. Pastikan email sama dengan saat mendaftar.',
                ['field' => 'email'],
                404
            );
        }

        if ($user->status === User::STATUS_NONAKTIF) {
            return $this->fail(
                'Akun dinonaktifkan administrator. Hubungi administrator SIM-TKD.',
                ['status' => User::STATUS_NONAKTIF],
                403
            );
        }

        if ($user->status === User::STATUS_PENDING) {
            return $this->fail(
                'Akun belum diverifikasi. Aktifkan akun dulu via halaman Aktivasi Akun.',
                [
                    'status'          => User::STATUS_PENDING,
                    'need_activation' => true,
                    'aktivasi'        => 'aktivasi.html?email=' . urlencode((string) $user->email),
                ],
                403
            );
        }

        $sisa = $this->otp->sisaDetik($user);

        return $this->ok(
            'Data akun ditemukan. Periksa nama & instansi, lalu klik Konfirmasi untuk menerima kode OTP reset.',
            [
                'status'        => User::STATUS_AKTIF,
                'user'          => $this->dataUser($user),
                'otp_aktif'     => $sisa > 0,
                'otp_sisa_detik' => $sisa,
            ]
        );
    }

    /**
     * POST /api/v1/lupa-password/kirim-otp
     */
    public function kirimOtp(Request $request)
    {
        $kunci = strtolower(trim((string) $request->input('email', '')));

        if ($kunci === '') {
            return $this->fail('Email wajib diisi.', ['field' => 'email'], 422);
        }

        if (RateLimiter::tooManyAttempts('reset_kirim_' . $kunci, 5)) {
            return $this->fail('Permintaan kode OTP terlalu sering. Coba lagi dalam beberapa menit.', [], 429);
        }

        $user = $this->cariUser($kunci);

        if (! $user) {
            return $this->fail('Email belum terdaftar.', ['field' => 'email'], 404);
        }

        if ($user->status === User::STATUS_NONAKTIF) {
            return $this->fail('Akun dinonaktifkan administrator.', [], 403);
        }

        if ($user->status === User::STATUS_PENDING) {
            return $this->fail(
                'Akun belum diverifikasi. Aktifkan dulu via halaman Aktivasi Akun.',
                [
                    'status'          => User::STATUS_PENDING,
                    'need_activation' => true,
                    'aktivasi'        => 'aktivasi.html?email=' . urlencode((string) $user->email),
                ],
                403
            );
        }

        // Jeda 60 detik antar pengiriman.
        $tunggu = $this->otp->sisaJedaKirim($user);

        if ($tunggu > 0) {
            return $this->fail(
                "Kode baru saja dikirim. Tunggu {$tunggu} detik lagi.",
                ['tunggu_detik' => $tunggu],
                429
            );
        }

        $otp = $this->otp->generate();

        try {
            $this->otp->issue($user, $otp);
        } catch (\Throwable $e) {
            report($e);

            return $this->fail('Server gagal menyimpan kode OTP.', [], 500);
        }

        RateLimiter::hit('reset_kirim_' . $kunci, 900);

        $terkirim = $this->kirimOtpKeEmail($user, $otp);
        $menit    = (int) ceil(OtpService::BERLAKU_DETIK / 60);
        $debugOtp = (bool) env('SIMTKD_DEBUG_OTP', false);

        if (! $terkirim) {
            Log::error('[SIM-TKD] Kirim OTP reset ke ' . $user->email . ' gagal (kode: ' . $otp . ')');

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
            'Kode OTP reset telah dikirim ke email ' . Validator::maskEmail((string) $user->email)
                . ". Kode berlaku {$menit} menit.",
            $extra
        );
    }

    /**
     * POST /api/v1/lupa-password/reset
     */
    public function reset(Request $request)
    {
        $kunci = strtolower(trim((string) $request->input('email', '')));
        $otp   = preg_replace('/\D/', '', (string) $request->input('otp', ''));
        $baru  = (string) $request->input('password_baru', '');
        $konf  = (string) $request->input('password_konfirmasi', '');
        $ip    = $request->ip() ?? '-';

        if ($kunci === '') {
            return $this->fail('Email wajib diisi.', ['field' => 'email'], 422);
        }

        if ($otp === '') {
            return $this->fail('Kode OTP wajib diisi.', ['field' => 'otp'], 422);
        }

        if (strlen($otp) !== OtpService::PANJANG) {
            return $this->fail('Kode OTP terdiri dari ' . OtpService::PANJANG . ' angka.', ['field' => 'otp'], 422);
        }

        if (strlen($baru) < 8) {
            return $this->fail('Password baru minimal 8 karakter.', ['field' => 'password_baru'], 422);
        }

        if ($baru !== $konf) {
            return $this->fail('Konfirmasi password tidak cocok.', ['field' => 'password_konfirmasi'], 422);
        }

        // Batas percobaan tebak kode (per email + IP).
        $rlKey = 'reset_verif_' . $kunci . '_' . $ip;

        if (RateLimiter::tooManyAttempts($rlKey, 15)) {
            return $this->fail('Terlalu banyak percobaan. Minta kode OTP baru atau coba lagi nanti.', [], 429);
        }
        RateLimiter::hit($rlKey, 900);

        $user = $this->cariUser($kunci);

        if (! $user) {
            return $this->fail('Email belum terdaftar.', ['field' => 'email'], 404);
        }

        if ($user->status !== User::STATUS_AKTIF) {
            return $this->fail('Akun tidak dalam status aktif.', [], 403);
        }

        if (empty($user->otp_hash)) {
            return $this->fail('Kode OTP belum dibuat. Minta kode OTP lebih dulu.', ['field' => 'otp'], 422);
        }

        if ($this->otp->sisaDetik($user) <= 0) {
            $this->otp->clear($user);

            return $this->fail(
                'Kode OTP sudah kedaluwarsa. Silakan minta kode baru.',
                ['kedaluwarsa' => true],
                422
            );
        }

        if ((int) $user->otp_percobaan >= OtpService::TEBAK_MAKSIMAL) {
            return $this->fail(
                'Terlalu banyak kode salah. Silakan minta kode OTP baru.',
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

        try {
            $user->forceFill(['password' => $baru])->save();
            $this->otp->clear($user);
        } catch (\Throwable $e) {
            report($e);

            return $this->fail('Server gagal mengubah password. Coba lagi beberapa saat lagi.', [], 500);
        }

        RateLimiter::clear($rlKey);

        return $this->ok('Password berhasil diubah. Silakan login dengan password baru.');
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    private function cariUser(string $kunci): ?User
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [$kunci])
            ->orWhereRaw('LOWER(username) = ?', [$kunci])
            ->first();
    }

    private function dataUser(User $u): array
    {
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

    private function kirimOtpKeEmail(User $user, string $otp): bool
    {
        try {
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
