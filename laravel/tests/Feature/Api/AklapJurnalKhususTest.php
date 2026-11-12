<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tes modul AKLAP — Buku Jurnal Khusus (blanko Modul-3E-LK, diisi manual).
 *
 * Diporting dari peta.simtkd.com/api/akuntansi.php (`jurnal_khusus_template`,
 * `jurnal_khusus`, `_daftar`, `_simpan`, `_hapus`).
 *
 * Fokus: template 6 blanko (maks 10 slot per grup), satu dokumen per jenis per
 * periode, 20 kolom uang per baris, kolom bebas lewat `kode_extra`, pesan
 * "belum seimbang" untuk baris timpang, dan SKPKD yang dipertahankan bila
 * tidak dikirim ulang.
 *
 * Paritas dengan legacy diuji lewat `tools/difftest/run.php --filter=aklap`
 * dan `tools/difftest/e2e-aklap.php`.
 */
class AklapJurnalKhususTest extends TestCase
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

    public function test_template_dan_dokumen_kosong_memakai_blanko_belanja_pemda(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $tpl = $this->actingAs($a)->getJson('/api/v1/aklap/jurnal-khusus/template')->assertOk();

        $this->assertSame(10, (int) $tpl->json('maks_slot'));
        $this->assertCount(6, (array) $tpl->json('data'));

        $belanja = null;

        foreach ((array) $tpl->json('data') as $t) {
            if (($t['key'] ?? '') === 'belanja_pemda') {
                $belanja = $t;
            }
        }

        $this->assertIsArray($belanja);
        $this->assertSame('Buku Jurnal Khusus Belanja Pemda', (string) $belanja['judul']);
        $this->assertSame(['No', 'BKU'], (array) $belanja['nomor_label']);
        $this->assertSame('NIK', (string) $belanja['label_id']);
        // 4 kode akun tetap + 4 kolom bebas.
        $this->assertSame(['5.1.2.01.01', '5.1.3.01.01', '5.1.4.03.01', '5.1.5.01.01', '', '', '', ''], (array) $belanja['debet']['kode']);
        $this->assertSame(['3.1.2.01.01'], (array) $belanja['kredit']['kode']);
        $this->assertSame(['EPSAL'], (array) $belanja['kredit']['nama']);

        // Dokumen periode tertentu: belum tersimpan, judul & tanggal dari blanko.
        $dok = $this->actingAs($a)->getJson('/api/v1/aklap/jurnal-khusus?jenis=belanja_pemda&tahun=2026&bulan=3')->assertOk();

        $this->assertSame('belanja_pemda', (string) $dok->json('jenis'));
        $this->assertSame('Maret', (string) $dok->json('bulan_nama'));
        $this->assertSame('2026-03-31', (string) $dok->json('tgl_ttd'));
        $this->assertFalse((bool) $dok->json('tersimpan'));
        $this->assertSame(0, (int) $dok->json('jumlah_baris'));
        $this->assertSame(0, (int) $dok->json('baris_tidak_seimbang'));
        $this->assertCount(8, (array) $dok->json('total_kolom.debet'));   // 4 kode + 4 kosong
        $this->assertCount(1, (array) $dok->json('total_kolom.kredit'));
        $this->assertSame('Dinas A', (string) $dok->json('instansi'));
        $this->assertSame('Bendahara Dinas A', (string) $dok->json('nama'));

        // Jenis tidak dikenal -> blanko pertama; periode tidak valid -> hari ini.
        $asing = $this->actingAs($a)->getJson('/api/v1/aklap/jurnal-khusus?jenis=ngawur&tahun=20&bulan=99')->assertOk();

        $this->assertSame('pendapatan_lo', (string) $asing->json('jenis'));
        $this->assertSame(date('Y'), (string) $asing->json('tahun'));
        $this->assertSame((int) date('n'), (int) $asing->json('bulan'));

        // Riwayat periode kosong.
        $this->actingAs($a)->getJson('/api/v1/aklap/jurnal-khusus/daftar?jenis=belanja_pemda')
            ->assertOk()
            ->assertJsonPath('jenis', 'belanja_pemda')
            ->assertJsonPath('total', 0);
    }

    public function test_simpan_validasi_baris_kode_extra_dan_skp(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus', ['jenis' => 'ngawur', 'tahun' => 2026, 'bulan' => 3])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jenis jurnal tidak dikenal.');

        $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus', ['jenis' => 'belanja_pemda', 'tahun' => 1999, 'bulan' => 3])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tahun tidak valid (2000 - 2100).');

        $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus', ['jenis' => 'belanja_pemda', 'tahun' => 2026, 'bulan' => 13])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Bulan tidak valid (1 - 12).');

        $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus', ['jenis' => 'belanja_pemda', 'tahun' => 2026, 'bulan' => 3, 'baris' => '{bukan json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Data baris tidak valid.');

        $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus', [
            'jenis' => 'belanja_pemda', 'tahun' => 2026, 'bulan' => 3,
            'baris' => json_encode([['tanggal' => '', 'uraian' => '', 'debet' => ['0'], 'kredit' => ['0']]]),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Jurnal belum berisi data (minimal satu baris berisi angka atau uraian).');

        $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus', [
            'jenis' => 'belanja_pemda', 'tahun' => 2026, 'bulan' => 3,
            'baris' => json_encode([['uraian' => 'Uji', 'debet' => ['abc'], 'kredit' => ['0']]]),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Baris ke-1: nilai kolom Debet ke-1 bukan angka.');

        $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus', [
            'jenis' => 'belanja_pemda', 'tahun' => 2026, 'bulan' => 3,
            'baris' => json_encode([['uraian' => 'Uji', 'debet' => ['-1'], 'kredit' => ['0']]]),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Baris ke-1: nilai kolom Debet ke-1 tidak boleh negatif.');

        $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus', [
            'jenis' => 'belanja_pemda', 'tahun' => 2026, 'bulan' => 3,
            'baris' => json_encode([['tanggal' => '2026-13-99', 'uraian' => 'Uji', 'debet' => ['1'], 'kredit' => ['1']]]),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Baris ke-1: tanggal tidak valid (format YYYY-MM-DD).');

        $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus', [
            'jenis' => 'belanja_pemda', 'tahun' => 2026, 'bulan' => 3,
            'baris' => json_encode(array_fill(0, 301, ['uraian' => 'x', 'debet' => ['1'], 'kredit' => ['1']])),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Maksimal 300 baris per dokumen.');

        $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus', [
            'jenis' => 'belanja_pemda', 'tahun' => 2026, 'bulan' => 3, 'skp' => str_repeat('x', 81),
            'baris' => json_encode([['uraian' => 'x', 'debet' => ['1'], 'kredit' => ['1']]]),
        ])->assertStatus(422)
            ->assertJsonPath('message', 'SKPKD maksimal 80 karakter.');

        $this->assertSame(0, DB::table('jurnal_khusus')->count());
    }

    public function test_simpan_sukses_upsert_dan_hapus(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $res = $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus', [
            'jenis'      => 'belanja_pemda',
            'tahun'      => '2026',
            'bulan'      => '3',
            'skp'        => 'SKPKD Uji',
            'kode_extra' => json_encode(['debet' => ['5' => '5.1.9.99.01', '6' => '   ', '99' => 'DILUAR-SLOT'], 'kredit' => ['1' => '3.1.2.01.09']]),
            'baris'      => json_encode([
                ['tanggal' => '2026-03-05', 'nomor_dok' => 'BKU-1', 'uraian' => 'Belanja ATK', 'debet' => ['1.000.000'], 'kredit' => ['1.000.000']],
                ['tanggal' => '2026-03-06', 'nomor_dok' => 'BKU-2', 'uraian' => 'Timpang', 'debet' => ['500.000'], 'kredit' => ['250.000']],
                ['tanggal' => '', 'nomor_dok' => '', 'uraian' => '', 'debet' => ['0'], 'kredit' => ['0']],
            ]),
        ])->assertOk();

        $this->assertSame('Buku Jurnal Khusus Belanja Pemda Maret 2026 tersimpan (2 baris). Catatan: baris ke-2 belum seimbang (Jumlah Debet <> Jumlah Kredit).', (string) $res->json('message'));
        $this->assertSame(2, (int) $res->json('baris'));
        $this->assertSame(1, (int) $res->json('baris_tidak_seimbang'));

        $id = (int) $res->json('id');

        $this->assertSame(2, DB::table('jurnal_khusus_baris')->count());

        $baris = (array) DB::table('jurnal_khusus_baris')->where('jurnal_id', $id)->orderBy('urut')->first();

        $this->assertSame('1000000.00', $baris['debet_1']);
        $this->assertSame('1000000.00', $baris['kredit_1']);
        $this->assertSame('0.00', $baris['debet_8']);       // kolom bebas kosong
        $this->assertSame('2026-03-05', $baris['tanggal']);

        // kode_extra: hanya slot valid & nilai tidak kosong yang disimpan.
        $head  = (array) DB::table('jurnal_khusus')->where('id', $id)->first();
        $extra = json_decode((string) $head['kode_extra'], true);

        $this->assertSame('SKPKD Uji', $head['skp_label']);
        $this->assertSame(['5' => '5.1.9.99.01'], $extra['debet']);
        $this->assertSame(['1' => '3.1.2.01.09'], $extra['kredit']);

        // Dokumen dibaca kembali lengkap (baris + total kolom + kode_extra).
        $dok = $this->actingAs($a)->getJson('/api/v1/aklap/jurnal-khusus?jenis=belanja_pemda&tahun=2026&bulan=3')->assertOk();

        $this->assertTrue((bool) $dok->json('tersimpan'));
        $this->assertSame(2, (int) $dok->json('jumlah_baris'));
        $this->assertSame(1, (int) $dok->json('baris_tidak_seimbang'));
        $this->assertEqualsWithDelta(1500000.0, (float) $dok->json('total_debet'), 0.001);
        $this->assertEqualsWithDelta(1250000.0, (float) $dok->json('total_kredit'), 0.001);
        $this->assertEqualsWithDelta(250000.0, (float) $dok->json('selisih'), 0.001);
        $this->assertSame('5.1.9.99.01', (string) $dok->json('kode_extra.debet.5'));
        $this->assertSame('SKPKD Uji', (string) $dok->json('skp'));

        // Riwayat periode.
        $this->actingAs($a)->getJson('/api/v1/aklap/jurnal-khusus/daftar?jenis=belanja_pemda')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.tahun', 2026)
            ->assertJsonPath('data.0.bulan', 3)
            ->assertJsonPath('data.0.bulan_nama', 'Maret')
            ->assertJsonPath('data.0.baris', 2);
    }

    public function test_simpan_ulang_menjaga_skp_dan_hapus_menghapus_baris(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $res = $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus', [
            'jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '3', 'skp' => 'SKPKD Uji',
            'baris' => json_encode([
                ['tanggal' => '2026-03-05', 'uraian' => 'Dua baris', 'debet' => ['1.000'], 'kredit' => ['1.000']],
                ['tanggal' => '2026-03-06', 'uraian' => 'Baris kedua', 'debet' => ['2.000'], 'kredit' => ['2.000']],
            ]),
        ])->assertOk();

        $id = (int) $res->json('id');

        // Simpan ulang tanpa `skp` -> nilai SKPKD lama dipertahankan.
        $ulang = $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus', [
            'jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '3',
            'baris' => json_encode([['tanggal' => '2026-03-07', 'uraian' => 'Satu baris', 'debet' => ['250.000'], 'kredit' => ['250.000']]]),
        ])->assertOk();

        $this->assertSame($id, (int) $ulang->json('id'));
        $this->assertSame(1, (int) $ulang->json('baris'));
        $this->assertSame(0, (int) $ulang->json('baris_tidak_seimbang'));
        $this->assertSame(1, DB::table('jurnal_khusus_baris')->where('jurnal_id', $id)->count());
        $this->assertSame('SKPKD Uji', (string) DB::table('jurnal_khusus')->where('id', $id)->value('skp_label'));

        // Periode lain -> dokumen terpisah.
        $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus', [
            'jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '4',
            'baris' => json_encode([['uraian' => 'April', 'debet' => ['1'], 'kredit' => ['1']]]),
        ])->assertOk();

        $this->assertSame(2, DB::table('jurnal_khusus')->count());

        // Hapus: validasi, periode tidak ada, lalu penghapusan dokumen + baris.
        $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus/hapus', ['jenis' => 'ngawur', 'tahun' => 1999, 'bulan' => 0])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jenis / periode jurnal tidak valid.');

        $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus/hapus', ['jenis' => 'belanja_pemda', 'tahun' => 2026, 'bulan' => 11])
            ->assertOk()
            ->assertJsonPath('message', 'Jurnal periode tersebut belum ada.');

        $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus/hapus', ['jenis' => 'belanja_pemda', 'tahun' => 2026, 'bulan' => 3])
            ->assertOk()
            ->assertJsonPath('message', 'Buku Jurnal Khusus Belanja Pemda Maret 2026 dihapus.');

        $this->assertSame(0, DB::table('jurnal_khusus')->where('id', $id)->count());
        $this->assertSame(0, DB::table('jurnal_khusus_baris')->where('jurnal_id', $id)->count());
        $this->assertSame(1, DB::table('jurnal_khusus')->where('bulan', 4)->count());   // April utuh
    }

    public function test_jurnal_khusus_terbatas_instansi_dan_menolak_tanpa_login(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        $idB = (int) DB::table('jurnal_khusus')->insertGetId([
            'skpd' => 'Dinas B', 'jenis' => 'belanja_pemda', 'tahun' => 2026, 'bulan' => 3, 'kode_extra' => '{}', 'skp_label' => 'SKPKD B',
        ]);

        DB::table('jurnal_khusus_baris')->insert([
            'jurnal_id' => $idB, 'skpd' => 'Dinas B', 'urut' => 1, 'tanggal' => '2026-03-01',
            'nomor_dok' => 'BKU-B', 'uraian' => 'Milik B', 'debet_1' => '1000.00', 'kredit_1' => '1000.00',
        ]);

        $this->actingAs($a)->getJson('/api/v1/aklap/jurnal-khusus?jenis=belanja_pemda&tahun=2026&bulan=3')
            ->assertOk()
            ->assertJsonPath('tersimpan', false)
            ->assertJsonPath('jumlah_baris', 0);

        $this->actingAs($a)->getJson('/api/v1/aklap/jurnal-khusus/daftar?jenis=belanja_pemda')
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->actingAs($a)->postJson('/api/v1/aklap/jurnal-khusus/hapus', ['jenis' => 'belanja_pemda', 'tahun' => 2026, 'bulan' => 3])
            ->assertOk()
            ->assertJsonPath('message', 'Jurnal periode tersebut belum ada.');

        $this->assertSame(1, DB::table('jurnal_khusus')->where('skpd', 'Dinas B')->count());
        $this->assertSame(1, DB::table('jurnal_khusus_baris')->where('skpd', 'Dinas B')->count());

        $this->actingAs($b)->getJson('/api/v1/aklap/jurnal-khusus/daftar?jenis=belanja_pemda')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_jurnal_khusus_menolak_tanpa_login(): void
    {
        $this->getJson('/api/v1/aklap/jurnal-khusus/template')->assertStatus(401);
        $this->getJson('/api/v1/aklap/jurnal-khusus')->assertStatus(401);
        $this->getJson('/api/v1/aklap/jurnal-khusus/daftar')->assertStatus(401);
        $this->postJson('/api/v1/aklap/jurnal-khusus', ['jenis' => 'belanja_pemda'])->assertStatus(401);
        $this->postJson('/api/v1/aklap/jurnal-khusus/hapus', ['jenis' => 'belanja_pemda'])->assertStatus(401);
    }
}
