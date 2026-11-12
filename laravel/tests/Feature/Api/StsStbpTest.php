<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\SkpDaerah;
use App\Models\Stbp;
use App\Models\Sts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tes modul Penerimaan: STS (Surat Tanda Setoran) & STBP (Surat Tanda Bukti
 * Penerimaan).
 *
 * Fokus:
 *  - Bentuk respons & pesan tetap kompatibel dengan frontend legacy.
 *  - Isolasi multi-tenant (data instansi lain tidak terlihat/terpakai).
 *  - Aturan alur: STBP bertahap (verifikasi -> otorisasi -> validasi) dan
 *    STS hanya boleh dibuat dari STBP tervalidasi yang belum dipakai.
 */
class StsStbpTest extends TestCase
{
    use RefreshDatabase;

    private function buatUser(string $instansi, string $username = 'user'): User
    {
        return User::create([
            'nama_lengkap' => 'Bendahara ' . $instansi,
            'username'     => $username,
            'email'        => $username . '@polban.ac.id',
            'password'     => 'RahasiaKuat123',
            'instansi'     => $instansi,
            'peran'        => 'Bendahara',
            'status'       => User::STATUS_AKTIF,
        ]);
    }

    /** @param array<string, mixed> $ubah */
    private function buatStbp(User $user, string $instansi, array $ubah = []): Stbp
    {
        return Stbp::create(array_merge([
            'user_id'    => $user->id,
            'skpd'       => $instansi,
            'nomor_stbp' => 'STBP-001',
            'tanggal'    => '2026-03-10',
            'akun_kode'  => '4.1.1.01.03',
            'akun_nama'  => 'Pajak Hotel',
            'jumlah'     => 1000000,
            'uraian'     => 'SKP Daerah SKP-001',
            'status'     => 'belum_diverifikasi',
        ], $ubah));
    }

    /** @param array<string, mixed> $ubah */
    private function buatSts(User $user, string $instansi, array $ubah = []): Sts
    {
        return Sts::create(array_merge([
            'user_id'                 => $user->id,
            'skpd'                    => $instansi,
            'nomor_sts'               => 'STS-001',
            'nama_penyetor'           => 'Penyetor A',
            'tanggal_sts'             => '2026-03-11',
            'mengetahui'              => 'Kepala Dinas',
            'kuasa_pengguna_anggaran' => 'KPA A',
            'nama_bank'               => 'Bank Jabar',
            'nomor_rekening'          => '123',
            'nama_rekening'           => 'Kas Daerah',
            'keterangan'              => 'Setoran',
            'total'                   => 1000000,
            'status'                  => 'aktif',
        ], $ubah));
    }

    /** @param array<string, mixed> $ubah */
    private function buatSkp(User $user, string $instansi, array $ubah = []): SkpDaerah
    {
        return SkpDaerah::create(array_merge([
            'user_id'         => $user->id,
            'skpd'            => $instansi,
            'nomor_skp'       => 'SKP-001',
            'tanggal'         => '2026-03-01',
            'akun_kode'       => '4.1.1.01.03',
            'jenis_pajak'     => 'Pajak Hotel',
            'nama_penyetor'   => 'Penyetor A',
            'objek_pajak'     => 'Hotel Melati',
            'nilai_keputusan' => 250000,
            'status'          => 'aktif',
        ], $ubah));
    }

    // -----------------------------------------------------------------
    // STS
    // -----------------------------------------------------------------

