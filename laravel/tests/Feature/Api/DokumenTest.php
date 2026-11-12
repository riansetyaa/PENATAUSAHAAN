<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Dokumen;
use App\Models\DokumenTtd;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tes modul Dokumen & Tanda Tangan Elektronik.
 *
 * Modul ini paling sensitif dari sisi keamanan, jadi pengujiannya
 * berfokus pada batas kepemilikan, integritas, dan proteksi Stored XSS.
 */
class DokumenTest extends TestCase
{
    use RefreshDatabase;

    private const KONTEN = '<!DOCTYPE html><html><head><style>body{font-family:Arial}</style></head>'
        . '<body><div id="docArea"><h1>Surat Pencairan</h1>'
        . '<p>Nomor: SPP/001/2026. Nilai: Rp 1.000.000</p>'
        . '<div class="ttd-slot" data-urut="1"></div>'
        . '</div></body></html>';

    private function buatUser(string $instansi, string $username = 'user', ?string $nip = null): User
    {
        return User::create([
            'nama_lengkap' => 'User ' . $instansi,
            'username'     => $username,
            'email'        => $username . '@polban.ac.id',
            'password'     => 'RahasiaKuat123',
            'instansi'     => $instansi,
            'peran'        => 'Bendahara',
            'status'       => User::STATUS_AKTIF,
            'nip'          => $nip,
        ]);
    }

    /**
     * Dokumen "lama" tanpa baris penandatangan (keadaan data legacy).
     */
    private function buatDokumenTanpaSlot(User $pembuat, string $kode = 'TTD-AAAAAAAA'): Dokumen
    {
        return Dokumen::create([
            'user_id'         => $pembuat->id,
            'skpd'            => (string) $pembuat->instansi,
            'jenis'           => 'SPP',
            'ref_id'          => 12,
            'nomor'           => 'SPP/009/2026',
            'judul'           => 'Surat Pencairan',
            'tanggal'         => '2026-03-15',
            'konten_html'     => self::KONTEN,
            'hash_original'   => hash('sha256', self::KONTEN),
            'kode_verifikasi' => $kode,
            'status'          => 'menunggu_ttd',
        ]);
    }


    // -----------------------------------------------------------------
    // Kirim dokumen
    // -----------------------------------------------------------------

    public function test_kirim_dokumen_membuat_dokumen_dan_slot(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $response = $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis'       => 'SPP',
                'ref_id'      => 12,
                'nomor'       => 'SPP/001/2026',
                'judul'       => 'Surat Pencairan',
                'tanggal'     => '2026-03-15',
                'konten_html' => self::KONTEN,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'menunggu_ttd');

        $kode = $response->json('kode_verifikasi');
        $this->assertMatchesRegularExpression('/^TTD-[A-F0-9]{8}$/', (string) $kode);

        $this->assertDatabaseHas('dokumen', [
            'user_id'         => $user->id,
            'skpd'            => 'Dinas A',
            'jenis'           => 'SPP',
            'ref_id'          => 12,
            'kode_verifikasi' => $kode,
            'status'          => 'menunggu_ttd',
        ]);

