<?php

/**
 * SIM-TKD — Konfigurasi CUTOVER (migrasi bertahap tanpa downtime)
 * ===============================================================
 *
 * File ini dibaca oleh includes/cutover.php (sisi legacy). Isinya
 * menentukan endpoint legacy mana yang DIALIHKAN ke aplikasi Laravel.
 *
 * PENGAMAN BAWAAN: `enabled => false` dan `rollout => 0`, sehingga mengunggah
 * file ini ke produksi TIDAK mengubah perilaku apa pun. Pengalihan baru
 * terjadi setelah sengaja dinyalakan (lihat laravel/docs/CUTOVER-TANPA-DOWNTIME.md).
 *
 * KILL SWITCH (tanpa edit file):
 *   - setenv SIMTKD_CUTOVER 0            -> matikan semua pengalihan (rollback total)
 *   - setenv SIMTKD_CUTOVER 1            -> aktifkan (ikut `enabled` di bawah)
 *   - setenv SIMTKD_CUTOVER_ROLLOUT 25   -> canary 25% (sticky per user)
 *   - setenv SIMTKD_CUTOVER_BASE https://next.simtkd.com -> alamat Laravel
 *
 * ROLLBACK = setel `enabled` ke false (atau env SIMTKD_CUTOVER=0). Tidak ada
 * perubahan data: kedua sistem memakai database yang sama.
 */

declare(strict_types=1);

