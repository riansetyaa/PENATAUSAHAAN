<?php

declare(strict_types=1);

/**
 * Katalog skenario differential test.
 *
 * Setiap skenario membandingkan satu endpoint LAMA (PHP vanilla) dengan
 * endpoint BARU (Laravel). API lama adalah golden master.
 *
 * Skenario yang endpoint barunya belum ada ditandai `'baru' => null` dan
 * akan dilaporkan sebagai PENDING — itulah CHECKLIST porting yang hidup.
 *
 * Placeholder pada parameter: {{nama}} diisi dari variabel runtime
 * (lihat run.php). Contoh: {{user.aktif.username}}
 */

return [

    // =================================================================
    // AUTH — SUDAH DIMIGDASI KE LARAVEL
    // =================================================================

    [
        'name'  => 'auth.login.gagal.password_salah',
        'desc'  => 'Login dengan password salah ditolak (401)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/login.php',
            'params' => [
                'username' => '{{user.aktif.username}}',
                'password' => 'PasswordSalahSekali',
            ],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/auth/login',
            'params' => [
                'username' => '{{user.aktif.username}}',
                'password' => 'PasswordSalahSekali',
            ],
        ],
        'expect' => ['success' => false, 'message' => 'Username/Email atau password tidak valid.'],
    ],

    [
        'name'  => 'auth.login.gagal.username_tidak_ada',
        'desc'  => 'Login dengan username yang tidak terdaftar ditolak (401)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/login.php',
            'params' => ['username' => 'user_hilang_total', 'password' => 'apapun123'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/auth/login',
            'params' => ['username' => 'user_hilang_total', 'password' => 'apapun123'],
        ],
        'expect' => ['success' => false, 'message' => 'Username/Email atau password tidak valid.'],
    ],

    [
        'name'  => 'auth.login.gagal.kosong',
        'desc'  => 'Login tanpa kredensial ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/login.php',
            'params' => ['username' => '', 'password' => ''],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/auth/login',
            'params' => ['username' => '', 'password' => ''],
        ],
        // Pesan legacy: "Username/Email dan password wajib diisi."
        // Pesan baru  : pesan validasi Laravel (berbeda format).
        // -> yang dibandingkan hanya flag sukses & status HTTP.
        'drop' => ['message', 'errors'],
        'expect' => ['success' => false],
    ],

    [
        'name'  => 'auth.login.gagal.akun_pending',
        'desc'  => 'Akun pending tidak bisa login (403 + petunjuk aktivasi)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/login.php',
            'params' => [
                'username' => '{{user.pending.username}}',
                'password' => '{{user.password}}',
            ],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/auth/login',
            'params' => [
                'username' => '{{user.pending.username}}',
                'password' => '{{user.password}}',
            ],
        ],
        'expect' => [
            'success'         => false,
            'status'          => 'pending',
            'need_activation' => true,
        ],
    ],

    [
        'name'  => 'auth.login.gagal.akun_nonaktif',
        'desc'  => 'Akun nonaktif ditolak (403)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/login.php',
            'params' => [
                'username' => '{{user.nonaktif.username}}',
                'password' => '{{user.password}}',
            ],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/auth/login',
            'params' => [
                'username' => '{{user.nonaktif.username}}',
                'password' => '{{user.password}}',
            ],
        ],
        'expect' => [
            'success' => false,
            'message' => 'Akun Anda dinonaktifkan. Hubungi administrator.',
        ],
    ],

    // =================================================================
    // REGISTER
    // =================================================================

    [
        'name'  => 'auth.register.gagal.email_bukan_polban',
        'desc'  => 'Pendaftaran ditolak untuk domain selain @polban.ac.id (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/register.php',
            'params' => [
                'nama_lengkap' => 'Uji Difftest',
                'username'     => '{{user.baru.username}}',
                'email'        => '{{user.baru.email_gmail}}',
                'password'     => '{{user.password}}',
                'instansi'     => 'Dinas Uji',
            ],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/auth/register',
            'params' => [
                'nama_lengkap' => 'Uji Difftest',
                'username'     => '{{user.baru.username}}',
                'email'        => '{{user.baru.email_gmail}}',
                'password'     => '{{user.password}}',
                'instansi'     => 'Dinas Uji',
            ],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'email',
            'message' => 'Pendaftaran hanya menggunakan email domain @polban.ac.id.',
        ],
    ],

    [
        'name'  => 'auth.register.gagal.username_pendek',
        'desc'  => 'Username di bawah 3 karakter ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/register.php',
            'params' => [
                'nama_lengkap' => 'Uji Difftest',
                'username'     => 'ab',
                'email'        => '{{user.baru.email}}',
                'password'     => '{{user.password}}',
                'instansi'     => 'Dinas Uji',
            ],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/auth/register',
            'params' => [
                'nama_lengkap' => 'Uji Difftest',
                'username'     => 'ab',
                'email'        => '{{user.baru.email}}',
                'password'     => '{{user.password}}',
                'instansi'     => 'Dinas Uji',
            ],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'username',
            'message' => 'Username 3-20 karakter (huruf, angka, . _ -).',
        ],
    ],

    [
        'name'  => 'auth.register.gagal.password_pendek',
        'desc'  => 'Password di bawah 8 karakter ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/register.php',
            'params' => [
                'nama_lengkap' => 'Uji Difftest',
                'username'     => '{{user.baru.username}}',
                'email'        => '{{user.baru.email}}',
                'password'     => 'pendek',
                'instansi'     => 'Dinas Uji',
            ],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/auth/register',
            'params' => [
                'nama_lengkap' => 'Uji Difftest',
                'username'     => '{{user.baru.username}}',
                'email'        => '{{user.baru.email}}',
                'password'     => 'pendek',
                'instansi'     => 'Dinas Uji',
            ],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'password',
            'message' => 'Kata sandi minimal 8 karakter.',
        ],
    ],

    [
        'name'  => 'auth.register.gagal.username_duplikat',
        'desc'  => 'Username yang sudah dipakai ditolak (409)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/register.php',
            'params' => [
                'nama_lengkap' => 'Uji Difftest',
                'username'     => '{{user.aktif.username}}',
                'email'        => '{{user.baru.email}}',
                'password'     => '{{user.password}}',
                'instansi'     => 'Dinas Uji',
            ],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/auth/register',
            'params' => [
                'nama_lengkap' => 'Uji Difftest',
                'username'     => '{{user.aktif.username}}',
                'email'        => '{{user.baru.email}}',
                'password'     => '{{user.password}}',
                'instansi'     => 'Dinas Uji',
            ],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'username',
            'message' => 'Username sudah digunakan.',
        ],
    ],

    // =================================================================
    // AKTIVASI OTP
    // =================================================================

    [
        'name'  => 'aktivasi.cek.gagal.email_tidak_terdaftar',
        'desc'  => 'Cek akun dengan email tak terdaftar (404)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/aktivasi.php',
            'params' => ['action' => 'cek', 'email' => 'entah_tidak_ada@polban.ac.id'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/aktivasi/cek',
            'params' => ['email' => 'entah_tidak_ada@polban.ac.id'],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'email',
            'message' => 'Email belum terdaftar. Pastikan email yang Anda masukkan sama dengan email saat mendaftar.',
        ],
    ],

    [
        'name'  => 'aktivasi.cek.gagal.format_email',
        'desc'  => 'Cek akun dengan format email tidak valid (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/aktivasi.php',
            'params' => ['action' => 'cek', 'email' => 'bukan-email'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/aktivasi/cek',
            'params' => ['email' => 'bukan-email'],
        ],
        // CATATAN: 'bukan-email' LOLOS dari pemeriksaan format pada kedua
        // API, karena string tersebut juga memenuhi pola username
        // (huruf + strip, 3-20 karakter). Karena itu keduanya sama-sama
        // pencarian akun dan sama-sama membalas 404 "belum terdaftar".
        'expect' => [
            'success' => false,
            'field'   => 'email',
            'message' => 'Email belum terdaftar. Pastikan email yang Anda masukkan sama dengan email saat mendaftar.',
        ],
    ],

    [
        'name'  => 'aktivasi.cek.berhasil.pending',
        'desc'  => 'Cek akun pending menampilkan nama & instansi, email disamarkan',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/aktivasi.php',
            'params' => ['action' => 'cek', 'email' => '{{user.pending.email}}'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/aktivasi/cek',
            'params' => ['email' => '{{user.pending.email}}'],
        ],
        'expect' => [
            'success'       => true,
            'status'        => 'pending',
            'user.instansi' => '{{user.pending.instansi}}',
        ],
    ],

    [
        'name'  => 'aktivasi.cek.berhasil.sudah_aktif',
        'desc'  => 'Cek akun yang sudah aktif memberi tahu untuk login',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/aktivasi.php',
            'params' => ['action' => 'cek', 'email' => '{{user.aktif.email}}'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/aktivasi/cek',
            'params' => ['email' => '{{user.aktif.email}}'],
        ],
        'expect' => [
            'success'  => true,
            'status'   => 'aktif',
            'redirect' => 'login.html',
        ],
    ],

    [
        'name'  => 'aktivasi.verifikasi.gagal.otp_kosong',
        'desc'  => 'Verifikasi tanpa kode OTP ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/aktivasi.php',
            'params' => ['action' => 'verifikasi', 'email' => '{{user.pending.email}}', 'otp' => ''],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/aktivasi/verifikasi',
            'params' => ['email' => '{{user.pending.email}}', 'otp' => ''],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'otp',
            'message' => 'Kode OTP wajib diisi.',
        ],
    ],

    [
        'name'  => 'aktivasi.verifikasi.gagal.otp_panjang_salah',
        'desc'  => 'Kode OTP dengan panjang salah ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/aktivasi.php',
            'params' => ['action' => 'verifikasi', 'email' => '{{user.pending.email}}', 'otp' => '123'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/aktivasi/verifikasi',
            'params' => ['email' => '{{user.pending.email}}', 'otp' => '123'],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'otp',
            'message' => 'Kode OTP terdiri dari 6 angka.',
        ],
    ],

    [
        'name'  => 'aktivasi.verifikasi.gagal.otp_belum_dibuat',
        'desc'  => 'Verifikasi tanpa kode OTP aktif ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/aktivasi.php',
            'params' => ['action' => 'verifikasi', 'email' => '{{user.pending.email}}', 'otp' => '123456'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/aktivasi/verifikasi',
            'params' => ['email' => '{{user.pending.email}}', 'otp' => '123456'],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'otp',
            'message' => 'Kode OTP belum dibuat. Klik Konfirmasi untuk menerima kode OTP lebih dulu.',
        ],
    ],

    // =================================================================
    // SESI & LOGOUT
    // =================================================================

    [
        'name'  => 'auth.session.terlogin',
        'desc'  => 'Cek sesi mengembalikan data user yang login',
        'legacy' => [
            'method' => 'GET',
            'path'   => '/api/session.php',
            'params' => [],
        ],
        'baru' => [
            'method' => 'GET',
            'path'   => '/api/v1/auth/session',
            'params' => [],
        ],
        // `api_token` milik legacy TIDAK lagi relevan: token API kini
        // dikelola Sanctum (tabel personal_access_tokens) dan tidak
        // disimpan di tabel users, sehingga tidak dikembalikan.
        'drop' => ['user.api_token'],
        'expect' => ['success' => true],
    ],

    [
        'name'  => 'auth.logout',
        'desc'  => 'Logout menghapus sesi',
        'legacy' => [
            'method' => 'GET',
            'path'   => '/api/logout.php',
            'params' => [],
        ],
        'baru' => [
            'method' => 'GET',
            'path'   => '/api/v1/auth/logout',
            'params' => [],
        ],
        'expect' => ['success' => true],
    ],

    // =================================================================
    // BELUM DIMIGDASI — CHECKLIST PORTING
    // =================================================================

    // ---- Modul utama: penerimaan ----
    ['name' => 'permohonan.list',          'desc' => 'Daftar permohonan rekening',            'legacy' => ['method' => 'GET', 'path' => '/api/permohonan.php'], 'baru' => null],
    ['name' => 'permohonan.set_aktif',     'desc' => 'Set aktif/nonaktif permohonan',          'legacy' => ['method' => 'POST', 'path' => '/api/permohonan.php', 'params' => ['action' => 'set_aktif']], 'baru' => null],
    ['name' => 'permohonan.verifikasi',    'desc' => 'Verifikasi permohonan',                  'legacy' => ['method' => 'POST', 'path' => '/api/permohonan.php', 'params' => ['action' => 'verifikasi']], 'baru' => null],
    ['name' => 'akun_penerimaan.list',     'desc' => 'Daftar akun penerimaan',                 'legacy' => ['method' => 'GET', 'path' => '/api/akun_penerimaan.php'], 'baru' => null],
    ['name' => 'akun_penerimaan.create',   'desc' => 'Buat akun penerimaan',                   'legacy' => ['method' => 'POST', 'path' => '/api/akun_penerimaan.php', 'params' => ['action' => 'create']], 'baru' => null],
    ['name' => 'akun_penerimaan.update',   'desc' => 'Ubah akun penerimaan',                   'legacy' => ['method' => 'POST', 'path' => '/api/akun_penerimaan.php', 'params' => ['action' => 'update_metode']], 'baru' => null],
    ['name' => 'sts.list',                 'desc' => 'Daftar STS',                             'legacy' => ['method' => 'GET', 'path' => '/api/sts.php'], 'baru' => null],
    ['name' => 'stbp.list',                'desc' => 'Daftar STBP',                            'legacy' => ['method' => 'GET', 'path' => '/api/stbp.php'], 'baru' => null],
    ['name' => 'stbp.update_status',       'desc' => 'Ubah status STBP',                       'legacy' => ['method' => 'POST', 'path' => '/api/stbp.php', 'params' => ['action' => 'update_status']], 'baru' => null],
    ['name' => 'skp.list',                 'desc' => 'Daftar SKP Daerah',                      'legacy' => ['method' => 'GET', 'path' => '/api/skp.php'], 'baru' => null],
    ['name' => 'skp.create',               'desc' => 'Buat SKP Daerah',                        'legacy' => ['method' => 'POST', 'path' => '/api/skp.php', 'params' => ['action' => 'create']], 'baru' => null],
    ['name' => 'skp.delete',               'desc' => 'Hapus SKP Daerah',                       'legacy' => ['method' => 'POST', 'path' => '/api/skp.php', 'params' => ['action' => 'delete']], 'baru' => null],
    ['name' => 'laporan.rekap',            'desc' => 'Rekap penerimaan',                       'legacy' => ['method' => 'GET', 'path' => '/api/laporan.php', 'params' => ['action' => 'rekap']], 'baru' => null],
    ['name' => 'laporan.buku_pembantu',    'desc' => 'Buku pembantu penerimaan',               'legacy' => ['method' => 'GET', 'path' => '/api/laporan.php', 'params' => ['action' => 'buku_pembantu']], 'baru' => null],
    ['name' => 'laporan.obyek_penerimaan', 'desc' => 'Rincian objek penerimaan',               'legacy' => ['method' => 'GET', 'path' => '/api/laporan.php', 'params' => ['action' => 'obyek_penerimaan']], 'baru' => null],

    // ---- Modul utama: belanja ----
    ['name' => 'belanja.summary',          'desc' => 'Ringkasan belanja',                      'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'summary']], 'baru' => null],
    ['name' => 'belanja.rekanan_list',     'desc' => 'Daftar rekanan',                         'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'rekanan_list']], 'baru' => null],
    ['name' => 'belanja.spd_list',         'desc' => 'Daftar SPD',                             'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'spd_list']], 'baru' => null],
    ['name' => 'belanja.spp_list',         'desc' => 'Daftar SPP',                             'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'spp_list']], 'baru' => null],
    ['name' => 'belanja.spm_list',         'desc' => 'Daftar SPM',                             'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'spm_list']], 'baru' => null],
    ['name' => 'belanja.sp2d_list',        'desc' => 'Daftar SP2D',                            'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'sp2d_list']], 'baru' => null],
    ['name' => 'belanja.bku_belanja',      'desc' => 'Buku Kas Belanja',                       'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'bku_belanja']], 'baru' => null],
    ['name' => 'belanja.lpj_list',         'desc' => 'Daftar LPJ belanja',                     'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'lpj_list']], 'baru' => null],
    ['name' => 'belanja.pengajuan_tu_list','desc' => 'Daftar pengajuan TU',                   'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'pengajuan_tu_list']], 'baru' => null],
    ['name' => 'belanja.rekening_skpd_list','desc' => 'Daftar rekening SKPD',                  'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'rekening_skpd_list']], 'baru' => null],
    ['name' => 'belanja.npd_list',         'desc' => 'Daftar NPD',                             'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'npd_list']], 'baru' => null],
    ['name' => 'belanja.spp_create',       'desc' => 'Buat SPP',                               'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'spp_create']], 'baru' => null],
    ['name' => 'belanja.spp_verifikasi',   'desc' => 'Verifikasi SPP',                         'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'spp_verifikasi']], 'baru' => null],
    ['name' => 'belanja.spm_verifikasi',   'desc' => 'Verifikasi SPM',                         'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'spm_verifikasi']], 'baru' => null],
    ['name' => 'belanja.sp2d_create',      'desc' => 'Buat SP2D',                              'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'sp2d_create']], 'baru' => null],
    ['name' => 'belanja.sp2d_verifikasi',  'desc' => 'Verifikasi SP2D',                        'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'sp2d_verifikasi']], 'baru' => null],
    ['name' => 'belanja.sp2d_pencairan',   'desc' => 'Pencairan SP2D',                         'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'sp2d_pencairan']], 'baru' => null],
    ['name' => 'belanja.pengajuan_tu_otorisasi', 'desc' => 'Otorisasi pengajuan TU',         'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'pengajuan_tu_otorisasi']], 'baru' => null],
    ['name' => 'belanja.npd_validasi_pa',  'desc' => 'Validasi NPD (PA)',                      'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'npd_validasi_pa']], 'baru' => null],
    ['name' => 'belanja.npd_validasi_bp',  'desc' => 'Validasi NPD (BP)',                      'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'npd_validasi_bp']], 'baru' => null],

    // ---- Modul utama: pengaturan & dashboard ----
    ['name' => 'summary.dashboard',        'desc' => 'Ringkasan APBD dashboard',               'legacy' => ['method' => 'GET', 'path' => '/api/summary.php'], 'baru' => null],
    ['name' => 'profil.get',               'desc' => 'Data profil user',                       'legacy' => ['method' => 'GET', 'path' => '/api/profil.php'], 'baru' => null],
    ['name' => 'profil.simpan',            'desc' => 'Simpan profil',                          'legacy' => ['method' => 'POST', 'path' => '/api/profil.php', 'params' => ['action' => 'simpan']], 'baru' => null],
    ['name' => 'profil.ganti_password',    'desc' => 'Ganti password',                         'legacy' => ['method' => 'POST', 'path' => '/api/profil.php', 'params' => ['action' => 'ganti_password']], 'baru' => null],
    ['name' => 'lupa_password.cek',        'desc' => 'Cek akun lupa password',                 'legacy' => ['method' => 'POST', 'path' => '/api/lupa_password.php', 'params' => ['action' => 'cek']], 'baru' => null],
    ['name' => 'lupa_password.kirim_otp',  'desc' => 'Kirim OTP lupa password',                'legacy' => ['method' => 'POST', 'path' => '/api/lupa_password.php', 'params' => ['action' => 'kirim_otp']], 'baru' => null],
    ['name' => 'lupa_password.reset',      'desc' => 'Reset password via OTP',                 'legacy' => ['method' => 'POST', 'path' => '/api/lupa_password.php', 'params' => ['action' => 'reset']], 'baru' => null],

    // ---- Modul dokumen & tanda tangan (doc.simtkd.com) ----
    ['name' => 'dokumen.list',             'desc' => 'Daftar dokumen milik user',              'legacy' => ['method' => 'GET', 'path' => '/api/dokumen.php', 'params' => ['action' => 'list']], 'baru' => null],
    ['name' => 'dokumen.detail',           'desc' => 'Detail dokumen',                         'legacy' => ['method' => 'GET', 'path' => '/api/dokumen.php', 'params' => ['action' => 'detail']], 'baru' => null],
    ['name' => 'dokumen.konten',           'desc' => 'HTML dokumen (viewer)',                  'legacy' => ['method' => 'GET', 'path' => '/api/dokumen.php', 'params' => ['action' => 'konten']], 'baru' => null],
    ['name' => 'dokumen.kirim',            'desc' => 'Kirim dokumen ke antrean TTD',           'legacy' => ['method' => 'POST', 'path' => '/api/dokumen.php', 'params' => ['action' => 'kirim']], 'baru' => null],
    ['name' => 'dokumen.verify',          'desc' => 'Verifikasi publik keaslian dokumen',      'legacy' => ['method' => 'GET', 'path' => '/api/dokumen.php', 'params' => ['action' => 'verify']], 'baru' => null],
    ['name' => 'dokumen.ttd',              'desc' => 'Tandatangani dokumen elektronik',         'legacy' => ['method' => 'POST', 'path' => '/api/dokumen.php', 'params' => ['action' => 'ttd']], 'baru' => null],

    // ---- Modul AKLAP (peta.simtkd.com) ----
    ['name' => 'aklap.akun_list',          'desc' => 'Daftar master akun (COA LRA)',           'legacy' => ['method' => 'GET', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'akun_list']], 'baru' => null],
    ['name' => 'aklap.akun_simpan',        'desc' => 'Simpan master akun',                     'legacy' => ['method' => 'POST', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'akun_simpan']], 'baru' => null],
    ['name' => 'aklap.akun_hapus',         'desc' => 'Hapus master akun',                      'legacy' => ['method' => 'POST', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'akun_hapus']], 'baru' => null],
    ['name' => 'aklap.akun_seed_coa',      'desc' => 'Seed COA LRA standar',                   'legacy' => ['method' => 'POST', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'akun_seed_coa']], 'baru' => null],
    ['name' => 'aklap.anggaran_list',     'desc' => 'Daftar pagu anggaran',                   'legacy' => ['method' => 'GET', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'anggaran_list']], 'baru' => null],
    ['name' => 'aklap.anggaran_simpan',   'desc' => 'Simpan pagu anggaran',                   'legacy' => ['method' => 'POST', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'anggaran_simpan']], 'baru' => null],
    ['name' => 'aklap.anggaran_hapus',    'desc' => 'Hapus pagu anggaran',                    'legacy' => ['method' => 'POST', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'anggaran_hapus']], 'baru' => null],
    ['name' => 'aklap.anggaran_sinkron',  'desc' => 'Sinkron pagu dengan master akun',        'legacy' => ['method' => 'POST', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'anggaran_sinkron_master']], 'baru' => null],
    ['name' => 'aklap.jurnal_list',       'desc' => 'Daftar jurnal umum',                     'legacy' => ['method' => 'GET', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'jurnal_list']], 'baru' => null],
    ['name' => 'aklap.jurnal_simpan',      'desc' => 'Simpan jurnal umum (double-entry)',      'legacy' => ['method' => 'POST', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'jurnal_simpan']], 'baru' => null],
    ['name' => 'aklap.jurnal_hapus',       'desc' => 'Hapus jurnal umum',                      'legacy' => ['method' => 'POST', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'jurnal_hapus']], 'baru' => null],
    ['name' => 'aklap.jurnal_status',      'desc' => 'Approve/reject jurnal',                  'legacy' => ['method' => 'POST', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'jurnal_status']], 'baru' => null],
    ['name' => 'aklap.saran_dokumen',      'desc' => 'Dokumen siap dijurnal',                  'legacy' => ['method' => 'GET', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'saran_dokumen']], 'baru' => null],
    ['name' => 'aklap.saran_jurnal',       'desc' => 'Template jurnal dari STS/SP2D',           'legacy' => ['method' => 'GET', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'saran_jurnal']], 'baru' => null],
    ['name' => 'aklap.buku_besar',        'desc' => 'Buku besar',                             'legacy' => ['method' => 'GET', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'buku_besar']], 'baru' => null],
    ['name' => 'aklap.neraca_saldo',       'desc' => 'Neraca saldo',                           'legacy' => ['method' => 'GET', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'neraca_saldo']], 'baru' => null],
    ['name' => 'aklap.buku_kas_list',      'desc' => 'Daftar buku kas',                        'legacy' => ['method' => 'GET', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'buku_kas_list']], 'baru' => null],
    ['name' => 'aklap.buku_kas_simpan',    'desc' => 'Simpan buku kas',                        'legacy' => ['method' => 'POST', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'buku_kas_simpan']], 'baru' => null],
    ['name' => 'aklap.buku_kas_umum',      'desc' => 'Buku Kas Umum (BUD)',                    'legacy' => ['method' => 'GET', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'buku_kas_umum']], 'baru' => null],
    ['name' => 'aklap.kas_harian',         'desc' => 'Laporan kas harian',                     'legacy' => ['method' => 'GET', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'kas_harian']], 'baru' => null],
    ['name' => 'aklap.rekonsiliasi_bank',  'desc' => 'Rekonsiliasi bank',                      'legacy' => ['method' => 'GET', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'rekonsiliasi_bank']], 'baru' => null],
    ['name' => 'aklap.register_ppkd',      'desc' => 'Register SPP/SPM/SP2D',                  'legacy' => ['method' => 'GET', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'register_ppkd_list']], 'baru' => null],
    ['name' => 'aklap.lpj_ppkd',          'desc' => 'LPJ Bendahara PPKD',                     'legacy' => ['method' => 'GET', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'lpj_ppkd']], 'baru' => null],
    ['name' => 'aklap.buku_pembantu',      'desc' => 'Buku pembantu objek',                    'legacy' => ['method' => 'GET', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'buku_pembantu_list']], 'baru' => null],
    ['name' => 'aklap.jurnal_khusus',      'desc' => 'Buku jurnal khusus',                     'legacy' => ['method' => 'GET', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'jurnal_khusus']], 'baru' => null],
    ['name' => 'aklap.jurnal_khusus_simpan','desc' => 'Simpan jurnal khusus',                  'legacy' => ['method' => 'POST', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'jurnal_khusus_simpan']], 'baru' => null],
    ['name' => 'aklap.aklap_rekap',        'desc' => 'Rekap AKLAP (dashboard)',                'legacy' => ['method' => 'GET', 'path' => '/api/aklap.php', 'params' => ['action' => 'rekap']], 'baru' => null],
    ['name' => 'aklap.lra_rekap',          'desc' => 'Rekap LRA',                              'legacy' => ['method' => 'GET', 'path' => '/api/aklap.php', 'params' => ['action' => 'lra_rekap']], 'baru' => null],
    ['name' => 'aklap.neraca_rekap',       'desc' => 'Rekap neraca',                           'legacy' => ['method' => 'GET', 'path' => '/api/aklap.php', 'params' => ['action' => 'neraca_rekap']], 'baru' => null],
];
