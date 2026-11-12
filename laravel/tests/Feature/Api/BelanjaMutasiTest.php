<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tes MUTASI modul Belanja (fase 3b-mutasi).
 *
 * Fokus:
 *  - Alur kerja SPD -> SPP -> SPM -> SP2D berjalan sesuai legacy
 *    (status, nomor dokumen, nilai netto, sisa SPD).
 *  - Efek samping tersimpan benar (spp_detail, potongan/pajak, SPM otomatis).
 *  - Isolasi tenant: data/alur instansi lain tidak dapat diubah.
 *
 * Jalur validasi (gagal sebelum menulis) dibandingkan langsung dengan legacy
 * lewat tools/difftest/run.php --filter=belanja.
 */
class BelanjaMutasiTest extends TestCase
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

    /** SPD terotorisasi siap dipakai SPP. */
    private function spd(User $u, string $instansi, string $jumlah = '1000000.00'): int
    {
        return (int) DB::table('spd')->insertGetId([
            'user_id'    => $u->id,
            'skpd'       => $instansi,
            'nomor_spd'  => 'SPD-SIAP',
            'tanggal'    => '2026-03-01',
            'jenis'      => 'Barang & Jasa',
            'periode'    => 'Triwulan 1',
            'jumlah'     => $jumlah,
            'status'     => 'sudah_otorisasi',
            'created_at' => '2026-03-01 08:00:00',
            'updated_at' => '2026-03-01 08:00:00',
        ]);
    }

    // -----------------------------------------------------------------
    // SPD
    // -----------------------------------------------------------------

    public function test_spd_dibuat_lalu_diotorisasi(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $res = $this->actingAs($a)
            ->postJson('/api/v1/belanja/spd', [
                'tanggal' => '2026-03-01',
                'jenis'   => 'Barang & Jasa',
                'periode' => 'Triwulan 1',
                'jumlah'  => 1500000,
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'SPD berhasil dibuat.');

        $nomor = (string) $res->json('nomor_spd');
        $this->assertMatchesRegularExpression('/^SPD-\d{8}-[0-9A-F]{4}$/', $nomor);

        $id = (int) $res->json('id');
        $this->assertDatabaseHas('spd', ['id' => $id, 'skpd' => 'Dinas A', 'status' => 'belum_otorisasi']);

        $this->actingAs($a)
            ->postJson('/api/v1/belanja/spd/' . $id . '/otorisasi')
            ->assertOk()
            ->assertJsonPath('message', 'SPD berhasil diotorisasi.');

        $this->assertDatabaseHas('spd', ['id' => $id, 'status' => 'sudah_otorisasi']);

        // Otorisasi ulang -> 404 (status tidak lagi belum_otorisasi).
        $this->actingAs($a)
            ->postJson('/api/v1/belanja/spd/' . $id . '/otorisasi')
            ->assertStatus(404);
    }

    public function test_spd_mengikuti_kebijakan_periode_aktif(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        DB::table('kebijakan_spd')->insert([
            'skpd'             => 'Dinas A',
            'jenis_penerbitan' => 'Bulanan',
            'jenis_periode'    => 'Bulanan',
            'tanggal'          => '2026-01-02',
            'status'           => 'aktif',
        ]);

        // Periode berbeda dengan kebijakan -> ditolak.
        $this->actingAs($a)
            ->postJson('/api/v1/belanja/spd', [
                'tanggal' => '2026-03-01',
                'periode' => 'Triwulanan',
                'jumlah'  => 1000000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kebijakan SPD aktif menetapkan periode "Bulanan". Ubah kebijakan terlebih dahulu bila ingin berbeda.');

        // Tanpa periode -> label diisi otomatis dari tanggal.
        $res = $this->actingAs($a)
            ->postJson('/api/v1/belanja/spd', [
                'tanggal' => '2026-03-01',
                'jumlah'  => 1000000,
            ])
            ->assertStatus(201);

        $this->assertSame('SPD berhasil dibuat. Periode mengikuti Kebijakan SPD (Bulanan).', $res->json('message'));
        $this->assertSame('Maret 2026', DB::table('spd')->where('id', $res->json('id'))->value('periode'));
    }

    // -----------------------------------------------------------------
    // SPP -> SPM
    // -----------------------------------------------------------------

    public function test_spp_menghitung_jumlah_dari_rincian_dan_pajak_persen(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $spd = $this->spd($a, 'Dinas A');

        $res = $this->actingAs($a)
            ->postJson('/api/v1/belanja/spp', [
                'tanggal'    => '2026-03-03',
                'jenis_spp'  => 'LS Barang & Jasa',
                'spd_id'     => $spd,
                'keperluan'  => 'Belanja alat tulis',
                'jumlah'     => 999, // diabaikan karena ada rincian
                'detail'     => [
                    ['kode_rekening' => '5.1.02.01', 'uraian' => 'Kertas', 'jumlah' => 250000],
                    ['kode_rekening' => '5.1.02.02', 'uraian' => 'Tinta', 'jumlah' => 150000],
                ],
                'pajak'      => [
                    ['nama' => 'PPN', 'nilai_persen' => 11],
                ],
                'potongan'   => [
                    ['nama' => 'PPh 22', 'nilai' => 10000],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $sppId = (int) $res->json('id');
        $spp   = (array) DB::table('spp')->where('id', $sppId)->first();

        $this->assertSame('400000.00', $spp['jumlah']);          // dari total rincian
        $this->assertSame('44000.00', $spp['total_pajak']);      // 11% x 400.000
        $this->assertSame('10000.00', $spp['total_potongan']);
        $this->assertSame('belum_diverifikasi', $spp['status']);

        $this->assertSame(2, DB::table('spp_detail')->where('spp_id', $sppId)->count());
        $this->assertSame(2, DB::table('spp_potongan_pajak')->where('spp_id', $sppId)->count());
    }

    public function test_spp_ditolak_bila_melebihi_sisa_spd(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $spd = $this->spd($a, 'Dinas A', '1000000.00');

        // SPP pertama 900rb memakai sebagian SPD.
        $this->actingAs($a)
            ->postJson('/api/v1/belanja/spp', [
                'tanggal' => '2026-03-03', 'jenis_spp' => 'UP', 'spd_id' => $spd, 'jumlah' => 900000,
            ])
            ->assertStatus(201);

        // SPP kedua 200rb -> melebihi sisa 100rb.
        $this->actingAs($a)
            ->postJson('/api/v1/belanja/spp', [
                'tanggal' => '2026-03-04', 'jenis_spp' => 'UP', 'spd_id' => $spd, 'jumlah' => 200000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jumlah SPP melebihi sisa SPD (sisa: Rp 100.000).');

        $this->assertSame(1, DB::table('spp')->where('spd_id', $spd)->count());
    }

    public function test_spp_verifikasi_setuju_membuat_spm_netto(): void
    {
        $a   = $this->buatUser('Dinas A', 'ua');
        $spd = $this->spd($a, 'Dinas A');

        $sppId = (int) DB::table('spp')->insertGetId([
            'user_id' => $a->id, 'skpd' => 'Dinas A', 'nomor_spp' => 'SPP-1', 'tanggal' => '2026-03-03',
            'jenis_spp' => 'LS Barang & Jasa', 'spd_id' => $spd, 'keperluan' => 'x',
            'jumlah' => '400000.00', 'total_potongan' => '10000.00', 'total_pajak' => '5000.00',
            'status' => 'belum_diverifikasi', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->actingAs($a)
            ->postJson('/api/v1/belanja/spp/' . $sppId . '/verifikasi', ['setuju' => true])
            ->assertOk();

        $this->assertSame('SPP diverifikasi & SPM berhasil dibuat.', $res->json('message'));
        $this->assertDatabaseHas('spp', ['id' => $sppId, 'status' => 'sudah_diverifikasi']);

        $spm = (array) DB::table('spm')->where('spp_id', $sppId)->first();
        $this->assertSame('385000.00', $spm['jumlah']);   // 400rb - 10rb - 5rb
        $this->assertSame('belum_disetujui', $spm['status']);
        $this->assertSame('Dinas A', $spm['skpd']);
        $this->assertMatchesRegularExpression('/^SPM-\d{8}-[0-9A-F]{4}$/', (string) $spm['nomor_spm']);
    }

    public function test_spp_verifikasi_tolak_tidak_membuat_spm(): void
    {
        $a   = $this->buatUser('Dinas A', 'ua');
        $spd = $this->spd($a, 'Dinas A');

        $sppId = (int) DB::table('spp')->insertGetId([
            'user_id' => $a->id, 'skpd' => 'Dinas A', 'nomor_spp' => 'SPP-1', 'tanggal' => '2026-03-03',
            'jenis_spp' => 'UP', 'spd_id' => $spd, 'keperluan' => 'x', 'jumlah' => '100000.00',
            'total_potongan' => '0.00', 'total_pajak' => '0.00',
            'status' => 'belum_diverifikasi', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($a)
            ->postJson('/api/v1/belanja/spp/' . $sppId . '/verifikasi', ['setuju' => false])
            ->assertOk()
            ->assertJsonPath('message', 'SPP ditolak.');

        $this->assertDatabaseHas('spp', ['id' => $sppId, 'status' => 'ditolak']);
        $this->assertSame(0, DB::table('spm')->count());
    }

    // -----------------------------------------------------------------
    // SPM -> SP2D (sampai pencairan & masuk BKU)
    // -----------------------------------------------------------------

    public function test_alur_spm_persetujuan_verifikasi_lalu_sp2d_sampai_pencairan(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $spmId = (int) DB::table('spm')->insertGetId([
            'user_id' => $a->id, 'skpd' => 'Dinas A', 'nomor_spm' => 'SPM-1', 'tanggal' => '2026-03-04',
            'jumlah' => '385000.00', 'status' => 'belum_disetujui',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Persetujuan (SPTJM SPM) -> tahap verifikasi.
        $this->actingAs($a)
            ->postJson('/api/v1/belanja/spm/' . $spmId . '/persetujuan')
            ->assertOk()
            ->assertJsonPath('message', 'SPM disetujui (SPTJM SPM dibuat).');

        $this->assertDatabaseHas('spm', ['id' => $spmId, 'status' => 'belum_diverifikasi']);

        // Verifikasi setuju.
        $this->actingAs($a)
            ->postJson('/api/v1/belanja/spm/' . $spmId . '/verifikasi', ['setuju' => true])
            ->assertOk()
            ->assertJsonPath('message', 'SPM berhasil diverifikasi.');

        $this->assertDatabaseHas('spm', ['id' => $spmId, 'status' => 'sudah_diverifikasi']);

        // SP2D dibuat dari SPM terverifikasi.
        $res = $this->actingAs($a)
            ->postJson('/api/v1/belanja/sp2d', [
                'tanggal'  => '2026-03-05',
                'spm_id'   => $spmId,
                'rekening' => '5140667171',
                'jumlah'   => 385000,
            ])
            ->assertStatus(201);

        $sp2dId = (int) $res->json('id');
        $this->assertMatchesRegularExpression('/^SP2D-\d{8}-[0-9A-F]{4}$/', (string) $res->json('nomor_sp2d'));
        $this->assertDatabaseHas('sp2d', ['id' => $sp2dId, 'status' => 'belum_diverifikasi']);

        // Verifikasi lalu pencairan (tanggal ikut diperbarui).
        $this->actingAs($a)
            ->postJson('/api/v1/belanja/sp2d/' . $sp2dId . '/verifikasi', ['setuju' => true])
            ->assertOk()
            ->assertJsonPath('message', 'SP2D berhasil diverifikasi.');

        $this->actingAs($a)
            ->postJson('/api/v1/belanja/sp2d/' . $sp2dId . '/pencairan', ['tanggal' => '2026-03-20'])
            ->assertOk()
            ->assertJsonPath('message', 'Dana SP2D berhasil dicairkan (transfer).');

        $sp2d = (array) DB::table('sp2d')->where('id', $sp2dId)->first();
        $this->assertSame('sudah_dicairkan', $sp2d['status']);
        $this->assertSame('2026-03-20', (string) $sp2d['tanggal']);

        // Masuk BKU periode Maret.
        $bku = $this->actingAs($a)
            ->getJson('/api/v1/belanja/bku?dari=2026-03-01&sampai=2026-03-31')
            ->assertOk();

        $this->assertCount(1, $bku->json('data'));
        $this->assertEqualsWithDelta(385000.0, (float) $bku->json('total'), 0.001);
    }

    public function test_sp2d_menolak_spm_yang_belum_diverifikasi(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $spmId = (int) DB::table('spm')->insertGetId([
            'user_id' => $a->id, 'skpd' => 'Dinas A', 'nomor_spm' => 'SPM-1', 'tanggal' => '2026-03-04',
            'jumlah' => '100000.00', 'status' => 'belum_disetujui',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($a)
            ->postJson('/api/v1/belanja/sp2d', [
                'tanggal' => '2026-03-05', 'spm_id' => $spmId, 'jumlah' => 100000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'SPM tidak valid / belum diverifikasi / bukan milik instansi Anda.');

        $this->assertSame(0, DB::table('sp2d')->count());
    }

    // -----------------------------------------------------------------
    // Mutasi master & referensi
    // -----------------------------------------------------------------

    public function test_mutasi_master_rekanan_lpj_tu_rekening_besaran_kebijakan_npd(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        // Rekanan.
        $rek = $this->actingAs($a)
            ->postJson('/api/v1/belanja/rekanan', ['nama_rekanan' => 'CV Uji', 'bank' => 'Bank Jabar'])
            ->assertStatus(201)
            ->assertJsonPath('message', 'Rekanan berhasil disimpan.');

        $this->assertDatabaseHas('rekanan', [
            'id' => (int) $rek->json('id'), 'skpd' => 'Dinas A',
            'nama_rekanan' => 'CV Uji', 'jenis' => 'perusahaan', // default
        ]);

        // LPJ.
        $lpj = $this->actingAs($a)
            ->postJson('/api/v1/belanja/lpj', ['tanggal' => '2026-03-06', 'uraian' => 'LPJ uji', 'jumlah' => 250000])
            ->assertStatus(201);

        $this->assertMatchesRegularExpression('/^LPJ-\d{8}-[0-9A-F]{4}$/', (string) $lpj->json('nomor_lpj'));

        // Pengajuan TU: dibuat -> diotorisasi -> divalidasi.
        $tu = $this->actingAs($a)
            ->postJson('/api/v1/belanja/pengajuan-tu', ['tanggal' => '2026-03-07', 'jumlah' => 300000])
            ->assertStatus(201);

        $tuId = (int) $tu->json('id');
        $this->assertDatabaseHas('pengajuan_tu', ['id' => $tuId, 'status' => 'belum_otorisasi']);

        $this->actingAs($a)->postJson('/api/v1/belanja/pengajuan-tu/' . $tuId . '/otorisasi')->assertOk();
        $this->actingAs($a)->postJson('/api/v1/belanja/pengajuan-tu/' . $tuId . '/validasi')
            ->assertOk()
            ->assertJsonPath('message', 'Pengajuan TU berhasil divalidasi (PA/KPA).');

        $this->assertDatabaseHas('pengajuan_tu', ['id' => $tuId, 'status' => 'sudah_divalidasi']);

        // Rekening SKPD: permohonan -> pengajuan -> pembuatan -> aktif.
        $rsk = $this->actingAs($a)
            ->postJson('/api/v1/belanja/rekening-skpd', ['bank' => 'Bank Jabar', 'nama_pemilik' => 'BENDAHARA'])
            ->assertStatus(201);

        $rskId = (int) $rsk->json('id');
        $this->assertDatabaseHas('rekening_skpd', ['id' => $rskId, 'status' => 'permohonan']);

        $this->actingAs($a)->postJson('/api/v1/belanja/rekening-skpd/' . $rskId . '/ajukan')->assertOk();
        $this->assertDatabaseHas('rekening_skpd', ['id' => $rskId, 'status' => 'pengajuan']);

        $this->actingAs($a)
            ->postJson('/api/v1/belanja/rekening-skpd/' . $rskId . '/nomor', ['nomor_rekening' => '111222'])
            ->assertOk()
            ->assertJsonPath('message', 'Nomor rekening dibuat (BUD).');

        $this->assertDatabaseHas('rekening_skpd', ['id' => $rskId, 'status' => 'pembuatan', 'nomor_rekening' => '111222']);

        $this->actingAs($a)
            ->postJson('/api/v1/belanja/rekening-skpd/' . $rskId . '/validasi', ['setuju' => true, 'nomor_rekening' => '333444'])
            ->assertOk()
            ->assertJsonPath('message', 'Rekening divalidasi & aktif.');

        $this->assertDatabaseHas('rekening_skpd', ['id' => $rskId, 'status' => 'aktif', 'nomor_rekening' => '333444']);

        // Besaran UP: upsert (insert lalu update).
        $this->actingAs($a)
            ->postJson('/api/v1/belanja/besaran-up', ['tahun' => '2026', 'besaran_up' => 5000000, 'besaran_up_kkpd' => 2000000])
            ->assertOk()
            ->assertJsonPath('message', 'Besaran UP berhasil disimpan.');

        $this->actingAs($a)
            ->postJson('/api/v1/belanja/besaran-up', ['tahun' => '2026', 'besaran_up' => 6000000, 'besaran_up_kkpd' => 1000000])
            ->assertOk();

        $this->assertSame(1, DB::table('besaran_up')->where('skpd', 'Dinas A')->where('tahun', '2026')->count());
        $this->assertSame('6000000.00', (string) DB::table('besaran_up')->where('skpd', 'Dinas A')->value('besaran_up'));

        // Kebijakan SPD (tanggal boleh kosong).
        $keb = $this->actingAs($a)
            ->postJson('/api/v1/belanja/kebijakan-spd', ['jenis_penerbitan' => 'Bulanan', 'jenis_periode' => 'Bulanan'])
            ->assertStatus(201);

        $this->assertDatabaseHas('kebijakan_spd', ['id' => (int) $keb->json('id'), 'skpd' => 'Dinas A']);

        // NPD: diajukan -> divalidasi_pa -> divalidasi_bp.
        $npd = $this->actingAs($a)
            ->postJson('/api/v1/belanja/npd', ['tanggal' => '2026-03-09', 'jumlah' => 300000, 'metode' => 'non_panjar'])
            ->assertStatus(201);

        $npdId = (int) $npd->json('id');
        $this->assertDatabaseHas('npd', ['id' => $npdId, 'status' => 'diajukan', 'metode' => 'non_panjar']);

        $this->actingAs($a)->postJson('/api/v1/belanja/npd/' . $npdId . '/validasi-pa', ['setuju' => true])
            ->assertOk()
            ->assertJsonPath('message', 'NPD divalidasi (PA/KPA).');

        $this->actingAs($a)->postJson('/api/v1/belanja/npd/' . $npdId . '/validasi-bp', ['setuju' => true])
            ->assertOk()
            ->assertJsonPath('message', 'NPD divalidasi (BP/BPP).');

        $this->assertDatabaseHas('npd', ['id' => $npdId, 'status' => 'divalidasi_bp']);
    }

    public function test_mutasi_tidak_dapat_mengubah_data_instansi_lain(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        $spdId = (int) DB::table('spd')->insertGetId([
            'user_id' => $b->id, 'skpd' => 'Dinas B', 'nomor_spd' => 'SPD-B', 'tanggal' => '2026-03-01',
            'jenis' => 'UP', 'periode' => 'Triwulan 1', 'jumlah' => '500000.00', 'status' => 'belum_otorisasi',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sppId = (int) DB::table('spp')->insertGetId([
            'user_id' => $b->id, 'skpd' => 'Dinas B', 'nomor_spp' => 'SPP-B', 'tanggal' => '2026-03-03',
            'jenis_spp' => 'UP', 'spd_id' => $spdId, 'keperluan' => 'x', 'jumlah' => '100000.00',
            'total_potongan' => '0.00', 'total_pajak' => '0.00', 'status' => 'belum_diverifikasi',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sp2dId = (int) DB::table('sp2d')->insertGetId([
            'user_id' => $b->id, 'skpd' => 'Dinas B', 'nomor_sp2d' => 'SP2D-B', 'tanggal' => '2026-03-05',
            'jumlah' => '100000.00', 'status' => 'sudah_diverifikasi', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $npdId = (int) DB::table('npd')->insertGetId([
            'skpd' => 'Dinas B', 'user_id' => $b->id, 'nomor_npd' => 'NPD-B', 'tanggal' => '2026-03-09',
            'metode' => 'panjar', 'keterangan' => 'x', 'kegiatan' => 'k', 'sub_kegiatan' => 's',
            'jumlah' => '100000.00', 'status' => 'diajukan', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($a)->postJson('/api/v1/belanja/spd/' . $spdId . '/otorisasi')->assertStatus(404);
        $this->actingAs($a)->postJson('/api/v1/belanja/spp/' . $sppId . '/verifikasi', ['setuju' => true])->assertStatus(404);
        $this->actingAs($a)->postJson('/api/v1/belanja/sp2d/' . $sp2dId . '/pencairan')->assertStatus(404);
        $this->actingAs($a)->postJson('/api/v1/belanja/npd/' . $npdId . '/validasi-pa', ['setuju' => true])->assertStatus(404);

        // Tak satu pun status berubah.
        $this->assertDatabaseHas('spd', ['id' => $spdId, 'status' => 'belum_otorisasi']);
        $this->assertDatabaseHas('spp', ['id' => $sppId, 'status' => 'belum_diverifikasi']);
        $this->assertDatabaseHas('sp2d', ['id' => $sp2dId, 'status' => 'sudah_diverifikasi']);
        $this->assertDatabaseHas('npd', ['id' => $npdId, 'status' => 'diajukan']);
    }
}