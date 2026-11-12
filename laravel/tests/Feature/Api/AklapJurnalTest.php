<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tes modul AKLAP — Jurnal Umum (double-entry) + saran jurnal.
 *
 * Diporting dari peta.simtkd.com/api/akuntansi.php. Fokus:
 *  - Kaidah D = K, minimal 2 baris, penamaan akun dari Master Akun.
 *  - Penanda dokumen sumber (`sudah_dijurnal`) diisi saat simpan dan
 *    dibebaskan saat jurnal dihapus.
 *  - Template saran jurnal STS (per rincian) & SP2D (LS vs UP/GU/TU).
 *
 * Jalur validasi dibandingkan dengan legacy lewat
 * tools/difftest/run.php --filter=aklap.
 */
class AklapJurnalTest extends TestCase
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

    /** Isi Master Akun agar nama akun jurnal dapat diresolusi. */
    private function isiMaster(string $instansi): void
    {
        DB::table('akun_master')->insert([
            ['skpd' => $instansi, 'kode_akun' => '1.1.1.01', 'nama_akun' => 'Kas di Bendahara Penerimaan', 'tipe' => 'aset', 'saldo_normal' => 'debet'],
            ['skpd' => $instansi, 'kode_akun' => '1.1.1.02', 'nama_akun' => 'Kas di Bendahara Pengeluaran', 'tipe' => 'aset', 'saldo_normal' => 'debet'],
            ['skpd' => $instansi, 'kode_akun' => '1.1.3.01', 'nama_akun' => 'Kas di Bendahara Umum Daerah', 'tipe' => 'aset', 'saldo_normal' => 'debet'],
            ['skpd' => $instansi, 'kode_akun' => '2.1.1.01', 'nama_akun' => 'Utang Pajak', 'tipe' => 'liabilitas', 'saldo_normal' => 'kredit'],
            ['skpd' => $instansi, 'kode_akun' => '4.1.4.01', 'nama_akun' => 'Pendapatan Lain-lain PAD', 'tipe' => 'pendapatan', 'saldo_normal' => 'kredit'],
            ['skpd' => $instansi, 'kode_akun' => '5.1.2.01', 'nama_akun' => 'Belanja Barang dan Jasa', 'tipe' => 'belanja', 'saldo_normal' => 'debet'],
        ]);
    }

    /** @param array<string, mixed> $ubah */
    private function sp2d(User $u, string $instansi, array $ubah = []): array
    {
        $spmId = (int) DB::table('spm')->insertGetId([
            'user_id' => $u->id, 'skpd' => $instansi, 'nomor_spm' => 'SPM-1', 'tanggal' => '2026-03-04',
            'jumlah' => '1000000.00', 'status' => 'sudah_diverifikasi', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $sppId = (int) DB::table('spp')->insertGetId([
            'user_id' => $u->id, 'skpd' => $instansi, 'nomor_spp' => 'SPP-1', 'tanggal' => '2026-03-03',
            'jenis_spp' => 'LS Barang & Jasa', 'keperluan' => 'Uji', 'jumlah' => '1000000.00',
            'total_potongan' => '0.00', 'total_pajak' => '0.00', 'status' => 'sudah_diverifikasi',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('spm')->where('id', $spmId)->update(['spp_id' => $sppId]);

        // Potongan pajak: PPN 100.000 (pajak) -> utang pajak.
        DB::table('spp_potongan_pajak')->insert([
            'skpd' => $instansi, 'spp_id' => $sppId, 'jenis' => 'pajak', 'nama' => 'PPN',
            'nilai_persen' => '10.00', 'nilai' => '100000.00', 'created_at' => now(),
        ]);

        $sp2dId = (int) DB::table('sp2d')->insertGetId(array_merge([
            'user_id' => $u->id, 'skpd' => $instansi, 'nomor_sp2d' => 'SP2D-1', 'tanggal' => '2026-03-05',
            'spm_id' => $spmId, 'rekening' => '5140', 'jumlah' => '1000000.00', 'status' => 'sudah_dicairkan',
            'created_at' => now(), 'updated_at' => now(),
        ], $ubah));

        return ['sp2d' => $sp2dId, 'spp' => $sppId, 'spm' => $spmId];
    }

    // -----------------------------------------------------------------
    // Simpan / ubah / status / hapus
    // -----------------------------------------------------------------

    public function test_jurnal_seimbang_tersimpan_muncul_di_daftar_dan_dinamai_dari_master(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $this->isiMaster('Dinas A');

        $res = $this->actingAs($a)
            ->postJson('/api/v1/aklap/jurnal-umum', [
                'tanggal' => '2026-03-10',
                'uraian'  => 'Penerimaan pajak',
                'detail'  => json_encode([
                    ['kode_akun' => '1.1.1.01', 'posisi' => 'debet', 'jumlah' => 1500000],
                    ['kode_akun' => '4.1.4.01', 'posisi' => 'kredit', 'jumlah' => 1500000],
                ]),
            ])
            ->assertOk();

        $nomor = (string) $res->json('message');
        $this->assertMatchesRegularExpression('/^Jurnal JU-\d{6}-\d{4} tersimpan\.$/', $nomor);

        $id = (int) $res->json('id');
        $jurnal = (array) DB::table('jurnal_umum')->where('id', $id)->first();

        $this->assertSame('Dinas A', $jurnal['skpd']);
        $this->assertSame('manual', $jurnal['sumber_tipe']);
        $this->assertSame('draft', $jurnal['status']);
        $this->assertStringStartsWith('JU-', (string) $jurnal['nomor_jurnal']);

        // Rincian tersimpan dengan nama akun dari Master Akun.
        $detail = DB::table('jurnal_umum_detail')->where('jurnal_id', $id)->orderBy('id')->get();

        $this->assertCount(2, $detail);
        $this->assertSame('Kas di Bendahara Penerimaan', (string) $detail[0]->nama_akun);
        $this->assertSame('4.1.4.01', (string) $detail[1]->kode_akun);

        // Daftar menampilkan total D/K (urutan rincian: kredit dulu, lalu debet).
        $list = $this->actingAs($a)->getJson('/api/v1/aklap/jurnal-umum')->assertOk();

        $this->assertSame(1, $list->json('total'));
        $this->assertEqualsWithDelta(1500000.0, (float) $list->json('data.0.total_debet'), 0.001);
        $this->assertEqualsWithDelta(1500000.0, (float) $list->json('data.0.total_kredit'), 0.001);
        $this->assertSame('kredit', $list->json('data.0.detail.0.posisi'));
        $this->assertSame('debet', $list->json('data.0.detail.1.posisi'));
    }

    public function test_jurnal_tidak_seimbang_dan_kurang_baris_tidak_menyimpan(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($a)
            ->postJson('/api/v1/aklap/jurnal-umum', [
                'tanggal' => '2026-03-10',
                'detail'  => json_encode([
                    ['kode_akun' => '1.1.1.01', 'posisi' => 'debet', 'jumlah' => 1000],
                    ['kode_akun' => '4.1.4.01', 'posisi' => 'kredit', 'jumlah' => 500],
                ]),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jurnal TIDAK seimbang: Total Debet Rp 1.000,00 != Total Kredit Rp 500,00.');

        $this->assertSame(0, DB::table('jurnal_umum')->count());
    }

    public function test_jurnal_dapat_diubah_statusnya_dan_dihapus(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $id = (int) $this->actingAs($a)
            ->postJson('/api/v1/aklap/jurnal-umum', [
                'tanggal' => '2026-03-10',
                'uraian'  => 'Awal',
                'detail'  => json_encode([
                    ['kode_akun' => '1.1.1.01', 'posisi' => 'debet', 'jumlah' => 500],
                    ['kode_akun' => '4.1.4.01', 'posisi' => 'kredit', 'jumlah' => 500],
                ]),
            ])
            ->assertOk()
            ->json('id');

        // Ubah: rincian diganti, uraian berubah.
        $this->actingAs($a)
            ->postJson('/api/v1/aklap/jurnal-umum', [
                'id'      => $id,
                'tanggal' => '2026-03-11',
                'uraian'  => 'Diubah',
                'detail'  => json_encode([
                    ['kode_akun' => '1.1.1.01', 'posisi' => 'debet', 'jumlah' => 700],
                    ['kode_akun' => '4.1.4.01', 'posisi' => 'kredit', 'jumlah' => 700],
                ]),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Jurnal diperbarui.');

        $this->assertSame('Diubah', (string) DB::table('jurnal_umum')->where('id', $id)->value('uraian'));
        $this->assertSame(2, DB::table('jurnal_umum_detail')->where('jurnal_id', $id)->count());
        $this->assertSame('700.00', (string) DB::table('jurnal_umum_detail')->where('jurnal_id', $id)->orderBy('id')->value('jumlah'));

        // Setujui lalu kembalikan ke draft.
        $this->actingAs($a)
            ->postJson('/api/v1/aklap/jurnal-umum/status', ['id' => $id, 'status' => 'sudah_approve'])
            ->assertOk()
            ->assertJsonPath('message', 'Jurnal di-approve.');

        $this->assertSame('sudah_approve', (string) DB::table('jurnal_umum')->where('id', $id)->value('status'));

        $this->actingAs($a)
            ->postJson('/api/v1/aklap/jurnal-umum/status', ['id' => $id, 'status' => 'draft'])
            ->assertOk()
            ->assertJsonPath('message', 'Jurnal dikembalikan ke draft.');

        // Hapus.
        $this->actingAs($a)
            ->postJson('/api/v1/aklap/jurnal-umum/hapus', ['id' => $id])
            ->assertOk()
            ->assertJsonPath('message', 'Jurnal dihapus.');

        $this->assertSame(0, DB::table('jurnal_umum')->where('id', $id)->count());
        $this->assertSame(0, DB::table('jurnal_umum_detail')->where('jurnal_id', $id)->count());
    }

    // -----------------------------------------------------------------
    // Saran jurnal dari dokumen
    // -----------------------------------------------------------------

    public function test_saran_jurnal_sts_membagi_per_rincian_dan_menyeimbangkan_selisih(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $this->isiMaster('Dinas A');

        $stsId = (int) DB::table('sts')->insertGetId([
            'user_id' => $a->id, 'skpd' => 'Dinas A', 'nomor_sts' => 'STS-1', 'nama_penyetor' => 'A',
            'tanggal_sts' => '2026-03-03', 'total' => '1000000.00', 'keterangan' => 'Uji',
            'status' => 'aktif', 'jurnal_status' => 'belum_approve', 'sudah_dijurnal' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sts_detail')->insert([
            ['sts_id' => $stsId, 'stbp_id' => 0, 'nomor_stbp' => 'STBP-1', 'tanggal' => '2026-03-03',
                'akun_kode' => '4.1.4.01', 'akun_nama' => 'Pendapatan Lain-lain PAD', 'jumlah' => '600000.00', 'uraian' => 'x'],
            ['sts_id' => $stsId, 'stbp_id' => 0, 'nomor_stbp' => 'STBP-2', 'tanggal' => '2026-03-03',
                'akun_kode' => '', 'akun_nama' => '', 'jumlah' => '300000.00', 'uraian' => 'y'],
        ]);

        $saran = $this->actingAs($a)->getJson('/api/v1/aklap/saran-jurnal?sumber=sts&id=' . $stsId)->assertOk();

        $this->assertSame('sts', $saran->json('sumber'));
        $this->assertSame('STS-1', $saran->json('nomor'));

        $detail = $saran->json('detail');
        $this->assertCount(4, $detail);   // D kas + K 600rb + K 300rb (kode kosong -> fallback) + penyeimbang 100rb

        $this->assertSame('1.1.1.01', $detail[0]['kode_akun']);
        $this->assertSame('debet', $detail[0]['posisi']);
        $this->assertEqualsWithDelta(1000000.0, (float) $detail[0]['jumlah'], 0.001);
        $this->assertSame('4.1.4.01', $detail[1]['kode_akun']);
        $this->assertSame('4.1.4.01', $detail[2]['kode_akun']);   // fallback untuk kode kosong
        $this->assertEqualsWithDelta(100000.0, (float) $detail[3]['jumlah'], 0.001); // penyeimbang

        // Total debet == total kredit -> jurnal dari template ini sah.
        $totalD = 0.0;
        $totalK = 0.0;

        foreach ($detail as $d) {
            if ($d['posisi'] === 'debet') {
                $totalD += (float) $d['jumlah'];
            } else {
                $totalK += (float) $d['jumlah'];
            }
        }

        $this->assertEqualsWithDelta($totalD, $totalK, 0.001);

        // STS tanpa rincian -> satu baris kredit senilai total.
        $stsKosong = (int) DB::table('sts')->insertGetId([
            'user_id' => $a->id, 'skpd' => 'Dinas A', 'nomor_sts' => 'STS-2', 'nama_penyetor' => 'B',
            'tanggal_sts' => '2026-03-04', 'total' => '250000.00', 'keterangan' => 'Uji',
            'status' => 'aktif', 'jurnal_status' => 'belum_approve', 'sudah_dijurnal' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $saran2 = $this->actingAs($a)->getJson('/api/v1/aklap/saran-jurnal?sumber=sts&id=' . $stsKosong)->assertOk()->json('detail');

        $this->assertCount(2, $saran2);
        $this->assertSame('4.1.4.01', $saran2[1]['kode_akun']);
        $this->assertEqualsWithDelta(250000.0, (float) $saran2[1]['jumlah'], 0.001);
    }

    public function test_saran_jurnal_sp2d_ls_dan_menandai_sudah_dijurnal(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $this->isiMaster('Dinas A');

        $dok = $this->sp2d($a, 'Dinas A');

        $saran = $this->actingAs($a)->getJson('/api/v1/aklap/saran-jurnal?sumber=sp2d&id=' . $dok['sp2d'])->assertOk();

        $detail = $saran->json('detail');
        $this->assertCount(3, $detail);   // D belanja bruto, K utang pajak, K kas BUD netto

        $this->assertSame('5.1.2.01', $detail[0]['kode_akun']);
        $this->assertSame('debet', $detail[0]['posisi']);
        $this->assertEqualsWithDelta(1000000.0, (float) $detail[0]['jumlah'], 0.001);
        $this->assertSame('2.1.1.01', $detail[1]['kode_akun']);
        $this->assertEqualsWithDelta(100000.0, (float) $detail[1]['jumlah'], 0.001);
        $this->assertSame('1.1.3.01', $detail[2]['kode_akun']);
        $this->assertEqualsWithDelta(900000.0, (float) $detail[2]['jumlah'], 0.001);   // neto

        // Simpan jurnal dari template (memakai dokumen sumber).
        $res = $this->actingAs($a)
            ->postJson('/api/v1/aklap/jurnal-umum', [
                'tanggal'     => '2026-03-05',
                'uraian'      => (string) $saran->json('uraian'),
                'sumber_tipe' => 'sp2d',
                'sumber_id'   => $dok['sp2d'],
                'detail'      => json_encode($detail),
            ])
            ->assertOk();

        $this->assertSame('sp2d', (string) DB::table('jurnal_umum')->where('id', $res->json('id'))->value('sumber_tipe'));
        $this->assertSame(1, (int) DB::table('sp2d')->where('id', $dok['sp2d'])->value('sudah_dijurnal'));

        // Sudah dijurnal -> hilang dari saran dokumen & tidak bisa dijurnal ulang.
        $this->assertCount(0, $this->actingAs($a)->getJson('/api/v1/aklap/saran-dokumen')->assertOk()->json('sp2d'));

        $this->actingAs($a)
            ->postJson('/api/v1/aklap/jurnal-umum', [
                'tanggal'     => '2026-03-05',
                'sumber_tipe' => 'sp2d',
                'sumber_id'   => $dok['sp2d'],
                'detail'      => json_encode($detail),
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Dokumen sumber tidak tersedia / sudah pernah dibuatkan jurnal.');

        // Hapus jurnal -> penanda dibebaskan sehingga dokumen bisa dijurnal ulang.
        $this->actingAs($a)
            ->postJson('/api/v1/aklap/jurnal-umum/hapus', ['id' => $res->json('id')])
            ->assertOk();

        $this->assertSame(0, (int) DB::table('sp2d')->where('id', $dok['sp2d'])->value('sudah_dijurnal'));
        $this->assertCount(1, $this->actingAs($a)->getJson('/api/v1/aklap/saran-dokumen')->assertOk()->json('sp2d'));
    }

    public function test_jurnal_dan_saran_terbatas_instansi(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        $stsB = (int) DB::table('sts')->insertGetId([
            'user_id' => $b->id, 'skpd' => 'Dinas B', 'nomor_sts' => 'STS-B', 'nama_penyetor' => 'B',
            'tanggal_sts' => '2026-03-03', 'total' => '100000.00', 'keterangan' => 'x',
            'status' => 'aktif', 'jurnal_status' => 'belum_approve', 'sudah_dijurnal' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // STS instansi lain: tidak muncul di saran & tidak bisa dijadikan template.
        $this->assertCount(0, $this->actingAs($a)->getJson('/api/v1/aklap/saran-dokumen')->assertOk()->json('sts'));

        $this->actingAs($a)
            ->getJson('/api/v1/aklap/saran-jurnal?sumber=sts&id=' . $stsB)
            ->assertStatus(404)
            ->assertJsonPath('message', 'STS tidak ditemukan / tidak aktif.');

        // Jurnal instansi lain tidak dapat diubah status/hapus.
        $jurnalB = (int) DB::table('jurnal_umum')->insertGetId([
            'skpd' => 'Dinas B', 'user_id' => $b->id, 'nomor_jurnal' => 'JU-B', 'tanggal' => '2026-03-03',
            'uraian' => 'x', 'sumber_tipe' => 'manual', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($a)
            ->postJson('/api/v1/aklap/jurnal-umum/hapus', ['id' => $jurnalB])
            ->assertStatus(404);

        $this->actingAs($a)
            ->postJson('/api/v1/aklap/jurnal-umum/status', ['id' => $jurnalB, 'status' => 'sudah_approve'])
            ->assertOk()
            ->assertJsonPath('message', 'Jurnal tidak ditemukan.');

        $this->assertSame('draft', (string) DB::table('jurnal_umum')->where('id', $jurnalB)->value('status'));
        $this->assertSame(1, DB::table('jurnal_umum')->where('id', $jurnalB)->count());
    }

    public function test_jurnal_menolak_tanpa_login(): void
    {
        $this->getJson('/api/v1/aklap/jurnal-umum')->assertStatus(401);
        $this->postJson('/api/v1/aklap/jurnal-umum', ['tanggal' => '2026-03-01'])->assertStatus(401);
    }
}