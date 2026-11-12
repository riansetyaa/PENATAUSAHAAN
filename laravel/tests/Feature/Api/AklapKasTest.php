<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tes modul AKLAP — mutasi kas: Buku Kas manual & Laporan Posisi Kas Harian.
 *
 * Diporting dari peta.simtkd.com/api/akuntansi.php (`buku_kas_simpan`,
 * `buku_kas_hapus`, `kas_harian`, `kas_harian_daftar`, `kas_harian_simpan`,
 * `kas_harian_hapus`). Fokus pada kesetiaan perilaku legacy:
 *  - `jumlah` buku kas dibaca sebagai float mentah (bukan parser angka manual);
 *  - kas harian memakai parser angka manual ("1.000,50" -> 1000.5);
 *  - total & posisi kas harian dihitung server, baris kosong dibuang;
 *  - posisi H-1 disarankan dari dokumen terdekat sebelumnya.
 *
 * Paritas dengan legacy diuji lewat `tools/difftest/run.php --filter=aklap`
 * dan `tools/difftest/e2e-aklap.php`.
 */
class AklapKasTest extends TestCase
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
    private function entri(string $instansi, array $ubah = []): int
    {
        return (int) DB::table('buku_kas')->insertGetId(array_merge([
            'skpd'          => $instansi,
            'tanggal'       => '2026-03-02',
            'jenis'         => 'penerimaan',
            'no_bukti'      => 'BK-1',
            'kode_rekening' => '4.1.4.01',
            'uraian'        => 'Setoran',
            'jumlah'        => '200000.00',
        ], $ubah));
    }

    public function test_buku_kas_simpan_validasi_dan_tambah_ubah_hapus(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($a)->postJson('/api/v1/aklap/buku-kas', ['tanggal' => '2026-13-99', 'jenis' => 'penerimaan', 'uraian' => 'Uji'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tanggal wajib diisi dengan benar.');

        $this->actingAs($a)->postJson('/api/v1/aklap/buku-kas', ['tanggal' => '2026-03-02', 'jenis' => '', 'uraian' => 'Uji'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jenis transaksi wajib dipilih.');

        $this->actingAs($a)->postJson('/api/v1/aklap/buku-kas', ['tanggal' => '2026-03-02', 'jenis' => 'penerimaan', 'uraian' => '   '])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Uraian wajib diisi.');

        $this->actingAs($a)->postJson('/api/v1/aklap/buku-kas', ['tanggal' => '2026-03-02', 'jenis' => 'penerimaan', 'uraian' => 'Uji', 'jumlah' => '0'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jumlah harus lebih besar dari nol.');

        $this->assertSame(0, DB::table('buku_kas')->count());

        // Tambah baru.
        $res = $this->actingAs($a)->postJson('/api/v1/aklap/buku-kas', [
            'tanggal'       => '2026-03-02',
            'jenis'         => 'penerimaan',
            'no_bukti'      => 'BK-9',
            'kode_rekening' => ' 4.1.4.01 ',
            'uraian'        => ' Setoran pajak ',
            'jumlah'        => '200000',
        ])->assertOk()->assertJsonPath('message', 'Transaksi tersimpan.');

        $id  = (int) $res->json('id');
        $row = (array) DB::table('buku_kas')->where('id', $id)->first();

        $this->assertSame('Dinas A', $row['skpd']);
        $this->assertSame('BK-9', $row['no_bukti']);
        $this->assertSame('4.1.4.01', $row['kode_rekening']);
        $this->assertSame('Setoran pajak', $row['uraian']);   // dipangkas (trim)
        $this->assertSame('200000.00', $row['jumlah']);

        // Ubah entri yang sama (tanpa membuat baris baru).
        $this->actingAs($a)->postJson('/api/v1/aklap/buku-kas', [
            'id' => $id, 'tanggal' => '2026-03-03', 'jenis' => 'pengeluaran',
            'uraian' => 'Belanja ATK', 'jumlah' => '175000',
        ])->assertOk()->assertJsonPath('message', 'Transaksi diperbarui.')->assertJsonPath('id', $id);

        $this->assertSame(1, DB::table('buku_kas')->count());
        $row = (array) DB::table('buku_kas')->where('id', $id)->first();
        $this->assertSame('pengeluaran', $row['jenis']);
        $this->assertSame('175000.00', $row['jumlah']);

        // Hapus.
        $this->actingAs($a)->postJson('/api/v1/aklap/buku-kas/hapus', ['id' => 0])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ID transaksi tidak valid.');

        $this->actingAs($a)->postJson('/api/v1/aklap/buku-kas/hapus', ['id' => $id])
            ->assertOk()
            ->assertJsonPath('message', 'Transaksi dihapus.');

        $this->actingAs($a)->postJson('/api/v1/aklap/buku-kas/hapus', ['id' => $id])
            ->assertOk()
            ->assertJsonPath('message', 'Transaksi tidak ditemukan.');
    }

    public function test_buku_kas_jumlah_float_mentah_dan_terbatas_instansi(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        // Kekhasan legacy: `jumlah` di-cast (float) MENTAH sehingga
        // "1.000,50" terbaca 1 (bukan 1000,5) — dipertahankan apa adanya.
        $this->actingAs($a)->postJson('/api/v1/aklap/buku-kas', [
            'tanggal' => '2026-03-02', 'jenis' => 'penerimaan', 'uraian' => 'Uji', 'jumlah' => '1.000,50',
        ])->assertOk();

        $this->assertSame('1.00', (string) DB::table('buku_kas')->where('skpd', 'Dinas A')->value('jumlah'));

        // Instansi lain tidak boleh mengubah/menghapus entri instansi ini.
        $id = $this->entri('Dinas A', ['uraian' => 'Milik A']);

        $this->actingAs($b)->postJson('/api/v1/aklap/buku-kas', [
            'id' => $id, 'tanggal' => '2026-03-05', 'jenis' => 'pengeluaran', 'uraian' => 'Diubah pihak lain', 'jumlah' => '999',
        ])->assertOk(); // legacy selalu menjawab "diperbarui" walau 0 baris terpengaruh

        $this->assertSame('Milik A', (string) DB::table('buku_kas')->where('id', $id)->value('uraian'));

        $this->actingAs($b)->postJson('/api/v1/aklap/buku-kas/hapus', ['id' => $id])
            ->assertOk()
            ->assertJsonPath('message', 'Transaksi tidak ditemukan.');

        $this->assertSame(1, DB::table('buku_kas')->where('id', $id)->count());
    }

    public function test_kas_harian_dokumen_kosong_dan_saran_posisi_h1(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($a)->getJson('/api/v1/aklap/kas-harian?tanggal=2026-02-30')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tanggal laporan tidak valid (format YYYY-MM-DD).');

        // Belum ada dokumen -> hari/periode dihitung, posisi nol.
        $this->actingAs($a)->getJson('/api/v1/aklap/kas-harian?tanggal=2026-03-18')
            ->assertOk()
            ->assertJsonPath('tanggal', '2026-03-18')
            ->assertJsonPath('hari', 'Rabu')
            ->assertJsonPath('periode', 'Maret')
            ->assertJsonPath('sumber_posisi_h1', 'nol')
            ->assertJsonPath('posisi_h1', 0)
            ->assertJsonPath('posisi_h', 0)
            ->assertJsonPath('tersimpan', false)
            ->assertJsonPath('bank1_nama', 'Saldo di Bank 1')
            ->assertJsonPath('instansi', 'Dinas A')
            ->assertJsonPath('bendahara', 'Bendahara Dinas A');

        // Dokumen hari sebelumnya -> posisi H-1 disarankan dari posisi H-nya.
        DB::table('kas_harian')->insert([
            'skpd' => 'Dinas A', 'tanggal' => '2026-03-17', 'hari' => 'Selasa', 'periode' => 'Maret',
            'posisi_h1' => '1000000.00', 'total_penerimaan' => '500000.00', 'total_pengeluaran' => '250000.00',
            'posisi_h' => '1250000.00',
        ]);

        $this->actingAs($a)->getJson('/api/v1/aklap/kas-harian?tanggal=2026-03-19')
            ->assertOk()
            ->assertJsonPath('sumber_posisi_h1', 'hari_sebelumnya')
            ->assertJsonPath('posisi_h1_dari', '2026-03-17')
            ->assertJsonPath('posisi_h1', 1250000)
            ->assertJsonPath('posisi_h', 1250000);

        // Dokumen menjadi tersimpan -> posisi diambil dari nilai tersimpan,
        // sedangkan `posisi_h` tetap DIHITUNG ULANG dari barisnya (perilaku
        // legacy): tanpa baris, posisi_h = posisi_h1. Nilai tersimpan pada
        // kolom `posisi_h` itulah yang dipakai sebagai saran H-1 hari berikutnya.
        $this->actingAs($a)->getJson('/api/v1/aklap/kas-harian?tanggal=2026-03-17')
            ->assertOk()
            ->assertJsonPath('sumber_posisi_h1', 'tersimpan')
            ->assertJsonPath('tersimpan', true)
            ->assertJsonPath('posisi_h1', 1000000)
            ->assertJsonPath('posisi_h', 1000000);
    }

    public function test_kas_harian_simpan_daftar_dan_hapus(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($a)->postJson('/api/v1/aklap/kas-harian', ['tanggal' => ''])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tanggal laporan wajib diisi dengan benar (YYYY-MM-DD).');

        $this->actingAs($a)->postJson('/api/v1/aklap/kas-harian', ['tanggal' => '2026-03-18', 'items' => '{bukan json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Data baris transaksi tidak valid.');

        $this->actingAs($a)->postJson('/api/v1/aklap/kas-harian', [
            'tanggal' => '2026-03-18',
            'items'   => json_encode([['uraian' => 'Uji', 'penerimaan' => '-5']]),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Nilai penerimaan/pengeluaran tidak boleh negatif (baris 1).');

        $this->assertSame(0, DB::table('kas_harian')->count());

        // Simpan: parser angka manual, hari/periode otomatis, baris kosong dibuang,
        // total dihitung server (nilai kiriman klien diabaikan).
        $res = $this->actingAs($a)->postJson('/api/v1/aklap/kas-harian', [
            'tanggal'     => '2026-03-18',
            'posisi_h1'   => '1.000.000',
            'bank1_nama'  => 'Bank Daerah',
            'bank1_saldo' => '2.500.000,50',
            'bank2_saldo' => '500000',
            'catatan'     => 'Catatan uji',
            'total_penerimaan' => '99999999',  // diabaikan (server otoritatif)
            'posisi_h'         => '1',         // diabaikan
            'items'       => json_encode([
                ['no_sts' => 'STS-1', 'uraian' => 'Setoran', 'penerimaan' => '500.000'],
                ['no_sp2d' => 'SP2D-1', 'uraian' => 'Pencairan', 'pengeluaran' => '250.000'],
                ['uraian' => '', 'penerimaan' => '0', 'pengeluaran' => '0'],
            ]),
        ])->assertOk();

        $this->assertSame('Laporan Posisi Kas Harian tersimpan (2 baris).', (string) $res->json('message'));
        $this->assertGreaterThan(0, (int) $res->json('laporan_id'));

        $items = array_values((array) $res->json('items'));

        $this->assertCount(2, $items);
        $this->assertSame('STS-1', $items[0]['no_sts']);
        $this->assertEqualsWithDelta(500000.0, (float) $items[0]['penerimaan'], 0.001);
        $this->assertSame('SP2D-1', $items[1]['no_sp2d']);
        $this->assertSame('Rabu', (string) $res->json('hari'));
        $this->assertSame('Maret', (string) $res->json('periode'));
        $this->assertEqualsWithDelta(1000000.0, (float) $res->json('posisi_h1'), 0.001);
        $this->assertEqualsWithDelta(500000.0, (float) $res->json('total_penerimaan'), 0.001);
        $this->assertEqualsWithDelta(250000.0, (float) $res->json('total_pengeluaran'), 0.001);
        $this->assertEqualsWithDelta(1250000.0, (float) $res->json('posisi_h'), 0.001);
        $this->assertEqualsWithDelta(2500000.5, (float) $res->json('bank1_saldo'), 0.001);
        $this->assertEqualsWithDelta(3000000.5, (float) $res->json('total_saldo'), 0.001);
        $this->assertTrue((bool) $res->json('tersimpan'));
        $this->assertSame('Bank Daerah', (string) $res->json('bank1_nama'));
        $this->assertSame(2, DB::table('kas_harian_item')->count());

        // Simpan ulang tanggal yang sama -> dokumen diperbarui, baris lama diganti.
        $ulang = $this->actingAs($a)->postJson('/api/v1/aklap/kas-harian', [
            'tanggal'   => '2026-03-18',
            'posisi_h1' => '100000',
            'items'     => json_encode([['uraian' => 'Satu saja', 'penerimaan' => '10000']]),
        ])->assertOk();

        $this->assertSame(1, DB::table('kas_harian')->count());
        $this->assertSame(1, DB::table('kas_harian_item')->count());
        $this->assertSame('Laporan Posisi Kas Harian tersimpan (1 baris).', (string) $ulang->json('message'));
        $this->assertEqualsWithDelta(110000.0, (float) $ulang->json('posisi_h'), 0.001);

        // Daftar: ringkasan + jumlah baris.
        $this->actingAs($a)->getJson('/api/v1/aklap/kas-harian/daftar')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.tanggal', '2026-03-18')
            ->assertJsonPath('data.0.baris', 1)
            ->assertJsonPath('data.0.hari', 'Rabu');

        // Hapus.
        $this->actingAs($a)->postJson('/api/v1/aklap/kas-harian/hapus', ['tanggal' => 'ngawur'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tanggal laporan tidak valid (format YYYY-MM-DD).');

        $this->actingAs($a)->postJson('/api/v1/aklap/kas-harian/hapus', ['tanggal' => '2026-03-18'])
            ->assertOk()
            ->assertJsonPath('message', 'Laporan tanggal 2026-03-18 dihapus.');

        $this->assertSame(0, DB::table('kas_harian')->count());
        $this->assertSame(0, DB::table('kas_harian_item')->count());

        $this->actingAs($a)->postJson('/api/v1/aklap/kas-harian/hapus', ['tanggal' => '2026-03-18'])
            ->assertOk()
            ->assertJsonPath('message', 'Laporan tanggal tersebut belum ada.');
    }

    public function test_kas_terbatas_instansi_dan_menolak_tanpa_login(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $this->buatUser('Dinas B', 'ub');

        // Dokumen instansi B tidak terlihat & tidak bisa dihapus oleh instansi A.
        DB::table('kas_harian')->insert([
            'skpd' => 'Dinas B', 'tanggal' => '2026-03-18', 'hari' => 'Rabu', 'periode' => 'Maret',
            'posisi_h' => '500000.00', 'bank1_saldo' => '0.00', 'bank2_saldo' => '0.00',
        ]);

        $this->actingAs($a)->getJson('/api/v1/aklap/kas-harian/daftar')->assertOk()->assertJsonPath('total', 0);

        $this->actingAs($a)->getJson('/api/v1/aklap/kas-harian?tanggal=2026-03-18')
            ->assertOk()
            ->assertJsonPath('tersimpan', false)
            ->assertJsonPath('posisi_h1', 0);

        $this->actingAs($a)->postJson('/api/v1/aklap/kas-harian/hapus', ['tanggal' => '2026-03-18'])
            ->assertOk()
            ->assertJsonPath('message', 'Laporan tanggal tersebut belum ada.');

        $this->assertSame(1, DB::table('kas_harian')->where('skpd', 'Dinas B')->count());
    }

    public function test_kas_menolak_tanpa_login(): void
    {
        $this->postJson('/api/v1/aklap/buku-kas', ['tanggal' => '2026-03-02'])->assertStatus(401);
        $this->postJson('/api/v1/aklap/buku-kas/hapus', ['id' => 1])->assertStatus(401);
        $this->getJson('/api/v1/aklap/kas-harian')->assertStatus(401);
        $this->getJson('/api/v1/aklap/kas-harian/daftar')->assertStatus(401);
        $this->postJson('/api/v1/aklap/kas-harian', ['tanggal' => '2026-03-02'])->assertStatus(401);
        $this->postJson('/api/v1/aklap/kas-harian/hapus', ['tanggal' => '2026-03-02'])->assertStatus(401);
    }
}
