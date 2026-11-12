<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tes modul AKLAP — Register SPP/SPM/SP2D & LPJ Bendahara Pengeluaran PPKD.
 *
 * Diporting dari peta.simtkd.com/api/akuntansi.php (`register_ppkd_list`,
 * `register_ppkd_simpan`, `lpj_ppkd`). Fokus:
 *  - Register hanya menampilkan transaksi Buku Pengeluaran PPKD (fail-closed:
 *    hanya milik instansi ini yang boleh diisi).
 *  - LPJ: pagu memakai fallback kode induk, hanya kode ber-realisasi yang
 *    tampil, dan bucket rekonsiliasi (SP2D/SPJ, PPN, PPh, PFK).
 *
 * Paritas dengan legacy diuji lewat `tools/difftest/run.php --filter=aklap`
 * dan `tools/difftest/e2e-aklap.php`.
 */
class AklapRegisterPpkdTest extends TestCase
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
    private function bukuKas(string $instansi, array $ubah = []): int
    {
        return (int) DB::table('buku_kas')->insertGetId(array_merge([
            'skpd'          => $instansi,
            'tanggal'       => '2026-03-10',
            'jenis'         => 'pengeluaran',
            'no_bukti'      => 'BK-1',
            'kode_rekening' => '5.1.2.01',
            'uraian'        => 'Belanja ATK',
            'jumlah'        => '75000.00',
        ], $ubah));
    }

    public function test_register_ppkd_mendaftar_pengeluaran_dan_menyimpan_isi(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $keluar = $this->bukuKas('Dinas A');
        // Penerimaan TIDAK ikut terdaftar di register.
        $this->bukuKas('Dinas A', ['jenis' => 'penerimaan', 'kode_rekening' => '4.1.4.01', 'jumlah' => '500000.00', 'no_bukti' => 'BK-2']);

        $list = $this->actingAs($a)->getJson('/api/v1/aklap/register-ppkd')->assertOk();

        $this->assertSame(1, (int) $list->json('total'));
        $this->assertSame(75000.0, (float) $list->json('total_jumlah'));
        $this->assertSame(0, (int) $list->json('jumlah_lengkap'));
        $this->assertSame('', (string) $list->json('data.0.spp_no'));
        $this->assertSame(0, (int) $list->json('data.0.lengkap'));
        $this->assertSame('Dinas A', (string) $list->json('instansi'));
        $this->assertSame('Bendahara Dinas A', (string) $list->json('bendahara'));
        $this->assertSame(['dari' => '', 'sampai' => ''], (array) $list->json('periode'));

        // Validasi.
        $this->actingAs($a)->postJson('/api/v1/aklap/register-ppkd', ['buku_kas_id' => 0])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Transaksi Buku Pengeluaran PPKD tidak valid.');

        $this->actingAs($a)->postJson('/api/v1/aklap/register-ppkd', ['buku_kas_id' => 999999])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Transaksi tidak ditemukan pada Buku Pengeluaran PPKD Anda.');

        $this->actingAs($a)->postJson('/api/v1/aklap/register-ppkd', ['buku_kas_id' => $keluar, 'spp_tgl' => '2026-13-99'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Format tanggal SPP tidak valid.');

        $this->actingAs($a)->postJson('/api/v1/aklap/register-ppkd', ['buku_kas_id' => $keluar, 'spm_no' => str_repeat('x', 65)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nomor SPM maksimal 64 karakter.');

        $this->actingAs($a)->postJson('/api/v1/aklap/register-ppkd', ['buku_kas_id' => $keluar, 'ket' => str_repeat('x', 121)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Keterangan maksimal 120 karakter.');

        $this->assertSame(0, DB::table('register_ppkd')->count());

        // Simpan lengkap (nomor dipangkas, tanggal diisi).
        $this->actingAs($a)->postJson('/api/v1/aklap/register-ppkd', [
            'buku_kas_id' => $keluar,
            'spp_tgl'     => '2026-03-08',
            'spp_no'      => ' SPP-1 ',
            'spm_tgl'     => '2026-03-09',
            'spm_no'      => 'SPM-1',
            'sp2d_tgl'    => '2026-03-10',
            'sp2d_no'     => 'SP2D-1',
            'ket'         => ' Uji ',
        ])->assertOk()->assertJsonPath('message', 'Register SPP/SPM/SP2D tersimpan.')->assertJsonPath('buku_kas_id', $keluar);

        $row = (array) DB::table('register_ppkd')->first();

        $this->assertSame('SPP-1', $row['spp_no']);
        $this->assertSame('2026-03-08', $row['spp_tgl']);
        $this->assertSame('Uji', $row['ket']);
        $this->assertSame('Dinas A', $row['skpd']);

        $list = $this->actingAs($a)->getJson('/api/v1/aklap/register-ppkd')->assertOk();
        $this->assertSame(1, (int) $list->json('jumlah_lengkap'));
        $this->assertSame(1, (int) $list->json('data.0.lengkap'));

        // Simpan ulang tanpa tanggal -> tetap satu baris, tanggal dikosongkan (NULL).
        $this->actingAs($a)->postJson('/api/v1/aklap/register-ppkd', [
            'buku_kas_id' => $keluar, 'spp_no' => 'SPP-2',
        ])->assertOk();

        $this->assertSame(1, DB::table('register_ppkd')->count());
        $row = (array) DB::table('register_ppkd')->first();
        $this->assertSame('SPP-2', $row['spp_no']);
        $this->assertNull($row['spp_tgl']);
        $this->assertNull($row['sp2d_tgl']);

        $list = $this->actingAs($a)->getJson('/api/v1/aklap/register-ppkd')->assertOk();
        $this->assertSame(0, (int) $list->json('jumlah_lengkap'));
    }

    public function test_lpj_ppkd_rincian_pagu_induk_dan_bucket_rekonsiliasi(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        // Master akun (nama akun untuk baris LPJ).
        DB::table('akun_master')->insert([
            ['skpd' => 'Dinas A', 'kode_akun' => '5.1.2.01', 'nama_akun' => 'Belanja Barang dan Jasa', 'tipe' => 'belanja', 'saldo_normal' => 'debet'],
        ]);

        // Pagu di kode INDUK saja -> dipakai oleh kode anak (5.1.2.01.04).
        DB::table('anggaran_lra')->insert([
            ['skpd' => 'Dinas A', 'tahun' => '2026', 'kode_akun' => '5.1.2.01', 'anggaran' => '2000000.00', 'nama_akun' => 'Belanja Barang dan Jasa'],
        ]);

        // Pengeluaran: Maret (bulan ini) + Februari (s.d. bulan lalu) + kode tanpa pagu.
        $this->bukuKas('Dinas A', ['tanggal' => '2026-03-10', 'kode_rekening' => '5.1.2.01.04', 'jumlah' => '75000.00']);
        $this->bukuKas('Dinas A', ['tanggal' => '2026-02-10', 'kode_rekening' => '5.1.2.01.04', 'jumlah' => '25000.00', 'no_bukti' => 'BK-2']);
        $this->bukuKas('Dinas A', ['tanggal' => '2026-03-11', 'kode_rekening' => '9.9.9.99', 'uraian' => 'Tanpa pagu', 'jumlah' => '1000.00', 'no_bukti' => 'BK-3']);
        // Baris kode kosong & jumlah nol tidak ikut.
        $this->bukuKas('Dinas A', ['tanggal' => '2026-03-12', 'kode_rekening' => '', 'jumlah' => '5000.00', 'no_bukti' => 'BK-4']);
        $this->bukuKas('Dinas A', ['tanggal' => '2026-03-13', 'kode_rekening' => '5.1.2.01.04', 'jumlah' => '0.00', 'no_bukti' => 'BK-5']);

        // Penerimaan & pengeluaran untuk uji bucket.
        $this->bukuKas('Dinas A', ['tanggal' => '2026-03-05', 'jenis' => 'penerimaan', 'kode_rekening' => '4.1.4.01', 'uraian' => 'Setoran pajak hotel', 'jumlah' => '200000.00', 'no_bukti' => 'BK-6']);
        $this->bukuKas('Dinas A', ['tanggal' => '2026-03-06', 'jenis' => 'penerimaan', 'kode_rekening' => '4.1.4.02', 'uraian' => 'Potongan PPh 21', 'jumlah' => '50000.00', 'no_bukti' => 'BK-7']);
        $this->bukuKas('Dinas A', ['tanggal' => '2026-03-07', 'jenis' => 'pengeluaran', 'kode_rekening' => '2.1.1.01', 'uraian' => 'Setoran PPN', 'jumlah' => '11000.00', 'no_bukti' => 'BK-8']);
        $this->bukuKas('Dinas A', ['tanggal' => '2026-03-08', 'jenis' => 'pengeluaran', 'kode_rekening' => '2.1.1.02', 'uraian' => 'Pihak ketiga', 'jumlah' => '9000.00', 'no_bukti' => 'BK-9']);

        $res = $this->actingAs($a)->getJson('/api/v1/aklap/lpj-ppkd?tahun=2026&bulan=3')->assertOk();

        $this->assertSame('2026', (string) $res->json('tahun'));
        $this->assertSame(3, (int) $res->json('bulan'));
        $this->assertSame('Maret', (string) $res->json('bulan_nama'));
        $this->assertSame('2026-03-31', (string) $res->json('tgl_laporan'));
        $this->assertSame('Dinas A', (string) $res->json('instansi'));
        $this->assertSame('Bendahara Dinas A', (string) $res->json('bendahara'));

        $rincian = [];

        foreach ((array) $res->json('rincian') as $r) {
            $rincian[(string) $r['kode_rekening']] = $r;
        }

        $this->assertCount(4, $rincian);   // 2.1.1.01, 2.1.1.02, 5.1.2.01.04, 9.9.9.99
        $this->assertSame('Belanja Barang dan Jasa', (string) $rincian['5.1.2.01.04']['uraian']);
        $this->assertEqualsWithDelta(2000000.0, (float) $rincian['5.1.2.01.04']['anggaran'], 0.001);   // pagu kode induk
        $this->assertEqualsWithDelta(25000.0, (float) $rincian['5.1.2.01.04']['sd_lalu'], 0.001);
        $this->assertEqualsWithDelta(75000.0, (float) $rincian['5.1.2.01.04']['bulan_ini'], 0.001);
        $this->assertEqualsWithDelta(100000.0, (float) $rincian['5.1.2.01.04']['sd_ini'], 0.001);
        $this->assertEqualsWithDelta(1900000.0, (float) $rincian['5.1.2.01.04']['sisa'], 0.001);
        // Tanpa pagu -> anggaran 0, uraian dari uraian buku kas terakhir.
        $this->assertEqualsWithDelta(0.0, (float) $rincian['9.9.9.99']['anggaran'], 0.001);
        $this->assertSame('Tanpa pagu', (string) $rincian['9.9.9.99']['uraian']);
        $this->assertSame(4, (int) $res->json('jumlah_baris'));
        $this->assertSame(3, (int) $res->json('tanpa_pagu'));   // 2.1.1.01, 2.1.1.02, 9.9.9.99

        $this->assertEqualsWithDelta(2000000.0, (float) $res->json('jumlah_rincian.anggaran'), 0.001);
        $this->assertEqualsWithDelta(25000.0, (float) $res->json('jumlah_rincian.sd_lalu'), 0.001);
        $this->assertEqualsWithDelta(96000.0, (float) $res->json('jumlah_rincian.bulan_ini'), 0.001);

        // Bucket: penerimaan biasa -> sp2d; uraian ber-"pph 21" -> pph21;
        // pengeluaran biasa -> spj; "ppn" -> ppn; "pihak ketiga" -> pfk.
        $this->assertEqualsWithDelta(200000.0, (float) $res->json('penerimaan.sp2d.bulan_ini'), 0.001);
        $this->assertEqualsWithDelta(50000.0, (float) $res->json('penerimaan.pph21.bulan_ini'), 0.001);
        $this->assertEqualsWithDelta(81000.0, (float) $res->json('pengeluaran.spj.bulan_ini'), 0.001);   // termasuk baris kode kosong
        $this->assertEqualsWithDelta(11000.0, (float) $res->json('pengeluaran.ppn.bulan_ini'), 0.001);
        $this->assertEqualsWithDelta(9000.0, (float) $res->json('pengeluaran.pfk.bulan_ini'), 0.001);
        $this->assertEqualsWithDelta(250000.0, (float) $res->json('penerimaan.jumlah.bulan_ini'), 0.001);
        $this->assertEqualsWithDelta(101000.0, (float) $res->json('pengeluaran.jumlah.bulan_ini'), 0.001);
        $this->assertEqualsWithDelta(149000.0, (float) $res->json('saldo_kas.bulan_ini'), 0.001);

        // April: pengeluaran Maret masuk sd_lalu, bulan ini nol.
        $apr = $this->actingAs($a)->getJson('/api/v1/aklap/lpj-ppkd?tahun=2026&bulan=4')->assertOk();

        $this->assertEqualsWithDelta(126000.0, (float) $apr->json('pengeluaran.jumlah.sd_lalu'), 0.001);   // Feb 25.000 + Mar 101.000
        $this->assertEqualsWithDelta(0.0, (float) $apr->json('pengeluaran.jumlah.bulan_ini'), 0.001);
        $this->assertEqualsWithDelta(124000.0, (float) $apr->json('saldo_kas.sd_ini'), 0.001);

        // Tahun/bulan tidak valid -> periode hari ini (tgl_laporan <= hari ini).
        $def = $this->actingAs($a)->getJson('/api/v1/aklap/lpj-ppkd?tahun=26&bulan=99')->assertOk();

        $this->assertSame(date('Y'), (string) $def->json('tahun'));
        $this->assertSame((int) date('n'), (int) $def->json('bulan'));
        $this->assertLessThanOrEqual(date('Y-m-d'), (string) $def->json('tgl_laporan'));
    }

    public function test_register_lpj_terbatas_instansi_dan_menolak_tanpa_login(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        $milikB = $this->bukuKas('Dinas B', ['uraian' => 'Milik B']);

        // Instansi A: daftar kosong & tidak bisa mengisi transaksi instansi B.
        $this->actingAs($a)->getJson('/api/v1/aklap/register-ppkd')->assertOk()->assertJsonPath('total', 0);

        $this->actingAs($a)->postJson('/api/v1/aklap/register-ppkd', ['buku_kas_id' => $milikB])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Transaksi tidak ditemukan pada Buku Pengeluaran PPKD Anda.');

        $this->assertSame(0, DB::table('register_ppkd')->count());

        $this->actingAs($a)->getJson('/api/v1/aklap/lpj-ppkd?tahun=2026&bulan=3')
            ->assertOk()
            ->assertJsonPath('jumlah_baris', 0)
            ->assertJsonPath('pengeluaran.jumlah.bulan_ini', 0);

        // Instansi B melihat datanya sendiri.
        $this->actingAs($b)->getJson('/api/v1/aklap/register-ppkd')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_register_lpj_menolak_tanpa_login(): void
    {
        $this->getJson('/api/v1/aklap/register-ppkd')->assertStatus(401);
        $this->getJson('/api/v1/aklap/lpj-ppkd')->assertStatus(401);
        $this->postJson('/api/v1/aklap/register-ppkd', ['buku_kas_id' => 1])->assertStatus(401);
    }
}
