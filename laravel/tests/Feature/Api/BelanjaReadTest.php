<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tes modul Belanja — AKSI BACA (fase 3b-baca).
 *
 * Fokus:
 *  - Bentuk respons identik dengan legacy (baris tabel apa adanya, tipe nilai
 *    mengikuti PDO: INT tetap angka, DECIMAL berupa string).
 *  - Kolom turunan (sisa SPD, spd_sisa, total BKU) dan lampiran
 *    (details/potongan/pajak) benar.
 *  - Isolasi multi-tenant: data instansi lain tidak terlihat.
 *
 * Kesetaraan penuh terhadap legacy (dengan data nyata) diuji oleh
 * tools/difftest/e2e-belanja.php.
 */
class BelanjaReadTest extends TestCase
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

    private function spd(User $u, string $instansi, array $ubah = []): int
    {
        return (int) DB::table('spd')->insertGetId(array_merge([
            'user_id'    => $u->id,
            'skpd'       => $instansi,
            'nomor_spd'  => 'SPD-1',
            'tanggal'    => '2026-03-01',
            'jenis'      => 'Barang & Jasa',
            'periode'    => 'Triwulan 1',
            'jumlah'     => '1000000.00',
            'status'     => 'sudah_otorisasi',
            'created_at' => '2026-03-01 08:00:00',
            'updated_at' => '2026-03-01 08:00:00',
        ], $ubah));
    }

    private function spp(User $u, string $instansi, int $spdId, array $ubah = []): int
    {
        return (int) DB::table('spp')->insertGetId(array_merge([
            'user_id'        => $u->id,
            'skpd'           => $instansi,
            'nomor_spp'      => 'SPP-1',
            'tanggal'        => '2026-03-03',
            'jenis_spp'      => 'GU',
            'spd_id'         => $spdId,
            'keperluan'      => 'Belanja alat tulis',
            'jumlah'         => '400000.00',
            'total_potongan' => '10000.00',
            'total_pajak'    => '5000.00',
            'status'         => 'sudah_diverifikasi',
            'created_at'     => '2026-03-03 08:00:00',
            'updated_at'     => '2026-03-03 08:00:00',
        ], $ubah));
    }

    private function sp2d(User $u, string $instansi, array $ubah = []): int
    {
        return (int) DB::table('sp2d')->insertGetId(array_merge([
            'user_id'    => $u->id,
            'skpd'       => $instansi,
            'nomor_sp2d' => 'SP2D-1',
            'tanggal'    => '2026-03-05',
            'rekening'   => '5140667171',
            'jumlah'     => '400000.00',
            'status'     => 'sudah_dicairkan',
            'created_at' => '2026-03-05 08:00:00',
            'updated_at' => '2026-03-05 08:00:00',
        ], $ubah));
    }

    // -----------------------------------------------------------------
    // Akses
    // -----------------------------------------------------------------

    public function test_daftar_belanja_menolak_tanpa_login_dan_tanpa_instansi(): void
    {
        $this->getJson('/api/v1/belanja/spd')->assertStatus(401);

        $tanpaInstansi = User::create([
            'nama_lengkap' => 'Tanpa Instansi',
            'username'     => 'tanpa',
            'email'        => 'tanpa@polban.ac.id',
            'password'     => 'RahasiaKuat123',
            'instansi'     => '',
            'peran'        => 'Bendahara',
            'status'       => User::STATUS_AKTIF,
        ]);

        $this->actingAs($tanpaInstansi)
            ->getJson('/api/v1/belanja/spd')
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Daftar sederhana
    // -----------------------------------------------------------------

    public function test_daftar_rekanan_hanya_instansi_sendiri(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        DB::table('rekanan')->insert([
            ['user_id' => $a->id, 'skpd' => 'Dinas A', 'nama_rekanan' => 'CV A', 'jenis' => 'perusahaan', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $b->id, 'skpd' => 'Dinas B', 'nama_rekanan' => 'CV B', 'jenis' => 'perusahaan', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $res = $this->actingAs($a)->getJson('/api/v1/belanja/rekanan')->assertOk();

        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('CV A', $data[0]['nama_rekanan']);
    }

    public function test_daftar_spd_bisa_disaring_status(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $this->spd($a, 'Dinas A', ['nomor_spd' => 'SPD-A']);
        $this->spd($a, 'Dinas A', ['nomor_spd' => 'SPD-B', 'status' => 'belum_otorisasi']);

        $semua = $this->actingAs($a)->getJson('/api/v1/belanja/spd')->assertOk()->json('data');
        $this->assertCount(2, $semua);

        $saring = $this->actingAs($a)
            ->getJson('/api/v1/belanja/spd?status=sudah_otorisasi')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $saring);
        $this->assertSame('SPD-A', $saring[0]['nomor_spd']);
        // DECIMAL dikembalikan sebagai string, sama seperti legacy (PDO).
        $this->assertSame('1000000.00', $saring[0]['jumlah']);
    }

    // -----------------------------------------------------------------
    // Kolom turunan & lampiran
    // -----------------------------------------------------------------

    public function test_spd_otorisasi_hanya_yang_masih_punya_sisa(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $punyaSisa = $this->spd($a, 'Dinas A', ['nomor_spd' => 'SPD-SISA']);
        $habis     = $this->spd($a, 'Dinas A', ['nomor_spd' => 'SPD-HABIS']);
        $this->spd($a, 'Dinas A', ['nomor_spd' => 'SPD-DRAFT', 'status' => 'belum_otorisasi']);

        // SPD-SISA terpakai 400rb -> sisa 600rb.
        $this->spp($a, 'Dinas A', $punyaSisa);
        // SPD-HABIS terpakai penuh -> tidak muncul.
        $this->spp($a, 'Dinas A', $habis, ['nomor_spp' => 'SPP-HABIS', 'jumlah' => '1000000.00']);

        $data = $this->actingAs($a)
            ->getJson('/api/v1/belanja/spd-otor')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('SPD-SISA', $data[0]['nomor_spd']);
        $this->assertEqualsWithDelta(600000.0, (float) $data[0]['sisa'], 0.001);
    }

    public function test_daftar_spp_menyertakan_rincian_dan_sisa_spd(): void
    {
        $a   = $this->buatUser('Dinas A', 'ua');
        $spd = $this->spd($a, 'Dinas A');
        $spp = $this->spp($a, 'Dinas A', $spd);

        DB::table('spp_detail')->insert([
            ['skpd' => 'Dinas A', 'spp_id' => $spp, 'kode_rekening' => '5.1.02.01', 'uraian' => 'Kertas', 'jumlah' => '250000.00', 'created_at' => now()],
            ['skpd' => 'Dinas A', 'spp_id' => $spp, 'kode_rekening' => '5.1.02.02', 'uraian' => 'Tinta', 'jumlah' => '150000.00', 'created_at' => now()],
        ]);

        $data = $this->actingAs($a)->getJson('/api/v1/belanja/spp')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('SPD-1', $data[0]['nomor_spd']);
        $this->assertEqualsWithDelta(600000.0, (float) $data[0]['spd_sisa'], 0.001);
        $this->assertCount(2, $data[0]['details']);
        $this->assertSame('Kertas', $data[0]['details'][0]['uraian']);
        $this->assertEqualsWithDelta(250000.0, (float) $data[0]['details'][0]['jumlah'], 0.001);
    }

    public function test_daftar_spm_menyertakan_potongan_pajak_dan_rincian(): void
    {
        $a   = $this->buatUser('Dinas A', 'ua');
        $spd = $this->spd($a, 'Dinas A');
        $spp = $this->spp($a, 'Dinas A', $spd);

        DB::table('spp_potongan_pajak')->insert([
            ['skpd' => 'Dinas A', 'spp_id' => $spp, 'jenis' => 'pajak', 'nama' => 'PPN', 'nilai_persen' => '11.00', 'nilai' => '5000.00', 'created_at' => now()],
            ['skpd' => 'Dinas A', 'spp_id' => $spp, 'jenis' => 'potongan', 'nama' => 'PPh 22', 'nilai_persen' => '1.50', 'nilai' => '10000.00', 'created_at' => now()],
        ]);

        DB::table('spp_detail')->insert([
            'skpd' => 'Dinas A', 'spp_id' => $spp, 'kode_rekening' => '5.1.02.01',
            'uraian' => 'Kertas', 'jumlah' => '400000.00', 'created_at' => now(),
        ]);

        DB::table('spm')->insert([
            'user_id' => $a->id, 'skpd' => 'Dinas A', 'nomor_spm' => 'SPM-1', 'tanggal' => '2026-03-04',
            'spp_id' => $spp, 'jumlah' => '400000.00', 'status' => 'sudah_diverifikasi',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->actingAs($a)->getJson('/api/v1/belanja/spm')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('SPM-1', $data[0]['nomor_spm']);
        $this->assertSame('SPP-1', $data[0]['nomor_spp']);
        $this->assertCount(1, $data[0]['pajak']);
        $this->assertSame('PPN', $data[0]['pajak'][0]['nama']);
        $this->assertCount(1, $data[0]['potongan']);
        $this->assertSame('PPh 22', $data[0]['potongan'][0]['nama']);
        $this->assertCount(1, $data[0]['details']);
    }

    // -----------------------------------------------------------------
    // Laporan, referensi, ringkasan
    // -----------------------------------------------------------------

    public function test_bku_menjumlahkan_sp2d_dicairkan_pada_periode(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $this->sp2d($a, 'Dinas A', ['nomor_sp2d' => 'SP2D-MARET', 'tanggal' => '2026-03-05']);
        // Di luar periode -> tidak dihitung.
        $this->sp2d($a, 'Dinas A', ['nomor_sp2d' => 'SP2D-APRIL', 'tanggal' => '2026-04-05']);
        // Belum dicairkan -> tidak dihitung.
        $this->sp2d($a, 'Dinas A', ['nomor_sp2d' => 'SP2D-BELUM', 'tanggal' => '2026-03-06', 'status' => 'belum_diverifikasi']);

        $res = $this->actingAs($a)
            ->getJson('/api/v1/belanja/bku?dari=2026-03-01&sampai=2026-03-31')
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('SP2D-MARET', $res->json('data.0.nomor_sp2d'));
        $this->assertEqualsWithDelta(400000.0, (float) $res->json('total'), 0.001);
    }

    public function test_besaran_up_mengembalikan_null_bila_belum_ada(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($a)
            ->getJson('/api/v1/belanja/besaran-up?tahun=2026')
            ->assertOk()
            ->assertJsonPath('data', null);

        DB::table('besaran_up')->insert([
            'skpd' => 'Dinas A', 'tahun' => '2026',
            'besaran_up' => '5000000.00', 'besaran_up_kkpd' => '2000000.00',
            'updated_at' => now(),
        ]);

        $res = $this->actingAs($a)
            ->getJson('/api/v1/belanja/besaran-up?tahun=2026')
            ->assertOk();

        $this->assertSame('5000000.00', $res->json('data.besaran_up'));

        // Tahun lain tetap null.
        $this->actingAs($a)
            ->getJson('/api/v1/belanja/besaran-up?tahun=2025')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_ringkasan_menghitung_per_tahap(): void
    {
        $a   = $this->buatUser('Dinas A', 'ua');
        $b   = $this->buatUser('Dinas B', 'ub');
        $spd = $this->spd($a, 'Dinas A');

        $this->spp($a, 'Dinas A', $spd);
        $this->sp2d($a, 'Dinas A');

        // Data instansi lain tidak boleh terhitung.
        $spdB = $this->spd($b, 'Dinas B');
        $this->spp($b, 'Dinas B', $spdB);

        $res = $this->actingAs($a)->getJson('/api/v1/belanja/summary')->assertOk();

        $this->assertSame(1, $res->json('spd'));
        $this->assertSame(1, $res->json('spd_otor'));
        $this->assertSame(1, $res->json('spp'));
        $this->assertSame(1, $res->json('spp_ver'));
        $this->assertSame(0, $res->json('spm'));
        $this->assertSame(1, $res->json('sp2d'));
        $this->assertSame(1, $res->json('sp2d_cair'));
        $this->assertEqualsWithDelta(1000000.0, (float) $res->json('total_spd'), 0.001);
        $this->assertEqualsWithDelta(400000.0, (float) $res->json('total_spp'), 0.001);
    }

    public function test_daftar_lpj_pengajuan_tu_rekening_dan_npd_terbatas_instansi(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        DB::table('lpj')->insert([
            ['skpd' => 'Dinas A', 'nomor_lpj' => 'LPJ-A', 'tanggal' => '2026-03-06', 'uraian' => 'x', 'jumlah' => '1.00', 'created_at' => now()],
            ['skpd' => 'Dinas B', 'nomor_lpj' => 'LPJ-B', 'tanggal' => '2026-03-06', 'uraian' => 'x', 'jumlah' => '1.00', 'created_at' => now()],
        ]);

        DB::table('pengajuan_tu')->insert([
            ['skpd' => 'Dinas A', 'nomor_pengajuan' => 'TU-A', 'tanggal' => '2026-03-07', 'keterangan' => 'x', 'jumlah' => '1.00', 'status' => 'belum_otorisasi', 'created_at' => now(), 'updated_at' => now()],
            ['skpd' => 'Dinas B', 'nomor_pengajuan' => 'TU-B', 'tanggal' => '2026-03-07', 'keterangan' => 'x', 'jumlah' => '1.00', 'status' => 'belum_otorisasi', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('rekening_skpd')->insert([
            ['skpd' => 'Dinas A', 'user_id' => $a->id, 'bank' => 'Bank A', 'nama_pemilik' => 'A', 'nomor_rekening' => '1', 'status' => 'aktif', 'created_at' => now(), 'updated_at' => now()],
            ['skpd' => 'Dinas B', 'user_id' => $b->id, 'bank' => 'Bank B', 'nama_pemilik' => 'B', 'nomor_rekening' => '2', 'status' => 'aktif', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('npd')->insert([
            ['skpd' => 'Dinas A', 'user_id' => $a->id, 'nomor_npd' => 'NPD-A', 'tanggal' => '2026-03-09', 'metode' => 'non_panjar', 'keterangan' => 'x', 'kegiatan' => 'k', 'sub_kegiatan' => 's', 'jumlah' => '1.00', 'status' => 'divalidasi_bp', 'created_at' => now(), 'updated_at' => now()],
            ['skpd' => 'Dinas B', 'user_id' => $b->id, 'nomor_npd' => 'NPD-B', 'tanggal' => '2026-03-09', 'metode' => 'non_panjar', 'keterangan' => 'x', 'kegiatan' => 'k', 'sub_kegiatan' => 's', 'jumlah' => '1.00', 'status' => 'divalidasi_bp', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $harus = ['lpj' => 'LPJ-A', 'pengajuan-tu' => 'TU-A', 'rekening-skpd' => 'Bank A', 'npd' => 'NPD-A'];
        $kolom = ['lpj' => 'nomor_lpj', 'pengajuan-tu' => 'nomor_pengajuan', 'rekening-skpd' => 'bank', 'npd' => 'nomor_npd'];

        foreach ($harus as $path => $nilai) {
            $data = $this->actingAs($a)->getJson('/api/v1/belanja/' . $path)->assertOk()->json('data');

            $this->assertCount(1, $data, 'endpoint ' . $path);
            $this->assertSame($nilai, (string) $data[0][$kolom[$path]], 'endpoint ' . $path);
        }
    }
}