return [

    // Saklar utama. false = legacy melayani SEMUA request (perilaku normal).
    'enabled' => false,

    // Alamat aplikasi Laravel (tanpa garis miring di akhir).
    'base_url' => 'https://next.simtkd.com',

    // Persentase trafik yang dialihkan (0-100). Bersifat "sticky" per user
    // (hash user_id) agar satu pengguna tidak berpindah-pindah sistem.
    'rollout' => 0,

    // Secret HMAC tiket SSO. WAJIB sama dengan SIMTKD_CUTOVER_SECRET di .env
    // Laravel. Kosong = pengalihan TIDAK dijalankan (fail-safe).
    'sso_secret' => '',

    // Umur tiket (detik); harus <= nilai di sisi Laravel.
    'ttl' => 90,

    // Batas waktu request ke Laravel (detik).
    'connect_timeout' => 2,
    'timeout' => 15,

    // Catat setiap pengalihan ke logs/cutover.log (lihat logs/.htaccess).
    'log' => true,

    // Tambahkan header X-Simtkd-Served-By pada respons hasil pengalihan
    // (memudahkan verifikasi: `curl -I https://simtkd.com/api/summary.php`).
    'tandai_respons' => true,

    // Verifikasi TLS. Biarkan true di produksi.
    'verify_tls' => true,

    /*
    |--------------------------------------------------------------------------
    | PETA ENDPOINT
    |--------------------------------------------------------------------------
    |
    | Setiap entri: file legacy + action  ->  endpoint Laravel.
    |
    |   file     : path relatif dari akar repo (mis. 'api/profil.php')
    |   action   : nama action legacy; '' = tanpa action, '*' = semua action
    |   path     : path tujuan di Laravel (mendukung {id} dari request)
    |   methods  : metode yang dialihkan (lainnya tetap dilayani legacy)
    |   fallback : true = bila Laravel tidak terjangkau, request dilayani
    |              legacy lagi (aman untuk endpoint baca-saja). Untuk
    |              endpoint tulis biarkan false agar TIDAK terjadi
    |              penulisan ganda.
    |
    | Urutan: entri action spesifik diperiksa lebih dulu daripada '*'.
    | HANYA endpoint yang modulnya sudah selesai diporting boleh didaftarkan
    | di sini (lihat laravel/README-MIGRASI.md -> status porting).
    |
    */

    'routes' => [

        // ---------------- Dashboard & Pengaturan ----------------
        [
            'file' => 'api/summary.php', 'action' => '*',
            'path' => '/api/v1/dashboard/summary', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/profil.php', 'action' => '',
            'path' => '/api/v1/profil', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/profil.php', 'action' => 'simpan',
            'path' => '/api/v1/profil', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/profil.php', 'action' => 'ganti_password',
            'path' => '/api/v1/profil/password', 'methods' => ['POST'],
        ],

        // ---------------- Lupa password (OTP) ----------------
        // Halaman lupa password diakses TANPA login -> perlu_login false.
        [
            'file' => 'api/lupa_password.php', 'action' => 'cek',
            'path' => '/api/v1/lupa-password/cek', 'methods' => ['POST'], 'perlu_login' => false,
        ],
        [
            'file' => 'api/lupa_password.php', 'action' => 'kirim_otp',
            'path' => '/api/v1/lupa-password/kirim-otp', 'methods' => ['POST'], 'perlu_login' => false,
        ],
        [
            'file' => 'api/lupa_password.php', 'action' => 'reset',
            'path' => '/api/v1/lupa-password/reset', 'methods' => ['POST'], 'perlu_login' => false,
        ],

        // ---------------- Penerimaan: permohonan ----------------
        [
            'file' => 'api/permohonan.php', 'action' => '',
            'path' => '/api/v1/penerimaan/permohonan', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/permohonan.php', 'action' => 'create',
            'path' => '/api/v1/penerimaan/permohonan', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/permohonan.php', 'action' => 'set_aktif',
            'path' => '/api/v1/penerimaan/permohonan/{id}/set-aktif', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/permohonan.php', 'action' => 'verifikasi',
            'path' => '/api/v1/penerimaan/permohonan/{id}/verifikasi', 'methods' => ['POST'],
        ],

        // ---------------- Penerimaan: akun ----------------
        [
            'file' => 'api/akun_penerimaan.php', 'action' => '',
            'path' => '/api/v1/penerimaan/akun', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/akun_penerimaan.php', 'action' => 'create',
            'path' => '/api/v1/penerimaan/akun', 'methods' => ['POST'],
        ],
        // Aksi bulk_create / save_selection / update_metode BELUM diporting:
        // sengaja tidak didaftarkan -> tetap dilayani legacy sampai selesai.

        // ---------------- Penerimaan: laporan ----------------
        [
            'file' => 'api/laporan.php', 'action' => 'obyek_penerimaan',
            'path' => '/api/v1/penerimaan/laporan/obyek', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/laporan.php', 'action' => 'buku_pembantu',
            'path' => '/api/v1/penerimaan/laporan/buku-pembantu', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/laporan.php', 'action' => 'rekap',
            'path' => '/api/v1/penerimaan/laporan/rekap', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/laporan.php', 'action' => 'bku',
            'path' => '/api/v1/penerimaan/laporan/bku', 'methods' => ['GET'], 'fallback' => true,
        ],

        // ---------------- SKP Daerah ----------------
        [
            'file' => 'api/skp.php', 'action' => '',
            'path' => '/api/v1/penerimaan/skp', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/skp.php', 'action' => 'create',
            'path' => '/api/v1/penerimaan/skp', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/skp.php', 'action' => 'update',
            'path' => '/api/v1/penerimaan/skp', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/skp.php', 'action' => 'delete',
            'path' => '/api/v1/penerimaan/skp', 'methods' => ['POST'],
        ],
        [
            // POST tanpa `action` diperlakukan legacy sebagai create.
            'file' => 'api/skp.php', 'action' => '',
            'path' => '/api/v1/penerimaan/skp', 'methods' => ['POST'],
        ],

        // ---------------- STS (Surat Tanda Setoran) ----------------
        [
            'file' => 'api/sts.php', 'action' => '*',
            'path' => '/api/v1/penerimaan/sts', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/sts.php', 'action' => 'create',
            'path' => '/api/v1/penerimaan/sts', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/sts.php', 'action' => '',
            'path' => '/api/v1/penerimaan/sts', 'methods' => ['POST'],
        ],

        // ---------------- STBP (Surat Tanda Bukti Penerimaan) ----------------
        [
            'file' => 'api/stbp.php', 'action' => '*',
            'path' => '/api/v1/penerimaan/stbp', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/stbp.php', 'action' => 'create',
            'path' => '/api/v1/penerimaan/stbp', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/stbp.php', 'action' => 'update_status',
            'path' => '/api/v1/penerimaan/stbp', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/stbp.php', 'action' => '',
            'path' => '/api/v1/penerimaan/stbp', 'methods' => ['POST'],
        ],

        // ---------------- Dokumen (app utama) ----------------
        [
            'file' => 'api/dokumen.php', 'action' => 'kirim',
            'path' => '/api/v1/dokumen/kirim', 'methods' => ['POST'],
        ],

        // ---------------- Dokumen (doc.simtkd.com) ----------------
        // Autentikasi legacy di sini memakai token API (?token=). Proksi
        // legacy menerjemahkannya menjadi tiket SSO (lihat cutover_identitas()).
        [
            'file' => 'doc.simtkd.com/api/dokumen.php', 'action' => 'me',
            'path' => '/api/v1/dokumen/me', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'doc.simtkd.com/api/dokumen.php', 'action' => 'list',
            'path' => '/api/v1/dokumen/list', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'doc.simtkd.com/api/dokumen.php', 'action' => 'detail',
            'path' => '/api/v1/dokumen/detail', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'doc.simtkd.com/api/dokumen.php', 'action' => 'konten',
            'path' => '/api/v1/dokumen/konten', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'doc.simtkd.com/api/dokumen.php', 'action' => 'ttd_gambar_saya',
            'path' => '/api/v1/dokumen/ttd-gambar-saya', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'doc.simtkd.com/api/dokumen.php', 'action' => 'ttd_gambar',
            'path' => '/api/v1/dokumen/ttd-gambar', 'methods' => ['POST'],
        ],
        [
            'file' => 'doc.simtkd.com/api/dokumen.php', 'action' => 'ttd',
            'path' => '/api/v1/dokumen/ttd', 'methods' => ['POST'],
        ],
        [
            'file' => 'doc.simtkd.com/api/dokumen.php', 'action' => 'verify',
            'path' => '/api/v1/dokumen/verify', 'methods' => ['GET'], 'fallback' => true, 'perlu_login' => false,
        ],

        // ---------------- Belanja: AKSI BACA (fase 3b-baca) ----------------
        // Semua endpoint di bawah bersifat baca-saja sehingga aman memakai
        // fallback: bila Laravel tidak dapat dihubungi / 5xx, permintaan
        // dikembalikan ke legacy (pengguna tidak melihat galat).
        [
            'file' => 'api/belanja.php', 'action' => 'summary',
            'path' => '/api/v1/belanja/summary', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            // GET tanpa `action` diperlakukan legacy sebagai summary.
            'file' => 'api/belanja.php', 'action' => '',
            'path' => '/api/v1/belanja/summary', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'rekanan_list',
            'path' => '/api/v1/belanja/rekanan', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'spd_list',
            'path' => '/api/v1/belanja/spd', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'spd_otor_list',
            'path' => '/api/v1/belanja/spd-otor', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'spp_list',
            'path' => '/api/v1/belanja/spp', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'spm_list',
            'path' => '/api/v1/belanja/spm', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'spm_ver_list',
            'path' => '/api/v1/belanja/spm-ver', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'sp2d_list',
            'path' => '/api/v1/belanja/sp2d', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'bku_belanja',
            'path' => '/api/v1/belanja/bku', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'lpj_list',
            'path' => '/api/v1/belanja/lpj', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'pengajuan_tu_list',
            'path' => '/api/v1/belanja/pengajuan-tu', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'rekening_skpd_list',
            'path' => '/api/v1/belanja/rekening-skpd', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'besaran_up_get',
            'path' => '/api/v1/belanja/besaran-up', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'kebijakan_spd_list',
            'path' => '/api/v1/belanja/kebijakan-spd', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'npd_list',
            'path' => '/api/v1/belanja/npd', 'methods' => ['GET'], 'fallback' => true,
        ],
        // ---------------- Belanja: MUTASI (fase 3b-mutasi) ----------------
        // TIDAK memakai fallback: bila Laravel gagal, proksi membalas 503 agar
        // tidak terjadi penulisan ganda (legacy + Laravel).
        [
            'file' => 'api/belanja.php', 'action' => 'rekanan_create',
            'path' => '/api/v1/belanja/rekanan', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'spd_create',
            'path' => '/api/v1/belanja/spd', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'spd_otorisasi',
            'path' => '/api/v1/belanja/spd/{id}/otorisasi', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'spp_create',
            'path' => '/api/v1/belanja/spp', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'spp_verifikasi',
            'path' => '/api/v1/belanja/spp/{id}/verifikasi', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'spm_persetujuan',
            'path' => '/api/v1/belanja/spm/{id}/persetujuan', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'spm_verifikasi',
            'path' => '/api/v1/belanja/spm/{id}/verifikasi', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'sp2d_create',
            'path' => '/api/v1/belanja/sp2d', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'sp2d_verifikasi',
            'path' => '/api/v1/belanja/sp2d/{id}/verifikasi', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'sp2d_pencairan',
            'path' => '/api/v1/belanja/sp2d/{id}/pencairan', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'lpj_create',
            'path' => '/api/v1/belanja/lpj', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'pengajuan_tu_create',
            'path' => '/api/v1/belanja/pengajuan-tu', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'pengajuan_tu_otorisasi',
            'path' => '/api/v1/belanja/pengajuan-tu/{id}/otorisasi', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'pengajuan_tu_validasi',
            'path' => '/api/v1/belanja/pengajuan-tu/{id}/validasi', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'rekening_skpd_create',
            'path' => '/api/v1/belanja/rekening-skpd', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'rekening_skpd_ajukan',
            'path' => '/api/v1/belanja/rekening-skpd/{id}/ajukan', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'rekening_skpd_nomor',
            'path' => '/api/v1/belanja/rekening-skpd/{id}/nomor', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'rekening_skpd_validasi',
            'path' => '/api/v1/belanja/rekening-skpd/{id}/validasi', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'besaran_up_set',
            'path' => '/api/v1/belanja/besaran-up', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'kebijakan_spd_create',
            'path' => '/api/v1/belanja/kebijakan-spd', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'npd_create',
            'path' => '/api/v1/belanja/npd', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'npd_validasi_pa',
            'path' => '/api/v1/belanja/npd/{id}/validasi-pa', 'methods' => ['POST'],
        ],
        [
            'file' => 'api/belanja.php', 'action' => 'npd_validasi_bp',
            'path' => '/api/v1/belanja/npd/{id}/validasi-bp', 'methods' => ['POST'],
        ],
// ---------------- AKLAP (peta.simtkd.com): aksi baca ----------------
        // Autentikasi legacy memakai token API (?token= / Bearer) yang
        // diterjemahkan proksi menjadi tiket SSO (lihat cutover_identitas()).
        [
            'file' => 'peta.simtkd.com/api/aklap.php', 'action' => 'jurnal',
            'path' => '/api/v1/aklap/jurnal', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            // GET tanpa `action` diperlakukan legacy sebagai jurnal.
            'file' => 'peta.simtkd.com/api/aklap.php', 'action' => '',
            'path' => '/api/v1/aklap/jurnal', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/aklap.php', 'action' => 'jurnal_biasa',
            'path' => '/api/v1/aklap/jurnal-biasa', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/aklap.php', 'action' => 'akun_list',
            'path' => '/api/v1/aklap/akun', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/aklap.php', 'action' => 'dokumen_list',
            'path' => '/api/v1/aklap/dokumen', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/aklap.php', 'action' => 'lra_rekap',
            'path' => '/api/v1/aklap/lra', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/aklap.php', 'action' => 'neraca_rekap',
            'path' => '/api/v1/aklap/neraca', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/aklap.php', 'action' => 'profil',
            'path' => '/api/v1/aklap/profil', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/aklap.php', 'action' => 'rekap',
            'path' => '/api/v1/aklap/rekap', 'methods' => ['GET'], 'fallback' => true,
        ],
        // Mutasi AKLAP (approve/reject, jurnal_biasa simpan/hapus) belum
        // dipetakan sehingga tetap dilayani legacy.

        // ---------- AKLAP: akuntansi.php, master akun + anggaran ----------
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'profil',
            'path' => '/api/v1/aklap/profil-akuntansi', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'akun_list',
            'path' => '/api/v1/aklap/akun-master', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'akun_simpan',
            'path' => '/api/v1/aklap/akun-master', 'methods' => ['POST'],
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'akun_hapus',
            'path' => '/api/v1/aklap/akun-master/hapus', 'methods' => ['POST'],
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'akun_seed_coa',
            'path' => '/api/v1/aklap/akun-master/seed-coa', 'methods' => ['POST'],
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'anggaran_list',
            'path' => '/api/v1/aklap/anggaran', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'anggaran_simpan',
            'path' => '/api/v1/aklap/anggaran', 'methods' => ['POST'],
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'anggaran_hapus',
            'path' => '/api/v1/aklap/anggaran/hapus', 'methods' => ['POST'],
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'anggaran_sinkron_master',
            'path' => '/api/v1/aklap/anggaran/sinkron-master', 'methods' => ['POST'],
        ],

        // ---------- AKLAP: akuntansi.php, jurnal umum + saran jurnal ----------
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'jurnal_list',
            'path' => '/api/v1/aklap/jurnal-umum', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'jurnal_simpan',
            'path' => '/api/v1/aklap/jurnal-umum', 'methods' => ['POST'],
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'jurnal_hapus',
            'path' => '/api/v1/aklap/jurnal-umum/hapus', 'methods' => ['POST'],
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'jurnal_status',
            'path' => '/api/v1/aklap/jurnal-umum/status', 'methods' => ['POST'],
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'saran_dokumen',
            'path' => '/api/v1/aklap/saran-dokumen', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'saran_jurnal',
            'path' => '/api/v1/aklap/saran-jurnal', 'methods' => ['GET'], 'fallback' => true,
        ],
        // ---------- AKLAP: akuntansi.php, laporan akuntansi (baca-saja) ----------
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'buku_besar',
            'path' => '/api/v1/aklap/buku-besar', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'neraca_saldo',
            'path' => '/api/v1/aklap/neraca-saldo', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'buku_kas_list',
            'path' => '/api/v1/aklap/buku-kas', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'buku_kas_umum',
            'path' => '/api/v1/aklap/buku-kas-umum', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'buku_pembantu_list',
            'path' => '/api/v1/aklap/buku-pembantu', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'buku_pembantu_obyek_list',
            'path' => '/api/v1/aklap/buku-pembantu-obyek', 'methods' => ['GET'], 'fallback' => true,
        ],
        // ---------- AKLAP: akuntansi.php, mutasi kas + posisi kas harian ----------
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'buku_kas_simpan',
            'path' => '/api/v1/aklap/buku-kas', 'methods' => ['POST'],
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'buku_kas_hapus',
            'path' => '/api/v1/aklap/buku-kas/hapus', 'methods' => ['POST'],
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'kas_harian',
            'path' => '/api/v1/aklap/kas-harian', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'kas_harian_daftar',
            'path' => '/api/v1/aklap/kas-harian/daftar', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'kas_harian_simpan',
            'path' => '/api/v1/aklap/kas-harian', 'methods' => ['POST'],
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'kas_harian_hapus',
            'path' => '/api/v1/aklap/kas-harian/hapus', 'methods' => ['POST'],
        ],
        // ---------- AKLAP: akuntansi.php, rekonsiliasi bank ----------
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'rekonsiliasi_bank',
            'path' => '/api/v1/aklap/rekonsiliasi-bank', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'rekonsiliasi_bank_daftar',
            'path' => '/api/v1/aklap/rekonsiliasi-bank/daftar', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'rekonsiliasi_bank_simpan',
            'path' => '/api/v1/aklap/rekonsiliasi-bank', 'methods' => ['POST'],
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'rekonsiliasi_bank_hapus',
            'path' => '/api/v1/aklap/rekonsiliasi-bank/hapus', 'methods' => ['POST'],
        ],
        // ---------- AKLAP: akuntansi.php, register & LPJ PPKD ----------
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'register_ppkd_list',
            'path' => '/api/v1/aklap/register-ppkd', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'register_ppkd_simpan',
            'path' => '/api/v1/aklap/register-ppkd', 'methods' => ['POST'],
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'lpj_ppkd',
            'path' => '/api/v1/aklap/lpj-ppkd', 'methods' => ['GET'], 'fallback' => true,
        ],
        // ---------- AKLAP: akuntansi.php, jurnal khusus & kirim dokumen ----------
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'jurnal_khusus_template',
            'path' => '/api/v1/aklap/jurnal-khusus/template', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'jurnal_khusus',
            'path' => '/api/v1/aklap/jurnal-khusus', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'jurnal_khusus_daftar',
            'path' => '/api/v1/aklap/jurnal-khusus/daftar', 'methods' => ['GET'], 'fallback' => true,
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'jurnal_khusus_simpan',
            'path' => '/api/v1/aklap/jurnal-khusus', 'methods' => ['POST'],
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'jurnal_khusus_hapus',
            'path' => '/api/v1/aklap/jurnal-khusus/hapus', 'methods' => ['POST'],
        ],
        [
            'file' => 'peta.simtkd.com/api/akuntansi.php', 'action' => 'dokumen_kirim',
            'path' => '/api/v1/aklap/dokumen-kirim', 'methods' => ['POST'],
        ],
        // Seluruh aksi akuntansi.php kini portabel ke Laravel.
    ],
];
