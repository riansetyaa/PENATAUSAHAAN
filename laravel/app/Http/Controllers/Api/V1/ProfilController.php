<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * API Profil Pengguna (menu Pengaturan > Profil).
 *
 * Diporting dari api/profil.php pada sistem legacy.
 */
class ProfilController extends Controller
{
    /**
     * GET /api/v1/profil
     */
    public function show(Request $request)
    {
        $user = $request->user();

        return $this->ok('Data profil.', [
            'profil' => [
                'nama_lengkap' => (string) $user->nama_lengkap,
                'nip'          => (string) ($user->nip ?? ''),
                'username'     => (string) $user->username,
                'email'        => (string) $user->email,
                'instansi'     => (string) ($user->instansi ?? ''),
                'peran'        => (string) $user->peran,
                'status'       => (string) $user->status,
                'dibuat'       => substr((string) $user->created_at, 0, 10), // YYYY-MM-DD
            ],
        ]);
    }

    /**
     * POST /api/v1/profil  (action=simpan)
     *
     * Update nama_lengkap, email, instansi, dan nip.
     * Username & peran TIDAK bisa diubah lewat endpoint ini.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        if (RateLimiter::tooManyAttempts('profil_simpan_' . $user->id, 10)) {
            return $this->fail('Terlalu banyak percobaan. Coba lagi dalam beberapa menit.', [], 429);
        }
        RateLimiter::hit('profil_simpan_' . $user->id, 300);

        $nama     = trim((string) $request->input('nama_lengkap', ''));
        $email    = strtolower(trim((string) $request->input('email', '')));
        $instansi = trim((string) $request->input('instansi', ''));
        $nip      = trim((string) preg_replace(
            '/\s+/',
            ' ',
            (string) preg_replace('/[^0-9A-Za-z.\-\/ ]/', '', (string) $request->input('nip', ''))
        ));

        if (mb_strlen($nama) < 3) {
            return $this->fail('Nama lengkap tidak valid (3-100 karakter huruf).', [], 422);
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Format email tidak valid.', [], 422);
        }

        if (mb_strlen($instansi) > 150) {
            return $this->fail('Nama instansi maksimal 150 karakter.', [], 422);
        }

        if (mb_strlen($nip) > 30) {
            return $this->fail('NIP maksimal 30 karakter.', [], 422);
        }

        // Email harus unik (kecuali milik sendiri).
        $ada = User::query()
            ->where('email', $email)
            ->where('id', '<>', $user->id)
            ->first();

        if ($ada) {
            return $this->fail('Email sudah dipakai oleh akun lain.', [], 409);
        }

        $user->forceFill([
            'nama_lengkap' => $nama,
            'email'        => $email,
            'instansi'     => $instansi !== '' ? $instansi : null,
            'nip'          => $nip !== '' ? $nip : null,
        ])->save();

        // Refresh sesi agar sidebar/topbar di semua halaman langsung
        // menampilkan data baru.
        //
        // PENTING: endpoint ini bisa dipanggil dengan Bearer token (tanpa
        // cookie sesi), sehingga session store belum tentu tersedia.
        // Karena itu sesi hanya disegarkan bila memang ada.
        if ($request->hasSession()) {
            $request->session()->put([
                'nama'     => $nama,
                'email'    => $email,
                'instansi' => $instansi,
                'nip'      => $nip,
            ]);
        }

        return $this->ok('Profil berhasil diperbarui.', [
            'profil' => [
                'nama_lengkap' => $nama,
                'nip'          => $nip,
                'email'        => $email,
                'instansi'     => $instansi,
            ],
        ]);
    }

    /**
     * POST /api/v1/profil/password  (action=ganti_password)
     *
     * Ganti password dengan verifikasi password lama.
     */
    public function gantiPassword(Request $request)
    {
        $user = $request->user();

        if (RateLimiter::tooManyAttempts('profil_pw_' . $user->id, 5)) {
            return $this->fail('Terlalu banyak percobaan. Coba lagi dalam beberapa menit.', [], 429);
        }
        RateLimiter::hit('profil_pw_' . $user->id, 300);

        $lama = (string) $request->input('password_lama', '');
        $baru = (string) $request->input('password_baru', '');
        $konf = (string) $request->input('password_konfirmasi', '');

        if ($lama === '' || $baru === '') {
            return $this->fail('Password lama dan password baru wajib diisi.', [], 422);
        }

        if (strlen($baru) < 6) {
            return $this->fail('Password baru minimal 6 karakter.', [], 422);
        }

        if ($baru !== $konf) {
            return $this->fail('Konfirmasi password tidak cocok.', [], 422);
        }

        if ($baru === $lama) {
            return $this->fail('Password baru tidak boleh sama dengan password lama.', [], 422);
        }

        if (! Hash::check($lama, (string) $user->password)) {
            return $this->fail('Password lama salah.', [], 401);
        }

        $user->forceFill(['password' => $baru])->save();

        // Cast 'hashed' pada model melakukan hashing otomatis.

        return $this->ok('Password berhasil diubah.');
    }
}
