<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * API Autentikasi (v1).
 *
 * Diporting dari api/login.php, api/register.php, api/logout.php, dan
 * api/session.php pada sistem legacy.
 *
 * Kompatibilitas dijaga: nama parameter, pesan error, dan HTTP status code
 * SAMA PERSIS dengan versi legacy, sehingga frontend vanilla yang sudah ada
 * tidak perlu diubah dan differential test harness dapat membandingkan
 * kedua implementasi.
 */
class AuthController extends Controller
{
    /** Nilai ENUM `peran` yang diizinkan. */
    private const PERAN_DIIZINKAN = [
        'Admin Dinas',
        'Operator',
        'Bendahara',
        'Verifikator',
        'Kepala Dinas',
        'Pengguna Umum',
    ];

    /**
     * POST /api/v1/auth/login
     *
     * `username` menerima username ATAU email terdaftar.
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [
            'username.required' => 'Username/Email dan password wajib diisi.',
            'password.required' => 'Username/Email dan password wajib diisi.',
        ]);

        $username = trim((string) $request->input('username'));
        $password = (string) $request->input('password');

        // ---- Rate limit: maks 8 percobaan / 5 menit per username+IP ----
        $ip = $request->ip() ?? '-';
        $rlKey = 'login:' . strtolower($username) . ':' . $ip;

        if (RateLimiter::tooManyAttempts($rlKey, 8)) {
            return $this->fail(
                'Terlalu banyak percobaan login. Coba lagi dalam beberapa menit.',
                [],
                429
            );
        }

        $user = User::query()
            ->where('username', $username)
            ->orWhere('email', $username)
            ->first();

        // Verifikasi password
        if (! $user || ! Hash::check($password, (string) $user->password)) {
            RateLimiter::hit($rlKey, 300);

            return $this->fail('Username/Email atau password tidak valid.', [], 401);
        }

        // Akun 'pending' belum boleh login.
        if ($user->status === User::STATUS_PENDING) {
            return $this->fail(
                'Akun Anda belum diverifikasi. Silakan aktifkan akun terlebih dahulu dengan kode OTP yang dikirim ke email Anda.',
                [
                    'status'          => User::STATUS_PENDING,
                    'need_activation' => true,
                    'aktivasi'        => 'aktivasi.html?email=' . urlencode((string) $user->email),
                ],
                403
            );
        }

        if ($user->status === User::STATUS_NONAKTIF) {
            return $this->fail('Akun Anda dinonaktifkan. Hubungi administrator.', [], 403);
        }

        RateLimiter::clear($rlKey);

        // Buat sesi login
        Auth::login($user, (bool) $request->boolean('remember'));
        $request->session()->regenerate();

        // Token API untuk subdomain (AKLAP / dokumen).
        //
        // Token DIBUAT SAAT LOGIN dan hanya disimpan hash-nya (Sanctum
        // menyimpan hash di tabel personal_access_tokens). Token mentah
        // hanya diberikan ke user yang sedang login ini.
        //
        // Perbedaan dari legacy: token legacy disimpan di kolom
        // `users.api_token` dan tidak punya masa berlaku. Sanctum mendukung
        // expiry sehingga risiko kebocoran jangka panjang berkurang.
        $plainToken = $user->createToken('simtkd-web')->plainTextToken;

        return $this->ok('Login berhasil. Mengalihkan ke dashboard...', [
            'redirect' => 'dashboard.html',
            'token'    => $plainToken,
            'user'     => [
                'nama'     => $user->nama_lengkap,
                'username' => $user->username,
                'peran'    => $user->peran,
                'instansi' => $user->instansi,
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/register
     *
     * Akun baru SELALU berstatus 'pending' dan wajib diverifikasi lewat
     * kode OTP sebelum bisa login.
     */
    public function register(Request $request)
    {
        $nama     = trim((string) $request->input('nama_lengkap', ''));
        $username = trim((string) $request->input('username', ''));
        $email    = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $instansi = trim((string) $request->input('instansi', ''));
        $peran    = trim((string) $request->input('peran', 'Bendahara'));
        $kota     = trim((string) $request->input('kota', ''));
        $provinsi = trim((string) $request->input('provinsi', ''));

        // --- Validasi server-side (pesan identik dengan legacy) ---
        if (! Validator::nama($nama)) {
            return $this->fail('Nama lengkap minimal 3 karakter.', ['field' => 'nama_lengkap'], 422);
        }

        if (! Validator::username($username)) {
            return $this->fail('Username 3-20 karakter (huruf, angka, . _ -).', ['field' => 'username'], 422);
        }

        if (! Validator::email($email)) {
            return $this->fail('Format email tidak valid.', ['field' => 'email'], 422);
        }

        // Pendaftaran hanya diizinkan via email domain POLBAN
        if (! Validator::emailDomainDiizinkan($email)) {
            return $this->fail(
                'Pendaftaran hanya menggunakan email domain @polban.ac.id.',
                ['field' => 'email'],
                422
            );
        }

        if (strlen($password) < 8) {
            return $this->fail('Kata sandi minimal 8 karakter.', ['field' => 'password'], 422);
        }

        if (! in_array($peran, self::PERAN_DIIZINKAN, true)) {
            $peran = 'Pengguna Umum';
        }

        // Cek duplikasi username & email
        $existing = User::query()
            ->where('username', $username)
            ->orWhere('email', $email)
            ->first();

        if ($existing) {
            if ($existing->username === $username) {
                return $this->fail('Username sudah digunakan.', ['field' => 'username'], 409);
            }

            return $this->fail('Email sudah terdaftar.', ['field' => 'email'], 409);
        }

        try {
            $user = User::create([
                'nama_lengkap' => $nama,
                'username'     => $username,
                'email'        => $email,
                'password'     => $password, // di-hash otomatis oleh cast 'hashed'
                'instansi'     => $instansi !== '' ? $instansi : null,
                'kota'         => $kota !== '' ? $kota : null,
                'provinsi'     => $provinsi !== '' ? $provinsi : null,
                'peran'        => $peran,
                'status'       => User::STATUS_PENDING,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return $this->fail('Terjadi kesalahan saat menyimpan data. Silakan coba lagi.', [], 500);
        }

        // Sinkronkan role Spatie dengan kolom ENUM `peran`.
        $user->syncPeranRole();

        return $this->ok(
            'Pendaftaran berhasil! Akun Anda berstatus PENDING. '
            . 'Silakan verifikasi dengan kode OTP yang dikirim ke email Anda untuk mengaktifkan akun.',
            [
                'user_id'  => $user->id,
                'status'   => User::STATUS_PENDING,
                'email'    => $email,
                'aktivasi' => 'aktivasi.html?email=' . urlencode($email),
            ],
            201
        );
    }

    /**
     * GET /api/v1/auth/logout
     */
    public function logout(Request $request)
    {
        // Cabut seluruh token API milik user ini (termasuk token yang
        // dipakai subdomain AKLAP & dokumen).
        $user = $request->user();

        if ($user) {
            $user->tokens()->delete();
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->ok('Logout berhasil.', ['redirect' => 'login.html']);
    }

    /**
     * GET /api/v1/auth/session
     *
     * Dipakai frontend untuk memastikan session masih valid.
     */
    public function session(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return $this->fail('Tidak terautentikasi.', [], 401);
        }

        return $this->ok('Sesi aktif.', [
            'user' => [
                'nama'     => $user->nama_lengkap,
                'username' => $user->username,
                'peran'    => $user->peran,
                'instansi' => $user->instansi,
                'nip'      => $user->nip,
            ],
        ]);
    }
}