    public function test_daftar_sts_terbatas_instansi_sendiri(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        $this->buatSts($a, 'Dinas A');
        $this->buatSts($b, 'Dinas B', ['nomor_sts' => 'STS-B']);

        $this->actingAs($a)
            ->getJson('/api/v1/penerimaan/sts')
            ->assertOk()
            ->assertJsonPath('status', 'aktif')
            ->assertJsonPath('counts.aktif', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nomor_sts', 'STS-001')
            ->assertJsonPath('data.0.dibuat_oleh', 'Bendahara Dinas A');
    }

    private function tambahRincian(int $stsId, int $stbpId, float $jumlah = 1000000): void
    {
        DB::table('sts_detail')->insert([
            'sts_id'     => $stsId,
            'stbp_id'    => $stbpId,
            'nomor_stbp' => 'STBP-001',
            'tanggal'    => '2026-03-10',
            'akun_kode'  => '4.1.1.01.03',
            'akun_nama'  => 'Pajak Hotel',
            'jumlah'     => $jumlah,
            'uraian'     => 'SKP Daerah SKP-001',
        ]);
    }

    public function test_detail_sts_menyertakan_rincian_stbp(): void
    {
        $a  = $this->buatUser('Dinas A', 'ua');
        $sb = $this->buatStbp($a, 'Dinas A', ['status' => 'sudah_divalidasi']);
        $st = $this->buatSts($a, 'Dinas A');

        $this->tambahRincian((int) $st->id, (int) $sb->id);

        $this->actingAs($a)
            ->getJson('/api/v1/penerimaan/sts?id=' . $st->id)
            ->assertOk()
            ->assertJsonPath('sts.nomor_sts', 'STS-001')
            ->assertJsonPath('sts.total', 1000000)
            ->assertJsonCount(1, 'detail')
            ->assertJsonPath('detail.0.nomor_stbp', 'STBP-001')
            ->assertJsonPath('detail.0.jumlah', 1000000);
    }

    public function test_detail_sts_instansi_lain_tidak_terlihat(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        $st = $this->buatSts($b, 'Dinas B');

        $this->actingAs($a)
            ->getJson('/api/v1/penerimaan/sts?id=' . $st->id)
            ->assertStatus(404)
            ->assertJsonPath('message', 'STS tidak ditemukan.');

        $this->actingAs($a)
            ->getJson('/api/v1/penerimaan/sts?id=99999999')
            ->assertStatus(404);
    }

    public function test_lpj_menghitung_penerimaan_penyetoran_dan_saldo(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        // STBP tervalidasi dengan pembayaran tunai.
        $sb = $this->buatStbp($a, 'Dinas A', ['status' => 'sudah_divalidasi']);
        DB::table('stbp_pembayaran')->insert([
            'stbp_id' => $sb->id, 'metode_penyetoran' => 'tunai', 'nama_penyetor' => 'Penyetor A',
            'nama_bank' => '', 'nomor_rekening' => '',
        ]);

        // STBP belum divalidasi -> tidak dihitung pada LPJ.
        $this->buatStbp($a, 'Dinas A', ['nomor_stbp' => 'STBP-002', 'jumlah' => 500000]);

        // Penyetoran: STS aktif.
        $this->buatSts($a, 'Dinas A', ['total' => 400000]);

        $this->actingAs($a)
            ->getJson('/api/v1/penerimaan/sts?lpj=1&dari=2026-01-01&akhir=2026-12-31')
            ->assertOk()
            ->assertJsonPath('penerimaan.total', 1000000)
            ->assertJsonPath('penerimaan.tunai', 1000000)
            ->assertJsonPath('penyetoran', 400000)
            ->assertJsonPath('saldo', 600000)
            ->assertJsonPath('bendahara', 'Bendahara Dinas A')
            ->assertJsonPath('kuasa_pengguna_anggaran', 'KPA A');
    }

    public function test_register_sts_menyusun_baris_dan_total(): void
    {
        $a  = $this->buatUser('Dinas A', 'ua');
        $sb = $this->buatStbp($a, 'Dinas A', ['status' => 'sudah_divalidasi']);
        $st = $this->buatSts($a, 'Dinas A');

        $this->tambahRincian((int) $st->id, (int) $sb->id);

        $this->actingAs($a)
            ->getJson('/api/v1/penerimaan/sts?register=1')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.nomor_sts', 'STS-001')
            ->assertJsonPath('rows.0.akun_nama', 'Pajak Hotel')
            ->assertJsonPath('total', 1000000)
            ->assertJsonPath('bendahara', 'Bendahara Dinas A');
    }

    // -----------------------------------------------------------------
    // STS — pembuatan
    // -----------------------------------------------------------------

    public function test_buat_sts_menghitung_total_dari_stbp_tervalidasi(): void
    {
        $a  = $this->buatUser('Dinas A', 'ua');
        $sb = $this->buatStbp($a, 'Dinas A', ['status' => 'sudah_divalidasi', 'jumlah' => 750000]);

        $this->actingAs($a)
            ->postJson('/api/v1/penerimaan/sts', [
                'action'        => 'create',
                'nomor_sts'     => 'STS-777',
                'tanggal_sts'   => '2026-03-12',
                'nama_penyetor' => 'Penyetor A',
                'stbp_ids'      => [$sb->id],
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('nomor', 'STS-777');

        $this->assertDatabaseHas('sts', ['nomor_sts' => 'STS-777', 'skpd' => 'Dinas A']);
        $this->assertDatabaseHas('sts_detail', ['stbp_id' => $sb->id, 'jumlah' => 750000]);
        $this->assertSame(750000.0, (float) Sts::query()->where('nomor_sts', 'STS-777')->value('total'));
    }

    public function test_buat_sts_menolak_stbp_belum_divalidasi(): void
    {
        $a  = $this->buatUser('Dinas A', 'ua');
        $sb = $this->buatStbp($a, 'Dinas A');

        $this->actingAs($a)
            ->postJson('/api/v1/penerimaan/sts', [
                'action'      => 'create',
                'tanggal_sts' => '2026-03-12',
                'stbp_ids'    => [$sb->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ada STBP yang belum divalidasi (tahap 3) sehingga tidak dapat dimasukkan ke STS.');

        $this->assertDatabaseCount('sts', 0);
    }

    public function test_buat_sts_menolak_stbp_yang_sudah_dipakai(): void
    {
        $a  = $this->buatUser('Dinas A', 'ua');
        $sb = $this->buatStbp($a, 'Dinas A', ['status' => 'sudah_divalidasi']);
        $st = $this->buatSts($a, 'Dinas A');

        $this->tambahRincian((int) $st->id, (int) $sb->id);

        $this->actingAs($a)
            ->postJson('/api/v1/penerimaan/sts', [
                'action'      => 'create',
                'tanggal_sts' => '2026-03-12',
                'stbp_ids'    => [$sb->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ada STBP yang sudah pernah dibuatkan STS. STBP tersebut tidak dapat dipilih lagi.');

        $this->assertDatabaseCount('sts', 1);
    }

    public function test_buat_sts_menolak_tanpa_stbp_dan_tanpa_tanggal(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($a)
            ->postJson('/api/v1/penerimaan/sts', ['action' => 'create', 'tanggal_sts' => '2026-03-12'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pilih minimal satu STBP.');

        $this->actingAs($a)
            ->postJson('/api/v1/penerimaan/sts', ['action' => 'create', 'stbp_ids' => [1]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tanggal STS wajib diisi.');
    }

    public function test_buat_sts_tidak_bisa_memakai_stbp_instansi_lain(): void
    {
        $a  = $this->buatUser('Dinas A', 'ua');
        $b  = $this->buatUser('Dinas B', 'ub');
        $sb = $this->buatStbp($b, 'Dinas B', ['status' => 'sudah_divalidasi']);

        $this->actingAs($a)
            ->postJson('/api/v1/penerimaan/sts', [
                'action'      => 'create',
                'tanggal_sts' => '2026-03-12',
                'stbp_ids'    => [$sb->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ada STBP yang belum divalidasi (tahap 3) sehingga tidak dapat dimasukkan ke STS.');

        $this->assertDatabaseMissing('sts', ['skpd' => 'Dinas A']);
    }

    // -----------------------------------------------------------------
    // STBP
    // -----------------------------------------------------------------

    public function test_daftar_stbp_memuat_penghitung_dan_data_pembayaran(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $sb = $this->buatStbp($a, 'Dinas A');
        DB::table('stbp_pembayaran')->insert([
            'stbp_id' => $sb->id, 'metode_penyetoran' => 'non_tunai', 'nama_penyetor' => 'Penyetor A',
            'nama_bank' => 'Bank Jabar', 'nomor_rekening' => '999',
        ]);

        $this->actingAs($a)
            ->getJson('/api/v1/penerimaan/stbp')
            ->assertOk()
            ->assertJsonPath('status', 'belum_diverifikasi')
            ->assertJsonPath('counts.belum_diverifikasi', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nomor_stbp', 'STBP-001')
            ->assertJsonPath('data.0.metode_penyetoran', 'non_tunai')
            ->assertJsonPath('data.0.nama_penyetor', 'Penyetor A')
            ->assertJsonPath('data.0.bank', 'Bank Jabar')
            ->assertJsonPath('data.0.dibuat_oleh', 'Bendahara Dinas A')
            ->assertJsonPath('data.0.jumlah', 1000000);
    }

    public function test_daftar_stbp_siap_sts_menyaring_yang_belum_dipakai(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        // Belum divalidasi -> bukan kandidat STS.
        $this->buatStbp($a, 'Dinas A', ['nomor_stbp' => 'STBP-001']);

        // Sudah divalidasi & belum dipakai -> kandidat.
        $belumDipakai = $this->buatStbp($a, 'Dinas A', ['nomor_stbp' => 'STBP-002', 'status' => 'sudah_divalidasi']);

        // Sudah divalidasi & SUDAH dipakai STS aktif -> bukan kandidat.
        $sudahDipakai = $this->buatStbp($a, 'Dinas A', ['nomor_stbp' => 'STBP-003', 'status' => 'sudah_divalidasi']);
        $st = $this->buatSts($a, 'Dinas A');
        $this->tambahRincian((int) $st->id, (int) $sudahDipakai->id);

        $this->actingAs($a)
            ->getJson('/api/v1/penerimaan/stbp?status=siap_sts')
            ->assertOk()
            ->assertJsonPath('status', 'siap_sts')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (int) $belumDipakai->id);
    }

    public function test_detail_stbp_menyertakan_pembayaran_pendapatan_dan_data_skp(): void
    {
        $a  = $this->buatUser('Dinas A', 'ua');
        $skp = $this->buatSkp($a, 'Dinas A');
        $sb = $this->buatStbp($a, 'Dinas A', ['skp_daerah_id' => $skp->id]);

        DB::table('stbp_pembayaran')->insert([
            'stbp_id' => $sb->id, 'metode_penyetoran' => 'tunai', 'nama_penyetor' => 'Penyetor A',
            'nama_bank' => 'Bank Jabar', 'nomor_rekening' => '999',
        ]);
        DB::table('stbp_pendapatan')->insert([
            'stbp_id' => $sb->id, 'akun_kode' => '4.1.1.01.03', 'akun_nama' => 'Pajak Hotel',
            'rekening_bank' => '', 'rekening_nama' => '', 'rekening_nomor' => '', 'nominal' => 1000000,
        ]);

        $this->actingAs($a)
            ->getJson('/api/v1/penerimaan/stbp?id=' . $sb->id)
            ->assertOk()
            ->assertJsonPath('stbp.nomor_stbp', 'STBP-001')
            ->assertJsonPath('stbp.nomor_skp', 'SKP-001')
            ->assertJsonPath('stbp.jenis_pajak', 'Pajak Hotel')
            ->assertJsonPath('stbp.skp_nama_penyetor', 'Penyetor A')
            ->assertJsonPath('stbp.skp_nilai', 250000)
            ->assertJsonPath('pembayaran.metode', 'tunai')
            ->assertJsonPath('pembayaran.bank', 'Bank Jabar')
            ->assertJsonCount(1, 'pendapatan')
            ->assertJsonPath('pendapatan.0.nominal', 1000000);
    }

    public function test_detail_stbp_instansi_lain_tidak_terlihat(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        $sb = $this->buatStbp($b, 'Dinas B');

        $this->actingAs($a)
            ->getJson('/api/v1/penerimaan/stbp?id=' . $sb->id)
            ->assertStatus(404)
            ->assertJsonPath('message', 'STBP tidak ditemukan.');
    }

    // -----------------------------------------------------------------
    // STBP — alur status
    // -----------------------------------------------------------------

    public function test_majukan_status_stbp_harus_berurutan(): void
    {
        $a  = $this->buatUser('Dinas A', 'ua');
        $sb = $this->buatStbp($a, 'Dinas A');

        $this->actingAs($a)
            ->postJson('/api/v1/penerimaan/stbp', [
                'action' => 'update_status', 'id' => $sb->id, 'status' => 'sudah_diverifikasi',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Status STBP berhasil dimajukan ke tahap berikutnya.')
            ->assertJsonPath('status', 'sudah_diverifikasi');

        // Melompat satu tahap -> ditolak.
        $this->actingAs($a)
            ->postJson('/api/v1/penerimaan/stbp', [
                'action' => 'update_status', 'id' => $sb->id, 'status' => 'sudah_divalidasi',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tahap tidak boleh melompat. Majukan status secara berurutan.');

        // Tahap berikutnya diterima.
        $this->actingAs($a)
            ->postJson('/api/v1/penerimaan/stbp', [
                'action' => 'update_status', 'id' => $sb->id, 'status' => 'sudah_diotorisasi',
            ])
            ->assertOk();

        $this->assertDatabaseHas('stbp', ['id' => $sb->id, 'status' => 'sudah_diotorisasi']);
    }

    public function test_majukan_status_menolak_status_tidak_valid_dan_id_kosong(): void
    {
        $a  = $this->buatUser('Dinas A', 'ua');
        $sb = $this->buatStbp($a, 'Dinas A');

        $this->actingAs($a)
            ->postJson('/api/v1/penerimaan/stbp', [
                'action' => 'update_status', 'id' => $sb->id, 'status' => 'status_ngawur',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Status tahap tidak valid.');

        $this->actingAs($a)
            ->postJson('/api/v1/penerimaan/stbp', [
                'action' => 'update_status', 'id' => 0, 'status' => 'sudah_diverifikasi',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ID tidak valid.');
    }

    public function test_majukan_status_stbp_instansi_lain_ditolak(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        $sb = $this->buatStbp($b, 'Dinas B');

        $this->actingAs($a)
            ->postJson('/api/v1/penerimaan/stbp', [
                'action' => 'update_status', 'id' => $sb->id, 'status' => 'sudah_diverifikasi',
            ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'STBP tidak ditemukan atau bukan milik instansi Anda.');

        $this->assertDatabaseHas('stbp', ['id' => $sb->id, 'status' => 'belum_diverifikasi']);
    }

    // -----------------------------------------------------------------
    // STBP — pembuatan dari SKP Daerah
    // -----------------------------------------------------------------

    public function test_buat_stbp_menyalin_data_skp_dan_menandai_terpakai(): void
    {
        $a   = $this->buatUser('Dinas A', 'ua');
        $skp = $this->buatSkp($a, 'Dinas A');

        $this->actingAs($a)
            ->postJson('/api/v1/penerimaan/stbp', [
                'action'          => 'create',
                'nomor_stbp'      => 'STBP-900',
                'tanggal'         => '2026-03-05',
                'skp_daerah_id'   => $skp->id,
                'data_pembayaran' => [
                    ['metode' => 'tunai', 'nama_penyetor' => 'Diabaikan', 'bank' => 'Bank Jabar', 'nomor_rekening' => '777'],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('nomor', 'STBP-900');

        // Data STBP mengikuti SKP (server authority, bukan input klien).
        $this->assertDatabaseHas('stbp', [
            'nomor_stbp' => 'STBP-900',
            'skpd'       => 'Dinas A',
            'akun_kode'  => '4.1.1.01.03',
            'akun_nama'  => 'Pajak Hotel',
            'jumlah'     => 250000,
            'uraian'     => 'Hotel Melati | SKP Daerah SKP-001',
            'status'     => 'belum_diverifikasi',
        ]);

        // Nama penyetor pembayaran mengikuti SKP.
        $this->assertDatabaseHas('stbp_pembayaran', [
            'metode_penyetoran' => 'tunai',
            'nama_penyetor'     => 'Penyetor A',
            'nama_bank'         => 'Bank Jabar',
        ]);

        // Pendapatan dibuat otomatis dari SKP.
        $this->assertDatabaseHas('stbp_pendapatan', [
            'akun_kode' => '4.1.1.01.03',
            'nominal'   => 250000,
        ]);

        // SKP tidak boleh dipakai lagi.
        $this->assertDatabaseHas('skp_daerah', ['id' => $skp->id, 'status' => 'terpakai']);
    }

    public function test_buat_stbp_menolak_tanpa_skp_dan_skp_instansi_lain(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        $skpB = $this->buatSkp($b, 'Dinas B');

        $this->actingAs($a)
            ->postJson('/api/v1/penerimaan/stbp', ['action' => 'create', 'tanggal' => '2026-03-05'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'SKP Daerah wajib dipilih sebagai dasar pembuatan STBP.');

        $this->actingAs($a)
            ->postJson('/api/v1/penerimaan/stbp', [
                'action' => 'create', 'tanggal' => '2026-03-05', 'skp_daerah_id' => $skpB->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'SKP Daerah tidak valid / sudah terpakai. Pilih SKP Daerah yang masih aktif.');

        $this->assertDatabaseCount('stbp', 0);
    }

    public function test_buat_stbp_menolak_tanpa_tanggal(): void
    {
        $a   = $this->buatUser('Dinas A', 'ua');
        $skp = $this->buatSkp($a, 'Dinas A');

        $this->actingAs($a)
            ->postJson('/api/v1/penerimaan/stbp', [
                'action' => 'create', 'skp_daerah_id' => $skp->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tanggal wajib diisi.');
    }
}
