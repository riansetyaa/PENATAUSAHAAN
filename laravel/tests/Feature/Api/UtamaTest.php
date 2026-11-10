<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AkunPenerimaan;
use App\Models\Kegiatan;
use App\Models\SkpDaerah;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tes modul utama: dashboard, profil, SKP daerah.
 */
class UtamaTest extends TestCase
{
    use RefreshDatabase;

    private function buatUser(string $instansi, string $username = 'user'): User
    {
        return User::create([
            'nama_lengkap' => 'User ' . $instansi,
            'username'     => $username,
            'email'        => $username . '@polban.ac.id',
            'password'     => 'RahasiaKuat123',
            'instansi'     => $instansi,
            'peran'        => 'Bendahara',
            'status'       => User::STATUS_AKTIF,
        ]);
    }

    // -----------------------------------------------------------------
    // Dashboard
    // -----------------------------------------------------------------

    public function test_dashboard_summary(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        Kegiatan::create([
            'skpd' => 'Dinas A', 'nama_kegiatan' => 'Pembangunan Jalan',
            'tahun' => (int) date('Y'), 'pagu' => 1000000, 'realisasi' => 250000,
            'status' => 'berjalan',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.username', 'ua')
            ->assertJsonPath('summary.jumlah_kegiatan', 1);

        $response->assertJsonPath('summary.total_pagu', 1000000);
        $response->assertJsonPath('summary.total_realisasi', 250000);
        $response->assertJsonPath('summary.persen', 25);
    }

    public function test_dashboard_hanya_menampilkan_kegiatan_instansi_sendiri(): void
    {
        $userA = $this->buatUser('Dinas A', 'ua');
        $this->buatUser('Dinas B', 'ub');

        Kegiatan::create([
            'skpd' => 'Dinas A', 'nama_kegiatan' => 'A', 'tahun' => (int) date('Y'),
            'pagu' => 100, 'realisasi' => 50, 'status' => 'berjalan',
        ]);
        Kegiatan::create([
            'skpd' => 'Dinas B', 'nama_kegiatan' => 'B', 'tahun' => (int) date('Y'),
            'pagu' => 999999, 'realisasi' => 0, 'status' => 'berjalan',
        ]);

        $this->actingAs($userA)
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('summary.total_pagu', 100)
            ->assertJsonCount(1, 'kegiatan');
    }

    // -----------------------------------------------------------------
    // Profil
    // -----------------------------------------------------------------

    public function test_profil_menampilkan_data_user(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($user)
            ->getJson('/api/v1/profil')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('profil.username', 'ua')
            ->assertJsonPath('profil.email', 'ua@polban.ac.id')
            ->assertJsonPath('profil.instansi', 'Dinas A');
    }

    public function test_profil_simpan_memperbarui_data(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($user)
            ->postJson('/api/v1/profil', [
                'action'       => 'simpan',
                'nama_lengkap' => 'Nama Baru',
                'email'        => 'baru@polban.ac.id',
                'instansi'     => 'Dinas Baru',
                'nip'          => '19800101 199001 001',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Profil berhasil diperbarui.');

        $this->assertDatabaseHas('users', [
            'id'           => $user->id,
            'nama_lengkap' => 'Nama Baru',
            'email'        => 'baru@polban.ac.id',
            'instansi'     => 'Dinas Baru',
            'nip'          => '19800101 199001 001',
        ]);
    }

    public function test_profil_ganti_password(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($user)
            ->postJson('/api/v1/profil/password', [
                'action'              => 'ganti_password',
                'password_lama'       => 'RahasiaKuat123',
                'password_baru'       => 'PasswordBaruKuat123',
                'password_konfirmasi' => 'PasswordBaruKuat123',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Password berhasil diubah.');

        // Password baru harus bisa dipakai, lama tidak.
        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check('PasswordBaruKuat123', (string) $user->fresh()->password)
        );
    }

    public function test_profil_ganti_password_menolak_password_lama_salah(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($user)
            ->postJson('/api/v1/profil/password', [
                'action'              => 'ganti_password',
                'password_lama'       => 'Salah',
                'password_baru'       => 'PasswordBaruKuat123',
                'password_konfirmasi' => 'PasswordBaruKuat123',
            ])->assertStatus(401)
            ->assertJsonPath('message', 'Password lama salah.');
    }

    // -----------------------------------------------------------------
    // SKP Daerah
    // -----------------------------------------------------------------

    public function test_skp_daftar_dan_penghitung_status(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        SkpDaerah::create([
            'user_id' => $user->id, 'skpd' => 'Dinas A',
            'nomor_skp' => 'SKP-001', 'tanggal' => '2026-03-15',
            'akun_kode' => '4.1.1.01', 'jenis_pajak' => 'Pajak Hotel',
            'nama_penyetor' => 'Wajib Pajak', 'nilai_keputusan' => 100000,
            'status' => 'aktif',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/penerimaan/skp')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('counts.aktif', 1)
            ->assertJsonCount(1, 'data');

        $response->assertJsonPath('data.0.nomor_skp', 'SKP-001');
    }

    public function test_skp_create_wajib_terikat_akun_penerimaan_aktif(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        // Belum ada akun penerimaan -> ditolak.
        $this->actingAs($user)
            ->postJson('/api/v1/penerimaan/skp', [
                'action' => 'create', 'tanggal' => '2026-03-15',
                'nama_penyetor' => 'Uji', 'nilai_keputusan' => 1000,
                'jenis_pajak' => 'Pajak Hotel',
            ])->assertStatus(422)
            ->assertJsonPath('field', 'jenis_pajak');

        // Daftarkan akun penerimaan aktif.
        AkunPenerimaan::create([
            'user_id' => $user->id, 'skpd' => 'Dinas A',
            'kode_akun' => '4.1.1.01', 'nama_akun' => 'Pajak Hotel',
            'metode_input' => 'harian', 'status_aktif' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/penerimaan/skp', [
                'action' => 'create', 'tanggal' => '2026-03-15',
                'nama_penyetor' => 'Wajib Pajak', 'nilai_keputusan' => 100000,
                'jenis_pajak' => 'Pajak Hotel',
            ])->assertStatus(201)
            ->assertJsonPath('success', true);

        // Kode & nama akun ditentukan server, bukan input klien.
        $this->assertDatabaseHas('skp_daerah', [
            'skpd'          => 'Dinas A',
            'akun_kode'     => '4.1.1.01',
            'jenis_pajak'   => 'Pajak Hotel',
            'status'        => 'aktif',
        ]);
    }

    public function test_skp_delete_menandai_dihapus(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $skp = SkpDaerah::create([
            'user_id' => $user->id, 'skpd' => 'Dinas A',
            'nomor_skp' => 'SKP-001', 'tanggal' => '2026-03-15',
            'akun_kode' => '4.1.1.01', 'jenis_pajak' => 'Pajak Hotel',
            'nama_penyetor' => 'Wajib Pajak', 'nilai_keputusan' => 100000,
            'status' => 'aktif',
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/penerimaan/skp', ['action' => 'delete', 'id' => $skp->id])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'SKP Daerah berhasil dihapus.');

        $this->assertSame('dihapus', (string) $skp->fresh()->status);
    }

    public function test_skp_delete_tidak_boleh_menapus_instansi_lain(): void
    {
        $userA = $this->buatUser('Dinas A', 'ua');
        $userB = $this->buatUser('Dinas B', 'ub');

        $skp = SkpDaerah::create([
            'user_id' => $userB->id, 'skpd' => 'Dinas B',
            'nomor_skp' => 'SKP-B', 'tanggal' => '2026-03-15',
            'akun_kode' => '4.1.1.01', 'jenis_pajak' => 'Pajak Hotel',
            'nama_penyetor' => 'Wajib Pajak', 'nilai_keputusan' => 100000,
            'status' => 'aktif',
        ]);

        $this->actingAs($userA)
            ->postJson('/api/v1/penerimaan/skp', ['action' => 'delete', 'id' => $skp->id])
            ->assertStatus(404);

        $this->assertSame('aktif', (string) $skp->fresh()->status);
    }
}
