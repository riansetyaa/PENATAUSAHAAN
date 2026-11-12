<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tes modul AKLAP — akuntansi manual: Master Akun + Anggaran (pagu).
 *
 * Diporting dari peta.simtkd.com/api/akuntansi.php. Fokus:
 *  - Seed COA otomatis (COA ringkas + COA LRA standar dari config/coa_lra.json)
 *    bersifat idempoten.
 *  - Simpan/ubah/hapus akun (kode unik per instansi).
 *  - Simpan pagu batch dengan normalisasi angka ("1.000,50" -> 1000.50),
 *    daftar pagu di luar Master Akun, dan sinkronisasi ke Master Akun.
 *  - Isolasi multi-tenant.
 *
 * Jalur validasi (gagal sebelum menulis) dibandingkan dengan legacy lewat
 * tools/difftest/run.php --filter=aklap.
 */
class AklapAkuntansiTest extends TestCase
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

    /** Jumlah akun pada COA LRA standar (berkas yang sama dipakai legacy). */
    private function jumlahCoa(): int
    {
        $berkas = is_readable(base_path('../peta.simtkd.com/config/coa_lra.json'))
            ? base_path('../peta.simtkd.com/config/coa_lra.json')
            : resource_path('data/coa_lra.json');

        $data = json_decode((string) file_get_contents($berkas), true);

        return count($data['akun']);
    }

    public function test_daftar_akun_melakukan_seed_otomatis(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $res = $this->actingAs($a)->getJson('/api/v1/aklap/akun-master')->assertOk();

        $this->assertArrayNotHasKey('message', $res->json());
        $this->assertSame(DB::table('akun_master')->where('skpd', 'Dinas A')->count(), $res->json('total'));
        $this->assertGreaterThanOrEqual($this->jumlahCoa(), $res->json('total'));

        // Akun COA ringkas maupun COA LRA standar tersedia.
        $kodes = array_column($res->json('data'), 'kode_akun');
        $this->assertContains('1.1.1.01', $kodes);     // COA ringkas
        $this->assertContains('4', $kodes);            // level 1 dari COA LRA
        $this->assertContains('5.1.2.01', $kodes);

        // Instansi lain tidak ikut ter-seed.
        $this->assertSame(0, DB::table('akun_master')->where('skpd', 'Dinas B')->count());
    }

    public function test_simpan_ubah_dan_hapus_akun(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        // Tambah baru.
        $res = $this->actingAs($a)
            ->postJson('/api/v1/aklap/akun-master', [
                'kode_akun' => '9.9.1.01', 'nama_akun' => 'Akun Uji', 'tipe' => 'belanja', 'saldo_normal' => 'debet',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Akun tersimpan.');

        $id = (int) $res->json('id');
        $this->assertDatabaseHas('akun_master', ['id' => $id, 'skpd' => 'Dinas A', 'kode_akun' => '9.9.1.01', 'tipe' => 'belanja']);

        // Kode duplikat -> 409.
        $this->actingAs($a)
            ->postJson('/api/v1/aklap/akun-master', ['kode_akun' => '9.9.1.01', 'nama_akun' => 'Duplikat'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Kode akun sudah dipakai untuk instansi ini.');

        // Tipe/saldo normal tak dikenal dinormalkan.
        $this->actingAs($a)
            ->postJson('/api/v1/aklap/akun-master', ['kode_akun' => '9.9.1.02', 'nama_akun' => 'Normalisasi', 'tipe' => 'ngawur', 'saldo_normal' => 'ngawur'])
            ->assertOk();

        $this->assertDatabaseHas('akun_master', ['kode_akun' => '9.9.1.02', 'tipe' => 'aset', 'saldo_normal' => 'debet']);

        // Ubah.
        $this->actingAs($a)
            ->postJson('/api/v1/aklap/akun-master', ['id' => $id, 'kode_akun' => '9.9.1.01', 'nama_akun' => 'Akun Uji Diubah'])
            ->assertOk()
            ->assertJsonPath('message', 'Akun diperbarui.');

        $this->assertDatabaseHas('akun_master', ['id' => $id, 'nama_akun' => 'Akun Uji Diubah']);

        // Hapus.
        $this->actingAs($a)
            ->postJson('/api/v1/aklap/akun-master/hapus', ['id' => $id])
            ->assertOk()
            ->assertJsonPath('message', 'Akun dihapus.');

        $this->actingAs($a)
            ->postJson('/api/v1/aklap/akun-master/hapus', ['id' => $id])
            ->assertOk()
            ->assertJsonPath('message', 'Akun tidak ditemukan.');
    }

    public function test_seed_coa_idempoten_dan_tidak_menyentuh_instansi_lain(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $pertama = $this->actingAs($a)->postJson('/api/v1/aklap/akun-master/seed-coa')->assertOk();

        $this->assertSame($this->jumlahCoa(), (int) $pertama->json('ditambahkan'));
        $this->assertSame($this->jumlahCoa(), (int) $pertama->json('coa_jumlah'));
        $this->assertStringContainsString('akun COA LRA standar ditambahkan', (string) $pertama->json('message'));

        $kedua = $this->actingAs($a)->postJson('/api/v1/aklap/akun-master/seed-coa')->assertOk();

        $this->assertSame(0, (int) $kedua->json('ditambahkan'));
        $this->assertSame('Seluruh COA LRA standar sudah tersedia.', $kedua->json('message'));

        $this->assertSame(0, DB::table('akun_master')->where('skpd', 'Dinas B')->count());
    }

    // -----------------------------------------------------------------
    // Anggaran (pagu)
    // -----------------------------------------------------------------

    public function test_anggaran_simpan_batch_menormalkan_angka(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        // Seed master lewat daftar akun (perilaku legacy).
        $this->actingAs($a)->getJson('/api/v1/aklap/akun-master')->assertOk();

        $this->actingAs($a)
            ->postJson('/api/v1/aklap/anggaran', [
                'tahun' => '2026',
                'rows'  => json_encode([
                    ['kode_akun' => '4.1.1.01', 'anggaran' => '1.000,50'],  // dinormalkan
                    ['kode_akun' => '5.1.2.01', 'anggaran' => '2000000'],
                    ['kode_akun' => '', 'anggaran' => '999'],                // dilewati (kode kosong)
                ]),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Anggaran tahun 2026 tersimpan (2 akun).')
            ->assertJsonPath('tersimpan', 2);

        $this->assertSame('1000.50', (string) DB::table('anggaran_lra')->where('skpd', 'Dinas A')->where('kode_akun', '4.1.1.01')->value('anggaran'));
        $this->assertSame('2000000.00', (string) DB::table('anggaran_lra')->where('skpd', 'Dinas A')->where('kode_akun', '5.1.2.01')->value('anggaran'));

        // Upsert: simpan ulang tidak menambah baris.
        $this->actingAs($a)
            ->postJson('/api/v1/aklap/anggaran', ['tahun' => '2026', 'kode_akun' => '4.1.1.01', 'anggaran' => '3000'])
            ->assertOk()
            ->assertJsonPath('tersimpan', 1);

        $this->assertSame(1, DB::table('anggaran_lra')->where('skpd', 'Dinas A')->where('kode_akun', '4.1.1.01')->count());
        $this->assertSame('3000.00', (string) DB::table('anggaran_lra')->where('skpd', 'Dinas A')->where('kode_akun', '4.1.1.01')->value('anggaran'));

        // Daftar anggaran (tipe lra = pendapatan + belanja).
        $list = $this->actingAs($a)->getJson('/api/v1/aklap/anggaran?tahun=2026')->assertOk();

        $this->assertSame('2026', $list->json('tahun'));
        $this->assertSame(2, $list->json('jumlah_terisi'));
        $this->assertContains('2026', $list->json('daftar_tahun'));
        $this->assertEqualsWithDelta(3000.0, (float) $list->json('total_pendapatan'), 0.001);
        $this->assertEqualsWithDelta(2000000.0, (float) $list->json('total_belanja'), 0.001);

        foreach ($list->json('data') as $row) {
            $this->assertContains($row['tipe'], ['pendapatan', 'belanja']);   // filter 'lra'
        }
    }

    public function test_anggaran_di_luar_master_dan_sinkronisasi(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        // Pagu lama dengan kode yang belum ada di Master Akun.
        DB::table('anggaran_lra')->insert([
            'skpd' => 'Dinas A', 'tahun' => '2026', 'kode_akun' => '4.9.9.99',
            'nama_akun' => 'Pagu Lama', 'anggaran' => '500000.00',
        ]);

        $list = $this->actingAs($a)->getJson('/api/v1/aklap/anggaran?tahun=2026')->assertOk();

        $luar = $list->json('luar_master');
        $this->assertCount(1, $luar);
        $this->assertSame('4.9.9.99', $luar[0]['kode_akun']);
        $this->assertSame('pendapatan', $luar[0]['tipe']);            // tipe dari digit kode
        $this->assertEqualsWithDelta(500000.0, (float) $list->json('total_luar_master'), 0.001);

        // Sinkronisasi -> masuk Master Akun.
        $sync = $this->actingAs($a)->postJson('/api/v1/aklap/anggaran/sinkron-master')->assertOk();

        $this->assertSame(1, (int) $sync->json('ditambahkan'));
        $this->assertSame(['4.9.9.99'], $sync->json('kode'));
        $this->assertDatabaseHas('akun_master', [
            'skpd' => 'Dinas A', 'kode_akun' => '4.9.9.99', 'nama_akun' => 'Pagu Lama',
            'tipe' => 'pendapatan', 'saldo_normal' => 'kredit',
        ]);

        // Tanpa kode baru -> pesan "sudah ada".
        $this->actingAs($a)
            ->postJson('/api/v1/aklap/anggaran/sinkron-master')
            ->assertOk()
            ->assertJsonPath('message', 'Semua kode anggaran sudah ada di Master Akun.');

        // Hapus pagu.
        $this->actingAs($a)
            ->postJson('/api/v1/aklap/anggaran/hapus', ['tahun' => '2026', 'kode_akun' => '4.9.9.99'])
            ->assertOk()
            ->assertJsonPath('message', 'Pagu akun 4.9.9.99 dihapus (menjadi 0).');

        $this->actingAs($a)
            ->postJson('/api/v1/aklap/anggaran/hapus', ['tahun' => '2026', 'kode_akun' => '4.9.9.99'])
            ->assertOk()
            ->assertJsonPath('message', 'Belum ada pagu untuk akun tersebut.');
    }

    public function test_anggaran_terbatas_instansi_dan_profil_akuntansi(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        // Pagu instansi lain tidak terlihat & tidak bisa dihapus.
        DB::table('anggaran_lra')->insert([
            'skpd' => 'Dinas B', 'tahun' => '2026', 'kode_akun' => '4.1.1.01',
            'nama_akun' => 'Punya Dinas B', 'anggaran' => '123.00',
        ]);

        $list = $this->actingAs($a)->getJson('/api/v1/aklap/anggaran?tahun=2026')->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $list->json('total_pendapatan'), 0.001);

        $this->actingAs($a)
            ->postJson('/api/v1/aklap/anggaran/hapus', ['tahun' => '2026', 'kode_akun' => '4.1.1.01'])
            ->assertOk()
            ->assertJsonPath('message', 'Belum ada pagu untuk akun tersebut.');

        $this->assertDatabaseHas('anggaran_lra', ['skpd' => 'Dinas B', 'kode_akun' => '4.1.1.01']);

        // Profil akuntansi memuat NIP & gambar TTD.
        $a->forceFill(['nip' => '19800101 200501 1 001'])->save();

        DB::table('user_ttd')->insert([
            'user_id' => $a->id,
            'gambar'  => 'data:image/png;base64,AAA',
        ]);

        $profil = $this->actingAs($a)->getJson('/api/v1/aklap/profil-akuntansi')->assertOk();

        $this->assertSame('Bendahara Dinas A', $profil->json('user.nama'));
        $this->assertSame('Dinas A', $profil->json('user.instansi'));
        $this->assertSame('19800101 200501 1 001', $profil->json('user.nip'));
        $this->assertSame('data:image/png;base64,AAA', $profil->json('user.ttd_gambar'));
        $this->assertArrayNotHasKey('message', $profil->json());
    }

    public function test_aklap_akuntansi_menolak_tanpa_login(): void
    {
        $this->getJson('/api/v1/aklap/akun-master')->assertStatus(401);
        $this->postJson('/api/v1/aklap/anggaran', ['tahun' => '2026'])->assertStatus(401);
    }
}