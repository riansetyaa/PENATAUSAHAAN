<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tes modul AKLAP (peta.simtkd.com) — aksi baca.
 *
 * Fokus:
 *  - Bentuk respons khas AKLAP: sukses TANPA kunci `message`
 *    (mis. {success, data} / {success, user}).
 *  - Aturan pelaporan: LRA & rekap hanya menghitung jurnal yang sudah
 *    di-approve; belanja dari SP2D yang sudah dicairkan.
 *  - Isolasi multi-tenant pada seluruh daftar.
 *
 * Kesetaraan terhadap legacy diuji oleh tools/difftest/run.php --filter=aklap.
 */
class AklapReadTest extends TestCase
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
            'kota'         => 'Bandung',
            'provinsi'     => 'Jawa Barat',
            'peran'        => 'Bendahara',
            'status'       => User::STATUS_AKTIF,
        ]);
    }

    /** @param array<string, mixed> $ubah */
    private function stbp(User $u, string $instansi, array $ubah = []): int
    {
        return (int) DB::table('stbp')->insertGetId(array_merge([
            'user_id' => $u->id, 'skpd' => $instansi, 'nomor_stbp' => 'STBP-1', 'tanggal' => '2026-03-05',
            'akun_kode' => '4.1.1.01.03', 'akun_nama' => 'Pajak Hotel', 'jumlah' => '1000000.00',
            'uraian' => 'Uji', 'status' => 'sudah_divalidasi', 'jurnal_status' => 'belum_approve',
            'created_at' => now(), 'updated_at' => now(),
        ], $ubah));
    }

    /** @param array<string, mixed> $ubah */
    private function sts(User $u, string $instansi, array $ubah = []): int
    {
        return (int) DB::table('sts')->insertGetId(array_merge([
            'user_id' => $u->id, 'skpd' => $instansi, 'nomor_sts' => 'STS-1', 'nama_penyetor' => 'A',
            'tanggal_sts' => '2026-03-03', 'tanggal_acuan_akhir' => '2026-03-31', 'total' => '500000.00',
            'keterangan' => 'Setoran uji', 'status' => 'aktif', 'jurnal_status' => 'belum_approve',
            'created_at' => now(), 'updated_at' => now(),
        ], $ubah));
    }

    /** @param array<string, mixed> $ubah */
    private function sp2d(User $u, string $instansi, array $ubah = []): int
    {
        return (int) DB::table('sp2d')->insertGetId(array_merge([
            'user_id' => $u->id, 'skpd' => $instansi, 'nomor_sp2d' => 'SP2D-1', 'tanggal' => '2026-03-04',
            'jumlah' => '400000.00', 'status' => 'sudah_dicairkan', 'jurnal_status' => 'belum_approve',
            'created_at' => now(), 'updated_at' => now(),
        ], $ubah));
    }

    public function test_jurnal_menggabungkan_sumber_dan_mengurutkan_tanggal(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $this->stbp($a, 'Dinas A', ['tanggal' => '2026-03-05']);
        $this->sts($a, 'Dinas A', ['tanggal_sts' => '2026-03-03']);
        $this->sp2d($a, 'Dinas A', ['tanggal' => '2026-03-04']);

        // Tidak boleh muncul: STBP belum divalidasi & SP2D belum dicairkan.
        $this->stbp($a, 'Dinas A', ['nomor_stbp' => 'STBP-X', 'status' => 'belum_diverifikasi']);
        $this->sp2d($a, 'Dinas A', ['nomor_sp2d' => 'SP2D-X', 'status' => 'belum_diverifikasi']);

        $res = $this->actingAs($a)->getJson('/api/v1/aklap/jurnal')->assertOk();

        // Respons AKLAP: tanpa kunci `message`.
        $this->assertArrayNotHasKey('message', $res->json());

        $data = $res->json('data');
        $this->assertSame(3, $res->json('total'));
        $this->assertSame(['2026-03-03', '2026-03-04', '2026-03-05'], array_column($data, 'tgl'));
        $this->assertSame(['Penyetoran', 'Pengeluaran', 'Penerimaan'], array_column($data, 'transaksi'));
        $this->assertSame(['JRN-STS-1', 'JRN-SP2D-1', 'JRN-STBP-1'], array_column($data, 'no'));
        $this->assertEqualsWithDelta(500000.0, (float) $data[0]['nilai'], 0.001);
    }

    public function test_jurnal_menampilkan_label_status_dan_hanya_instansi_sendiri(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        $this->stbp($a, 'Dinas A', ['nomor_stbp' => 'STBP-APP', 'jurnal_status' => 'sudah_approve']);
        $this->stbp($a, 'Dinas A', ['nomor_stbp' => 'STBP-TOLAK', 'jurnal_status' => 'ditolak']);
        $this->stbp($b, 'Dinas B', ['nomor_stbp' => 'STBP-B']);

        $data = $this->actingAs($a)->getJson('/api/v1/aklap/jurnal')->assertOk()->json('data');

        $this->assertCount(2, $data);
        $label = array_column($data, 'status', 'dok');
        $this->assertSame('Sudah Approve', $label['STBP-APP']);
        $this->assertSame('Ditolak', $label['STBP-TOLAK']);
    }

    public function test_jurnal_biasa_memfilter_per_periode_tipe_dan_kata_kunci(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        DB::table('jurnal_manual')->insert([
            ['skpd' => 'Dinas A', 'nomor_jurnal' => 'JB-0001', 'nomor_dokumen' => 'STBP-1', 'kode_akun' => '4.1.1',
                'nama_akun' => 'Pajak', 'tanggal' => '2026-03-01', 'uraian' => 'Setoran pajak', 'jumlah' => '100000.00', 'created_at' => now()],
            ['skpd' => 'Dinas A', 'nomor_jurnal' => 'JB-0002', 'nomor_dokumen' => 'STS-9', 'kode_akun' => '4.1.2',
                'nama_akun' => 'Retribusi', 'tanggal' => '2026-04-01', 'uraian' => 'Retribusi parkir', 'jumlah' => '50000.00', 'created_at' => now()],
            ['skpd' => 'Dinas B', 'nomor_jurnal' => 'JB-0003', 'nomor_dokumen' => 'STBP-B', 'kode_akun' => '4.1.1',
                'nama_akun' => 'Pajak', 'tanggal' => '2026-03-01', 'uraian' => 'Milik dinas lain', 'jumlah' => '999.00', 'created_at' => now()],
        ]);

        // Tanpa filter -> hanya milik Dinas A, urut tanggal desc.
        $semua = $this->actingAs($a)->getJson('/api/v1/aklap/jurnal-biasa')->assertOk()->json('data');
        $this->assertCount(2, $semua);
        $this->assertSame(['JB-0002', 'JB-0001'], array_column($semua, 'nomor_jurnal'));

        // Filter periode.
        $maret = $this->actingAs($a)
            ->getJson('/api/v1/aklap/jurnal-biasa?dari=2026-03-01&akhir=2026-03-31')
            ->assertOk()->json('data');
        $this->assertCount(1, $maret);
        $this->assertSame('JB-0001', $maret[0]['nomor_jurnal']);

        // Tanggal tidak valid diabaikan (sama seperti tanpa filter periode).
        $abaiTanggal = $this->actingAs($a)
            ->getJson('/api/v1/aklap/jurnal-biasa?dari=bukan-tanggal')
            ->assertOk()->json('data');
        $this->assertCount(2, $abaiTanggal);

        // Filter tipe + kata kunci.
        $dokumen = $this->actingAs($a)
            ->getJson('/api/v1/aklap/jurnal-biasa?q=STS&tipe=dokumen')
            ->assertOk()->json('data');
        $this->assertCount(1, $dokumen);
        $this->assertSame('JB-0002', $dokumen[0]['nomor_jurnal']);

        $ket = $this->actingAs($a)
            ->getJson('/api/v1/aklap/jurnal-biasa?q=pajak&tipe=keterangan')
            ->assertOk()->json('data');
        $this->assertCount(1, $ket);
        $this->assertSame('JB-0001', $ket[0]['nomor_jurnal']);

        // Tipe tak dikenal -> mencari di ketiga kolom (tidak ditemukan).
        $this->assertCount(0, $this->actingAs($a)
            ->getJson('/api/v1/aklap/jurnal-biasa?q=zzz&tipe=ngawur')
            ->assertOk()->json('data'));
    }

    // -----------------------------------------------------------------
    // Akun & dokumen sumber
    // -----------------------------------------------------------------

    public function test_akun_list_mencari_akun_dan_tanpa_tenant(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        DB::table('akun_penerimaan')->insert([
            ['kode_akun' => '4.1.1.01.03', 'nama_akun' => 'Pajak Hotel', 'skpd' => 'Dinas A', 'status_aktif' => 1, 'metode_input' => 'harian', 'created_at' => now(), 'updated_at' => now()],
            ['kode_akun' => '4.1.1.01.04', 'nama_akun' => 'Pajak Restoran', 'skpd' => 'Dinas B', 'status_aktif' => 1, 'metode_input' => 'harian', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Tabel akun bersifat referensi -> tidak difilter per instansi.
        $semua = $this->actingAs($a)->getJson('/api/v1/aklap/akun')->assertOk();
        $this->assertCount(2, $semua->json('data'));
        $this->assertSame(['kode', 'nama'], array_keys($semua->json('data.0')));

        $cari = $this->actingAs($a)->getJson('/api/v1/aklap/akun?q=Hotel')->assertOk()->json('data');
        $this->assertCount(1, $cari);
        $this->assertSame('4.1.1.01.03', $cari[0]['kode']);
    }

    public function test_dokumen_list_menggabungkan_stbp_lalu_sts(): void
    {
        $a  = $this->buatUser('Dinas A', 'ua');
        $sb = $this->stbp($a, 'Dinas A', ['nomor_stbp' => 'STBP-1', 'tanggal' => '2026-03-05']);
        $st = $this->sts($a, 'Dinas A', ['nomor_sts' => 'STS-1']);

        DB::table('sts_detail')->insert([
            'sts_id' => $st, 'stbp_id' => $sb, 'nomor_stbp' => 'STBP-1', 'tanggal' => '2026-03-05',
            'akun_kode' => '4.1.1.01.03', 'akun_nama' => 'Pajak Hotel', 'jumlah' => '500000.00', 'uraian' => 'x',
        ]);

        // Tidak muncul: STBP dihapus & STBP tanpa nomor.
        $this->stbp($a, 'Dinas A', ['nomor_stbp' => 'STBP-DEL', 'status' => 'dihapus']);
        $this->stbp($a, 'Dinas A', ['nomor_stbp' => '']);

        $docs = $this->actingAs($a)->getJson('/api/v1/aklap/dokumen')->assertOk()->json('data');

        $this->assertCount(2, $docs);
        $this->assertSame(['STBP', 'STS'], array_column($docs, 'jenis'));   // STBP lebih dulu
        $this->assertSame('STBP-1', $docs[0]['nomor']);
        $this->assertSame('4.1.1.01.03', $docs[1]['akun_kode']);            // dari rincian STS pertama
    }

    // -----------------------------------------------------------------
    // Laporan & rekap
    // -----------------------------------------------------------------

    public function test_lra_hanya_menghitung_jurnal_yang_sudah_disetujui(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $this->stbp($a, 'Dinas A', ['nomor_stbp' => 'STBP-OK', 'akun_kode' => '4.1.1.01.03', 'jumlah' => '1000000.00', 'jurnal_status' => 'sudah_approve']);
        $this->stbp($a, 'Dinas A', ['nomor_stbp' => 'STBP-BELUM', 'akun_kode' => '4.1.1.01.04', 'jumlah' => '7000000.00', 'jurnal_status' => 'belum_approve']);

        DB::table('kegiatan')->insert([
            ['skpd' => 'Dinas A', 'tahun' => '2026', 'nama_kegiatan' => 'Uji A', 'pagu' => '2000000.00', 'realisasi' => '1500000.00', 'created_at' => now(), 'updated_at' => now()],
            ['skpd' => 'Dinas A', 'tahun' => '2026', 'nama_kegiatan' => 'Uji B', 'pagu' => '3000000.00', 'realisasi' => '2500000.00', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->sp2d($a, 'Dinas A', ['nomor_sp2d' => 'SP2D-OK', 'jumlah' => '400000.00', 'status' => 'sudah_dicairkan', 'jurnal_status' => 'sudah_approve']);
        $this->sp2d($a, 'Dinas A', ['nomor_sp2d' => 'SP2D-BELUM', 'jumlah' => '900000.00', 'status' => 'sudah_dicairkan', 'jurnal_status' => 'belum_approve']);

        DB::table('anggaran_lra')->insert([
            ['skpd' => 'Dinas A', 'kode_akun' => '4.1.1.01.03', 'tahun' => '2026', 'anggaran' => '5000000.00'],
            ['skpd' => 'Dinas A', 'kode_akun' => '4.1.1.01.03', 'tahun' => '2026', 'anggaran' => '1000000.00'],
            ['skpd' => 'Dinas A', 'kode_akun' => '4.1.1.01.03', 'tahun' => '2025', 'anggaran' => '9999999.00'],
        ]);

        $res = $this->actingAs($a)->getJson('/api/v1/aklap/lra?tahun=2026')->assertOk();

        $this->assertArrayNotHasKey('message', $res->json());

        $realisasi = $res->json('realisasi_by_akun');
        $this->assertSame(['4.1.1.01.03'], array_keys($realisasi));
        $this->assertEqualsWithDelta(1000000.0, (float) $realisasi['4.1.1.01.03'], 0.001);

        $anggaran = $res->json('anggaran_by_akun');
        $this->assertSame(['4.1.1.01.03'], array_keys($anggaran));
        $this->assertEqualsWithDelta(6000000.0, (float) $anggaran['4.1.1.01.03'], 0.001);
        $this->assertEqualsWithDelta(5000000.0, (float) $res->json('total_pagu'), 0.001);
        $this->assertEqualsWithDelta(4000000.0, (float) $res->json('total_realisasi'), 0.001);
        $this->assertSame(2, $res->json('jumlah_kegiatan'));
        $this->assertEqualsWithDelta(1000000.0, (float) $res->json('total_penerimaan'), 0.001);
        $this->assertEqualsWithDelta(400000.0, (float) $res->json('total_belanja'), 0.001);
    }

    public function test_neraca_rekap_per_tahun_dan_instansi(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        DB::table('neraca_awal')->insert([
            ['skpd' => 'Dinas A', 'kode_akun' => '1.1.1.01', 'nama_akun' => 'Kas', 'jenis' => 'aset', 'tahun' => '2026', 'saldo' => '1000000.00'],
            ['skpd' => 'Dinas A', 'kode_akun' => '1.1.1.01', 'nama_akun' => 'Kas', 'jenis' => 'aset', 'tahun' => '2026', 'saldo' => '500000.00'],
            ['skpd' => 'Dinas A', 'kode_akun' => '1.1.1.01', 'nama_akun' => 'Kas', 'jenis' => 'aset', 'tahun' => '2025', 'saldo' => '777.00'],
            ['skpd' => 'Dinas B', 'kode_akun' => '1.1.1.01', 'nama_akun' => 'Kas', 'jenis' => 'aset', 'tahun' => '2026', 'saldo' => '999.00'],
        ]);

        $res = $this->actingAs($a)->getJson('/api/v1/aklap/neraca?tahun=2026')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('1.1.1.01', $res->json('data.0.kode_akun'));
        $this->assertSame('aset', $res->json('data.0.jenis'));
        $this->assertEqualsWithDelta(1500000.0, (float) $res->json('data.0.saldo'), 0.001);
    }

    public function test_profil_dan_rekap(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        $this->stbp($a, 'Dinas A', ['jurnal_status' => 'sudah_approve', 'jumlah' => '1000000.00']);
        $this->stbp($a, 'Dinas A', ['nomor_stbp' => 'STBP-DEL', 'status' => 'dihapus']);
        $this->sts($a, 'Dinas A');
        $this->sp2d($a, 'Dinas A', ['jurnal_status' => 'sudah_approve']);
        $this->stbp($b, 'Dinas B');

        DB::table('kegiatan')->insert([
            ['skpd' => 'Dinas A', 'tahun' => '2026', 'nama_kegiatan' => 'Uji', 'pagu' => '2000000.00', 'realisasi' => '0.00', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Profil memakai kunci `user` (bukan `data`).
        $profil = $this->actingAs($a)->getJson('/api/v1/aklap/profil')->assertOk();
        $this->assertSame('Bendahara Dinas A', $profil->json('user.nama'));
        $this->assertSame('Dinas A', $profil->json('user.instansi'));
        $this->assertSame('Bandung', $profil->json('user.kota'));
        $this->assertArrayNotHasKey('message', $profil->json());

        $rekap = $this->actingAs($a)->getJson('/api/v1/aklap/rekap')->assertOk()->json('data');

        $this->assertSame(1, $rekap['stbp']);          // yang dihapus tidak dihitung
        $this->assertSame(1, $rekap['sts']);
        $this->assertSame(1, $rekap['kegiatan']);
        $this->assertSame(1, $rekap['sp2d_cair']);
        $this->assertEqualsWithDelta(2000000.0, (float) $rekap['total_pagu'], 0.001);
        $this->assertEqualsWithDelta(1000000.0, (float) $rekap['total_penerimaan'], 0.001);
        $this->assertEqualsWithDelta(400000.0, (float) $rekap['total_belanja'], 0.001);
    }

    public function test_aklap_menolak_tanpa_login_dan_tanpa_instansi(): void
    {
        $this->getJson('/api/v1/aklap/rekap')->assertStatus(401);

        $tanpa = User::create([
            'nama_lengkap' => 'Tanpa Instansi',
            'username'     => 'tanpa',
            'email'        => 'tanpa@polban.ac.id',
            'password'     => 'RahasiaKuat123',
            'instansi'     => '',
            'peran'        => 'Bendahara',
            'status'       => User::STATUS_AKTIF,
        ]);

        $this->actingAs($tanpa)->getJson('/api/v1/aklap/jurnal')->assertStatus(403);
    }
}