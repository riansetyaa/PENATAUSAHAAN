<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\CutoverTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tes migrasi bertahap (cutover) TANPA DOWNTIME.
 *
 * Skenario inti: selama migrasi, endpoint legacy diproxy ke Laravel oleh
 * includes/cutover.php sambil membawa TIKET SSO dari sesi legacy. Tiket
 * itulah yang membuat pengguna tidak ter-logout saat sebagian request
 * dilayani sistem baru.
 */
class CutoverTest extends TestCase
{
    use RefreshDatabase;

    private function buatUser(string $instansi = 'Dinas A', string $status = User::STATUS_AKTIF): User
    {
        return User::create([
            'nama_lengkap' => 'User Cutover',
            'username'     => 'ucut',
            'email'        => 'ucut@polban.ac.id',
            'password'     => 'RahasiaKuat123',
            'instansi'     => $instansi,
            'peran'        => 'Bendahara',
            'status'       => $status,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function headerTiket(User $user): array
    {
        return [
            CutoverTicket::HEADER => CutoverTicket::mint([
                'uid'  => (int) $user->id,
                'skpd' => (string) $user->instansi,
            ]),
            CutoverTicket::HEADER_REQUIRED => '1',
        ];
    }

    private function kontenDokumen(): string
    {
        return '<!DOCTYPE html><html><body><div id="docArea">'
            . str_repeat('<p>Isi dokumen uji cutover yang panjang.</p>', 6)
            . '</div></body></html>';
    }

    // -----------------------------------------------------------------
    // Health check (syarat sebelum cutover)
    // -----------------------------------------------------------------

    public function test_health_mengembalikan_ok(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'ok');
    }

    public function test_health_melaporkan_status_cutover(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('cutover.aktif', true);
    }

    // -----------------------------------------------------------------
    // Tiket SSO
    // -----------------------------------------------------------------

    public function test_tiket_sah_mengautentikasi_request_tanpa_login(): void
    {
        $user = $this->buatUser();

        // Tanpa tiket: endpoint terproteksi menolak (401) — perilaku normal.
        $this->getJson('/api/v1/dokumen/list')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Tidak terautentikasi.');

        // Dengan tiket dari proksi legacy: request dianggap milik user tsb.
        $this->getJson('/api/v1/dokumen/list', $this->headerTiket($user))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_tiket_sah_dihormati_pada_endpoint_tulis(): void
    {
        $user = $this->buatUser();

        $this->postJson('/api/v1/dokumen/kirim', [
            'jenis'       => 'SPP',
            'ref_id'      => 12,
            'konten_html' => $this->kontenDokumen(),
        ], $this->headerTiket($user))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('dokumen', ['user_id' => $user->id]);
    }

    public function test_tiket_kedaluwarsa_ditolak(): void
    {
        $user = $this->buatUser();

        $tiketKedaluwarsa = CutoverTicket::mint(
            ['uid' => (int) $user->id, 'skpd' => (string) $user->instansi],
            time() - 3600
        );

        $this->getJson('/api/v1/dokumen/list', [
            CutoverTicket::HEADER          => $tiketKedaluwarsa,
            CutoverTicket::HEADER_REQUIRED => '1',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Tiket cutover tidak valid atau kedaluwarsa.');
    }

    public function test_tiket_tanda_tangan_diubah_ditolak(): void
    {
        $user  = $this->buatUser();
        $tiket = CutoverTicket::mint(['uid' => (int) $user->id, 'skpd' => (string) $user->instansi]);

        // Ubah satu karakter pada bagian tanda tangan.
        $palsu = substr($tiket, 0, -1) . (str_ends_with($tiket, 'A') ? 'B' : 'A');

        $this->getJson('/api/v1/dokumen/list', [
            CutoverTicket::HEADER          => $palsu,
            CutoverTicket::HEADER_REQUIRED => '1',
        ])->assertStatus(401);
    }

    public function test_penanda_cutover_tanpa_tiket_ditolak(): void
    {
        $this->getJson('/api/v1/dokumen/list', [CutoverTicket::HEADER_REQUIRED => '1'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Tiket cutover tidak disertakan.');
    }

    public function test_tiket_untuk_akun_nonaktif_ditolak(): void
    {
        $user = $this->buatUser('Dinas A', User::STATUS_NONAKTIF);

        $this->getJson('/api/v1/dokumen/list', $this->headerTiket($user))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Akun pada tiket cutover tidak aktif.');
    }

    public function test_tiket_dengan_instansi_berbeda_ditolak(): void
    {
        $user = $this->buatUser('Dinas A');

        $this->getJson('/api/v1/dokumen/list', [
            CutoverTicket::HEADER          => CutoverTicket::mint(['uid' => (int) $user->id, 'skpd' => 'Dinas Lain']),
            CutoverTicket::HEADER_REQUIRED => '1',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Instansi pada tiket cutover tidak cocok.');
    }

    // -----------------------------------------------------------------
    // Verifikasi tiket (unit-like)
    // -----------------------------------------------------------------

    public function test_verify_menerima_tiket_yang_sah(): void
    {
        $claims = CutoverTicket::verify(CutoverTicket::mint(['uid' => 7, 'skpd' => 'Dinas A']));

        $this->assertIsArray($claims);
        $this->assertSame(7, (int) $claims['uid']);
        $this->assertNotEmpty($claims['jti']);
    }

    public function test_verify_menolak_tiket_dari_masa_depan(): void
    {
        $tiket = CutoverTicket::mint(['uid' => 1], time() + 3600);

        $this->assertNull(CutoverTicket::verify($tiket, time()));
    }
}
