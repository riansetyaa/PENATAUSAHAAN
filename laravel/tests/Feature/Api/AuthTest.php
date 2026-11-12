<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tes alur autentikasi (login, register, aktivasi OTP).
 *
 * Menggantikan api/login.php, api/register.php, dan api/aktivasi.php pada
 * sistem legacy. Pesan dan status code diuji agar tetap kompatibel dengan
 * frontend vanilla yang sudah ada.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function buatUser(array $atribut = []): User
    {
        return User::create(array_merge([
            'nama_lengkap' => 'Budi Santoso',
            'username'     => 'budi',
            'email'        => 'budi@polban.ac.id',
            'password'     => 'RahasiaKuat123',
            'instansi'     => 'Dinas Testing',
            'kota'         => 'Bandung',
            'provinsi'     => 'Jawa Barat',
            'peran'        => 'Bendahara',
            'status'       => User::STATUS_AKTIF,
        ], $atribut));
    }

    // -----------------------------------------------------------------
    // Login
    // -----------------------------------------------------------------

    public function test_login_dengan_username_berhasil(): void
    {
        $user = $this->buatUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'budi',
            'password' => 'RahasiaKuat123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.username', 'budi')
            ->assertJsonPath('user.instansi', 'Dinas Testing')
            ->assertJsonStructure(['success', 'message', 'redirect', 'token', 'user']);
    }

    public function test_login_dengan_email_berhasil(): void
    {
        $this->buatUser();

        $this->postJson('/api/v1/auth/login', [
            'username' => 'budi@polban.ac.id',
            'password' => 'RahasiaKuat123',
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_login_password_salah_ditolak(): void
    {
        $this->buatUser();

        $this->postJson('/api/v1/auth/login', [
            'username' => 'budi',
            'password' => 'salahsekali',
        ])->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Username/Email atau password tidak valid.');
    }

    public function test_login_akun_pending_ditolak_dengan_petunjuk_aktivasi(): void
    {
        $this->buatUser(['status' => User::STATUS_PENDING]);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'budi',
            'password' => 'RahasiaKuat123',
        ])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('need_activation', true)
            ->assertJsonPath('status', User::STATUS_PENDING);
    }

    public function test_login_akun_nonaktif_ditolak(): void
    {
        $this->buatUser(['status' => User::STATUS_NONAKTIF]);

        $this->postJson('/api/v1/auth/login', [
            'username' => 'budi',
            'password' => 'RahasiaKuat123',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Akun Anda dinonaktifkan. Hubungi administrator.');
    }

    public function test_token_dicabut_setelah_logout(): void
    {
        $user = $this->buatUser();

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/session')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        // Token benar-benar dihapus dari database.
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // PENTUNG: RequestGuard milik Sanctum menyimpan user di dalam
        // instance guard, dan instance itu bertahan antar-request selama
        // satu instance aplikasi (mengejar: test & Octane). Karena itu
        // guard perlu di-reset sebelum memverifikasi penolakan token.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/session')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Tidak terautentikasi.');
    }

    // -----------------------------------------------------------------
    // Register
    // -----------------------------------------------------------------

    public function test_register_membuat_akun_pending(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'nama_lengkap' => 'Siti Aminah',
            'username'     => 'siti',
            'email'        => 'siti@polban.ac.id',
            'password'     => 'RahasiaKuat123',
            'instansi'     => 'Dinas Testing',
            'peran'        => 'Operator',
            'kota'         => 'Bandung',
            'provinsi'     => 'Jawa Barat',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', User::STATUS_PENDING);

        $this->assertDatabaseHas('users', [
            'username' => 'siti',
            'status'   => User::STATUS_PENDING,
        ]);
    }

    public function test_register_menolak_email_non_polban(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'nama_lengkap' => 'Siti Aminah',
            'username'     => 'siti',
            'email'        => 'siti@gmail.com',
            'password'     => 'RahasiaKuat123',
            'instansi'     => 'Dinas Testing',
        ])->assertStatus(422)
            ->assertJsonPath('field', 'email')
            ->assertJsonPath('message', 'Pendaftaran hanya menggunakan email domain @polban.ac.id.');
    }

    public function test_register_menolak_username_duplikat(): void
    {
        $this->buatUser();

        $this->postJson('/api/v1/auth/register', [
            'nama_lengkap' => 'Siti Aminah',
            'username'     => 'budi',
            'email'        => 'siti@polban.ac.id',
            'password'     => 'RahasiaKuat123',
            'instansi'     => 'Dinas Testing',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Username sudah digunakan.');
    }

    public function test_register_menolak_password_pendek(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'nama_lengkap' => 'Siti Aminah',
            'username'     => 'siti',
            'email'        => 'siti@polban.ac.id',
            'password'     => 'pendek',
            'instansi'     => 'Dinas Testing',
        ])->assertStatus(422)->assertJsonPath('field', 'password');
    }

    // -----------------------------------------------------------------
    // Aktivasi OTP
    // -----------------------------------------------------------------

    public function test_alur_aktivasi_otp_lengkap(): void
    {
        $this->buatUser(['status' => User::STATUS_PENDING]);

        // Tahap 1: cek akun
        $cek = $this->postJson('/api/v1/aktivasi/cek', ['email' => 'budi@polban.ac.id']);
        $cek->assertOk()
            ->assertJsonPath('status', User::STATUS_PENDING)
            ->assertJsonPath('user.nama_lengkap', 'Budi Santoso')
            ->assertJsonPath('user.instansi', 'Dinas Testing');

        // Email harus disamarkan, tidak boleh bocor penuh.
        $this->assertStringNotContainsString('budi@polban.ac.id', $cek->json('user.email_samar'));

        // Tahap 2: kirim OTP
        // Pada environment uji MAIL_MAILER=array sehingga pengiriman
        // "berhasil" dan responsnya 200 (bukan 500 seperti saat SMTP gagal).
        $kirim = $this->postJson('/api/v1/aktivasi/kirim-otp', ['email' => 'budi@polban.ac.id']);
        $kirim->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('panjang_otp', 6);

        $otp = $kirim->json('dev_otp');
        $this->assertNotNull($otp, 'Kode OTP harus dikembalikan pada mode debug');
        $this->assertSame(6, strlen($otp));

        // Tahap 3: kode salah -> 401 dengan sisa percobaan
        $this->postJson('/api/v1/aktivasi/verifikasi', [
            'email' => 'budi@polban.ac.id',
            'otp'   => '000000',
        ])->assertStatus(401)
            ->assertJsonPath('field', 'otp')
            ->assertJsonPath('sisa_percobaan', 4);

        // Tahap 3: kode benar -> akun aktif
        $this->postJson('/api/v1/aktivasi/verifikasi', [
            'email' => 'budi@polban.ac.id',
            'otp'   => $otp,
        ])->assertOk()
            ->assertJsonPath('status', User::STATUS_AKTIF);

        $this->assertDatabaseHas('users', [
            'username' => 'budi',
            'status'   => User::STATUS_AKTIF,
        ]);

        // Kolom OTP harus dibersihkan setelah verifikasi.
        $user = User::where('username', 'budi')->first();
        $this->assertNull($user->otp_hash);
        $this->assertNull($user->otp_expired_at);
        $this->assertNotNull($user->otp_verifikasi_at);

        // Tahap 4: sekarang bisa login
        $this->postJson('/api/v1/auth/login', [
            'username' => 'budi',
            'password' => 'RahasiaKuat123',
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_otp_disimpan_sebagai_hash_bukan_mentah(): void
    {
        $user = $this->buatUser(['status' => User::STATUS_PENDING]);

        $this->postJson('/api/v1/aktivasi/kirim-otp', ['email' => 'budi@polban.ac.id']);

        $user->refresh();

        $this->assertNotNull($user->otp_hash);
        // Hash bcrypt, bukan 6 digit mentah.
        $this->assertNotSame(6, strlen((string) $user->otp_hash));
        $this->assertStringStartsWith('$2y$', (string) $user->otp_hash);
    }

    public function test_otp_kedaluwarsa_ditolak(): void
    {
        $user = $this->buatUser(['status' => User::STATUS_PENDING]);

        $this->postJson('/api/v1/aktivasi/kirim-otp', ['email' => 'budi@polban.ac.id']);

        // Paksa kedaluwarsa
        $user->refresh()->forceFill(['otp_expired_at' => now()->subMinute()])->save();

        $this->postJson('/api/v1/aktivasi/verifikasi', [
            'email' => 'budi@polban.ac.id',
            'otp'   => '123456',
        ])->assertStatus(422)
            ->assertJsonPath('kedaluwarsa', true);
    }

    public function test_aktivasi_email_tidak_terdaftar(): void
    {
        $this->postJson('/api/v1/aktivasi/cek', ['email' => 'entah@polban.ac.id'])
            ->assertStatus(404)
            ->assertJsonPath('field', 'email');
    }

    // -----------------------------------------------------------------
    // Sesi
    // -----------------------------------------------------------------

    public function test_endpoint_terlindungi_menolak_tanpa_token(): void
    {
        $this->getJson('/api/v1/auth/session')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Tidak terautentikasi.');
    }

    // -----------------------------------------------------------------
    // Kompatibilitas bentuk respons (ditemukan oleh differential test)
    // -----------------------------------------------------------------

    /**
     * Validasi Laravel secara bawaan menjawab {message, errors} TANPA kunci
     * `success`. Frontend legacy memeriksa `d.success`, sehingga bentuk
     * bawaan itu dianggap "gagal" walau request-nya memang tidak valid.
     * Guard ini mencegah regresi.
     */
    public function test_respons_validasi_selalu_memiliki_kunci_success(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Username/Email dan password wajib diisi.');
    }

    /**
     * Bentuk respons session harus identik dengan api/session.php legacy:
     * pesan "Autentikasi valid." dan user memuat id serta email.
     */
    public function test_bentuk_respons_session_sama_dengan_legacy(): void
    {
        $user = $this->buatUser();

        $this->actingAs($user)
            ->getJson('/api/v1/auth/session')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Autentikasi valid.')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.username', 'budi')
            ->assertJsonPath('user.email', 'budi@polban.ac.id')
            ->assertJsonPath('user.instansi', 'Dinas Testing')
            ->assertJsonPath('user.peran', 'Bendahara');

        // `api_token` milik legacy tidak lagi dikembalikan (token API kini
        // dikelola Sanctum di tabel personal_access_tokens).
        $this->assertArrayNotHasKey('api_token', $this->actingAs($user)->getJson('/api/v1/auth/session')->json('user'));
    }

    /**
     * Rate limit login harus memakai kunci 'username+IP' seperti legacy,
     * bukan throttle bawaan Laravel (kunci IP, bentuk JSON berbeda).
     */
    public function test_rate_limit_login_mengikuti_pola_legacy(): void
    {
        $this->buatUser();

        // 8 percobaan diizinkan, ke-9 ditolak (identik dengan legacy).
        for ($i = 1; $i <= 8; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => 'budi',
                'password' => 'salah',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'username' => 'budi',
            'password' => 'salah',
        ])->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Terlalu banyak percobaan login. Coba lagi dalam beberapa menit.');
    }
}
