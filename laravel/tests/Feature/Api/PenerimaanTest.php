<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AkunPenerimaan;
use App\Models\AnggaranLra;
use App\Models\Permohonan;
use App\Models\Stbp;
use App\Models\Sts;
use App\Models\StsDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tes modul Penerimaan (permohonan, akun penerimaan, laporan).
 *
 * Fokus pengujian:
 *  - Bentuk respons tetap kompatibel dengan frontend legacy.
 *  - Isolasi multi-tenant: data instansi lain TIDAK boleh terlihat/diubah.
 */
class PenerimaanTest extends TestCase
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
    // Permohonan
    // -----------------------------------------------------------------

    public function test_daftar_permohonan_hanya_menampilkan_instansi_sendiri(): void
    {
        $userA = $this->buatUser('Dinas A', 'ua');
        $userB = $this->buatUser('Dinas B', 'ub');

        Permohonan::create([
            'user_id' => $userA->id, 'skpd' => 'Dinas A',
            'bank' => 'BANK A', 'nama_rekening' => 'Pemilik A', 'nomor_rekening' => '111',
        ]);
        Permohonan::create([
            'user_id' => $userB->id, 'skpd' => 'Dinas B',
            'bank' => 'BANK B', 'nama_rekening' => 'Pemilik B', 'nomor_rekening' => '222',
        ]);

        $this->actingAs($userA)
            ->getJson('/api/v1/penerimaan/permohonan')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.bank', 'BANK A');

        $this->actingAs($userB)
            ->getJson('/api/v1/penerimaan/permohonan')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.bank', 'BANK B');
    }

    public function test_permohonan_memakai_instansi_dari_sesi_bukan_input_klien(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($user)
            ->postJson('/api/v1/penerimaan/permohonan', [
                'bank'           => 'BANK UJI',
                'nama_rekening'  => 'Pemilik Uji',
                'nomor_rekening' => '1234567890',
                // Klien mencoba memalsukan SKPD -> harus diabaikan.
                'skpd'           => 'Instansi Asing',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('permohonan', [
            'skpd' => 'Dinas A',
            'bank' => 'BANK UJI',
        ]);

        $this->assertDatabaseMissing('permohonan', ['skpd' => 'Instansi Asing']);
    }

    public function test_validasi_permohonan(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($user)
            ->postJson('/api/v1/penerimaan/permohonan', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('field', 'bank')
            ->assertJsonPath('message', 'Silakan pilih bank.');

        $this->actingAs($user)
            ->postJson('/api/v1/penerimaan/permohonan', ['bank' => 'B'])
            ->assertStatus(422)
            ->assertJsonPath('field', 'nama_rekening');

        $this->actingAs($user)
            ->postJson('/api/v1/penerimaan/permohonan', ['bank' => 'B', 'nama_rekening' => 'N'])
            ->assertStatus(422)
            ->assertJsonPath('field', 'nomor_rekening');
    }

    public function test_set_aktif_tidak_bisa_menukar_instansi_lain(): void
    {
        $userA = $this->buatUser('Dinas A', 'ua');
        $userB = $this->buatUser('Dinas B', 'ub');

        $p = Permohonan::create([
            'user_id' => $userB->id, 'skpd' => 'Dinas B',
            'bank' => 'BANK B', 'nama_rekening' => 'Pemilik B', 'nomor_rekening' => '222',
        ]);

        // User A mencoba menyalakan rekening milik Dinas B -> harus 404.
        $this->actingAs($userA)
            ->postJson("/api/v1/penerimaan/permohonan/{$p->id}/set-aktif", ['status_aktif' => true])
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Data tidak ditemukan atau bukan milik instansi Anda.');

        $this->assertFalse((bool) $p->fresh()->status_aktif);
    }

    public function test_verifikasi_menyalakan_semua_status(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $p = Permohonan::create([
            'user_id' => $user->id, 'skpd' => 'Dinas A',
            'bank' => 'BANK A', 'nama_rekening' => 'Pemilik A', 'nomor_rekening' => '111',
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/penerimaan/permohonan/{$p->id}/verifikasi")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Rekening berhasil diverifikasi dan aktif.');

        $p->refresh();
        $this->assertTrue((bool) $p->status_terbit);
        $this->assertTrue((bool) $p->status_disetujui);
        $this->assertTrue((bool) $p->status_aktif);
    }

    public function test_endpoint_penerimaan_menolak_tanpa_login(): void
    {
        $this->getJson('/api/v1/penerimaan/permohonan')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Tidak terautentikasi.');
    }

    public function test_endpoint_penerimaan_menolak_user_tanpa_instansi(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');
        $user->forceFill(['instansi' => null])->save();

        $this->actingAs($user->fresh())
            ->getJson('/api/v1/penerimaan/permohonan')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Instansi belum diatur pada akun Anda. Hubungi administrator.');
    }

    // -----------------------------------------------------------------
    // Akun penerimaan
    // -----------------------------------------------------------------

    public function test_daftar_akun_penerimaan(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        AkunPenerimaan::create([
            'user_id' => $user->id, 'skpd' => 'Dinas A',
            'kode_akun' => '4.1.1.01.01', 'nama_akun' => 'Pajak Hotel', 'metode_input' => 'harian',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/penerimaan/akun')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.kode_akun', '4.1.1.01.01')
            ->assertJsonPath('data.0.nama_akun', 'Pajak Hotel')
            ->assertJsonCount(1, 'data');

        // Daftar metode input harus sama dengan legacy.
        $this->actingAs($user)
            ->getJson('/api/v1/penerimaan/akun')
            ->assertJsonPath('metode_list', [
                'harian', 'mingguan', 'bulanan', 'per_wajib_pajak', 'per_wajib_retribusi',
            ]);
    }

    public function test_create_akun_penerimaan(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($user)
            ->postJson('/api/v1/penerimaan/akun', [
                'action'       => 'create',
                'kode_akun'    => '4.1.2.01',
                'nama_akun'    => 'Retribusi Pasar',
                'metode_input' => 'bulanan',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Akun penerimaan berhasil disimpan.');

        $this->assertDatabaseHas('akun_penerimaan', [
            'skpd'         => 'Dinas A',
            'kode_akun'    => '4.1.2.01',
            'metode_input' => 'bulanan',
            'status_aktif' => 1,
        ]);
    }

    public function test_metode_input_tidak_valid_dinormalkan_ke_harian(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($user)
            ->postJson('/api/v1/penerimaan/akun', [
                'action'       => 'create',
                'kode_akun'    => '4.1.2.02',
                'nama_akun'    => 'Retribusi Pasar',
                'metode_input' => 'ngawur',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('akun_penerimaan', [
            'kode_akun'    => '4.1.2.02',
            'metode_input' => 'harian',
        ]);
    }

    public function test_aksi_tidak_dikenal_ditolak(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($user)
            ->postJson('/api/v1/penerimaan/akun', ['action' => 'ngawur'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Aksi tidak dikenali.');
    }

    public function test_save_selection_hanya_menghapus_pilihan_instansi_sendiri(): void
    {
        $userA = $this->buatUser('Dinas A', 'ua');
        $userB = $this->buatUser('Dinas B', 'ub');

        AkunPenerimaan::create([
            'user_id' => $userA->id, 'skpd' => 'Dinas A',
            'kode_akun' => '4.1.1.01', 'nama_akun' => 'A', 'metode_input' => 'harian',
        ]);
        $milikB = AkunPenerimaan::create([
            'user_id' => $userB->id, 'skpd' => 'Dinas B',
            'kode_akun' => '4.1.1.02', 'nama_akun' => 'B', 'metode_input' => 'harian',
        ]);

        $this->actingAs($userA)
            ->postJson('/api/v1/penerimaan/akun', [
                'action' => 'save_selection',
                'items'  => [
                    ['kode_akun' => '4.1.1.09', 'nama_akun' => 'Pajak Baru', 'metode_input' => 'harian'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        // Pilihan Dinas A tergantikan.
        $this->assertDatabaseMissing('akun_penerimaan', ['skpd' => 'Dinas A', 'kode_akun' => '4.1.1.01']);
        $this->assertDatabaseHas('akun_penerimaan', ['skpd' => 'Dinas A', 'kode_akun' => '4.1.1.09']);

        // Pilihan Dinas B TIDAK boleh tersentuh.
        $this->assertDatabaseHas('akun_penerimaan', ['id' => $milikB->id]);
    }

    // -----------------------------------------------------------------
    // Laporan
    // -----------------------------------------------------------------

    public function test_laporan_obyek_penerimaan(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        AnggaranLra::create([
            'skpd' => 'Dinas A', 'tahun' => '2026', 'kode_akun' => '4.1.1.01.01',
            'nama_akun' => 'Pajak Hotel', 'anggaran' => 1000000,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/penerimaan/laporan/obyek?tahun=2026')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('tahun', 2026)
            ->assertJsonPath('skpd', 'Dinas A');
    }

    public function test_laporan_rekap_menghitung_total(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        Stbp::create([
            'user_id' => $user->id, 'skpd' => 'Dinas A',
            'nomor_stbp' => 'BPP-001', 'tanggal' => '2026-03-15',
            'akun_kode' => '4.1.1.01.01', 'akun_nama' => 'Pajak Hotel',
            'jumlah' => 500000, 'uraian' => 'Setoran Maret', 'status' => 'belum_diverifikasi',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/penerimaan/laporan/rekap?mode=harian&dari=2026-01-01&sampai=2026-12-31')
            ->assertOk()
            ->assertJsonPath('success', true);

        $response->assertJsonPath('total', 500000);

        // Catatan: PHP json_encode() mengubah float bulat (500000.0)
        // menjadi "500000" tanpa desimal, sehingga json_decode
        // mengembalikannya sebagai int. Perilaku ini IDENTIK dengan API
        // legacy (differential test mengonfirmasi), sehingga assertion
        // memakai perbandingan longgar.
        $this->assertEquals(500000.0, $response->json('total_kelompok.0'));
        $this->assertEquals(0.0, $response->json('total_kelompok.1'));
        $this->assertEquals(0.0, $response->json('total_kelompok.2'));
    }

    public function test_laporan_rekap_menuntut_periode(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($user)
            ->getJson('/api/v1/penerimaan/laporan/rekap')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Periode (tanggal awal dan akhir) wajib diisi.');
    }

    public function test_laporan_buku_pembantu(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $sts = Sts::create([
            'user_id' => $user->id, 'skpd' => 'Dinas A',
            'nomor_sts' => 'STS-001', 'nama_penyetor' => 'Wajib Pajak',
            'tanggal_sts' => '2026-03-20', 'total' => 300000,
            'status' => 'aktif',
        ]);

        StsDetail::create([
            'sts_id' => $sts->id, 'akun_kode' => '4.1.1.01.01',
            'akun_nama' => 'Pajak Hotel', 'jumlah' => 300000,
            'nomor_stbp' => 'BPP-001',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/penerimaan/laporan/buku-pembantu?kode_akun=4.1.1.01.01&bulan=3&tahun=2026')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('kode_akun', '4.1.1.01.01')
            ->assertJsonPath('bulan', 3)
            ->assertJsonPath('tahun', 2026);

        $response->assertJsonCount(1, 'rows');
        $response->assertJsonPath('rows.0.no_bpp', 'BPP-001');
        $response->assertJsonPath('rows.0.jumlah', 300000);
        $response->assertJsonPath('jumlah_bulan_ini', 300000);
        $response->assertJsonPath('jumlah_sd_bulan_ini', 300000);
    }

    public function test_laporan_bku_menjumlahkan_stbp_dan_sts(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        Stbp::create([
            'user_id' => $user->id, 'skpd' => 'Dinas A',
            'nomor_stbp' => 'BPP-001', 'tanggal' => '2026-03-15',
            'akun_kode' => '4.1.1.01.01', 'akun_nama' => 'Pajak Hotel',
            'jumlah' => 500000, 'uraian' => 'Setoran', 'status' => 'belum_diverifikasi',
        ]);

        $sts = Sts::create([
            'user_id' => $user->id, 'skpd' => 'Dinas A',
            'nomor_sts' => 'STS-001', 'nama_penyetor' => 'Wajib Pajak',
            'tanggal_sts' => '2026-03-20', 'total' => 200000,
            'keterangan' => 'Setor ke kas daerah', 'status' => 'aktif',
        ]);

        StsDetail::create([
            'sts_id' => $sts->id, 'akun_kode' => '4.1.1.01.01',
            'akun_nama' => 'Pajak Hotel', 'jumlah' => 200000, 'nomor_stbp' => 'BPP-001',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/penerimaan/laporan/bku?dari=2026-01-01&sampai=2026-12-31')
            ->assertOk()
            ->assertJsonPath('success', true);

        $response->assertJsonPath('total_penerimaan', 500000);
        $response->assertJsonPath('total_pengeluaran', 200000);
        $response->assertJsonPath('saldo_akhir', 300000);
        $response->assertJsonCount(2, 'entries');
    }
}
