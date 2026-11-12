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

    // =================================================================
    // BELUM DIMIGDASI — CHECKLIST PORTING
    // =================================================================

    // ---- Modul utama: penerimaan (SUDAH DIMIGDASI) ----
    [
        'name'  => 'permohonan.list',
        'desc'  => 'Daftar permohonan rekening',
        'legacy' => ['method' => 'GET', 'path' => '/api/permohonan.php'],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/permohonan'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'permohonan.list.cari',
        'desc'  => 'Daftar permohonan dengan pencarian (?q=)',
        'legacy' => ['method' => 'GET', 'path' => '/api/permohonan.php', 'params' => ['q' => 'BANK']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/permohonan', 'params' => ['q' => 'BANK']],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'permohonan.list.hanya_aktif',
        'desc'  => 'Daftar permohonan hanya yang disetujui & aktif (?aktif=1)',
        'legacy' => ['method' => 'GET', 'path' => '/api/permohonan.php', 'params' => ['aktif' => '1']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/permohonan', 'params' => ['aktif' => '1']],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'permohonan.create.gagal.bank_kosong',
        'desc'  => 'Simpan permohonan tanpa bank ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/permohonan.php',
            'params' => ['nama_rekening' => 'Pemilik Uji', 'nomor_rekening' => '1234567890'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/penerimaan/permohonan',
            'params' => ['nama_rekening' => 'Pemilik Uji', 'nomor_rekening' => '1234567890'],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'bank',
            'message' => 'Silakan pilih bank.',
        ],
    ],
    [
        'name'  => 'permohonan.create.gagal.nama_kosong',
        'desc'  => 'Simpan permohonan tanpa nama pemilik ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/permohonan.php',
            'params' => ['bank' => 'BANK UJI', 'nomor_rekening' => '1234567890'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/penerimaan/permohonan',
            'params' => ['bank' => 'BANK UJI', 'nomor_rekening' => '1234567890'],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'nama_rekening',
            'message' => 'Nama pemilik rekening wajib diisi.',
        ],
    ],
    [
        'name'  => 'permohonan.create.gagal.nomor_kosong',
        'desc'  => 'Simpan permohonan tanpa nomor rekening ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/permohonan.php',
            'params' => ['bank' => 'BANK UJI', 'nama_rekening' => 'Pemilik Uji'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/penerimaan/permohonan',
            'params' => ['bank' => 'BANK UJI', 'nama_rekening' => 'Pemilik Uji'],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'nomor_rekening',
            'message' => 'Nomor rekening wajib diisi.',
        ],
    ],
    [
        'name'  => 'permohonan.set_aktif.gagal.id_tidak_ada',
        'desc'  => 'Set aktif pada ID yang tidak ada (404)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/permohonan.php',
            'params' => ['action' => 'set_aktif', 'id' => '99999999', 'status_aktif' => '1'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/penerimaan/permohonan/99999999/set-aktif',
            'params' => ['status_aktif' => '1'],
        ],
        'expect' => [
            'success' => false,
            'message' => 'Data tidak ditemukan atau bukan milik instansi Anda.',
        ],
    ],
    [
        'name'  => 'permohonan.verifikasi.gagal.id_tidak_ada',
        'desc'  => 'Verifikasi pada ID yang tidak ada (404)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/permohonan.php',
            'params' => ['action' => 'verifikasi', 'id' => '99999999'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/penerimaan/permohonan/99999999/verifikasi',
        ],
        'expect' => [
            'success' => false,
            'message' => 'Data tidak ditemukan atau bukan milik instansi Anda.',
        ],
    ],
    [
        'name'  => 'akun_penerimaan.list',
        'desc'  => 'Daftar akun penerimaan',
        'legacy' => ['method' => 'GET', 'path' => '/api/akun_penerimaan.php'],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/akun'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'akun_penerimaan.list.cari',
        'desc'  => 'Daftar akun penerimaan dengan pencarian',
        'legacy' => ['method' => 'GET', 'path' => '/api/akun_penerimaan.php', 'params' => ['q' => '4.1']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/akun', 'params' => ['q' => '4.1']],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'akun_penerimaan.create.gagal.kode_kosong',
        'desc'  => 'Simpan akun penerimaan tanpa kode ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/akun_penerimaan.php',
            'params' => ['action' => 'create', 'nama_akun' => 'Uji'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/penerimaan/akun',
            'params' => ['action' => 'create', 'nama_akun' => 'Uji'],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'kode_akun',
            'message' => 'Kode akun wajib diisi.',
        ],
    ],
    [
        'name'  => 'akun_penerimaan.create.gagal.nama_kosong',
        'desc'  => 'Simpan akun penerimaan tanpa nama ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/akun_penerimaan.php',
            'params' => ['action' => 'create', 'kode_akun' => '9.9.99'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/penerimaan/akun',
            'params' => ['action' => 'create', 'kode_akun' => '9.9.99'],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'nama_akun',
            'message' => 'Nama akun wajib diisi.',
        ],
    ],
    [
        'name'  => 'akun_penerimaan.aksi_tidak_dikenal',
        'desc'  => 'Aksi tak dikenal ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/akun_penerimaan.php',
            'params' => ['action' => 'aksi_ngawur'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/penerimaan/akun',
            'params' => ['action' => 'aksi_ngawur'],
        ],
        'expect' => [
            'success' => false,
            'message' => 'Aksi tidak dikenali.',
        ],
    ],
    [
        'name'  => 'laporan.obyek_penerimaan',
        'desc'  => 'Daftar kode rekening rincian obyek penerimaan',
        'legacy' => ['method' => 'GET', 'path' => '/api/laporan.php', 'params' => ['action' => 'obyek_penerimaan']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/laporan/obyek'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'laporan.buku_pembantu.gagal.kode_kosong',
        'desc'  => 'Buku pembantu tanpa kode rekening ditolak (422)',
        'legacy' => ['method' => 'GET', 'path' => '/api/laporan.php', 'params' => ['action' => 'buku_pembantu', 'kode_akun' => '']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/laporan/buku-pembantu', 'params' => ['kode_akun' => '']],
        'expect' => [
            'success' => false,
            'message' => 'Kode rekening rincian obyek wajib dipilih.',
        ],
    ],
    [
        'name'  => 'laporan.rekap.gagal.periode_kosong',
        'desc'  => 'Rekap tanpa periode ditolak (422)',
        'legacy' => ['method' => 'GET', 'path' => '/api/laporan.php', 'params' => ['action' => 'rekap']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/laporan/rekap'],
        'expect' => [
            'success' => false,
            'message' => 'Periode (tanggal awal dan akhir) wajib diisi.',
        ],
    ],
    [
        'name'  => 'laporan.rekap.harian',
        'desc'  => 'Rekap penerimaan harian',
        'legacy' => ['method' => 'GET', 'path' => '/api/laporan.php', 'params' => ['action' => 'rekap', 'mode' => 'harian', 'dari' => '2020-01-01', 'sampai' => '2030-12-31']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/laporan/rekap', 'params' => ['mode' => 'harian', 'dari' => '2020-01-01', 'sampai' => '2030-12-31']],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'laporan.rekap.bulanan',
        'desc'  => 'Rekap penerimaan bulanan',
        'legacy' => ['method' => 'GET', 'path' => '/api/laporan.php', 'params' => ['action' => 'rekap', 'mode' => 'bulanan', 'dari' => '2020-01-01', 'sampai' => '2030-12-31']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/laporan/rekap', 'params' => ['mode' => 'bulanan', 'dari' => '2020-01-01', 'sampai' => '2030-12-31']],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'laporan.bku',
        'desc'  => 'Buku Kas Umum penerimaan',
        'legacy' => ['method' => 'GET', 'path' => '/api/laporan.php', 'params' => ['action' => 'bku', 'dari' => '2020-01-01', 'sampai' => '2030-12-31']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/laporan/bku', 'params' => ['dari' => '2020-01-01', 'sampai' => '2030-12-31']],
        'expect' => ['success' => true],
    ],

    // ---- Modul penerimaan: STS & STBP (SUDAH diporting) ----
    // Catatan: seluruh skenario di bawah bersifat baca-saja atau jalur gagal,
    // sehingga dapat dibandingkan tanpa mengubah data (mutasi seperti
    // create STS/STBP diuji di tests/Feature/Api/StsStbpTest.php).
    [
        'name'  => 'sts.list',
        'desc'  => 'Daftar STS + penghitung status',
        'legacy' => ['method' => 'GET', 'path' => '/api/sts.php'],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/sts'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'sts.lpj',
        'desc'  => 'LPJ Bendahara Penerimaan (penerimaan vs penyetoran)',
        'legacy' => ['method' => 'GET', 'path' => '/api/sts.php', 'params' => ['lpj' => '1', 'dari' => '2020-01-01', 'akhir' => '2030-12-31']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/sts', 'params' => ['lpj' => '1', 'dari' => '2020-01-01', 'akhir' => '2030-12-31']],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'sts.register',
        'desc'  => 'Register STS (rincian pendapatan)',
        'legacy' => ['method' => 'GET', 'path' => '/api/sts.php', 'params' => ['register' => '1']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/sts', 'params' => ['register' => '1']],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'sts.detail.gagal.id_tidak_ada',
        'desc'  => 'Detail STS yang tidak ada (404)',
        'legacy' => ['method' => 'GET', 'path' => '/api/sts.php', 'params' => ['id' => '99999999']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/sts', 'params' => ['id' => '99999999']],
        'expect' => ['success' => false, 'message' => 'STS tidak ditemukan.'],
    ],
    [
        'name'  => 'sts.create.gagal.tanpa_stbp',
        'desc'  => 'Buat STS tanpa memilih STBP (422)',
        'legacy' => ['method' => 'POST', 'path' => '/api/sts.php', 'params' => ['action' => 'create', 'tanggal_sts' => '2026-01-01']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/penerimaan/sts', 'params' => ['action' => 'create', 'tanggal_sts' => '2026-01-01']],
        'expect' => ['success' => false, 'message' => 'Pilih minimal satu STBP.'],
    ],
    [
        'name'  => 'sts.create.gagal.tanpa_tanggal',
        'desc'  => 'Buat STS tanpa tanggal (422)',
        'legacy' => ['method' => 'POST', 'path' => '/api/sts.php', 'params' => ['action' => 'create', 'stbp_ids' => ['1']]],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/penerimaan/sts', 'params' => ['action' => 'create', 'stbp_ids' => ['1']]],
        'expect' => ['success' => false, 'message' => 'Tanggal STS wajib diisi.'],
    ],
    [
        'name'  => 'stbp.list',
        'desc'  => 'Daftar STBP + penghitung status',
        'legacy' => ['method' => 'GET', 'path' => '/api/stbp.php'],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/stbp'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'stbp.list.siap_sts',
        'desc'  => 'Daftar STBP yang siap dimasukkan ke STS',
        'legacy' => ['method' => 'GET', 'path' => '/api/stbp.php', 'params' => ['status' => 'siap_sts']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/stbp', 'params' => ['status' => 'siap_sts']],
        'expect' => ['success' => true, 'status' => 'siap_sts'],
    ],
    [
        'name'  => 'stbp.detail.gagal.id_tidak_ada',
        'desc'  => 'Detail STBP yang tidak ada (404)',
        'legacy' => ['method' => 'GET', 'path' => '/api/stbp.php', 'params' => ['id' => '99999999']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/stbp', 'params' => ['id' => '99999999']],
        'expect' => ['success' => false, 'message' => 'STBP tidak ditemukan.'],
    ],
    [
        'name'  => 'stbp.update_status.gagal.status_tidak_valid',
        'desc'  => 'Majukan status dengan tahap tidak valid (422)',
        'legacy' => ['method' => 'POST', 'path' => '/api/stbp.php', 'params' => ['action' => 'update_status', 'id' => '99999999', 'status' => 'ngawur']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/penerimaan/stbp', 'params' => ['action' => 'update_status', 'id' => '99999999', 'status' => 'ngawur']],
        'expect' => ['success' => false, 'message' => 'Status tahap tidak valid.'],
    ],
    [
        'name'  => 'stbp.update_status.gagal.id_tidak_ada',
        'desc'  => 'Majukan status STBP yang tidak ada (404)',
        'legacy' => ['method' => 'POST', 'path' => '/api/stbp.php', 'params' => ['action' => 'update_status', 'id' => '99999999', 'status' => 'sudah_diverifikasi']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/penerimaan/stbp', 'params' => ['action' => 'update_status', 'id' => '99999999', 'status' => 'sudah_diverifikasi']],
        'expect' => ['success' => false, 'message' => 'STBP tidak ditemukan atau bukan milik instansi Anda.'],
    ],
    [
        'name'  => 'stbp.create.gagal.tanpa_skp',
        'desc'  => 'Buat STBP tanpa SKP Daerah (422)',
        'legacy' => ['method' => 'POST', 'path' => '/api/stbp.php', 'params' => ['action' => 'create', 'tanggal' => '2026-01-01']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/penerimaan/stbp', 'params' => ['action' => 'create', 'tanggal' => '2026-01-01']],
        'expect' => ['success' => false, 'message' => 'SKP Daerah wajib dipilih sebagai dasar pembuatan STBP.'],
    ],

    // ---- Modul belanja: AKSI BACA (SUDAH DIPORTING, fase 3b-baca) ----
    // Mutasi belanja (create/verifikasi/pencairan) masih legacy -> lihat
    // daftar PENDING di bawah.
    //
    // Catatan cakupan: harness memakai akun uji miliknya sendiri sehingga
    // daftar pada instansi itu kosong. Yang dijamin di sini adalah kesamaan
    // struktur & status (dan tidak adanya galat SQL). Kesamaan BENTUK BARIS
    // dengan data nyata diuji dengan menyemai data sementara pada
    // pengujian E2E (lihat README §9.3).
    [
        'name'  => 'belanja.summary',
        'desc'  => 'Ringkasan belanja (penghitung & total)',
        'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'summary']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/belanja/summary'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'belanja.rekanan_list',
        'desc'  => 'Daftar rekanan',
        'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'rekanan_list']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/belanja/rekanan'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'belanja.spd_list',
        'desc'  => 'Daftar SPD',
        'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'spd_list']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/belanja/spd'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'belanja.spd_otor_list',
        'desc'  => 'SPD terotorisasi yang masih punya sisa',
        'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'spd_otor_list']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/belanja/spd-otor'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'belanja.spp_list',
        'desc'  => 'Daftar SPP + rincian & sisa SPD',
        'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'spp_list']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/belanja/spp'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'belanja.spm_list',
        'desc'  => 'Daftar SPM + potongan/pajak/rincian',
        'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'spm_list']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/belanja/spm'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'belanja.spm_ver_list',
        'desc'  => 'SPM terverifikasi (referensi SP2D)',
        'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'spm_ver_list']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/belanja/spm-ver'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'belanja.sp2d_list',
        'desc'  => 'Daftar SP2D',
        'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'sp2d_list']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/belanja/sp2d'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'belanja.bku_belanja',
        'desc'  => 'Buku Kas Belanja (SP2D dicairkan + total)',
        'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'bku_belanja', 'dari' => '2020-01-01', 'sampai' => '2030-12-31']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/belanja/bku', 'params' => ['dari' => '2020-01-01', 'sampai' => '2030-12-31']],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'belanja.lpj_list',
        'desc'  => 'Daftar LPJ belanja',
        'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'lpj_list']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/belanja/lpj'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'belanja.pengajuan_tu_list',
        'desc'  => 'Daftar pengajuan TU',
        'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'pengajuan_tu_list']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/belanja/pengajuan-tu'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'belanja.rekening_skpd_list',
        'desc'  => 'Daftar rekening SKPD',
        'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'rekening_skpd_list']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/belanja/rekening-skpd'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'belanja.besaran_up_get',
        'desc'  => 'Besaran UP/KKPD per tahun',
        'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'besaran_up_get', 'tahun' => '2026']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/belanja/besaran-up', 'params' => ['tahun' => '2026']],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'belanja.kebijakan_spd_list',
        'desc'  => 'Daftar kebijakan SPD',
        'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'kebijakan_spd_list']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/belanja/kebijakan-spd'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'belanja.npd_list',
        'desc'  => 'Daftar NPD',
        'legacy' => ['method' => 'GET', 'path' => '/api/belanja.php', 'params' => ['action' => 'npd_list']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/belanja/npd'],
        'expect' => ['success' => true],
    ],

    // ---- Modul belanja: MUTASI (SUDAH DIPORTING, fase 3b-mutasi) ----
    // Semua skenario di bawah adalah JALUR VALIDASI (gagal sebelum menulis),
    // sehingga aman dibandingkan pada basis data bersama. Jalur sukses yang
    // menulis data diuji di tests/Feature/Api/BelanjaMutasiTest.php.
    ['name' => 'belanja.rekanan_create.gagal.nama_kosong', 'desc' => 'Rekanan tanpa nama (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'rekanan_create', 'jenis' => 'perusahaan']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/rekanan'], 'expect' => ['success' => false, 'message' => 'Nama rekanan wajib diisi.']],
    ['name' => 'belanja.spd_create.gagal.tanggal_kosong', 'desc' => 'SPD tanpa tanggal (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'spd_create', 'jumlah' => '1000']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/spd', 'params' => ['jumlah' => '1000']], 'expect' => ['success' => false, 'message' => 'Tanggal SPD wajib diisi.']],
    ['name' => 'belanja.spd_create.gagal.tanggal_invalid', 'desc' => 'SPD tanggal tidak valid (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'spd_create', 'tanggal' => '2026-13-99', 'jumlah' => '1000']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/spd', 'params' => ['tanggal' => '2026-13-99', 'jumlah' => '1000']], 'expect' => ['success' => false, 'message' => 'Format tanggal tidak valid.']],
    ['name' => 'belanja.spd_create.gagal.jumlah_nol', 'desc' => 'SPD jumlah 0 (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'spd_create', 'tanggal' => '2026-03-01', 'jumlah' => '0']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/spd', 'params' => ['tanggal' => '2026-03-01', 'jumlah' => '0']], 'expect' => ['success' => false, 'message' => 'Jumlah harus lebih dari 0.']],
    ['name' => 'belanja.spd_otorisasi.gagal.id_tidak_ada', 'desc' => 'Otorisasi SPD id tak ada (404)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'spd_otorisasi', 'id' => '99999999']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/spd/99999999/otorisasi', 'params' => ['id' => '99999999']], 'expect' => ['success' => false, 'message' => 'SPD tidak ditemukan / sudah diotorisasi / bukan milik instansi Anda.']],
    ['name' => 'belanja.spp_create.gagal.tanpa_spd', 'desc' => 'SPP tanpa SPD (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'spp_create', 'tanggal' => '2026-03-03', 'jumlah' => '1000']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/spp', 'params' => ['tanggal' => '2026-03-03', 'jumlah' => '1000']], 'expect' => ['success' => false, 'message' => 'Pilih SPD.']],
    ['name' => 'belanja.spp_create.gagal.jumlah_nol', 'desc' => 'SPP jumlah 0 (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'spp_create', 'tanggal' => '2026-03-03', 'spd_id' => '1', 'jumlah' => '0']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/spp', 'params' => ['tanggal' => '2026-03-03', 'spd_id' => '1', 'jumlah' => '0']], 'expect' => ['success' => false, 'message' => 'Jumlah harus lebih dari 0.']],
    ['name' => 'belanja.spp_create.gagal.gu_tanpa_lpj', 'desc' => 'SPP GU tanpa LPJ (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'spp_create', 'tanggal' => '2026-03-03', 'jenis_spp' => 'GU', 'spd_id' => '1', 'jumlah' => '1000']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/spp', 'params' => ['tanggal' => '2026-03-03', 'jenis_spp' => 'GU', 'spd_id' => '1', 'jumlah' => '1000']], 'expect' => ['success' => false, 'message' => 'Pilih LPJ untuk SPP GU.']],
    ['name' => 'belanja.spp_create.gagal.spd_tidak_ada', 'desc' => 'SPP dengan SPD tak valid (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'spp_create', 'tanggal' => '2026-03-03', 'spd_id' => '99999999', 'jumlah' => '1000']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/spp', 'params' => ['tanggal' => '2026-03-03', 'spd_id' => '99999999', 'jumlah' => '1000']], 'expect' => ['success' => false, 'message' => 'SPD tidak valid / belum diotorisasi / bukan milik instansi Anda.']],
    ['name' => 'belanja.spp_verifikasi.gagal.id_tidak_ada', 'desc' => 'Verifikasi SPP id tak ada (404)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'spp_verifikasi', 'id' => '99999999', 'setuju' => true]], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/spp/99999999/verifikasi', 'params' => ['id' => '99999999', 'setuju' => true]], 'expect' => ['success' => false, 'message' => 'SPP tidak ditemukan / bukan milik instansi Anda.']],
    ['name' => 'belanja.spm_persetujuan.gagal.id_tidak_ada', 'desc' => 'Persetujuan SPM id tak ada (404)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'spm_persetujuan', 'id' => '99999999']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/spm/99999999/persetujuan', 'params' => ['id' => '99999999']], 'expect' => ['success' => false, 'message' => 'SPM tidak ditemukan / bukan milik instansi Anda.']],
    ['name' => 'belanja.spm_verifikasi.gagal.id_tidak_ada', 'desc' => 'Verifikasi SPM id tak ada (404)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'spm_verifikasi', 'id' => '99999999', 'setuju' => true]], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/spm/99999999/verifikasi', 'params' => ['id' => '99999999', 'setuju' => true]], 'expect' => ['success' => false, 'message' => 'SPM tidak ditemukan / belum diverifikasi / bukan milik instansi Anda.']],
    ['name' => 'belanja.sp2d_create.gagal.tanpa_spm', 'desc' => 'SP2D tanpa SPM (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'sp2d_create', 'tanggal' => '2026-03-05', 'jumlah' => '1000']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/sp2d', 'params' => ['tanggal' => '2026-03-05', 'jumlah' => '1000']], 'expect' => ['success' => false, 'message' => 'Pilih SPM.']],
    ['name' => 'belanja.sp2d_create.gagal.spm_tidak_ada', 'desc' => 'SP2D dengan SPM tak valid (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'sp2d_create', 'tanggal' => '2026-03-05', 'spm_id' => '99999999', 'jumlah' => '1000']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/sp2d', 'params' => ['tanggal' => '2026-03-05', 'spm_id' => '99999999', 'jumlah' => '1000']], 'expect' => ['success' => false, 'message' => 'SPM tidak valid / belum diverifikasi / bukan milik instansi Anda.']],
    ['name' => 'belanja.sp2d_verifikasi.gagal.id_tidak_ada', 'desc' => 'Verifikasi SP2D id tak ada (404)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'sp2d_verifikasi', 'id' => '99999999', 'setuju' => true]], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/sp2d/99999999/verifikasi', 'params' => ['id' => '99999999', 'setuju' => true]], 'expect' => ['success' => false, 'message' => 'SP2D tidak ditemukan / bukan milik instansi Anda.']],
    ['name' => 'belanja.sp2d_pencairan.gagal.id_tidak_ada', 'desc' => 'Pencairan SP2D id tak ada (404)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'sp2d_pencairan', 'id' => '99999999']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/sp2d/99999999/pencairan', 'params' => ['id' => '99999999']], 'expect' => ['success' => false, 'message' => 'SP2D tidak ditemukan / belum diverifikasi / bukan milik instansi Anda.']],
    ['name' => 'belanja.lpj_create.gagal.tanggal_kosong', 'desc' => 'LPJ tanpa tanggal (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'lpj_create', 'jumlah' => '1000']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/lpj', 'params' => ['jumlah' => '1000']], 'expect' => ['success' => false, 'message' => 'Tanggal LPJ wajib diisi.']],
    ['name' => 'belanja.pengajuan_tu_create.gagal.jumlah_nol', 'desc' => 'Pengajuan TU jumlah 0 (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'pengajuan_tu_create', 'tanggal' => '2026-03-07', 'jumlah' => '0']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/pengajuan-tu', 'params' => ['tanggal' => '2026-03-07', 'jumlah' => '0']], 'expect' => ['success' => false, 'message' => 'Jumlah harus lebih dari 0.']],
    ['name' => 'belanja.pengajuan_tu_otorisasi.gagal.id', 'desc' => 'Otorisasi pengajuan TU id tak ada (404)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'pengajuan_tu_otorisasi', 'id' => '99999999']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/pengajuan-tu/99999999/otorisasi', 'params' => ['id' => '99999999']], 'expect' => ['success' => false, 'message' => 'Pengajuan TU tidak ditemukan / sudah diotorisasi / bukan milik instansi Anda.']],
    ['name' => 'belanja.pengajuan_tu_validasi.gagal.id', 'desc' => 'Validasi pengajuan TU id tak ada (404)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'pengajuan_tu_validasi', 'id' => '99999999']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/pengajuan-tu/99999999/validasi', 'params' => ['id' => '99999999']], 'expect' => ['success' => false, 'message' => 'Pengajuan TU tidak ditemukan / belum diotorisasi / bukan milik instansi Anda.']],
    ['name' => 'belanja.rekening_skpd_create.gagal.bank_kosong', 'desc' => 'Rekening tanpa bank/pemilik (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'rekening_skpd_create']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/rekening-skpd'], 'expect' => ['success' => false, 'message' => 'Bank dan nama pemilik wajib diisi.']],
    ['name' => 'belanja.rekening_skpd_ajukan.gagal.id', 'desc' => 'Ajukan rekening id tak ada (404)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'rekening_skpd_ajukan', 'id' => '99999999']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/rekening-skpd/99999999/ajukan', 'params' => ['id' => '99999999']], 'expect' => ['success' => false, 'message' => 'Rekening tidak ditemukan / bukan milik instansi Anda.']],
    ['name' => 'belanja.rekening_skpd_nomor.gagal.tanpa_nomor', 'desc' => 'Nomor rekening kosong (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'rekening_skpd_nomor', 'id' => '1']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/rekening-skpd/1/nomor', 'params' => ['id' => '1']], 'expect' => ['success' => false, 'message' => 'ID dan nomor rekening wajib diisi.']],
    ['name' => 'belanja.rekening_skpd_validasi.gagal.tanpa_nomor', 'desc' => 'Validasi setuju tanpa nomor (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'rekening_skpd_validasi', 'id' => '1', 'setuju' => true]], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/rekening-skpd/1/validasi', 'params' => ['id' => '1', 'setuju' => true]], 'expect' => ['success' => false, 'message' => 'Nomor rekening wajib diisi saat validasi.']],
    ['name' => 'belanja.besaran_up_set.gagal.nilai_nol', 'desc' => 'Besaran UP 0 (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'besaran_up_set', 'tahun' => '2026']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/besaran-up', 'params' => ['tahun' => '2026']], 'expect' => ['success' => false, 'message' => 'Nilai besaran UP wajib diisi.']],
    ['name' => 'belanja.kebijakan_spd_create.gagal.kosong', 'desc' => 'Kebijakan SPD tanpa jenis (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'kebijakan_spd_create']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/kebijakan-spd'], 'expect' => ['success' => false, 'message' => 'Jenis penerbitan & periode wajib diisi.']],
    ['name' => 'belanja.npd_create.gagal.tanggal_kosong', 'desc' => 'NPD tanpa tanggal (422)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'npd_create', 'jumlah' => '1000', 'metode' => 'panjar']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/npd', 'params' => ['jumlah' => '1000', 'metode' => 'panjar']], 'expect' => ['success' => false, 'message' => 'Tanggal NPD wajib diisi.']],
    ['name' => 'belanja.npd_validasi_pa.gagal.id', 'desc' => 'Validasi NPD PA id tak ada (404)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'npd_validasi_pa', 'id' => '99999999']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/npd/99999999/validasi-pa', 'params' => ['id' => '99999999']], 'expect' => ['success' => false, 'message' => 'NPD tidak ditemukan / bukan milik instansi Anda.']],
    ['name' => 'belanja.npd_validasi_bp.gagal.id', 'desc' => 'Validasi NPD BP id tak ada (404)', 'legacy' => ['method' => 'POST', 'path' => '/api/belanja.php', 'params' => ['action' => 'npd_validasi_bp', 'id' => '99999999']], 'baru' => ['method' => 'POST', 'path' => '/api/v1/belanja/npd/99999999/validasi-bp', 'params' => ['id' => '99999999']], 'expect' => ['success' => false, 'message' => 'NPD tidak ditemukan / belum divalidasi PA / bukan milik instansi Anda.']],

    // ---- Modul utama: pengaturan & dashboard (SUDAH DIMIGDASI) ----
    [
        'name'  => 'summary.dashboard',
        'desc'  => 'Ringkasan APBD dashboard',
        'legacy' => ['method' => 'GET', 'path' => '/api/summary.php'],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/dashboard/summary'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'profil.get',
        'desc'  => 'Data profil user',
        'legacy' => ['method' => 'GET', 'path' => '/api/profil.php'],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/profil'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'profil.simpan.gagal.email_tidak_valid',
        'desc'  => 'Simpan profil dengan email tidak valid ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/profil.php',
            'params' => ['action' => 'simpan', 'nama_lengkap' => 'Uji Difftest', 'email' => 'bukan-email'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/profil',
            'params' => ['action' => 'simpan', 'nama_lengkap' => 'Uji Difftest', 'email' => 'bukan-email'],
        ],
        'expect' => [
            'success' => false,
            'message' => 'Format email tidak valid.',
        ],
    ],
    [
        'name'  => 'profil.ganti_password.gagal.password_lama_salah',
        'desc'  => 'Ganti password dengan password lama salah ditolak (401)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/profil.php',
            'params' => [
                'action'              => 'ganti_password',
                'password_lama'       => 'SalahSekali',
                'password_baru'       => 'PasswordBaruKuat123',
                'password_konfirmasi' => 'PasswordBaruKuat123',
            ],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/profil/password',
            'params' => [
                'action'              => 'ganti_password',
                'password_lama'       => 'SalahSekali',
                'password_baru'       => 'PasswordBaruKuat123',
                'password_konfirmasi' => 'PasswordBaruKuat123',
            ],
        ],
        'expect' => [
            'success' => false,
            'message' => 'Password lama salah.',
        ],
    ],
    [
        'name'  => 'profil.ganti_password.gagal.konfirmasi_tidak_cocok',
        'desc'  => 'Konfirmasi password tidak cocok ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/profil.php',
            'params' => [
                'action'              => 'ganti_password',
                'password_lama'       => '{{user.password}}',
                'password_baru'       => 'PasswordBaruKuat123',
                'password_konfirmasi' => 'PasswordLain123',
            ],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/profil/password',
            'params' => [
                'action'              => 'ganti_password',
                'password_lama'       => '{{user.password}}',
                'password_baru'       => 'PasswordBaruKuat123',
                'password_konfirmasi' => 'PasswordLain123',
            ],
        ],
        'expect' => [
            'success' => false,
            'message' => 'Konfirmasi password tidak cocok.',
        ],
    ],
    [
        'name'  => 'lupa_password.cek.gagal.email_tidak_terdaftar',
        'desc'  => 'Cek akun lupa password tidak terdaftar (404)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/lupa_password.php',
            'params' => ['action' => 'cek', 'email' => 'entah_tidak_ada@polban.ac.id'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/lupa-password/cek',
            'params' => ['email' => 'entah_tidak_ada@polban.ac.id'],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'email',
            'message' => 'Email belum terdaftar. Pastikan email sama dengan saat mendaftar.',
        ],
    ],
    [
        'name'  => 'lupa_password.cek.gagal.format_email',
        'desc'  => 'Cek akun lupa password format email salah (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/lupa_password.php',
            'params' => ['action' => 'cek', 'email' => 'bukan-email'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/lupa-password/cek',
            'params' => ['email' => 'bukan-email'],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'email',
            // CATATAN: 'bukan-email' LOLOS dari pemeriksaan format pada kedua
            // API karena string tersebut juga memenuhi pola username
            // (huruf + strip, 3-20 karakter). Keduanya lalu sama-sama
            // mencari akun dan membalas 404 "belum terdaftar".
            'message' => 'Email belum terdaftar. Pastikan email sama dengan saat mendaftar.',
        ],
    ],
    [
        'name'  => 'lupa_password.cek.gagal.akun_pending',
        'desc'  => 'Cek akun lupa password untuk akun pending (403)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/lupa_password.php',
            'params' => ['action' => 'cek', 'email' => '{{user.pending.email}}'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/lupa-password/cek',
            'params' => ['email' => '{{user.pending.email}}'],
        ],
        'expect' => [
            'success' => false,
            'status'  => 'pending',
        ],
    ],
    [
        'name'  => 'skp.list',
        'desc'  => 'Daftar SKP Daerah',
        'legacy' => ['method' => 'GET', 'path' => '/api/skp.php'],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/skp'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'skp.detail.gagal.id_tidak_ada',
        'desc'  => 'Detail SKP yang tidak ada (404)',
        'legacy' => ['method' => 'GET', 'path' => '/api/skp.php', 'params' => ['id' => '99999999']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/penerimaan/skp', 'params' => ['id' => '99999999']],
        'expect' => [
            'success' => false,
            'message' => 'SKP Daerah tidak ditemukan.',
        ],
    ],
    [
        'name'  => 'skp.create.gagal.tanggal_kosong',
        'desc'  => 'Simpan SKP tanpa tanggal ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/skp.php',
            'params' => ['action' => 'create', 'nama_penyetor' => 'Wajib Pajak Uji', 'nilai_keputusan' => '1000'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/penerimaan/skp',
            'params' => ['action' => 'create', 'nama_penyetor' => 'Wajib Pajak Uji', 'nilai_keputusan' => '1000'],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'tanggal',
            'message' => 'Tanggal SKP wajib diisi.',
        ],
    ],
    [
        'name'  => 'skp.create.gagal.nama_penyetor_kosong',
        'desc'  => 'Simpan SKP tanpa nama penyetor ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/skp.php',
            'params' => ['action' => 'create', 'tanggal' => '2026-03-15', 'nilai_keputusan' => '1000'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/penerimaan/skp',
            'params' => ['action' => 'create', 'tanggal' => '2026-03-15', 'nilai_keputusan' => '1000'],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'nama_penyetor',
            'message' => 'Nama Penyetor (Wajib Pajak) wajib diisi.',
        ],
    ],
    [
        'name'  => 'skp.create.gagal.nilai_nol',
        'desc'  => 'Simpan SKP dengan nilai 0 ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/skp.php',
            'params' => ['action' => 'create', 'tanggal' => '2026-03-15', 'nama_penyetor' => 'Uji', 'nilai_keputusan' => '0'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/penerimaan/skp',
            'params' => ['action' => 'create', 'tanggal' => '2026-03-15', 'nama_penyetor' => 'Uji', 'nilai_keputusan' => '0'],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'nilai_keputusan',
            'message' => 'Nilai ketetapan harus lebih dari 0.',
        ],
    ],
    [
        'name'  => 'skp.create.gagal.jenis_pajak_tidak_terdaftar',
        'desc'  => 'Jenis pajak yang tidak terdaftar di Akun Penerimaan ditolak (422)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/skp.php',
            'params' => [
                'action' => 'create', 'tanggal' => '2026-03-15',
                'nama_penyetor' => 'Uji', 'nilai_keputusan' => '1000',
                'jenis_pajak' => '9.9.9.99',
            ],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/penerimaan/skp',
            'params' => [
                'action' => 'create', 'tanggal' => '2026-03-15',
                'nama_penyetor' => 'Uji', 'nilai_keputusan' => '1000',
                'jenis_pajak' => '9.9.9.99',
            ],
        ],
        'expect' => [
            'success' => false,
            'field'   => 'jenis_pajak',
        ],
    ],
    [
        'name'  => 'skp.delete.gagal.id_tidak_ada',
        'desc'  => 'Hapus SKP yang tidak ada (404)',
        'legacy' => [
            'method' => 'POST',
            'path'   => '/api/skp.php',
            'params' => ['action' => 'delete', 'id' => '99999999'],
        ],
        'baru' => [
            'method' => 'POST',
            'path'   => '/api/v1/penerimaan/skp',
            'params' => ['action' => 'delete', 'id' => '99999999'],
        ],
        'expect' => [
            'success' => false,
            'message' => 'SKP Daerah tidak ditemukan atau bukan milik instansi Anda.',
        ],
    ],


    // ---- Modul utama: pengaturan & dashboard (SUDAH DIMIGRASI) ----
    // summary.dashboard & profil.get sudah terdaftar di atas (tidak diduplikasi).
    [
        'name'  => 'profil.simpan.gagal.nama_kosong',
        'desc'  => 'Simpan profil dengan nama kosong (422)',
        'legacy' => ['method' => 'POST', 'path' => '/api/profil.php', 'params' => ['action' => 'simpan', 'nama_lengkap' => '']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/profil', 'params' => ['action' => 'simpan', 'nama_lengkap' => '']],
        'expect' => ['success' => false, 'message' => 'Nama lengkap tidak valid (3-100 karakter huruf).'],
    ],
    [
        'name'  => 'profil.ganti_password.gagal.kosong',
        'desc'  => 'Ganti password tanpa mengisi apa pun (422)',
        'legacy' => ['method' => 'POST', 'path' => '/api/profil.php', 'params' => ['action' => 'ganti_password']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/profil/password', 'params' => ['action' => 'ganti_password']],
        'expect' => ['success' => false, 'message' => 'Password lama dan password baru wajib diisi.'],
    ],
    [
        'name'  => 'lupa_password.cek.gagal.tidak_terdaftar',
        'desc'  => 'Cek akun lupa password dengan email tak terdaftar (404)',
        'legacy' => ['method' => 'POST', 'path' => '/api/lupa_password.php', 'params' => ['action' => 'cek', 'email' => 'tidak-ada@polban.ac.id']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/lupa-password/cek', 'params' => ['action' => 'cek', 'email' => 'tidak-ada@polban.ac.id']],
        'expect' => ['success' => false, 'message' => 'Email belum terdaftar. Pastikan email sama dengan saat mendaftar.'],
    ],
    [
        'name'  => 'lupa_password.kirim_otp.gagal.tidak_terdaftar',
        'desc'  => 'Kirim OTP ke email tak terdaftar (404)',
        'legacy' => ['method' => 'POST', 'path' => '/api/lupa_password.php', 'params' => ['action' => 'kirim_otp', 'email' => 'tidak-ada@polban.ac.id']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/lupa-password/kirim-otp', 'params' => ['action' => 'kirim_otp', 'email' => 'tidak-ada@polban.ac.id']],
        'expect' => ['success' => false, 'message' => 'Email belum terdaftar.'],
    ],
    [
        'name'  => 'lupa_password.reset.gagal.tanpa_otp',
        'desc'  => 'Reset password tanpa kode OTP (422)',
        'legacy' => ['method' => 'POST', 'path' => '/api/lupa_password.php', 'params' => ['action' => 'reset', 'email' => 'tidak-ada@polban.ac.id']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/lupa-password/reset', 'params' => ['action' => 'reset', 'email' => 'tidak-ada@polban.ac.id']],
        'expect' => ['success' => false, 'message' => 'Kode OTP wajib diisi.'],
    ],
    [
        'name'  => 'lupa_password.reset.gagal.konfirmasi_beda',
        'desc'  => 'Reset password dengan konfirmasi tidak sama (422, field password_ulangi)',
        'legacy' => ['method' => 'POST', 'path' => '/api/lupa_password.php', 'params' => ['action' => 'reset', 'email' => 'tidak-ada@polban.ac.id', 'otp' => '123456', 'password_baru' => 'PanjangSekali123', 'password_ulangi' => 'BedaSekali123']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/lupa-password/reset', 'params' => ['action' => 'reset', 'email' => 'tidak-ada@polban.ac.id', 'otp' => '123456', 'password_baru' => 'PanjangSekali123', 'password_ulangi' => 'BedaSekali123']],
        'expect' => ['success' => false, 'message' => 'Konfirmasi kata sandi tidak sama.'],
    ],

    // ---- Modul dokumen & tanda tangan (doc.simtkd.com) ----
    //
    // API LEGACY-nya adalah doc.simtkd.com/api/dokumen.php (bukan
    // api/dokumen.php milik aplikasi utama, yang hanya melayani kirim).
    // Endpoint selain `verify` memakai `?token=` -> hash SHA-256 pada
    // kolom users.api_token; token uji disiapkan harness dan diisi lewat
    // placeholder {{user.aktif.token}} (API baru memakai Sanctum/Bearer).
    //
    // Fokus skenario: validasi, batas kepemilikan, dan bentuk respons —
    // semuanya dapat dibandingkan tanpa fixture dokumen. Alur positif
    // (kirim -> tanda tangan -> konten) bersifat mutasi sehingga butuh
    // fixture per-sisi dan diuji terpisah di tests/Feature/Api/DokumenTest.
    [
        'name'  => 'dokumen.me',
        'desc'  => 'Profil pemilik token (validasi token)',
        'legacy' => ['method' => 'GET', 'path' => '/doc.simtkd.com/api/dokumen.php', 'params' => [], 'query' => ['action' => 'me', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/dokumen/me'],
    ],
    [
        'name'  => 'dokumen.list.kosong',
        'desc'  => 'Daftar dokumen user yang belum punya dokumen',
        'legacy' => ['method' => 'GET', 'path' => '/doc.simtkd.com/api/dokumen.php', 'params' => [], 'query' => ['action' => 'list', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/dokumen/list'],
    ],
    [
        'name'  => 'dokumen.list.token_tidak_valid',
        'desc'  => 'Token API salah ditolak 401 (mekanisme auth berbeda)',
        'legacy' => ['method' => 'GET', 'path' => '/doc.simtkd.com/api/dokumen.php', 'params' => ['action' => 'list', 'token' => 'token-tidak-valid']],
        // API baru memakai Bearer (Sanctum): token pada skenario ini
        // menggantikan Bearer sementara, lalu dipulihkan kembali.
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/dokumen/list', 'token' => 'token-tidak-valid'],
        'known_divergence' => [
            'reason' => 'API baru memakai Sanctum Bearer Token, bukan ?token= + hash manual pada users.api_token',
            'note'   => 'Legacy: 401 "Token API tidak valid..."; baru: 401 "Tidak terautentikasi." (keduanya 401).',
        ],
    ],
    [
        'name'  => 'dokumen.detail.gagal.id_tidak_ada',
        'desc'  => 'Detail dokumen yang tidak ada / bukan milik user (404)',
        'legacy' => ['method' => 'GET', 'path' => '/doc.simtkd.com/api/dokumen.php', 'params' => ['id' => '99999999', ], 'query' => ['action' => 'detail', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/dokumen/detail', 'params' => ['id' => '99999999']],
    ],
    [
        'name'  => 'dokumen.detail.gagal.id_kosong',
        'desc'  => 'Detail tanpa id ditolak (422)',
        'legacy' => ['method' => 'GET', 'path' => '/doc.simtkd.com/api/dokumen.php', 'params' => [], 'query' => ['action' => 'detail', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/dokumen/detail'],
    ],
    [
        'name'  => 'dokumen.konten.gagal.id_tidak_ada',
        'desc'  => 'HTML dokumen yang tidak ada (404)',
        'legacy' => ['method' => 'GET', 'path' => '/doc.simtkd.com/api/dokumen.php', 'params' => ['id' => '99999999', ], 'query' => ['action' => 'konten', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/dokumen/konten', 'params' => ['id' => '99999999']],
    ],
    [
        'name'  => 'dokumen.ttd_gambar_saya.kosong',
        'desc'  => 'Gambar tanda tangan user yang belum pernah dibuat',
        'legacy' => ['method' => 'GET', 'path' => '/doc.simtkd.com/api/dokumen.php', 'params' => [], 'query' => ['action' => 'ttd_gambar_saya', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/dokumen/ttd-gambar-saya'],
    ],
    [
        'name'  => 'dokumen.ttd_gambar.gagal.format_salah',
        'desc'  => 'Simpan gambar TTD bukan PNG base64 (422)',
        'legacy' => ['method' => 'POST', 'path' => '/doc.simtkd.com/api/dokumen.php', 'params' => ['gambar' => 'bukan-png', ], 'query' => ['action' => 'ttd_gambar', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/dokumen/ttd-gambar', 'params' => ['gambar' => 'bukan-png']],
    ],
    [
        'name'  => 'dokumen.ttd.gagal.id_tidak_ada',
        'desc'  => 'Tanda tangan dokumen yang tidak ada / bukan milik user (404)',
        'legacy' => ['method' => 'POST', 'path' => '/doc.simtkd.com/api/dokumen.php', 'params' => ['id' => '99999999', ], 'query' => ['action' => 'ttd', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/dokumen/ttd', 'params' => ['id' => '99999999']],
    ],
    [
        'name'  => 'dokumen.verify.gagal.format_kode',
        'desc'  => 'Verifikasi publik dengan format kode salah (422)',
        'legacy' => ['method' => 'GET', 'path' => '/doc.simtkd.com/api/dokumen.php', 'params' => ['action' => 'verify', 'kode' => 'NGGAWAL']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/dokumen/verify', 'params' => ['kode' => 'NGGAWAL']],
    ],
    [
        'name'  => 'dokumen.verify.gagal.tidak_terdaftar',
        'desc'  => 'Verifikasi publik kode valid namun tidak terdaftar (404)',
        'legacy' => ['method' => 'GET', 'path' => '/doc.simtkd.com/api/dokumen.php', 'params' => ['action' => 'verify', 'kode' => 'TTD-DEADBEEF']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/dokumen/verify', 'params' => ['kode' => 'TTD-DEADBEEF']],
    ],
    [
        'name'  => 'dokumen.kirim.gagal.konten_pendek',
        'desc'  => 'Kirim dokumen dengan konten kosong/pendek (422) — app utama',
        'legacy' => ['method' => 'POST', 'path' => '/api/dokumen.php', 'params' => ['action' => 'kirim', 'jenis' => 'SPP', 'konten_html' => '<p>pendek</p>']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/dokumen/kirim', 'params' => ['jenis' => 'SPP', 'konten_html' => '<p>pendek</p>']],
    ],

    // ---- AKLAP: aklap.php, AKSI BACA (SUDAH DIPORTING) ----
    // Autentikasi legacy memakai token API (?token=) yang nilainya disediakan
    // harness lewat placeholder {{user.aktif.token}}; API baru memakai Bearer
    // (Sanctum) sehingga tidak memerlukan token tersebut.
    [
        'name'  => 'aklap.jurnal',
        'desc'  => 'Daftar jurnal (STBP + STS + SP2D)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/aklap.php', 'params' => [], 'query' => ['action' => 'jurnal', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/jurnal'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'aklap.jurnal_biasa',
        'desc'  => 'Daftar jurnal manual (jurnal biasa)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/aklap.php', 'params' => [], 'query' => ['action' => 'jurnal_biasa', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/jurnal-biasa'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'aklap.akun_list.aklap',
        'desc'  => 'Daftar akun COA (saran jurnal biasa)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/aklap.php', 'params' => [], 'query' => ['action' => 'akun_list', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/akun'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'aklap.dokumen_list',
        'desc'  => 'Daftar dokumen sumber (STBP + STS)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/aklap.php', 'params' => [], 'query' => ['action' => 'dokumen_list', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/dokumen'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'aklap.lra_rekap',
        'desc'  => 'Rekap LRA (realisasi per akun + anggaran)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/aklap.php', 'params' => [], 'query' => ['action' => 'lra_rekap', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/lra'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'aklap.neraca_rekap',
        'desc'  => 'Rekap neraca (saldo awal per akun)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/aklap.php', 'params' => [], 'query' => ['action' => 'neraca_rekap', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/neraca'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'aklap.profil',
        'desc'  => 'Profil pemilik token AKLAP',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/aklap.php', 'params' => [], 'query' => ['action' => 'profil', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/profil'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'aklap.rekap',
        'desc'  => 'Rekap AKLAP untuk dashboard',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/aklap.php', 'params' => [], 'query' => ['action' => 'rekap', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/rekap'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'aklap.token_tidak_valid',
        'desc'  => 'Token API salah ditolak 401 (mekanisme auth berbeda)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/aklap.php', 'params' => ['action' => 'rekap', 'token' => 'token-tidak-valid']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/rekap', 'token' => 'token-tidak-valid'],
        'known_divergence' => [
            'reason' => 'API baru memakai Sanctum Bearer Token, bukan ?token= + hash manual pada users.api_token',
            'note'   => 'Legacy: 401 "Token API tidak valid."; baru: 401 "Tidak terautentikasi." (keduanya 401).',
        ],
    ],

    // ---- AKLAP: akuntansi.php (BELUM diporting) ----
    // Jalur API legacy berada di peta.simtkd.com/api/akuntansi.php.
    // ---- AKLAP: akuntansi.php, MASTER AKUN + ANGGARAN (SUDAH DIPORTING) ----
    // Autentikasi: token legacy (?token=) vs Bearer Sanctum di API baru.
    // Skenario dipilih agar TIDAK mengubah data (jalur validasi/daftar saja);
    // jalur sukses (simpan/hapus/seed) diuji di tests/Feature/Api/AklapAkuntansiTest.php.
    [
        'name'  => 'aklap.akun_master_list',
        'desc'  => 'Daftar Master Akun (COA, auto-seed)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => [], 'query' => ['action' => 'akun_list', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/akun-master'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'aklap.profil_akuntansi',
        'desc'  => 'Profil akuntansi (NIP + gambar TTD)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => [], 'query' => ['action' => 'profil', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/profil-akuntansi'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'aklap.anggaran_list',
        'desc'  => 'Daftar pagu anggaran (berbasis Master Akun)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tahun' => '2026', ], 'query' => ['action' => 'anggaran_list', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/anggaran', 'params' => ['tahun' => '2026']],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'aklap.akun_simpan.gagal.tanpa_kode',
        'desc'  => 'Simpan akun tanpa kode/nama (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => [], 'query' => ['action' => 'akun_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/akun-master'],
        'expect' => ['success' => false, 'message' => 'Kode akun dan nama akun wajib diisi.'],
    ],
    [
        'name'  => 'aklap.akun_hapus.gagal.id_kosong',
        'desc'  => 'Hapus akun tanpa id (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => [], 'query' => ['action' => 'akun_hapus', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/akun-master/hapus'],
        'expect' => ['success' => false, 'message' => 'ID akun tidak valid.'],
    ],
    [
        'name'  => 'aklap.anggaran_simpan.gagal.tanpa_baris',
        'desc'  => 'Simpan pagu tanpa baris (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tahun' => '2026', ], 'query' => ['action' => 'anggaran_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/anggaran', 'params' => ['tahun' => '2026']],
        'expect' => ['success' => false, 'message' => 'Tidak ada baris anggaran yang dikirim.'],
    ],
    [
        'name'  => 'aklap.anggaran_simpan.gagal.bukan_angka',
        'desc'  => 'Pagu bukan angka (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tahun' => '2026', 'rows' => '[{"kode_akun":"1.1.1.01","anggaran":"abc"}]', ], 'query' => ['action' => 'anggaran_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/anggaran', 'params' => ['tahun' => '2026', 'rows' => '[{"kode_akun":"1.1.1.01","anggaran":"abc"}]']],
        'expect' => ['success' => false, 'message' => 'Anggaran akun 1.1.1.01 harus berupa angka.'],
    ],
    [
        'name'  => 'aklap.anggaran_simpan.gagal.negatif',
        'desc'  => 'Pagu negatif (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tahun' => '2026', 'rows' => '[{"kode_akun":"1.1.1.01","anggaran":"-5"}]', ], 'query' => ['action' => 'anggaran_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/anggaran', 'params' => ['tahun' => '2026', 'rows' => '[{"kode_akun":"1.1.1.01","anggaran":"-5"}]']],
        'expect' => ['success' => false, 'message' => 'Anggaran akun 1.1.1.01 tidak boleh negatif.'],
    ],
    [
        'name'  => 'aklap.anggaran_simpan.gagal.di_luar_master',
        'desc'  => 'Kode akun belum ada di Master Akun (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tahun' => '2026', 'rows' => '[{"kode_akun":"9.9.9.99","anggaran":"1000"}]', ], 'query' => ['action' => 'anggaran_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/anggaran', 'params' => ['tahun' => '2026', 'rows' => '[{"kode_akun":"9.9.9.99","anggaran":"1000"}]']],
        'expect' => ['success' => false, 'message' => 'Akun berikut belum ada di Master Akun: 9.9.9.99. Tambahkan dulu di halaman Master Akun.'],
    ],
    [
        'name'  => 'aklap.anggaran_simpan.gagal.tahun_invalid',
        'desc'  => 'Simpan pagu dengan tahun tidak valid (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tahun' => '20', ], 'query' => ['action' => 'anggaran_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/anggaran', 'params' => ['tahun' => '20']],
        'expect' => ['success' => false, 'message' => 'Tahun anggaran tidak valid (format YYYY).'],
    ],
    [
        'name'  => 'aklap.anggaran_hapus.gagal.tahun_invalid',
        'desc'  => 'Hapus pagu dengan tahun tidak valid (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tahun' => 'abc', 'kode_akun' => '', ], 'query' => ['action' => 'anggaran_hapus', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/anggaran/hapus', 'params' => ['tahun' => 'abc', 'kode_akun' => '']],
        'expect' => ['success' => false, 'message' => 'Tahun / kode akun tidak valid.'],
    ],

    // ---- AKLAP: akuntansi.php sisanya (BELUM diporting) ----
    ['name' => 'aklap.akun_seed_coa',      'desc' => 'Seed COA LRA standar (mutasi; diuji di PHPUnit)', 'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['action' => 'akun_seed_coa']], 'baru' => null],
    ['name' => 'aklap.anggaran_sinkron',  'desc' => 'Sinkron pagu dengan master akun (mutasi; diuji di PHPUnit)', 'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['action' => 'anggaran_sinkron_master']], 'baru' => null],
    // ---- AKLAP: akuntansi.php, JURNAL UMUM + SARAN JURNAL (DIPORTING) ----
    // Jalur sukses (simpan/approve/hapus) mengubah data sehingga diuji di
    // tests/Feature/Api/AklapJurnalTest.php + E2E; di sini hanya jalur
    // daftar/validasi agar dapat dibandingkan pada basis data bersama.
    [
        'name'  => 'aklap.jurnal_umum_list',
        'desc'  => 'Daftar jurnal umum + rincian D/K',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => [], 'query' => ['action' => 'jurnal_list', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/jurnal-umum'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'aklap.saran_dokumen',
        'desc'  => 'Dokumen STS/SP2D siap dijurnal',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => [], 'query' => ['action' => 'saran_dokumen', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/saran-dokumen'],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'aklap.saran_jurnal.gagal.tanpa_sumber',
        'desc'  => 'Saran jurnal tanpa sumber/id (422)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => [], 'query' => ['action' => 'saran_jurnal', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/saran-jurnal'],
        'expect' => ['success' => false, 'message' => 'Sumber dan ID dokumen wajib dipilih.'],
    ],
    [
        'name'  => 'aklap.saran_jurnal.gagal.sts_tidak_ada',
        'desc'  => 'Saran jurnal STS tidak ditemukan (404)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => [], 'query' => ['action' => 'saran_jurnal', 'sumber' => 'sts', 'id' => '99999999', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/saran-jurnal', 'params' => ['sumber' => 'sts', 'id' => '99999999']],
        'expect' => ['success' => false, 'message' => 'STS tidak ditemukan / tidak aktif.'],
    ],
    [
        'name'  => 'aklap.saran_jurnal.gagal.sp2d_tidak_ada',
        'desc'  => 'Saran jurnal SP2D tidak ditemukan (404)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => [], 'query' => ['action' => 'saran_jurnal', 'sumber' => 'sp2d', 'id' => '99999999', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/saran-jurnal', 'params' => ['sumber' => 'sp2d', 'id' => '99999999']],
        'expect' => ['success' => false, 'message' => 'SP2D tidak ditemukan / belum dicairkan.'],
    ],
    [
        'name'  => 'aklap.jurnal_simpan.gagal.tanggal',
        'desc'  => 'Jurnal tanpa tanggal valid (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tanggal' => '2026-13-99'], 'query' => ['action' => 'jurnal_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/jurnal-umum', 'params' => ['tanggal' => '2026-13-99']],
        'expect' => ['success' => false, 'message' => 'Tanggal jurnal wajib diisi (format YYYY-MM-DD).'],
    ],
    [
        'name'  => 'aklap.jurnal_simpan.gagal.detail_kurang',
        'desc'  => 'Jurnal dengan kurang dari 2 baris (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tanggal' => '2026-03-01', 'detail' => '[]'], 'query' => ['action' => 'jurnal_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/jurnal-umum', 'params' => ['tanggal' => '2026-03-01', 'detail' => '[]']],
        'expect' => ['success' => false, 'message' => 'Jurnal umum minimal memiliki 2 baris (Debet dan Kredit).'],
    ],
    [
        'name'  => 'aklap.jurnal_simpan.gagal.baris_tidak_lengkap',
        'desc'  => 'Baris jurnal tanpa kode/posisi (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tanggal' => '2026-03-01', 'detail' => '[{"kode_akun":"","posisi":"debet","jumlah":1000},{"kode_akun":"1.1.1.01","posisi":"","jumlah":1000}]'], 'query' => ['action' => 'jurnal_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/jurnal-umum', 'params' => ['tanggal' => '2026-03-01', 'detail' => '[{"kode_akun":"","posisi":"debet","jumlah":1000},{"kode_akun":"1.1.1.01","posisi":"","jumlah":1000}]']],
        'expect' => ['success' => false, 'message' => 'Setiap baris wajib memiliki kode akun, posisi D/K, dan jumlah > 0.'],
    ],
    [
        'name'  => 'aklap.jurnal_simpan.gagal.tidak_seimbang',
        'desc'  => 'Jurnal tidak seimbang D != K (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tanggal' => '2026-03-01', 'detail' => '[{"kode_akun":"1.1.1.01","posisi":"debet","jumlah":1000},{"kode_akun":"4.1.4.01","posisi":"kredit","jumlah":500}]'], 'query' => ['action' => 'jurnal_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/jurnal-umum', 'params' => ['tanggal' => '2026-03-01', 'detail' => '[{"kode_akun":"1.1.1.01","posisi":"debet","jumlah":1000},{"kode_akun":"4.1.4.01","posisi":"kredit","jumlah":500}]']],
        'expect' => ['success' => false, 'message' => 'Jurnal TIDAK seimbang: Total Debet Rp 1.000,00 != Total Kredit Rp 500,00.'],
    ],
    [
        'name'  => 'aklap.jurnal_simpan.gagal.sumber_tidak_tersedia',
        'desc'  => 'Dokumen sumber STS tidak tersedia (409)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tanggal' => '2026-03-01', 'sumber_tipe' => 'sts', 'sumber_id' => '99999999', 'detail' => '[{"kode_akun":"1.1.1.01","posisi":"debet","jumlah":1000},{"kode_akun":"4.1.4.01","posisi":"kredit","jumlah":1000}]'], 'query' => ['action' => 'jurnal_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/jurnal-umum', 'params' => ['tanggal' => '2026-03-01', 'sumber_tipe' => 'sts', 'sumber_id' => '99999999', 'detail' => '[{"kode_akun":"1.1.1.01","posisi":"debet","jumlah":1000},{"kode_akun":"4.1.4.01","posisi":"kredit","jumlah":1000}]']],
        'expect' => ['success' => false, 'message' => 'Dokumen sumber tidak tersedia / sudah pernah dibuatkan jurnal.'],
    ],
    [
        'name'  => 'aklap.jurnal_hapus.gagal.id_kosong',
        'desc'  => 'Hapus jurnal tanpa id (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => [], 'query' => ['action' => 'jurnal_hapus', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/jurnal-umum/hapus'],
        'expect' => ['success' => false, 'message' => 'ID jurnal tidak valid.'],
    ],
    [
        'name'  => 'aklap.jurnal_hapus.gagal.tidak_ada',
        'desc'  => 'Hapus jurnal yang tidak ada (404)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['id' => '99999999'], 'query' => ['action' => 'jurnal_hapus', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/jurnal-umum/hapus', 'params' => ['id' => '99999999']],
        'expect' => ['success' => false, 'message' => 'Jurnal tidak ditemukan.'],
    ],
    [
        'name'  => 'aklap.jurnal_status.gagal.status_invalid',
        'desc'  => 'Approve/reject dengan status tidak valid (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['id' => '99999999', 'status' => 'ngawur'], 'query' => ['action' => 'jurnal_status', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/jurnal-umum/status', 'params' => ['id' => '99999999', 'status' => 'ngawur']],
        'expect' => ['success' => false, 'message' => 'Parameter status tidak valid.'],
    ],
    [
        'name'  => 'aklap.buku_besar',
        'desc'  => 'Buku besar per akun (saldo awal + posting approved)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'buku_besar', 'token' => '{{user.aktif.token}}', 'kode_akun' => '4.1.1.01.03']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/buku-besar', 'query' => ['kode_akun' => '4.1.1.01.03']],
    ],
    [
        'name'  => 'aklap.buku_besar.tanpa_kode',
        'desc'  => 'Buku besar tanpa kode akun (422)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'buku_besar', 'token' => '{{user.aktif.token}}', 'kode_akun' => '']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/buku-besar', 'query' => ['kode_akun' => '']],
        'expect' => ['success' => false, 'message' => 'Pilih kode akun terlebih dahulu.'],
    ],
    [
        'name'  => 'aklap.buku_besar.periode_tidak_valid',
        'desc'  => 'Buku besar dengan tanggal mustahil (diabaikan, bukan galat)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'buku_besar', 'token' => '{{user.aktif.token}}', 'kode_akun' => '4.1.1.01.03', 'dari' => '2026-13-99', 'akhir' => 'bukan-tanggal']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/buku-besar', 'query' => ['kode_akun' => '4.1.1.01.03', 'dari' => '2026-13-99', 'akhir' => 'bukan-tanggal']],
        'expect' => ['success' => true],
    ],
    [
        'name'  => 'aklap.neraca_saldo',
        'desc'  => 'Neraca saldo (mutasi + saldo awal per akun)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'neraca_saldo', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/neraca-saldo'],
    ],
    [
        'name'  => 'aklap.neraca_saldo.periode',
        'desc'  => 'Neraca saldo dibatasi periode',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'neraca_saldo', 'token' => '{{user.aktif.token}}', 'dari' => '2026-03-01', 'akhir' => '2026-03-31']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/neraca-saldo', 'query' => ['dari' => '2026-03-01', 'akhir' => '2026-03-31']],
    ],
    [
        'name'  => 'aklap.buku_kas_list',
        'desc'  => 'Daftar buku kas + saldo berjalan',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'buku_kas_list', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/buku-kas'],
    ],
    [
        'name'  => 'aklap.buku_kas_list.jenis',
        'desc'  => 'Daftar buku kas difilter jenis pengeluaran',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'buku_kas_list', 'token' => '{{user.aktif.token}}', 'jenis' => 'pengeluaran']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/buku-kas', 'query' => ['jenis' => 'pengeluaran']],
    ],
    [
        'name'  => 'aklap.buku_kas_simpan',
        'desc'  => 'Simpan buku kas: tanggal tidak valid (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tanggal' => '2026-13-99', 'jenis' => 'penerimaan', 'uraian' => 'Uji'], 'query' => ['action' => 'buku_kas_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/buku-kas', 'params' => ['tanggal' => '2026-13-99', 'jenis' => 'penerimaan', 'uraian' => 'Uji']],
        'expect' => ['success' => false, 'message' => 'Tanggal wajib diisi dengan benar.'],
    ],
    [
        'name'  => 'aklap.buku_kas_simpan.jenis_kosong',
        'desc'  => 'Simpan buku kas: jenis tidak dipilih (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tanggal' => '2026-03-01', 'jenis' => '', 'uraian' => 'Uji', 'jumlah' => '1000'], 'query' => ['action' => 'buku_kas_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/buku-kas', 'params' => ['tanggal' => '2026-03-01', 'jenis' => '', 'uraian' => 'Uji', 'jumlah' => '1000']],
        'expect' => ['success' => false, 'message' => 'Jenis transaksi wajib dipilih.'],
    ],
    [
        'name'  => 'aklap.buku_kas_simpan.uraian_kosong',
        'desc'  => 'Simpan buku kas: uraian kosong (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tanggal' => '2026-03-01', 'jenis' => 'penerimaan', 'uraian' => '  ', 'jumlah' => '1000'], 'query' => ['action' => 'buku_kas_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/buku-kas', 'params' => ['tanggal' => '2026-03-01', 'jenis' => 'penerimaan', 'uraian' => '  ', 'jumlah' => '1000']],
        'expect' => ['success' => false, 'message' => 'Uraian wajib diisi.'],
    ],
    [
        'name'  => 'aklap.buku_kas_simpan.jumlah_nol',
        'desc'  => 'Simpan buku kas: jumlah nol (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tanggal' => '2026-03-01', 'jenis' => 'penerimaan', 'uraian' => 'Uji', 'jumlah' => '0'], 'query' => ['action' => 'buku_kas_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/buku-kas', 'params' => ['tanggal' => '2026-03-01', 'jenis' => 'penerimaan', 'uraian' => 'Uji', 'jumlah' => '0']],
        'expect' => ['success' => false, 'message' => 'Jumlah harus lebih besar dari nol.'],
    ],
    [
        'name'  => 'aklap.buku_kas_hapus.id_invalid',
        'desc'  => 'Hapus buku kas: id tidak valid (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['id' => '0'], 'query' => ['action' => 'buku_kas_hapus', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/buku-kas/hapus', 'params' => ['id' => '0']],
        'expect' => ['success' => false, 'message' => 'ID transaksi tidak valid.'],
    ],
    [
        'name'  => 'aklap.buku_kas_hapus.tidak_ada',
        'desc'  => 'Hapus buku kas yang tidak ada (pesan "tidak ditemukan")',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['id' => '99999999'], 'query' => ['action' => 'buku_kas_hapus', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/buku-kas/hapus', 'params' => ['id' => '99999999']],
        'expect' => ['success' => true, 'message' => 'Transaksi tidak ditemukan.'],
    ],
    [
        'name'  => 'aklap.buku_kas_umum',
        'desc'  => 'Buku Kas Umum (BUD) + identitas penanda tangan',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'buku_kas_umum', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/buku-kas-umum'],
    ],
    [
        'name'  => 'aklap.buku_kas_umum.periode_saldo_awal',
        'desc'  => 'Buku kas umum: saldo awal periode + timpaan manual',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'buku_kas_umum', 'token' => '{{user.aktif.token}}', 'dari' => '2026-03-01', 'saldo_awal' => '1.500.000,50']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/buku-kas-umum', 'query' => ['dari' => '2026-03-01', 'saldo_awal' => '1.500.000,50']],
    ],
    [
        'name'  => 'aklap.kas_harian.tanggal_libur',
        'desc'  => 'Kas harian tanggal mustahil (422)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'kas_harian', 'token' => '{{user.aktif.token}}', 'tanggal' => '2026-02-30']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/kas-harian', 'query' => ['tanggal' => '2026-02-30']],
        'expect' => ['success' => false, 'message' => 'Tanggal laporan tidak valid (format YYYY-MM-DD).'],
    ],
    [
        'name'  => 'aklap.kas_harian.belum_ada',
        'desc'  => 'Kas harian tanpa dokumen: hari/periode dihitung, posisi nol',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'kas_harian', 'token' => '{{user.aktif.token}}', 'tanggal' => '2026-05-04']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/kas-harian', 'query' => ['tanggal' => '2026-05-04']],
        'expect' => ['success' => true, 'hari' => 'Senin', 'periode' => 'Mei', 'sumber_posisi_h1' => 'nol', 'tersimpan' => false],
    ],
    [
        'name'  => 'aklap.kas_harian_daftar',
        'desc'  => 'Daftar kas harian (mungkin kosong)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'kas_harian_daftar', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/kas-harian/daftar'],
    ],
    [
        'name'  => 'aklap.kas_harian_daftar.periode',
        'desc'  => 'Daftar kas harian dibatasi periode',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'kas_harian_daftar', 'token' => '{{user.aktif.token}}', 'dari' => '2026-03-01', 'akhir' => '2026-03-31']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/kas-harian/daftar', 'query' => ['dari' => '2026-03-01', 'akhir' => '2026-03-31']],
    ],
    [
        'name'  => 'aklap.kas_harian_simpan.tanggal_wajib',
        'desc'  => 'Simpan kas harian tanpa tanggal (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tanggal' => ''], 'query' => ['action' => 'kas_harian_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/kas-harian', 'params' => ['tanggal' => '']],
        'expect' => ['success' => false, 'message' => 'Tanggal laporan wajib diisi dengan benar (YYYY-MM-DD).'],
    ],
    [
        'name'  => 'aklap.kas_harian_simpan.items_bukan_json',
        'desc'  => 'Simpan kas harian: items bukan JSON (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tanggal' => '2026-03-01', 'items' => '{bukan json'], 'query' => ['action' => 'kas_harian_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/kas-harian', 'params' => ['tanggal' => '2026-03-01', 'items' => '{bukan json']],
        'expect' => ['success' => false, 'message' => 'Data baris transaksi tidak valid.'],
    ],
    [
        'name'  => 'aklap.kas_harian_simpan.nilai_negatif',
        'desc'  => 'Simpan kas harian: penerimaan negatif (422 baris 1)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tanggal' => '2026-03-01', 'items' => json_encode([['uraian' => 'Uji', 'penerimaan' => '-5', 'pengeluaran' => '0']])], 'query' => ['action' => 'kas_harian_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/kas-harian', 'params' => ['tanggal' => '2026-03-01', 'items' => json_encode([['uraian' => 'Uji', 'penerimaan' => '-5', 'pengeluaran' => '0']])]],
        'expect' => ['success' => false, 'message' => 'Nilai penerimaan/pengeluaran tidak boleh negatif (baris 1).'],
    ],
    [
        'name'  => 'aklap.kas_harian_hapus.belum_ada',
        'desc'  => 'Hapus kas harian yang belum ada',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tanggal' => '2026-06-01'], 'query' => ['action' => 'kas_harian_hapus', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/kas-harian/hapus', 'params' => ['tanggal' => '2026-06-01']],
        'expect' => ['success' => true, 'message' => 'Laporan tanggal tersebut belum ada.'],
    ],
    [
        'name'  => 'aklap.kas_harian_hapus.tanggal_invalid',
        'desc'  => 'Hapus kas harian: tanggal tidak valid (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tanggal' => 'ngawur'], 'query' => ['action' => 'kas_harian_hapus', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/kas-harian/hapus', 'params' => ['tanggal' => 'ngawur']],
        'expect' => ['success' => false, 'message' => 'Tanggal laporan tidak valid (format YYYY-MM-DD).'],
    ],
    [
        'name'  => 'aklap.rekonsiliasi_bank',
        'desc'  => 'Dokumen rekonsiliasi bulan berjalan (belum tersimpan)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'rekonsiliasi_bank', 'token' => '{{user.aktif.token}}', 'tahun' => '2026', 'bulan' => '3']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/rekonsiliasi-bank', 'query' => ['tahun' => '2026', 'bulan' => '3']],
        'expect' => ['success' => true, 'periode' => 'Maret', 'tgl_ttd' => '2026-03-31', 'tersimpan' => false, 'total_buku' => 0],
    ],
    [
        'name'  => 'aklap.rekonsiliasi_bank.periode_default',
        'desc'  => 'Tahun/bulan tidak valid -> dipakai periode hari ini',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'rekonsiliasi_bank', 'token' => '{{user.aktif.token}}', 'tahun' => '20', 'bulan' => '99']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/rekonsiliasi-bank', 'query' => ['tahun' => '20', 'bulan' => '99']],
        'expect' => ['success' => true, 'tahun' => (int) date('Y'), 'bulan' => (int) date('n')],
    ],
    [
        'name'  => 'aklap.rekonsiliasi_bank_daftar',
        'desc'  => 'Daftar dokumen rekonsiliasi bank (mungkin kosong)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'rekonsiliasi_bank_daftar', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/rekonsiliasi-bank/daftar'],
    ],
    [
        'name'  => 'aklap.rekonsiliasi_bank_simpan.periode_invalid',
        'desc'  => 'Simpan rekonsiliasi: periode tidak valid (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tahun' => '1999', 'bulan' => '13'], 'query' => ['action' => 'rekonsiliasi_bank_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/rekonsiliasi-bank', 'params' => ['tahun' => '1999', 'bulan' => '13']],
        'expect' => ['success' => false, 'message' => 'Periode (tahun / bulan) tidak valid.'],
    ],
    [
        'name'  => 'aklap.rekonsiliasi_bank_simpan.items_bukan_json',
        'desc'  => 'Simpan rekonsiliasi: items bukan JSON (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tahun' => '2026', 'bulan' => '3', 'items' => 'bukan-json'], 'query' => ['action' => 'rekonsiliasi_bank_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/rekonsiliasi-bank', 'params' => ['tahun' => '2026', 'bulan' => '3', 'items' => 'bukan-json']],
        'expect' => ['success' => false, 'message' => 'Data rincian keterangan selisih tidak valid.'],
    ],
    [
        'name'  => 'aklap.rekonsiliasi_bank_simpan.nilai_negatif',
        'desc'  => 'Simpan rekonsiliasi: nilai negatif (422 bagian B baris 1)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tahun' => '2026', 'bulan' => '3', 'items' => json_encode(['B' => [['uraian' => 'Uji', 'nilai' => '-1']]])], 'query' => ['action' => 'rekonsiliasi_bank_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/rekonsiliasi-bank', 'params' => ['tahun' => '2026', 'bulan' => '3', 'items' => json_encode(['B' => [['uraian' => 'Uji', 'nilai' => '-1']]])]],
        'expect' => ['success' => false, 'message' => 'Nilai tidak boleh negatif (bagian B baris 1).'],
    ],
    [
        'name'  => 'aklap.rekonsiliasi_bank_simpan.baris_berlebih',
        'desc'  => 'Simpan rekonsiliasi: bagian C melebihi 60 baris (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tahun' => '2026', 'bulan' => '3', 'items' => json_encode(['C' => array_fill(0, 61, ['uraian' => 'x', 'nilai' => '1'])])], 'query' => ['action' => 'rekonsiliasi_bank_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/rekonsiliasi-bank', 'params' => ['tahun' => '2026', 'bulan' => '3', 'items' => json_encode(['C' => array_fill(0, 61, ['uraian' => 'x', 'nilai' => '1'])])]],
        'expect' => ['success' => false, 'message' => 'Baris bagian C melebihi batas 60 baris.'],
    ],
    [
        'name'  => 'aklap.rekonsiliasi_bank_hapus.periode_invalid',
        'desc'  => 'Hapus rekonsiliasi: periode tidak valid (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tahun' => '0', 'bulan' => '0'], 'query' => ['action' => 'rekonsiliasi_bank_hapus', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/rekonsiliasi-bank/hapus', 'params' => ['tahun' => '0', 'bulan' => '0']],
        'expect' => ['success' => false, 'message' => 'Periode (tahun / bulan) tidak valid.'],
    ],
    [
        'name'  => 'aklap.rekonsiliasi_bank_hapus.belum_ada',
        'desc'  => 'Hapus rekonsiliasi yang belum ada',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['tahun' => '2026', 'bulan' => '11'], 'query' => ['action' => 'rekonsiliasi_bank_hapus', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/rekonsiliasi-bank/hapus', 'params' => ['tahun' => '2026', 'bulan' => '11']],
        'expect' => ['success' => true, 'message' => 'Rekonsiliasi periode tersebut belum ada.'],
    ],
    [
        'name'  => 'aklap.register_ppkd',
        'desc'  => 'Register SPP/SPM/SP2D dari Buku Pengeluaran PPKD',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'register_ppkd_list', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/register-ppkd'],
        'expect' => ['success' => true, 'total' => 0, 'total_jumlah' => 0, 'jumlah_lengkap' => 0],
    ],
    [
        'name'  => 'aklap.register_ppkd.periode',
        'desc'  => 'Register PPKD dibatasi periode (tanggal tidak valid diabaikan)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'register_ppkd_list', 'token' => '{{user.aktif.token}}', 'dari' => '2026-03-01', 'akhir' => 'ngawur']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/register-ppkd', 'query' => ['dari' => '2026-03-01', 'akhir' => 'ngawur']],
        'expect' => ['success' => true, 'periode' => ['dari' => '2026-03-01', 'sampai' => '']],
    ],
    [
        'name'  => 'aklap.register_ppkd_simpan.id_invalid',
        'desc'  => 'Register PPKD: id buku kas tidak valid (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['buku_kas_id' => '0'], 'query' => ['action' => 'register_ppkd_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/register-ppkd', 'params' => ['buku_kas_id' => '0']],
        'expect' => ['success' => false, 'message' => 'Transaksi Buku Pengeluaran PPKD tidak valid.'],
    ],
    [
        'name'  => 'aklap.register_ppkd_simpan.bukan_milik_instansi',
        'desc'  => 'Register PPKD: transaksi milik instansi lain (403)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['buku_kas_id' => '99999999'], 'query' => ['action' => 'register_ppkd_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/register-ppkd', 'params' => ['buku_kas_id' => '99999999']],
        'expect' => ['success' => false, 'message' => 'Transaksi tidak ditemukan pada Buku Pengeluaran PPKD Anda.'],
    ],
    [
        'name'  => 'aklap.lpj_ppkd',
        'desc'  => 'LPJ PPKD periode Maret (rincian + rekonsiliasi kosong)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'lpj_ppkd', 'token' => '{{user.aktif.token}}', 'tahun' => '2026', 'bulan' => '3']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/lpj-ppkd', 'query' => ['tahun' => '2026', 'bulan' => '3']],
        'expect' => ['success' => true, 'bulan_nama' => 'Maret', 'tgl_laporan' => '2026-03-31', 'jumlah_baris' => 0, 'tanpa_pagu' => 0],
    ],
    [
        'name'  => 'aklap.lpj_ppkd.periode_default',
        'desc'  => 'LPJ PPKD: tahun/bulan tidak valid -> periode hari ini',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'lpj_ppkd', 'token' => '{{user.aktif.token}}', 'tahun' => '26', 'bulan' => '0']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/lpj-ppkd', 'query' => ['tahun' => '26', 'bulan' => '0']],
        'expect' => ['success' => true, 'tahun' => date('Y'), 'bulan' => (int) date('n')],
    ],
    [
        'name'  => 'aklap.buku_pembantu',
        'desc'  => 'Buku pembantu per rincian objek',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'buku_pembantu_list', 'token' => '{{user.aktif.token}}', 'jenis' => 'penerimaan', 'kode_akun' => '4.1.1.01.03', 'bulan' => '3', 'tahun' => '2026']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/buku-pembantu', 'query' => ['jenis' => 'penerimaan', 'kode_akun' => '4.1.1.01.03', 'bulan' => '3', 'tahun' => '2026']],
    ],
    [
        'name'  => 'aklap.buku_pembantu.tanpa_jenis',
        'desc'  => 'Buku pembantu tanpa jenis (422)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'buku_pembantu_list', 'token' => '{{user.aktif.token}}', 'jenis' => '', 'kode_akun' => '4.1.1.01.03']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/buku-pembantu', 'query' => ['jenis' => '', 'kode_akun' => '4.1.1.01.03']],
        'expect' => ['success' => false, 'message' => 'Pilih jenis (penerimaan / pengeluaran).'],
    ],
    [
        'name'  => 'aklap.buku_pembantu.tanpa_kode',
        'desc'  => 'Buku pembantu tanpa kode rekening (422)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'buku_pembantu_list', 'token' => '{{user.aktif.token}}', 'jenis' => 'penerimaan', 'kode_akun' => '']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/buku-pembantu', 'query' => ['jenis' => 'penerimaan', 'kode_akun' => '']],
        'expect' => ['success' => false, 'message' => 'Pilih kode rekening rincian objek.'],
    ],
    [
        'name'  => 'aklap.buku_pembantu.obyek',
        'desc'  => 'Daftar objek buku pembantu (pemilih)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'buku_pembantu_obyek_list', 'token' => '{{user.aktif.token}}', 'jenis' => 'penerimaan']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/buku-pembantu-obyek', 'query' => ['jenis' => 'penerimaan']],
    ],
    [
        'name'  => 'aklap.buku_pembantu.obyek_tanpa_jenis',
        'desc'  => 'Daftar objek buku pembantu tanpa jenis (422)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'buku_pembantu_obyek_list', 'token' => '{{user.aktif.token}}', 'jenis' => 'salah']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/buku-pembantu-obyek', 'query' => ['jenis' => 'salah']],
        'expect' => ['success' => false, 'message' => 'Pilih jenis (penerimaan / pengeluaran).'],
    ],
    [
        'name'  => 'aklap.jurnal_khusus_template',
        'desc'  => 'Daftar blanko jurnal khusus (6 jenis, maks 10 slot)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'jurnal_khusus_template', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/jurnal-khusus/template'],
        'expect' => ['success' => true, 'maks_slot' => 10],
    ],
    [
        'name'  => 'aklap.jurnal_khusus',
        'desc'  => 'Buku jurnal khusus periode Maret (belum tersimpan)',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'jurnal_khusus', 'token' => '{{user.aktif.token}}', 'jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '3']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/jurnal-khusus', 'query' => ['jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '3']],
        'expect' => ['success' => true, 'jenis' => 'belanja_pemda', 'bulan_nama' => 'Maret', 'tgl_ttd' => '2026-03-31', 'tersimpan' => false, 'jumlah_baris' => 0],
    ],
    [
        'name'  => 'aklap.jurnal_khusus.jenis_asing',
        'desc'  => 'Jenis jurnal tidak dikenal -> jatuh ke jenis pertama',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'jurnal_khusus', 'token' => '{{user.aktif.token}}', 'jenis' => 'ngawur', 'tahun' => '20', 'bulan' => '99']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/jurnal-khusus', 'query' => ['jenis' => 'ngawur', 'tahun' => '20', 'bulan' => '99']],
        'expect' => ['success' => true, 'jenis' => 'pendapatan_lo', 'tahun' => (int) date('Y'), 'bulan' => (int) date('n')],
    ],
    [
        'name'  => 'aklap.jurnal_khusus_daftar',
        'desc'  => 'Riwayat periode jurnal khusus satu jenis',
        'legacy' => ['method' => 'GET', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'query' => ['action' => 'jurnal_khusus_daftar', 'token' => '{{user.aktif.token}}', 'jenis' => 'belanja_pemda']],
        'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/jurnal-khusus/daftar', 'query' => ['jenis' => 'belanja_pemda']],
        'expect' => ['success' => true, 'jenis' => 'belanja_pemda', 'total' => 0],
    ],
    [
        'name'  => 'aklap.jurnal_khusus_simpan.jenis_asing',
        'desc'  => 'Simpan jurnal khusus: jenis tidak dikenal (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['jenis' => 'ngawur', 'tahun' => '2026', 'bulan' => '3'], 'query' => ['action' => 'jurnal_khusus_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/jurnal-khusus', 'params' => ['jenis' => 'ngawur', 'tahun' => '2026', 'bulan' => '3']],
        'expect' => ['success' => false, 'message' => 'Jenis jurnal tidak dikenal.'],
    ],
    [
        'name'  => 'aklap.jurnal_khusus_simpan.tahun_invalid',
        'desc'  => 'Simpan jurnal khusus: tahun di luar 2000-2100 (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['jenis' => 'belanja_pemda', 'tahun' => '1999', 'bulan' => '3'], 'query' => ['action' => 'jurnal_khusus_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/jurnal-khusus', 'params' => ['jenis' => 'belanja_pemda', 'tahun' => '1999', 'bulan' => '3']],
        'expect' => ['success' => false, 'message' => 'Tahun tidak valid (2000 - 2100).'],
    ],
    [
        'name'  => 'aklap.jurnal_khusus_simpan.bulan_invalid',
        'desc'  => 'Simpan jurnal khusus: bulan di luar 1-12 (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '13'], 'query' => ['action' => 'jurnal_khusus_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/jurnal-khusus', 'params' => ['jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '13']],
        'expect' => ['success' => false, 'message' => 'Bulan tidak valid (1 - 12).'],
    ],
    [
        'name'  => 'aklap.jurnal_khusus_simpan.kosong',
        'desc'  => 'Simpan jurnal khusus tanpa baris berisi (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '3', 'baris' => json_encode([['tanggal' => '', 'uraian' => '', 'debet' => ['0'], 'kredit' => ['0']]])], 'query' => ['action' => 'jurnal_khusus_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/jurnal-khusus', 'params' => ['jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '3', 'baris' => json_encode([['tanggal' => '', 'uraian' => '', 'debet' => ['0'], 'kredit' => ['0']]])]],
        'expect' => ['success' => false, 'message' => 'Jurnal belum berisi data (minimal satu baris berisi angka atau uraian).'],
    ],
    [
        'name'  => 'aklap.jurnal_khusus_simpan.bukan_angka',
        'desc'  => 'Simpan jurnal khusus: nilai kolom bukan angka (422 baris 1)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '3', 'baris' => json_encode([['uraian' => 'Uji', 'debet' => ['abc'], 'kredit' => ['0']]])], 'query' => ['action' => 'jurnal_khusus_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/jurnal-khusus', 'params' => ['jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '3', 'baris' => json_encode([['uraian' => 'Uji', 'debet' => ['abc'], 'kredit' => ['0']]])]],
        'expect' => ['success' => false, 'message' => 'Baris ke-1: nilai kolom Debet ke-1 bukan angka.'],
    ],
    [
        'name'  => 'aklap.jurnal_khusus_simpan.nilai_negatif',
        'desc'  => 'Simpan jurnal khusus: nilai kolom negatif (422 baris 1)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '3', 'baris' => json_encode([['uraian' => 'Uji', 'debet' => ['-5'], 'kredit' => ['0']]])], 'query' => ['action' => 'jurnal_khusus_simpan', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/jurnal-khusus', 'params' => ['jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '3', 'baris' => json_encode([['uraian' => 'Uji', 'debet' => ['-5'], 'kredit' => ['0']]])]],
        'expect' => ['success' => false, 'message' => 'Baris ke-1: nilai kolom Debet ke-1 tidak boleh negatif.'],
    ],
    [
        'name'  => 'aklap.jurnal_khusus_hapus.periode_invalid',
        'desc'  => 'Hapus jurnal khusus: jenis/periode tidak valid (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['jenis' => 'ngawur', 'tahun' => '1999', 'bulan' => '0'], 'query' => ['action' => 'jurnal_khusus_hapus', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/jurnal-khusus/hapus', 'params' => ['jenis' => 'ngawur', 'tahun' => '1999', 'bulan' => '0']],
        'expect' => ['success' => false, 'message' => 'Jenis / periode jurnal tidak valid.'],
    ],
    [
        'name'  => 'aklap.jurnal_khusus_hapus.belum_ada',
        'desc'  => 'Hapus jurnal khusus periode yang belum ada',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '11'], 'query' => ['action' => 'jurnal_khusus_hapus', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/jurnal-khusus/hapus', 'params' => ['jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '11']],
        'expect' => ['success' => true, 'message' => 'Jurnal periode tersebut belum ada.'],
    ],
    [
        'name'  => 'aklap.dokumen_kirim.konten_pendek',
        'desc'  => 'Kirim dokumen: konten kurang dari 200 karakter (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['jenis' => 'Uji', 'konten_html' => '<p>pendek</p>'], 'query' => ['action' => 'dokumen_kirim', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/dokumen-kirim', 'params' => ['jenis' => 'Uji', 'konten_html' => '<p>pendek</p>']],
        'expect' => ['success' => false, 'message' => 'Konten dokumen kosong / tidak valid.'],
    ],
    [
        'name'  => 'aklap.dokumen_kirim.tanggal_invalid',
        'desc'  => 'Kirim dokumen: format tanggal tidak valid (422)',
        'legacy' => ['method' => 'POST', 'path' => '/peta.simtkd.com/api/akuntansi.php', 'params' => ['jenis' => 'Uji', 'tanggal' => '2026-13-99', 'konten_html' => '<p>' . str_repeat('a', 250) . '</p>'], 'query' => ['action' => 'dokumen_kirim', 'token' => '{{user.aktif.token}}']],
        'baru'   => ['method' => 'POST', 'path' => '/api/v1/aklap/dokumen-kirim', 'params' => ['jenis' => 'Uji', 'tanggal' => '2026-13-99', 'konten_html' => '<p>' . str_repeat('a', 250) . '</p>']],
        'expect' => ['success' => false, 'message' => 'Format tanggal tidak valid.'],
    ],

    // ================================================================
    // LOGOUT — diletakkan TERAKHIR karena logout mencabut seluruh token
    // API milik user tersebut. Bila dijalankan lebih awal, seluruh
    // skenario yang menyusul akan berakhir dengan 401.
    // ================================================================
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
];