        // Slot tanda tangan harus dibuat (default: pembuat, urutan 1).
        $this->assertDatabaseHas('dokumen_ttd', [
            'user_id' => $user->id,
            'urutan'  => 1,
            'status'  => 'menunggu',
        ]);
    }

    public function test_kirim_dokumen_menolak_konten_terlalu_pendek(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis'       => 'SPP',
                'konten_html' => '<p>pendek</p>',
            ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Konten dokumen kosong / tidak valid.');
    }

    public function test_kirim_ulang_memperbarui_dokumen_yang_menunggu(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $pertama = $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis' => 'SPP', 'ref_id' => 12, 'konten_html' => self::KONTEN,
            ])->assertOk();

        $kedua = $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis' => 'SPP', 'ref_id' => 12,
                'konten_html' => str_replace('Surat Pencairan', 'Surat Pencairan (Revisi)', self::KONTEN),
            ])->assertOk()
            ->assertJsonPath('message', 'Dokumen diperbarui di antrean tanda tangan.');

        // ID harus sama (dokumen diperbarui, bukan dibuat baru).
        $this->assertSame($pertama->json('id'), $kedua->json('id'));
        $this->assertDatabaseCount('dokumen', 1);
    }

    public function test_dokumen_yang_sudah_ditandatangani_tidak_bisa_dikirim_ulang(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $r = $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis' => 'SPP', 'ref_id' => 12, 'konten_html' => self::KONTEN,
            ]);

        Dokumen::where('id', $r->json('id'))->update(['status' => 'ditandatangani']);

        $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis' => 'SPP', 'ref_id' => 12, 'konten_html' => self::KONTEN,
            ])->assertOk()
            ->assertJsonPath('message', 'Dokumen ini sudah ditandatangani dan tidak dapat dikirim ulang.')
            ->assertJsonPath('status', 'ditandatangani');
    }

    // -----------------------------------------------------------------
    // Akses dokumen
    // -----------------------------------------------------------------

    public function test_dokumen_milik_user_lain_tidak_bisa_diakses(): void
    {
        $userA = $this->buatUser('Dinas A', 'ua');
        $userB = $this->buatUser('Dinas B', 'ub');

        $r = $this->actingAs($userA)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis' => 'SPP', 'ref_id' => 12, 'konten_html' => self::KONTEN,
            ]);

        $id = $r->json('id');

        $this->actingAs($userB)
            ->getJson("/api/v1/dokumen/detail?id={$id}")
            ->assertStatus(404);

        $this->actingAs($userB)
            ->getJson("/api/v1/dokumen/konten?id={$id}")
            ->assertStatus(404);

        $this->actingAs($userB)
            ->getJson('/api/v1/dokumen/list')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_konten_dokumen_mengirim_csp_ketat(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $r = $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis' => 'SPP', 'ref_id' => 12, 'konten_html' => self::KONTEN,
            ]);

        $response = $this->actingAs($user)->getJson("/api/v1/dokumen/konten?id={$r->json('id')}");

        $response->assertOk();
        $this->assertStringContainsString("script-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    // -----------------------------------------------------------------
    // Tanda tangan
    // -----------------------------------------------------------------

    public function test_ttd_gambar_menolak_format_bukan_png(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $this->actingAs($user)
            ->postJson('/api/v1/dokumen/ttd-gambar', ['gambar' => 'data:image/jpeg;base64,AAAA'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Format gambar tanda tangan tidak valid (harus PNG base64).');
    }

    public function test_ttd_gambar_bisa_disimpan_dan_ditampilkan(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');
        $png  = 'data:image/png;base64,' . base64_encode('dummy-png');

        $this->actingAs($user)
            ->postJson('/api/v1/dokumen/ttd-gambar', ['gambar' => $png])
            ->assertOk()
            ->assertJsonPath('message', 'Gambar tanda tangan tersimpan.');

        $this->assertDatabaseHas('user_ttd', ['user_id' => $user->id]);

        $this->actingAs($user)
            ->getJson('/api/v1/dokumen/ttd-gambar-saya')
            ->assertOk()
            ->assertJsonPath('gambar', $png);
    }

    public function test_ttd_menuntut_gambar_tanda_tangan(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $r = $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis' => 'SPP', 'ref_id' => 12, 'konten_html' => self::KONTEN,
            ]);

        $this->actingAs($user)
            ->postJson('/api/v1/dokumen/ttd', ['id' => $r->json('id')])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Gambar tanda tangan belum dibuat. Gambar tanda tangan terlebih dahulu.');
    }

    public function test_ttd_menyisipkan_gambar_dan_menandai_status(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');
        $png  = 'data:image/png;base64,' . base64_encode('dummy-png');

        $r = $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis' => 'SPP', 'ref_id' => 12, 'konten_html' => self::KONTEN,
            ]);

        $this->actingAs($user)
            ->postJson('/api/v1/dokumen/ttd-gambar', ['gambar' => $png])
            ->assertOk();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/dokumen/ttd', ['id' => $r->json('id')])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'ditandatangani');

        $this->assertNotEmpty($response->json('hash_signed'));

        $dokumen = Dokumen::find($r->json('id'));
        $this->assertSame('ditandatangani', (string) $dokumen->status);
        $this->assertStringContainsString('ttd-img', (string) $dokumen->konten_html_signed);
        $this->assertStringContainsString('data-ttd-qr', (string) $dokumen->konten_html_signed);

        // Hash bertanda tangan harus cocok dengan HTML yang tersimpan.
        $this->assertSame(
            hash('sha256', (string) $dokumen->konten_html_signed),
            (string) $dokumen->hash_signed
        );

        // Slot ditandai ditandatangani.
        $this->assertDatabaseHas('dokumen_ttd', [
            'dokumen_id' => $r->json('id'),
            'status'     => 'ditandatangani',
        ]);
    }

    public function test_ttd_menolak_dokumen_yang_integritasnya_rusak(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');
        $png  = 'data:image/png;base64,' . base64_encode('dummy-png');

        $r = $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis' => 'SPP', 'ref_id' => 12, 'konten_html' => self::KONTEN,
            ]);

        $this->actingAs($user)->postJson('/api/v1/dokumen/ttd-gambar', ['gambar' => $png]);

        // Dokumen diubah diam-diam di luar aplikasi.
        Dokumen::where('id', $r->json('id'))->update([
            'konten_html' => str_replace('Rp 1.000.000', 'Rp 9.999.999', self::KONTEN),
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/dokumen/ttd', ['id' => $r->json('id')])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Integritas dokumen tidak terpenuhi. Kirim ulang dokumen dari laman cetak SIM-TKD.');
    }

    public function test_ttd_ganda_ditolak(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');
        $png  = 'data:image/png;base64,' . base64_encode('dummy-png');

        $r = $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis' => 'SPP', 'ref_id' => 12, 'konten_html' => self::KONTEN,
            ]);

        $this->actingAs($user)->postJson('/api/v1/dokumen/ttd-gambar', ['gambar' => $png]);

        $this->actingAs($user)
            ->postJson('/api/v1/dokumen/ttd', ['id' => $r->json('id')])
            ->assertOk();

        // Tanda tangan kedua -> 409
        $this->actingAs($user)
            ->postJson('/api/v1/dokumen/ttd', ['id' => $r->json('id')])
            ->assertStatus(409);
    }

    // -----------------------------------------------------------------
    // Verifikasi publik
    // -----------------------------------------------------------------

    public function test_verify_berhasil_untuk_dokumen_yang_ditandatangani(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');
        $png  = 'data:image/png;base64,' . base64_encode('dummy-png');

        $r = $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis' => 'SPP', 'nomor' => 'SPP/001/2026', 'konten_html' => self::KONTEN,
            ]);

        $this->actingAs($user)->postJson('/api/v1/dokumen/ttd-gambar', ['gambar' => $png]);
        $this->actingAs($user)->postJson('/api/v1/dokumen/ttd', ['id' => $r->json('id')])->assertOk();

        // Verifikasi bersifat PUBLIK (tanpa token).
        $this->getJson('/api/v1/dokumen/verify?kode=' . $r->json('kode_verifikasi'))
            ->assertOk()
            ->assertJsonPath('ditemukan', true)
            ->assertJsonPath('valid', true)
            ->assertJsonPath('dokumen.nomor', 'SPP/001/2026');
    }

    public function test_verify_menolak_kode_tidak_valid(): void
    {
        $this->getJson('/api/v1/dokumen/verify?kode=NGGAWAL')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_verify_menolak_kode_tidak_terdaftar(): void
    {
        $this->getJson('/api/v1/dokumen/verify?kode=TTD-DEADBEEF')
            ->assertStatus(404)
            ->assertJsonPath('ditemukan', false);
    }

    public function test_verify_dokumen_belum_ditandatangani_memakai_pesan_legacy(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');

        $r = $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis' => 'SPP', 'nomor' => 'SPP/001/2026', 'konten_html' => self::KONTEN,
            ]);

        // Publik: tetap 200, tetapi valid=false dengan pesan yang sama
        // seperti doc.simtkd.com/api/dokumen.php (dipakai verify.html).
        $this->getJson('/api/v1/dokumen/verify?kode=' . $r->json('kode_verifikasi'))
            ->assertOk()
            ->assertJsonPath('ditemukan', true)
            ->assertJsonPath('valid', false)
            ->assertJsonPath('message', 'Dokumen ditemukan namun belum ditandatangani / tanda tangan tidak valid.')
            ->assertJsonPath('dokumen.status', 'menunggu_ttd');
    }

    // -----------------------------------------------------------------
    // Kesetaraan bentuk respons dengan sistem legacy
    // -----------------------------------------------------------------

    public function test_detail_memakai_bentuk_respons_legacy(): void
    {
        $user = $this->buatUser('Dinas A', 'ua', '1987654321');
        $png  = 'data:image/png;base64,' . base64_encode('dummy-png');

        $r = $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis' => 'SPP', 'nomor' => 'SPP/001/2026', 'konten_html' => self::KONTEN,
            ]);

        $this->actingAs($user)->postJson('/api/v1/dokumen/ttd-gambar', ['gambar' => $png]);
        $this->actingAs($user)->postJson('/api/v1/dokumen/ttd', ['id' => $r->json('id')])->assertOk();

        // dokumen.html membaca SELURUH field ini secara datar dari `dokumen`.
        $this->actingAs($user)
            ->getJson('/api/v1/dokumen/detail?id=' . $r->json('id'))
            ->assertOk()
            ->assertJsonPath('dokumen.status', 'ditandatangani')
            ->assertJsonPath('dokumen.ttd_slot_urutan', 1)
            ->assertJsonPath('dokumen.ttd_slot_status', 'ditandatangani')
            ->assertJsonPath('dokumen.ttd_status', 'ditandatangani')
            ->assertJsonPath('dokumen.ttd_sisa', 0)
            ->assertJsonPath('dokumen.ttd_nama', 'User Dinas A')
            ->assertJsonPath('dokumen.ttd_jabatan', 'Bendahara')
            ->assertJsonPath('dokumen.ttd_nip', '1987654321')
            ->assertJsonPath('dokumen.hash_signed', fn ($v): bool => is_string($v) && strlen($v) === 64)
            ->assertJsonPath('dokumen.signed_at_fmt', fn ($v): bool => (bool) preg_match('/^\d{1,2} [A-Z][a-z]+ \d{4} \d{2}:\d{2}$/', (string) $v))
            ->assertJsonPath('dokumen.waktu_ttd', fn ($v): bool => (bool) preg_match('/^\d{1,2} [A-Z][a-z]+ \d{4} \d{2}:\d{2}$/', (string) $v));
    }

    public function test_list_mengirim_signed_at_fmt(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');
        $png  = 'data:image/png;base64,' . base64_encode('dummy-png');

        $r = $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis' => 'SPP', 'nomor' => 'SPP/001/2026', 'konten_html' => self::KONTEN,
            ]);

        $this->actingAs($user)->postJson('/api/v1/dokumen/ttd-gambar', ['gambar' => $png]);
        $this->actingAs($user)->postJson('/api/v1/dokumen/ttd', ['id' => $r->json('id')])->assertOk();

        // index.html memakai `signed_at_fmt` untuk baris kecil di bawah badge.
        $this->actingAs($user)
            ->getJson('/api/v1/dokumen/list')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'ditandatangani')
            ->assertJsonPath('data.0.signed_at_fmt', fn ($v): bool => (bool) preg_match('/^\d{1,2} [A-Z][a-z]+ \d{4} \d{2}:\d{2}$/', (string) $v));
    }

    // -----------------------------------------------------------------
    // Multi-penandatangan & keadaan data legacy
    // -----------------------------------------------------------------

    public function test_penandatangan_lain_tidak_bisa_menandatangani_dokumen(): void
    {
        $a   = $this->buatUser('Dinas A', 'ua');
        $b   = $this->buatUser('Dinas A', 'ub');
        $png = 'data:image/png;base64,' . base64_encode('dummy-png');

        $d = $this->buatDokumenTanpaSlot($a);

        DokumenTtd::create([
            'dokumen_id' => $d->id, 'user_id' => $a->id, 'urutan' => 1,
            'jabatan' => 'Bendahara', 'nama' => 'User Dinas A', 'status' => 'menunggu',
        ]);
        DokumenTtd::create([
            'dokumen_id' => $d->id, 'user_id' => $b->id, 'urutan' => 2,
            'jabatan' => 'Kepala Dinas', 'nama' => 'User Dinas A', 'status' => 'menunggu',
        ]);

        $this->actingAs($b)->postJson('/api/v1/dokumen/ttd-gambar', ['gambar' => $png]);
        $this->actingAs($a)->postJson('/api/v1/dokumen/ttd-gambar', ['gambar' => $png]);

        // Dokumen bersifat pribadi milik pembuatnya: penandatangan lain
        // ditolak 404 (sama dengan legacy: `WHERE id = ? AND user_id = ?`).
        $this->actingAs($b)
            ->postJson('/api/v1/dokumen/ttd', ['id' => $d->id])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Dokumen tidak ditemukan.');

        $this->actingAs($b)
            ->getJson('/api/v1/dokumen/detail?id=' . $d->id)
            ->assertStatus(404);

        // Pembuat dokumen tetap dapat menandatangani.
        $this->actingAs($a)
            ->postJson('/api/v1/dokumen/ttd', ['id' => $d->id])
            ->assertOk();
    }

    public function test_qr_verifikasi_tidak_digandakan(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');
        $png  = 'data:image/png;base64,' . base64_encode('dummy-png');

        // Konten dokumen sudah memuat blok verifikasi (mis. hasil cetak ulang);
        // penandatanganan tidak boleh menambah blok kedua.
        $konten = str_replace(
            '</div></body>',
            '<div data-ttd-qr="1">Blok verifikasi lama</div></div></body>',
            self::KONTEN
        );

        $r = $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis' => 'SPP', 'ref_id' => 12, 'konten_html' => $konten,
            ])
            ->assertOk();

        $this->actingAs($user)->postJson('/api/v1/dokumen/ttd-gambar', ['gambar' => $png]);

        $this->actingAs($user)
            ->postJson('/api/v1/dokumen/ttd', ['id' => $r->json('id')])
            ->assertOk()
            ->assertJsonPath('status', 'ditandatangani');

        $dokumen = Dokumen::find($r->json('id'));

        $this->assertSame(1, substr_count((string) $dokumen->konten_html_signed, 'data-ttd-qr="1"'));
    }

    public function test_slot_eksternal_tidak_menahan_penyelesaian_dokumen(): void
    {
        $user = $this->buatUser('Dinas A', 'ua');
        $png  = 'data:image/png;base64,' . base64_encode('dummy-png');

        // Slot pihak eksternal (user_id NULL) pada urutan 2.
        $r = $this->actingAs($user)
            ->postJson('/api/v1/dokumen/kirim', [
                'jenis'         => 'SPP',
                'ref_id'        => 12,
                'konten_html'   => self::KONTEN,
                'penandatangan' => [
                    ['user' => 'self', 'urutan' => 1, 'jabatan' => 'Bendahara', 'nama' => 'User Dinas A'],
                    ['user' => '', 'urutan' => 2, 'jabatan' => 'Kepala Dinas', 'nama' => 'Pihak Luar'],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('dokumen_ttd', [
            'dokumen_id' => $r->json('id'), 'urutan' => 2, 'user_id' => null,
        ]);

        $this->actingAs($user)->postJson('/api/v1/dokumen/ttd-gambar', ['gambar' => $png]);

        $this->actingAs($user)
            ->postJson('/api/v1/dokumen/ttd', ['id' => $r->json('id')])
            ->assertOk()
            ->assertJsonPath('status', 'ditandatangani');

        // ttd_sisa tidak menghitung slot pihak eksternal.
        $this->actingAs($user)
            ->getJson('/api/v1/dokumen/detail?id=' . $r->json('id'))
            ->assertOk()
            ->assertJsonPath('dokumen.ttd_sisa', 0);
    }

    public function test_ttd_pada_dokumen_tanpa_baris_penandatangan_membuat_slot_urutan_1(): void
    {
        $user = $this->buatUser('Dinas A', 'ua', '1987654321');
        $png  = 'data:image/png;base64,' . base64_encode('dummy-png');

        $d = $this->buatDokumenTanpaSlot($user);

        $this->actingAs($user)->postJson('/api/v1/dokumen/ttd-gambar', ['gambar' => $png]);

        $this->actingAs($user)
            ->postJson('/api/v1/dokumen/ttd', ['id' => $d->id])
            ->assertOk()
            ->assertJsonPath('status', 'ditandatangani');

        $this->assertDatabaseHas('dokumen_ttd', [
            'dokumen_id' => $d->id,
            'user_id'    => $user->id,
            'urutan'     => 1,
            'status'     => 'ditandatangani',
            'nip'        => '1987654321',
        ]);
    }
}
