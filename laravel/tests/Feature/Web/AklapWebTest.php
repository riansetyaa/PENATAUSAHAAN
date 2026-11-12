<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tes Fase 4 — halaman Blade (frontend Laravel).
 *
 * Halaman pilot: Buku Kas Pengeluaran PPKD. Yang diuji di sisi server:
 *  - tamu diarahkan ke /masuk (bukan login.html legacy),
 *  - login/logout memakai sesi Laravel + penolakan akun nonaktif,
 *  - halaman pilot tampil dengan layout, menu, dan data identitas pengguna,
 *  - halaman memuat token CSRF & basis API yang dipakai JS,
 *  - endpoint API tetap memakai sesi yang sama (Sanctum stateful) sehingga
 *    daftar buku kas bisa dibaca dari browser tanpa token Bearer.
 */
class AklapWebTest extends TestCase
{
    use RefreshDatabase;

    private function buatUser(string $instansi = 'Dinas A', string $username = 'ua', string $status = User::STATUS_AKTIF): User
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
            'status'       => $status,
        ]);
    }

    public function test_tamu_diarahkan_ke_halaman_masuk(): void
    {
        $this->get('/aklap')->assertRedirect('/masuk');
        $this->get('/aklap/buku-pengeluaran')->assertRedirect('/masuk');

        $this->get('/masuk')
            ->assertOk()
            ->assertSee('Masuk ke aplikasi')
            ->assertSee('SIM-TKD');
    }

    public function test_landing_page_aklap_identik_dengan_halaman_legacy(): void
    {
        $user = $this->buatUser();

        $res = $this->actingAs($user)->get('/aklap')->assertOk();

        // Kepala halaman & aset yang sama seperti peta.simtkd.com/index.html.
        $res->assertSee('<title>Dashboard - AKLAP | peta.simtkd.com</title>', false)
            ->assertSee('Aplikasi Laporan Akuntansi Pemerintahan')
            ->assertSee('AKLAP · SIM-TKD')
            ->assertSee('img/logo-polban.png', false)
            ->assertSee('js/aklap/config.js', false)
            ->assertSee('js/aklap/menu.js', false)
            ->assertSee('js/aklap/sidebar.js', false);

        // Enam kartu statistik dengan id yang sama (diisi JS dari API).
        foreach (['stStbp', 'stSts', 'stPermohonan', 'stKegiatan', 'stPenerimaan', 'stPagu'] as $id) {
            $res->assertSee('id="' . $id . '"', false);
        }

        // Akses cepat & tabel jurnal terbaru.
        $res->assertSee('Jurnal Umum (Manual)')
            ->assertSee('Laporan Realisasi Anggaran (LRA)')
            ->assertSee('Jurnal Terbaru')
            ->assertSee('id="recentBody"', false);

        // Tautan kembali ke Penatausahaan & footer persis legacy.
        $res->assertSee('https://simtkd.com/dashboard.html', false)
            ->assertSee('Politeknik Negeri Bandung (Jurusan Akuntansi)');

        // Data diambil dari endpoint Laravel (bukan api/*.php legacy).
        $res->assertSee('SIMTKD_API.endpoint', false)
            ->assertSee("(action === 'rekap' ? 'rekap' : action)", false)
            ->assertSee("(action === 'jurnal_list' ? 'jurnal-umum' : action)", false)
            ->assertSee("SIMTKD_API.endpoint + '/profil'", false)
            ->assertDontSee('api/akuntansi.php', false)
            ->assertDontSee('aklap.php?token=', false);

        // Shim config.js: basis API Laravel + tanpa token di URL.
        $shim = (string) file_get_contents(public_path('js/aklap/config.js'));

        $this->assertStringContainsString("endpoint: '/api/v1/aklap'", $shim);
        $this->assertStringContainsString("getToken: function () { return ''; }", $shim);
        $this->assertStringContainsString("'/aklap/buku-pengeluaran'", $shim);

        // menu.js & sidebar.js adalah salinan persis berkas legacy.
        // Sumber legacy ada di folder PENATAUSAHAAN (config aklap.legacy_root),
        // bukan di sebelah folder proyek Laravel.
        $akarLegacy = (string) config('aklap.legacy_root');

        foreach (['menu.js', 'sidebar.js'] as $berkas) {
            $this->assertFileEquals(
                $akarLegacy . '/peta.simtkd.com/js/' . $berkas,
                public_path('js/aklap/' . $berkas),
                $berkas . ' harus identik dengan versi legacy'
            );
        }
    }

    public function test_angka_landing_cocok_dengan_api_rekap_dan_jurnal(): void
    {
        $user = $this->buatUser();

        // Data contoh: satu STBP tervalidasi + satu jurnal manual.
        DB::table('stbp')->insert([
            'user_id' => $user->id, 'skpd' => 'Dinas A', 'nomor_stbp' => 'STBP-1', 'tanggal' => '2026-03-05',
            'akun_kode' => '4.1.4.01', 'akun_nama' => 'Pajak', 'jumlah' => '1000000.00', 'uraian' => 'Setoran',
            'status' => 'sudah_divalidasi', 'jurnal_status' => 'sudah_approve',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('jurnal_umum')->insert([
            'skpd' => 'Dinas A', 'nomor_jurnal' => 'JU-TEST-0001', 'tanggal' => '2026-03-10',
            'uraian' => 'Penerimaan pajak', 'sumber_tipe' => 'manual', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Endpoint yang dipakai landing: harus mengembalikan bentuk yang
        // dibaca skrip halaman (data.stbp, data.total_penerimaan, data[]).
        $rekap = $this->actingAs($user)->getJson('/api/v1/aklap/rekap')->assertOk();
        $rekap->assertJsonPath('data.stbp', 1);

        $jurnal = $this->actingAs($user)->getJson('/api/v1/aklap/jurnal-umum')->assertOk();
        $jurnal->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.nomor_jurnal', 'JU-TEST-0001');

        $profil = $this->actingAs($user)->getJson('/api/v1/aklap/profil')->assertOk();
        $profil->assertJsonPath('user.nama', 'Bendahara Dinas A')
            ->assertJsonPath('user.kota', 'Bandung');

        // Halaman tetap tampil walau data kosong (id kartu dipakai JS).
        $this->actingAs($user)->get('/aklap')->assertOk()->assertSee('id="stPenerimaan"', false);
    }

    public function test_login_berhasil_menuju_halaman_pilot_dan_logout_mengakhiri_sesi(): void
    {
        $user = $this->buatUser();

        $this->post('/masuk', ['username' => 'ua', 'password' => 'RahasiaKuat123'])
            ->assertRedirect(route('aklap.beranda'));

        $this->assertAuthenticatedAs($user);

        $this->get('/aklap/buku-pengeluaran')->assertOk();

        $this->post('/keluar')->assertRedirect(route('masuk'));

        $this->assertGuest();
        $this->get('/aklap/buku-pengeluaran')->assertRedirect('/masuk');
    }

    public function test_login_menolak_kata_sandi_salah_dan_akun_nonaktif(): void
    {
        $this->buatUser('Dinas A', 'ua');
        $this->buatUser('Dinas B', 'ub', User::STATUS_NONAKTIF);

        $this->from('/masuk')->post('/masuk', ['username' => 'ua', 'password' => 'salah'])
            ->assertRedirect('/masuk')
            ->assertSessionHasErrors('username');

        $this->assertGuest();

        $this->from('/masuk')->post('/masuk', ['username' => 'ub', 'password' => 'RahasiaKuat123'])
            ->assertRedirect('/masuk')
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_halaman_pilot_merender_layout_menu_dan_identitas(): void
    {
        $user = $this->buatUser();

        $res = $this->actingAs($user)->get('/aklap/buku-pengeluaran')->assertOk();

        // Kerangka layout bersama.
        $res->assertSee('AKLAP · SIM-TKD')
            ->assertSee('Aplikasi Laporan Akuntansi Pemerintahan')
            ->assertSee('img/logo-polban.png', false)
            ->assertSee('https://simtkd.com/dashboard.html', false)
            ->assertSee('Buku Kas Pengeluaran PPKD')
            ->assertSee('Menu AKLAP')
            ->assertSee('Kembali ke Penatausahaan')
            ->assertSee('css/aklap.css', false);

        // Identitas pengguna & instansi pada topbar.
        $res->assertSee('Bendahara Dinas A')
            ->assertSee('Dinas A')
            ->assertSee('Bandung');

        // Menu: halaman pilot aktif, halaman lain menautkan legacy.
        $res->assertSee(route('aklap.pengeluaran'), false)
            ->assertSee('buku-besar.html', false)
            ->assertSee('legacy');

        // Struktur menu WAJIB sama dengan peta.simtkd.com/js/menu.js:
        // 13 butir, dua grup dropdown (Buku Kas & Laporan Keuangan) dengan
        // id elemen yang sama seperti versi legacy.
        $res->assertSee('Jurnal Umum')
            ->assertSee('Buku Jurnal Khusus')
            ->assertSee('Jurnal Khusus Konsolidator')
            ->assertSee('Neraca Saldo')
            ->assertSee('Register SPP/SPM/SP2D')
            ->assertSee('LPJ Bendahara PPKD')
            ->assertSee('Buku Pembantu Objek')
            ->assertSee('Master Akun')
            ->assertSee('Anggaran (Pagu)')
            ->assertSee('id="sideGroupBukuKas"', false)
            ->assertSee('id="sideSubBukuKas"', false)
            ->assertSee('id="sideGroupKeuangan"', false)
            ->assertSee('id="sideSubKeuangan"', false)
            ->assertSee('Buku Kas Umum (BUD)')
            ->assertSee('Laporan Kas Harian')
            ->assertSee('Rekonsiliasi Bank')
            ->assertSee('fa-chevron-right side-arrow', false);

        // Dropdown "Buku Kas" otomatis terbuka karena halaman aktif ada di dalamnya.
        $res->assertSee('side-group open', false)
            ->assertSee('side-sub open', false);

        // Footer & toggle sidebar persis legacy.
        $res->assertSee('Politeknik Negeri Bandung (Jurusan Akuntansi)')
            ->assertSee('js/aklap/sidebar.js', false);

        // Kontrak untuk JS: token CSRF + basis API.
        $res->assertSee('name="csrf-token"', false)
            ->assertSee(url('/api/v1/aklap'), false)
            ->assertSee('/buku-kas/hapus', false);
    }

    public function test_api_aklap_dapat_dipakai_dengan_sesi_browser(): void
    {
        $user = $this->buatUser();

        DB::table('buku_kas')->insert([
            'skpd' => 'Dinas A', 'tanggal' => '2026-03-10', 'jenis' => 'pengeluaran',
            'no_bukti' => 'BK-1', 'kode_rekening' => '5.1.2.01', 'uraian' => 'Belanja ATK', 'jumlah' => '75000.00',
        ]);

        // Tanpa Bearer token: sesi web yang dipakai (Sanctum stateful).
        $this->actingAs($user)
            ->getJson('/api/v1/aklap/buku-kas?jenis=pengeluaran')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.uraian', 'Belanja ATK')
            ->assertJsonPath('total_pengeluaran', 75000);

        // Mutasi lewat API dari sesi yang sama.
        $this->actingAs($user)
            ->postJson('/api/v1/aklap/buku-kas', [
                'tanggal' => '2026-03-11', 'jenis' => 'pengeluaran',
                'no_bukti' => 'BK-2', 'kode_rekening' => '5.1.2.01', 'uraian' => 'Belanja Cetak', 'jumlah' => '25000',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Transaksi tersimpan.');

        $this->assertSame(2, DB::table('buku_kas')->where('skpd', 'Dinas A')->count());
    }
}
