<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tes modul AKLAP — laporan akuntansi (baca-saja).
 *
 * Diporting dari peta.simtkd.com/api/akuntansi.php: buku besar, neraca saldo,
 * buku kas (daftar & BUD), buku pembantu (per objek & pemilih objek).
 *
 * Fokus: hanya jurnal `sudah_approve` yang diposting, saldo awal dari
 * neraca_awal ikut dihitung, saldo berjalan buku kas, dan rentang bulan pada
 * buku pembantu. Paritas dengan legacy diuji lewat
 * tools/difftest/run.php --filter=aklap dan tools/difftest/e2e-aklap.php.
 */
class AklapLaporanTest extends TestCase
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

    /** Isi Master Akun (dipakai nama akun & saldo normal laporan). */
    private function isiMaster(string $instansi): void
    {
        DB::table('akun_master')->insert([
            ['skpd' => $instansi, 'kode_akun' => '1.1.1.01', 'nama_akun' => 'Kas di Bendahara Penerimaan', 'tipe' => 'aset', 'saldo_normal' => 'debet'],
            ['skpd' => $instansi, 'kode_akun' => '1.1.2.01', 'nama_akun' => 'Rekening Bank SKPD', 'tipe' => 'aset', 'saldo_normal' => 'debet'],
            ['skpd' => $instansi, 'kode_akun' => '4.1.4.01', 'nama_akun' => 'Pendapatan Lain-lain PAD', 'tipe' => 'pendapatan', 'saldo_normal' => 'kredit'],
        ]);
    }

    /**
     * Jurnal umum + rincian langsung ke basis data (tanpa lewat endpoint).
     *
     * @param  array<int, array{0: string, 1: string, 2: float}>  $baris  [kode_akun, posisi, jumlah]
     */
    private function jurnal(string $instansi, string $tanggal, array $baris, string $status = 'sudah_approve', string $uraian = 'Jurnal uji'): int
    {
        $nomor = 'JU-TEST-' . random_int(1000, 9999);

        $id = (int) DB::table('jurnal_umum')->insertGetId([
            'skpd'         => $instansi,
            'nomor_jurnal' => $nomor,
            'tanggal'      => $tanggal,
            'uraian'       => $uraian,
            'sumber_tipe'  => 'manual',
            'status'       => $status,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        foreach ($baris as [$kode, $posisi, $jumlah]) {
            DB::table('jurnal_umum_detail')->insert([
                'jurnal_id'  => $id,
                'skpd'       => $instansi,
                'kode_akun'  => $kode,
                'nama_akun'  => '',
                'posisi'     => $posisi,
                'jumlah'     => number_format($jumlah, 2, '.', ''),
                'created_at' => now(),
            ]);
        }

        return $id;
    }

    private function bukuKas(string $instansi, string $tanggal, string $jenis, string $kode, float $jumlah, string $noBukti = 'BK'): void
    {
        DB::table('buku_kas')->insert([
            'skpd'          => $instansi,
            'tanggal'       => $tanggal,
            'jenis'         => $jenis,
            'no_bukti'      => $noBukti,
            'kode_rekening' => $kode,
            'uraian'        => 'Uraian ' . $noBukti,
            'jumlah'        => number_format($jumlah, 2, '.', ''),
        ]);
    }

    public function test_buku_besar_validasi_saldo_awal_dan_hanya_jurnal_approve(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $this->isiMaster('Dinas A');

        $this->actingAs($a)->getJson('/api/v1/aklap/buku-besar')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pilih kode akun terlebih dahulu.');

        DB::table('neraca_awal')->insert([
            'skpd' => 'Dinas A', 'tahun' => '2026', 'kode_akun' => '1.1.1.01',
            'nama_akun' => 'Kas', 'saldo' => '50000.00', 'jenis' => 'aset',
        ]);

        $this->jurnal('Dinas A', '2026-03-10', [['1.1.1.01', 'debet', 150000], ['4.1.4.01', 'kredit', 150000]]);
        // Jurnal draft tidak boleh masuk laporan.
        $this->jurnal('Dinas A', '2026-03-11', [['1.1.1.01', 'debet', 999999], ['4.1.4.01', 'kredit', 999999]], 'draft');

        $this->actingAs($a)->getJson('/api/v1/aklap/buku-besar?kode_akun=1.1.1.01')
            ->assertOk()
            ->assertJsonPath('kode_akun', '1.1.1.01')
            ->assertJsonPath('nama_akun', 'Kas di Bendahara Penerimaan')
            ->assertJsonPath('saldo_normal', 'debet')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.ref', 'MANUAL')
            ->assertJsonPath('data.0.tanggal', '2026-03-10')
            ->assertJsonPath('data.0.saldo_posisi', 'debet')
            ->assertJsonPath('saldo_awal', 50000)
            ->assertJsonPath('total_debet', 150000)
            ->assertJsonPath('total_kredit', 0)
            ->assertJsonPath('saldo_akhir', 200000);

        // Filter periode: posting di luar rentang tidak ikut.
        $this->actingAs($a)->getJson('/api/v1/aklap/buku-besar?kode_akun=1.1.1.01&dari=2026-04-01')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('saldo_akhir', 50000);

        // Tanggal mustahil diabaikan (bukan galat) -> sama dengan tanpa filter.
        $this->actingAs($a)->getJson('/api/v1/aklap/buku-besar?kode_akun=1.1.1.01&dari=2026-13-99&akhir=ngawur')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_neraca_saldo_mutasi_saldo_awal_dan_akun_luar_master(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $this->isiMaster('Dinas A');

        DB::table('neraca_awal')->insert([
            'skpd' => 'Dinas A', 'tahun' => '2026', 'kode_akun' => '1.1.2.01',
            'nama_akun' => 'Bank', 'saldo' => '75000.00', 'jenis' => 'aset',
        ]);

        $this->jurnal('Dinas A', '2026-03-10', [
            ['1.1.1.01', 'debet', 100000],
            ['4.1.4.01', 'kredit', 60000],
            ['4.1.9.99', 'kredit', 40000],
        ]);

        $res = $this->actingAs($a)->getJson('/api/v1/aklap/neraca-saldo')->assertOk();

        $rows = [];

        foreach ((array) $res->json('data') as $r) {
            $rows[(string) $r['kode_akun']] = $r;
        }

        // Akun master: saldo normal menentukan arah saldo akhir.
        $this->assertEqualsWithDelta(100000.0, (float) $rows['1.1.1.01']['mutasi_debet'], 0.001);
        $this->assertEqualsWithDelta(100000.0, (float) $rows['1.1.1.01']['saldo_akhir'], 0.001);
        $this->assertEqualsWithDelta(75000.0, (float) $rows['1.1.2.01']['saldo_awal'], 0.001);
        $this->assertEqualsWithDelta(75000.0, (float) $rows['1.1.2.01']['saldo_akhir'], 0.001);
        $this->assertEqualsWithDelta(60000.0, (float) $rows['4.1.4.01']['saldo_akhir'], 0.001);

        // Akun ber-mutasi di luar Master Akun: ditambahkan sebagai pendapatan.
        $this->assertArrayHasKey('4.1.9.99', $rows);
        $this->assertSame('pendapatan', $rows['4.1.9.99']['tipe']);
        $this->assertSame('kredit', $rows['4.1.9.99']['saldo_normal']);
        $this->assertEqualsWithDelta(40000.0, (float) $rows['4.1.9.99']['saldo_akhir'], 0.001);

        // Urut menurut kode akun.
        $kode = array_keys($rows);
        $urut = $kode;
        sort($urut, SORT_STRING);
        $this->assertSame($urut, $kode);
    }

    public function test_buku_kas_saldo_berjalan_dan_filter_jenis_periode(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $this->bukuKas('Dinas A', '2026-03-02', 'penerimaan', '4.1.4.01', 200000, 'BK-1');
        $this->bukuKas('Dinas A', '2026-03-10', 'pengeluaran', '5.1.2.01', 75000, 'BK-2');
        $this->bukuKas('Dinas A', '2026-04-05', 'penerimaan', '4.1.4.01', 300000, 'BK-3');

        $this->actingAs($a)->getJson('/api/v1/aklap/buku-kas')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('total_penerimaan', 500000)
            ->assertJsonPath('total_pengeluaran', 75000)
            ->assertJsonPath('saldo_akhir', 425000)
            ->assertJsonPath('data.0.no_bukti', 'BK-1')
            ->assertJsonPath('data.0.saldo', 200000)
            ->assertJsonPath('data.1.saldo', 125000)
            ->assertJsonPath('data.2.saldo', 425000);

        $this->actingAs($a)->getJson('/api/v1/aklap/buku-kas?jenis=pengeluaran')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.no_bukti', 'BK-2');

        $this->actingAs($a)->getJson('/api/v1/aklap/buku-kas?dari=2026-03-01&akhir=2026-03-31')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('saldo_akhir', 125000);
    }

    public function test_buku_kas_umum_saldo_awal_periode_dan_timpaan_manual(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $this->bukuKas('Dinas A', '2026-02-20', 'penerimaan', '4.1.4.01', 90000, 'BK-0');
        $this->bukuKas('Dinas A', '2026-03-02', 'penerimaan', '4.1.4.01', 200000, 'BK-1');
        $this->bukuKas('Dinas A', '2026-03-10', 'pengeluaran', '5.1.2.01', 75000, 'BK-2');

        // Saldo awal = posisi kas sebelum tanggal awal periode.
        $this->actingAs($a)->getJson('/api/v1/aklap/buku-kas-umum?dari=2026-03-01')
            ->assertOk()
            ->assertJsonPath('dari', '2026-03-01')
            ->assertJsonPath('akhir', '')
            ->assertJsonPath('saldo_awal', 90000)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('saldo_akhir', 215000)
            ->assertJsonPath('instansi', 'Dinas A')
            ->assertJsonPath('kota', '')
            ->assertJsonPath('bendahara', 'Bendahara Dinas A');

        // Bisa ditimpa manual dengan format Rupiah.
        $this->actingAs($a)->getJson('/api/v1/aklap/buku-kas-umum?dari=2026-03-01&saldo_awal=1.500.000,50')
            ->assertOk()
            ->assertJsonPath('saldo_awal', 1500000.5)
            ->assertJsonPath('saldo_akhir', 1625000.5);

        // Nilai manual yang bukan angka diabaikan.
        $this->actingAs($a)->getJson('/api/v1/aklap/buku-kas-umum?dari=2026-03-01&saldo_awal=bukan-angka')
            ->assertOk()
            ->assertJsonPath('saldo_awal', 90000);
    }

    public function test_buku_pembantu_rentang_bulan_pagu_dan_validasi(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $this->isiMaster('Dinas A');

        $this->bukuKas('Dinas A', '2026-03-02', 'penerimaan', '4.1.4.01', 200000, 'BK-1');
        $this->bukuKas('Dinas A', '2026-03-20', 'penerimaan', '4.1.4.01', 50000, 'BK-2');
        $this->bukuKas('Dinas A', '2026-04-05', 'penerimaan', '4.1.4.01', 300000, 'BK-3');
        // Objek tanpa Master Akun -> nama diambil dari uraian buku kas terakhir.
        $this->bukuKas('Dinas A', '2026-04-06', 'penerimaan', '9.9.9.99', 10000, 'BK-4');

        DB::table('anggaran_lra')->insert([
            'skpd' => 'Dinas A', 'tahun' => '2026', 'kode_akun' => '4.1.4.01', 'anggaran' => '5000000.00',
        ]);

        $this->actingAs($a)->getJson('/api/v1/aklap/buku-pembantu?jenis=&kode_akun=4.1.4.01')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pilih jenis (penerimaan / pengeluaran).');

        $this->actingAs($a)->getJson('/api/v1/aklap/buku-pembantu?jenis=penerimaan&kode_akun=')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pilih kode rekening rincian objek.');

        // April: 1 baris, s.d. bulan lalu 250.000, s.d. bulan ini 550.000.
        $this->actingAs($a)->getJson('/api/v1/aklap/buku-pembantu?jenis=penerimaan&kode_akun=4.1.4.01&bulan=4&tahun=2026')
            ->assertOk()
            ->assertJsonPath('nama_akun', 'Pendapatan Lain-lain PAD')
            ->assertJsonPath('bulan', 4)
            ->assertJsonPath('tahun', 2026)
            ->assertJsonPath('skpd', 'Dinas A')
            ->assertJsonPath('bendahara', 'Bendahara Dinas A')
            ->assertJsonPath('nip', '')
            ->assertJsonPath('ttd_gambar', '')
            ->assertJsonPath('anggaran', 5000000)
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.no_bukti', 'BK-3')
            ->assertJsonPath('jumlah_bulan_ini', 300000)
            ->assertJsonPath('jumlah_sd_bulan_lalu', 250000)
            ->assertJsonPath('jumlah_sd_bulan_ini', 550000);

        // Nama akun fallback dari uraian buku kas bila tidak ada di Master Akun.
        $this->actingAs($a)->getJson('/api/v1/aklap/buku-pembantu?jenis=penerimaan&kode_akun=9.9.9.99&bulan=4&tahun=2026')
            ->assertOk()
            ->assertJsonPath('nama_akun', 'Uraian BK-4')
            ->assertJsonPath('anggaran', 0);

        // Bulan/tahun di luar rentang dikembalikan ke nilai hari ini.
        $this->actingAs($a)->getJson('/api/v1/aklap/buku-pembantu?jenis=penerimaan&kode_akun=4.1.4.01&bulan=13&tahun=1999')
            ->assertOk()
            ->assertJsonPath('bulan', (int) date('n'))
            ->assertJsonPath('tahun', (int) date('Y'));
    }

    public function test_buku_pembantu_obyek_menjumlahkan_per_kode_dan_menolak_jenis_salah(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $this->bukuKas('Dinas A', '2026-03-02', 'penerimaan', '4.1.4.01', 200000, 'BK-1');
        $this->bukuKas('Dinas A', '2026-04-05', 'penerimaan', '4.1.4.01', 300000, 'BK-3');
        $this->bukuKas('Dinas A', '2026-03-10', 'pengeluaran', '5.1.2.01', 75000, 'BK-2');
        // Objek kosong tidak ikut terdaftar.
        $this->bukuKas('Dinas A', '2026-03-11', 'penerimaan', '', 1000, 'BK-9');

        $this->actingAs($a)->getJson('/api/v1/aklap/buku-pembantu-obyek?jenis=salah')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pilih jenis (penerimaan / pengeluaran).');

        $this->actingAs($a)->getJson('/api/v1/aklap/buku-pembantu-obyek?jenis=penerimaan')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('obyek.0.kode_akun', '4.1.4.01')
            ->assertJsonPath('obyek.0.total', 500000);

        $this->actingAs($a)->getJson('/api/v1/aklap/buku-pembantu-obyek?jenis=pengeluaran')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('obyek.0.kode_akun', '5.1.2.01')
            ->assertJsonPath('obyek.0.total', 75000);
    }

    public function test_laporan_terbatas_instansi_dan_data_instansi_lain_utuh(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $this->buatUser('Dinas B', 'ub');

        $this->jurnal('Dinas B', '2026-03-10', [['1.1.1.01', 'debet', 500000], ['4.1.4.01', 'kredit', 500000]]);
        $this->bukuKas('Dinas B', '2026-03-02', 'penerimaan', '4.1.4.01', 250000, 'BK-B');

        // Instansi A tidak melihat data instansi B.
        $this->actingAs($a)->getJson('/api/v1/aklap/buku-besar?kode_akun=1.1.1.01')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('saldo_akhir', 0);

        // Neraca saldo instansi A: COA ringkas di-seed otomatis, tetapi seluruh
        // mutasi nol (data instansi B tidak bocor).
        $ns = $this->actingAs($a)->getJson('/api/v1/aklap/neraca-saldo')->assertOk();

        $this->assertGreaterThanOrEqual(13, (int) $ns->json('total'));

        foreach ((array) $ns->json('data') as $r) {
            $this->assertSame(0.0, (float) $r['mutasi_debet']);
            $this->assertSame(0.0, (float) $r['mutasi_kredit']);
            $this->assertSame(0.0, (float) $r['saldo_akhir']);
        }

        $this->actingAs($a)->getJson('/api/v1/aklap/buku-kas')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('saldo_akhir', 0);

        $this->actingAs($a)->getJson('/api/v1/aklap/buku-pembantu-obyek?jenis=penerimaan')
            ->assertOk()
            ->assertJsonPath('total', 0);

        // Data instansi B tetap utuh.
        $this->assertSame(1, DB::table('buku_kas')->where('skpd', 'Dinas B')->count());
        $this->assertSame(1, DB::table('jurnal_umum')->where('skpd', 'Dinas B')->count());
    }

    public function test_laporan_menolak_tanpa_login(): void
    {
        foreach ([
            '/api/v1/aklap/buku-besar?kode_akun=1.1.1.01',
            '/api/v1/aklap/neraca-saldo',
            '/api/v1/aklap/buku-kas',
            '/api/v1/aklap/buku-kas-umum',
            '/api/v1/aklap/buku-pembantu?jenis=penerimaan&kode_akun=4.1.4.01',
            '/api/v1/aklap/buku-pembantu-obyek?jenis=penerimaan',
        ] as $url) {
            $this->getJson($url)->assertStatus(401);
        }
    }
}
