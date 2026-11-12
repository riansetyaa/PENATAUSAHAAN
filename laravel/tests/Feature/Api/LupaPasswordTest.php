<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tes alur Lupa Password (cek -> kirim OTP -> reset).
 *
 * Fokus utama: KESETIAAN dengan frontend & API legacy.
 *  - Nama field konfirmasi adalah `password_ulangi` (dikirim oleh
 *    js/lupa-password.js); pernah tertukar menjadi `password_konfirmasi`
 *    sehingga seluruh permintaan reset akan ditolak.
 *  - Pesan & status HTTP harus sama dengan api/lupa_password.php.
 *  - Token sesi lama dicabut setelah password berubah (setara legacy yang
 *    mengosongkan users.api_token).
 */
class LupaPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function buatUser(string $status = User::STATUS_AKTIF): User
    {
        return User::create([
            'nama_lengkap' => 'User Lupa',
            'username'     => 'ulupa',
            'email'        => 'ulupa@polban.ac.id',
            'password'     => 'RahasiaKuat123',
            'instansi'     => 'Dinas A',
            'peran'        => 'Bendahara',
            'status'       => $status,
        ]);
    }

    private function otpService(): OtpService
    {
        return app(OtpService::class);
    }

    public function test_cek_email_tidak_terdaftar_memakai_pesan_legacy(): void
    {
        $this->postJson('/api/v1/lupa-password/cek', ['email' => 'tidak-ada@polban.ac.id'])
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Email belum terdaftar. Pastikan email sama dengan saat mendaftar.')
            ->assertJsonPath('field', 'email');
    }

    public function test_kirim_otp_akun_pending_mengarahkan_ke_aktivasi(): void
    {
        $this->buatUser(User::STATUS_PENDING);

        $this->postJson('/api/v1/lupa-password/kirim-otp', ['email' => 'ulupa@polban.ac.id'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Akun belum diverifikasi. Aktifkan dulu via halaman Aktivasi Akun.')
            ->assertJsonPath('need_activation', true);
    }

    public function test_reset_berhasil_dengan_field_password_ulangi(): void
    {
        $user = $this->buatUser();
        $this->otpService()->issue($user, '123456');

        $this->postJson('/api/v1/lupa-password/reset', [
            'email'           => 'ulupa@polban.ac.id',
            'otp'             => '123456',
            'password_baru'   => 'KataSandiBaru123',
            'password_ulangi' => 'KataSandiBaru123',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Kata sandi berhasil diubah. Silakan login dengan kata sandi baru.')
            ->assertJsonPath('status', 'aktif')
            ->assertJsonPath('redirect', 'login.html');

        $this->assertTrue(Hash::check('KataSandiBaru123', (string) $user->fresh()->password));
    }

    public function test_reset_menolak_konfirmasi_berbeda_dengan_field_password_ulangi(): void
    {
        $user = $this->buatUser();
        $this->otpService()->issue($user, '123456');

        $this->postJson('/api/v1/lupa-password/reset', [
            'email'           => 'ulupa@polban.ac.id',
            'otp'             => '123456',
            'password_baru'   => 'KataSandiBaru123',
            'password_ulangi' => 'BedaSekali123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Konfirmasi kata sandi tidak sama.')
            ->assertJsonPath('field', 'password_ulangi');
    }

    public function test_reset_menolak_kata_sandi_pendek(): void
    {
        $user = $this->buatUser();
        $this->otpService()->issue($user, '123456');

        $this->postJson('/api/v1/lupa-password/reset', [
            'email'           => 'ulupa@polban.ac.id',
            'otp'             => '123456',
            'password_baru'   => 'pendek',
            'password_ulangi' => 'pendek',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kata sandi baru minimal 8 karakter.')
            ->assertJsonPath('field', 'password_baru');
    }

    public function test_reset_mencabut_token_sesi_lama(): void
    {
        $user = $this->buatUser();
        $token = $user->createToken('uji')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/dokumen/list')
            ->assertOk();

        $this->otpService()->issue($user, '123456');

        $this->postJson('/api/v1/lupa-password/reset', [
            'email'           => 'ulupa@polban.ac.id',
            'otp'             => '123456',
            'password_baru'   => 'KataSandiBaru123',
            'password_ulangi' => 'KataSandiBaru123',
        ])->assertOk();

        // Token lama tidak berlaku lagi.
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Pada tes, instance guard dipakai ulang antar-request sehingga user
        // yang sudah ter-autentikasi masih ter-cache; di produksi setiap
        // request memiliki guard sendiri. Guard disegarkan agar pengujian
        // benar-benar memverifikasi pencabutan token.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/dokumen/list')
            ->assertStatus(401);
    }
}
