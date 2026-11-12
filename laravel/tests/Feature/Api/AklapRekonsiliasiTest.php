<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tes modul AKLAP — Rekonsiliasi Bank (blanko Modul-3E-LK).
 *
 * Diporting dari peta.simtkd.com/api/akuntansi.php (`rekonsiliasi_bank`,
 * `_daftar`, `_simpan`, `_hapus`). Satu dokumen per bulan per instansi.
 *
 * Fokus: saran "Saldo Kas menurut Buku" dari Buku Kas s.d. akhir bulan,
 * default `tgl_ttd` akhir bulan, parser angka manual, baris kosong dibuang,
 * batas 60 baris per bagian, serta subtotal (A+B vs C+D).
 *
 * Paritas dengan legacy diuji lewat `tools/difftest/run.php --filter=aklap`
 * dan `tools/difftest/e2e-aklap.php`.
 */
class AklapRekonsiliasiTest extends TestCase
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

    private function bukuKas(string $instansi, string $tanggal, string $jenis, float $jumlah): void
    {
        DB::table('buku_kas')->insert([
            'skpd' => $instansi, 'tanggal' => $tanggal, 'jenis' => $jenis,
            'no_bukti' => 'BK', 'kode_rekening' => '4.1.4.01', 'uraian' => 'Uji',
            'jumlah' => number_format($jumlah, 2, '.', ''),
        ]);
    }

    public function test_dokumen_belum_tersimpan_memakai_periode_dan_saran_saldo_buku(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        // Tahun harus 4 digit & bulan 1-12; selain itu -> periode hari ini.
        $this->actingAs($a)->getJson('/api/v1/aklap/rekonsiliasi-bank?tahun=20&bulan=99')
            ->assertOk()
            ->assertJsonPath('tahun', (int) date('Y'))
            ->assertJsonPath('bulan', (int) date('n'))
            ->assertJsonPath('periode', $this->namaBulan((int) date('n')));

        // Buku kas s.d. akhir bulan terpilih menjadi saran saldo buku.
        $this->bukuKas('Dinas A', '2026-03-02', 'penerimaan', 200000);
        $this->bukuKas('Dinas A', '2026-03-10', 'pengeluaran', 75000);
        $this->bukuKas('Dinas A', '2026-04-05', 'penerimaan', 300000);   // di luar periode

        $this->actingAs($a)->getJson('/api/v1/aklap/rekonsiliasi-bank?tahun=2026&bulan=3')
            ->assertOk()
            ->assertJsonPath('periode', 'Maret')
            ->assertJsonPath('tgl_ttd', '2026-03-31')
            ->assertJsonPath('tersimpan', false)
            ->assertJsonPath('saran_saldo_buku', 125000)
            ->assertJsonPath('saldo_buku', 0)
            ->assertJsonPath('saldo_bank', 0)
            ->assertJsonPath('selisih', 0)
            ->assertJsonPath('total_buku', 0)
            ->assertJsonPath('total_bank', 0)
            ->assertJsonPath('catatan', '')
            ->assertJsonPath('instansi', 'Dinas A')
            ->assertJsonPath('bendahara', 'Bendahara Dinas A')
            ->assertJsonPath('bagian.A', []);
    }

    /** Nama bulan Bahasa Indonesia (bantu assertion periode default). */
    private function namaBulan(int $bulan): string
    {
        return ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
            'Agustus', 'September', 'Oktober', 'November', 'Desember'][$bulan] ?? '';
    }

    public function test_simpan_validasi_subtotal_dan_ganti_rincian(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($a)->postJson('/api/v1/aklap/rekonsiliasi-bank', ['tahun' => '1999', 'bulan' => '13'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Periode (tahun / bulan) tidak valid.');

        $this->actingAs($a)->postJson('/api/v1/aklap/rekonsiliasi-bank', ['tahun' => '2026', 'bulan' => '3', 'items' => 'bukan-json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Data rincian keterangan selisih tidak valid.');

        $this->actingAs($a)->postJson('/api/v1/aklap/rekonsiliasi-bank', [
            'tahun' => '2026', 'bulan' => '3', 'items' => json_encode(['B' => [['uraian' => 'Uji', 'nilai' => '-1']]]),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Nilai tidak boleh negatif (bagian B baris 1).');

        $this->actingAs($a)->postJson('/api/v1/aklap/rekonsiliasi-bank', [
            'tahun' => '2026', 'bulan' => '3', 'items' => json_encode(['C' => array_fill(0, 61, ['uraian' => 'x', 'nilai' => '1'])]),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Baris bagian C melebihi batas 60 baris.');

        $this->assertSame(0, DB::table('rekonsiliasi_bank')->count());

        // Simpan pertama: tgl_ttd default akhir bulan, baris kosong dibuang.
        $res = $this->actingAs($a)->postJson('/api/v1/aklap/rekonsiliasi-bank', [
            'tahun'      => '2026',
            'bulan'      => '3',
            'tgl_ttd'    => 'bukan-tanggal',
            'saldo_buku' => '10.000.000',
            'saldo_bank' => '9.000.000,25',
            'catatan'    => 'Catatan uji',
            'items'      => json_encode([
                'A' => [['uraian' => 'Setoran belum tercatat', 'nilai' => '1.000.000']],
                'B' => [['uraian' => '', 'nilai' => '0']],
                'C' => [['uraian' => 'Biaya bank', 'nilai' => '25.000,50']],
                'D' => [['uraian' => 'Kliring belum cair', 'nilai' => '500000']],
            ]),
        ])->assertOk();

        $this->assertSame('Rekonsiliasi Bank tersimpan (3 baris rincian).', (string) $res->json('message'));
        $this->assertSame('2026-03-31', (string) $res->json('tgl_ttd'));
        $this->assertSame('Maret', (string) $res->json('periode'));
        $this->assertEqualsWithDelta(10000000.0, (float) $res->json('saldo_buku'), 0.001);
        $this->assertEqualsWithDelta(9000000.25, (float) $res->json('saldo_bank'), 0.001);
        $this->assertEqualsWithDelta(999999.75, (float) $res->json('selisih'), 0.001);
        $this->assertEqualsWithDelta(1000000.0, (float) $res->json('subtotal.A'), 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $res->json('subtotal.B'), 0.001);
        $this->assertEqualsWithDelta(25000.5, (float) $res->json('subtotal.C'), 0.001);
        $this->assertEqualsWithDelta(500000.0, (float) $res->json('subtotal.D'), 0.001);
        $this->assertEqualsWithDelta(1000000.0, (float) $res->json('total_buku'), 0.001);
        $this->assertEqualsWithDelta(525000.5, (float) $res->json('total_bank'), 0.001);
        $this->assertTrue((bool) $res->json('tersimpan'));
        $this->assertSame(3, DB::table('rekonsiliasi_bank_item')->count());

        $id = (int) $res->json('laporan_id');

        // Periode lain -> dokumen terpisah.
        $this->actingAs($a)->postJson('/api/v1/aklap/rekonsiliasi-bank', [
            'tahun' => '2026', 'bulan' => '4', 'items' => json_encode(['A' => [['uraian' => 'April', 'nilai' => '1000']]]),
        ])->assertOk()->assertJsonPath('periode', 'April');

        $this->assertSame(2, DB::table('rekonsiliasi_bank')->count());

        // Simpan ulang periode Maret -> rincian lama diganti (bukan ditambah).
        $ulang = $this->actingAs($a)->postJson('/api/v1/aklap/rekonsiliasi-bank', [
            'tahun' => '2026', 'bulan' => '3', 'tgl_ttd' => '2026-04-02',
            'saldo_buku' => '11000000', 'saldo_bank' => '11000000',
            'items' => json_encode(['A' => [['uraian' => 'Satu baris', 'nilai' => '1500000']]]),
        ])->assertOk();

        $this->assertSame($id, (int) $ulang->json('laporan_id'));
        $this->assertSame('Rekonsiliasi Bank tersimpan (1 baris rincian).', (string) $ulang->json('message'));
        $this->assertEqualsWithDelta(0.0, (float) $ulang->json('selisih'), 0.001);
        $this->assertSame('2026-04-02', (string) $ulang->json('tgl_ttd'));
        $this->assertSame(1, DB::table('rekonsiliasi_bank_item')->where('rekonsiliasi_id', $id)->count());
        $this->assertSame(2, DB::table('rekonsiliasi_bank')->count());
    }

    public function test_daftar_hapus_dan_batas_instansi(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        // Dokumen instansi B tidak terlihat oleh instansi A.
        DB::table('rekonsiliasi_bank')->insert([
            'skpd' => 'Dinas B', 'tahun' => 2026, 'bulan' => 3, 'periode' => 'Maret',
            'tgl_ttd' => '2026-03-31', 'saldo_buku' => '1000000.00', 'saldo_bank' => '750000.00',
        ]);

        $this->actingAs($a)->getJson('/api/v1/aklap/rekonsiliasi-bank/daftar')
            ->assertOk()
            ->assertJsonPath('total', 0);

        // Simpan dokumen instansi A, lalu daftar memuat selisih & jumlah baris.
        $res = $this->actingAs($a)->postJson('/api/v1/aklap/rekonsiliasi-bank', [
            'tahun' => '2026', 'bulan' => '3',
            'saldo_buku' => '5000000', 'saldo_bank' => '4750000',
            'items' => json_encode(['D' => [['uraian' => 'Kliring', 'nilai' => '250000']]]),
        ])->assertOk();

        $this->actingAs($a)->getJson('/api/v1/aklap/rekonsiliasi-bank/daftar')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.tahun', 2026)
            ->assertJsonPath('data.0.bulan', 3)
            ->assertJsonPath('data.0.periode', 'Maret')
            ->assertJsonPath('data.0.baris', 1)
            ->assertJsonPath('data.0.selisih', 250000);

        // Hapus: validasi periode, lalu penghapusan dokumen + rinciannya.
        $this->actingAs($a)->postJson('/api/v1/aklap/rekonsiliasi-bank/hapus', ['tahun' => '0', 'bulan' => '0'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Periode (tahun / bulan) tidak valid.');

        $this->actingAs($a)->postJson('/api/v1/aklap/rekonsiliasi-bank/hapus', ['tahun' => '2026', 'bulan' => '11'])
            ->assertOk()
            ->assertJsonPath('message', 'Rekonsiliasi periode tersebut belum ada.');

        // Instansi B tidak bisa menghapus periode yang hanya dimiliki instansi A
        // (dan sebaliknya dokumen B sendiri juga tidak tersentuh dari sisi A).
        $this->actingAs($b)->postJson('/api/v1/aklap/rekonsiliasi-bank/hapus', ['tahun' => '2026', 'bulan' => '5'])
            ->assertOk()
            ->assertJsonPath('message', 'Rekonsiliasi periode tersebut belum ada.');

        $this->assertSame(1, DB::table('rekonsiliasi_bank')->where('skpd', 'Dinas A')->count());
        $this->assertSame(1, DB::table('rekonsiliasi_bank')->where('skpd', 'Dinas B')->count());

        $this->actingAs($a)->postJson('/api/v1/aklap/rekonsiliasi-bank/hapus', ['tahun' => '2026', 'bulan' => '3'])
            ->assertOk()
            ->assertJsonPath('message', 'Rekonsiliasi Bank periode Maret 2026 dihapus.');

        $this->assertSame(0, DB::table('rekonsiliasi_bank')->where('id', (int) $res->json('laporan_id'))->count());
        $this->assertSame(0, DB::table('rekonsiliasi_bank_item')->where('rekonsiliasi_id', (int) $res->json('laporan_id'))->count());

        // Dokumen instansi B tetap utuh.
        $this->assertSame(1, DB::table('rekonsiliasi_bank')->where('skpd', 'Dinas B')->count());
    }

    public function test_rekonsiliasi_menolak_tanpa_login(): void
    {
        $this->getJson('/api/v1/aklap/rekonsiliasi-bank')->assertStatus(401);
        $this->getJson('/api/v1/aklap/rekonsiliasi-bank/daftar')->assertStatus(401);
        $this->postJson('/api/v1/aklap/rekonsiliasi-bank', ['tahun' => 2026, 'bulan' => 3])->assertStatus(401);
        $this->postJson('/api/v1/aklap/rekonsiliasi-bank/hapus', ['tahun' => 2026, 'bulan' => 3])->assertStatus(401);
    }
}
