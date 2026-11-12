<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tes modul AKLAP — kirim dokumen ke antrean tanda tangan (doc.simtkd.com).
 *
 * Diporting dari blok `action=dokumen_kirim` pada
 * peta.simtkd.com/api/akuntansi.php.
 *
 * Fokus: validasi konten (min 200 karakter, maks ±4 MB) & tanggal, default
 * jenis/judul, pembaruan dokumen sejenis yang masih `menunggu_ttd` (bukan
 * baris baru), kode verifikasi `TTD-XXXXXXXX`, dan penandatangan otomatis
 * (self, jabatan Bendahara) maupun eksplisit (urutan diklem 1-20).
 *
 * Paritas dengan legacy diuji lewat `tools/difftest/run.php --filter=aklap`
 * dan `tools/difftest/e2e-aklap.php`.
 */
class AklapDokumenKirimTest extends TestCase
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

    private function konten(int $panjang = 250): string
    {
        return '<p>' . str_repeat('a', max(0, $panjang - 7)) . '</p>';
    }

    public function test_validasi_konten_dan_tanggal(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($a)->postJson('/api/v1/aklap/dokumen-kirim', ['konten_html' => '<p>pendek</p>'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Konten dokumen kosong / tidak valid.');

        $this->actingAs($a)->postJson('/api/v1/aklap/dokumen-kirim', ['tanggal' => '2026-13-99', 'konten_html' => $this->konten()])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Format tanggal tidak valid.');

        // Konten > 4 MB ditolak dengan 413.
        $this->actingAs($a)->postJson('/api/v1/aklap/dokumen-kirim', [
            'konten_html' => str_repeat('a', 4 * 1024 * 1024 + 1),
        ])->assertStatus(413)
            ->assertJsonPath('message', 'Konten dokumen terlalu besar (maks ±4 MB).');

        $this->assertSame(0, DB::table('dokumen')->count());
    }

    public function test_kirim_baru_memakai_default_dan_penandatangan_otomatis(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        // Jenis & judul kosong -> default; tanggal kosong -> NULL.
        $res = $this->actingAs($a)->postJson('/api/v1/aklap/dokumen-kirim', [
            'konten_html' => $this->konten(),
            'nip'         => '198001012006041001',
        ])->assertOk();

        $this->assertSame('Dokumen terkirim ke antrean tanda tangan (doc.simtkd.com).', (string) $res->json('message'));
        $this->assertMatchesRegularExpression('/^TTD-[0-9A-F]{8}$/', (string) $res->json('kode_verifikasi'));

        $id  = (int) $res->json('id');
        $row = (array) DB::table('dokumen')->where('id', $id)->first();

        $this->assertSame('Dinas A', $row['skpd']);
        $this->assertSame('Dokumen', $row['jenis']);
        $this->assertSame('Dokumen', $row['judul']);
        $this->assertNull($row['tanggal']);
        $this->assertSame('menunggu_ttd', $row['status']);
        $this->assertSame(hash('sha256', $this->konten()), $row['hash_original']);
        $this->assertSame((string) $res->json('kode_verifikasi'), $row['kode_verifikasi']);

        // Penandatangan otomatis: pengirim sendiri, jabatan Bendahara, NIP dari isian.
        $ttd = (array) DB::table('dokumen_ttd')->where('dokumen_id', $id)->first();

        $this->assertSame($a->id, (int) $ttd['user_id']);
        $this->assertSame(1, (int) $ttd['urutan']);
        $this->assertSame('Bendahara', $ttd['jabatan']);
        $this->assertSame('Bendahara Dinas A', $ttd['nama']);
        $this->assertSame('198001012006041001', $ttd['nip']);
        $this->assertSame('menunggu', $ttd['status']);
    }

    public function test_kirim_ulang_memperbarui_dokumen_menunggu_ttd(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');

        $pertama = $this->actingAs($a)->postJson('/api/v1/aklap/dokumen-kirim', [
            'jenis' => 'BKU', 'nomor' => 'BKU-1', 'judul' => 'BKU Uji', 'tanggal' => '2026-03-31', 'konten_html' => $this->konten(),
        ])->assertOk();

        $id = (int) $pertama->json('id');

        // Jenis + judul sama & masih menunggu TTD -> diperbarui, bukan baris baru.
        $kontenBaru = '<p>' . str_repeat('b', 300) . '</p>';

        $kedua = $this->actingAs($a)->postJson('/api/v1/aklap/dokumen-kirim', [
            'jenis' => 'BKU', 'nomor' => 'BKU-1R', 'judul' => 'BKU Uji', 'konten_html' => $kontenBaru,
        ])->assertOk();

        $this->assertSame('Dokumen diperbarui di antrean tanda tangan.', (string) $kedua->json('message'));
        $this->assertSame($id, (int) $kedua->json('id'));
        $this->assertSame(1, DB::table('dokumen')->count());

        $row = (array) DB::table('dokumen')->where('id', $id)->first();

        $this->assertSame('BKU-1R', $row['nomor']);
        $this->assertNull($row['tanggal']);   // tanggal kosong -> NULL
        $this->assertSame(hash('sha256', $kontenBaru), $row['hash_original']);

        // Penandatangan tidak digandakan pada pembaruan (alur update berhenti
        // sebelum bagian penandatangan).
        $this->assertSame(1, DB::table('dokumen_ttd')->where('dokumen_id', $id)->count());

        // Judul berbeda -> dokumen baru.
        $this->actingAs($a)->postJson('/api/v1/aklap/dokumen-kirim', [
            'jenis' => 'BKU', 'judul' => 'BKU Lain', 'konten_html' => $this->konten(),
        ])->assertOk();

        $this->assertSame(2, DB::table('dokumen')->count());
    }

    public function test_penandatangan_eksplisit_diklem_dan_terbatas_instansi(): void
    {
        $a = $this->buatUser('Dinas A', 'ua');
        $b = $this->buatUser('Dinas B', 'ub');

        $res = $this->actingAs($a)->postJson('/api/v1/aklap/dokumen-kirim', [
            'jenis'         => 'BKU',
            'judul'         => 'BKU Uji',
            'konten_html'   => $this->konten(),
            'nip'           => '198001012006041001',
            'penandatangan' => json_encode([
                ['user' => 'self', 'urutan' => 0, 'jabatan' => 'Bendahara Pengeluaran', 'nama' => 'Bendahara Dinas A'],
                ['user' => 'lain', 'urutan' => 99, 'jabatan' => str_repeat('J', 120), 'nama' => str_repeat('N', 120)],
            ]),
        ])->assertOk();

        $id  = (int) $res->json('id');
        $ttd = DB::table('dokumen_ttd')->where('dokumen_id', $id)->orderBy('id')->get();

        $this->assertCount(2, $ttd);
        $this->assertSame(1, (int) $ttd[0]->urutan);
        $this->assertSame($a->id, (int) $ttd[0]->user_id);
        $this->assertSame(20, (int) $ttd[1]->urutan);
        $this->assertNull($ttd[1]->user_id);
        $this->assertSame(100, mb_strlen((string) $ttd[1]->jabatan));
        $this->assertSame(100, mb_strlen((string) $ttd[1]->nama));
        $this->assertSame('198001012006041001', (string) $ttd[1]->nip);

        // Instansi lain mengirim dokumen sejenis -> dokumen sendiri (tidak menimpa milik A).
        $this->actingAs($b)->postJson('/api/v1/aklap/dokumen-kirim', [
            'jenis' => 'BKU', 'judul' => 'BKU Uji', 'konten_html' => $this->konten(),
        ])->assertOk();

        $this->assertSame(2, DB::table('dokumen')->count());
        $this->assertSame(1, DB::table('dokumen')->where('skpd', 'Dinas A')->count());
        $this->assertSame(1, DB::table('dokumen')->where('skpd', 'Dinas B')->count());
    }

    public function test_dokumen_kirim_menolak_tanpa_login(): void
    {
        $this->postJson('/api/v1/aklap/dokumen-kirim', ['konten_html' => $this->konten()])->assertStatus(401);
    }
}
