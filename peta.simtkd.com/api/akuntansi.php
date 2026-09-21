<?php
/**
 * SIM-TKD (peta.simtkd.com) - API Model Akuntansi Manual (Double-Entry)
 * =====================================================================
 * Jurnal Umum dua sisi (Debet/Kredit) yang diinput MANUAL, dengan saran
 * template jurnal dari dokumen modul Penerimaan (STS) dan modul Belanja
 * (SP2D) yang sudah ada. Mengikuti lembar kerja Modul 3 AKLAP SKPKD.
 *
 *   GET  api/akuntansi.php?token=XXX&action=profil            : profil akun
 *   GET  api/akuntansi.php?token=XXX&action=akun_list&q=      : daftar akun (auto-seed)
 *   POST api/akuntansi.php?token=XXX&action=akun_simpan       : simpan/ubah akun
 *   POST api/akuntansi.php?token=XXX&action=akun_hapus        : hapus akun
 *   POST api/akuntansi.php?token=XXX&action=akun_seed_coa     : seed ulang COA LRA standar
 *   GET  api/akuntansi.php?token=XXX&action=anggaran_list     : pagu LRA per akun Master
 *   POST api/akuntansi.php?token=XXX&action=anggaran_simpan   : simpan pagu (batch per akun)
 *   POST api/akuntansi.php?token=XXX&action=anggaran_hapus    : hapus pagu 1 akun/tahun
 *   POST api/akuntansi.php?token=XXX&action=anggaran_sinkron_master : kode anggaran -> Master Akun
 *   GET  api/akuntansi.php?token=XXX&action=lpj_ppkd&tahun=&bulan= : LPJ Bendahara PPKD (Modul-3E-LK)
 *   GET  api/akuntansi.php?token=XXX&action=buku_kas_umum&dari=&akhir= : Buku Kas Penerimaan & Pengeluaran BUD
 *   GET  api/akuntansi.php?token=XXX&action=kas_harian&tanggal=        : Laporan Posisi Kas Harian (1 tanggal)
 *   GET  api/akuntansi.php?token=XXX&action=kas_harian_daftar&dari=&akhir= : daftar laporan kas harian tersimpan
 *   POST api/akuntansi.php?token=XXX&action=kas_harian_simpan           : simpan laporan kas harian (manual)
 *   POST api/akuntansi.php?token=XXX&action=kas_harian_hapus            : hapus laporan kas harian 1 tanggal
 *   GET  api/akuntansi.php?token=XXX&action=rekonsiliasi_bank&tahun=&bulan=  : Rekonsiliasi Bank (manual, blanko Modul-3E-LK)
 *   GET  api/akuntansi.php?token=XXX&action=rekonsiliasi_bank_daftar    : daftar rekonsiliasi bank tersimpan
 *   POST api/akuntansi.php?token=XXX&action=rekonsiliasi_bank_simpan    : simpan rekonsiliasi bank (manual)
 *   POST api/akuntansi.php?token=XXX&action=rekonsiliasi_bank_hapus     : hapus rekonsiliasi bank 1 periode
 *   GET  api/akuntansi.php?token=XXX&action=jurnal_khusus_template : jenis blanko Buku Jurnal Khusus
 *   GET  api/akuntansi.php?token=XXX&action=jurnal_khusus&jenis=&tahun=&bulan= : 1 dokumen Buku Jurnal Khusus
 *   GET  api/akuntansi.php?token=XXX&action=jurnal_khusus_daftar&jenis=  : riwayat periode tersimpan
 *   POST api/akuntansi.php?token=XXX&action=jurnal_khusus_simpan        : simpan jurnal khusus (manual)
 *   POST api/akuntansi.php?token=XXX&action=jurnal_khusus_hapus         : hapus jurnal khusus 1 periode
 *   GET  api/akuntansi.php?token=XXX&action=jurnal_list       : daftar jurnal + detail
 *   POST api/akuntansi.php?token=XXX&action=jurnal_simpan     : simpan jurnal (D=K)
 *   POST api/akuntansi.php?token=XXX&action=jurnal_hapus      : hapus jurnal
 *   POST api/akuntansi.php?token=XXX&action=jurnal_status     : approve / reject
 *   GET  api/akuntansi.php?token=XXX&action=saran_dokumen     : STS & SP2D siap dijurnal
 *   GET  api/akuntansi.php?token=XXX&action=saran_jurnal      : template D/K dari dokumen
 *   GET  api/akuntansi.php?token=XXX&action=buku_besar        : posting per akun
 *   GET  api/akuntansi.php?token=XXX&action=neraca_saldo      : rekap mutasi per akun
 *
 * Token dapat dikirim via query (?token=) atau header Authorization: Bearer.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';  // fallback sesi (sama host dengan app utama)
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api.php';

header('Content-Type: application/json; charset=utf-8');

$pdo    = db();
$action = (string) ($_GET['action'] ?? '');

// ---------- Verifikasi token (per-user / multi-tenant) ----------
// Pola sama dengan api/aklap.php: token = SHA-256 hash tersimpan di users.
$token = (string) ($_GET['token'] ?? '');
if ($token === '') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        $token = trim($m[1]);
    }
}
$tokenHash = hash('sha256', $token);
$stmtUser = $pdo->prepare("SELECT id, nama_lengkap, username, email, instansi, kota, provinsi, peran FROM users WHERE api_token = ? LIMIT 1");
$stmtUser->execute([$tokenHash]);
$user = $stmtUser->fetch();
// Fallback: bila token kosong/tidak valid, coba sesi PHP (halaman AKLAP dibuka
// dari aplikasi utama pada host yang sama -> cookie sesi tersedia).
if (!$user && isLoggedIn()) {
    $stmtUser2 = $pdo->prepare("SELECT id, nama_lengkap, username, email, instansi, kota, provinsi, peran FROM users WHERE id = ? LIMIT 1");
    $stmtUser2->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $user = $stmtUser2->fetch();
}
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token API tidak valid. Silakan buka modul Akuntansi melalui menu Akuntansi di SIM-TKD.'], JSON_UNESCAPED_UNICODE);
    exit;
}
// SKPD/instansi pemilik token -> pemisahan data antar dinas (multi-tenant).
// Fail-closed: token tanpa instansi ditolak (bukan melihat semua data).
$skpdUser = trim((string) ($user['instansi'] ?? ''));
if ($skpdUser === '') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Instansi akun belum diatur. Hubungi administrator.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Seluruh aksi dibungkus try/catch: kegagalan DB tetap mengembalikan JSON
// yang jelas, bukan error 500 (pola sama dengan api/aklap.php).
try {
// ============================================================
// 0. Pastikan tabel & kolom tersedia (tanpa import SQL manual)
// ============================================================
$pdo->exec("CREATE TABLE IF NOT EXISTS akun_master (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd         VARCHAR(150) NOT NULL DEFAULT '',
    kode_akun    VARCHAR(50)  NOT NULL,
    nama_akun    VARCHAR(200) NOT NULL,
    tipe         ENUM('aset','liabilitas','ekuitas','pendapatan','belanja') NOT NULL DEFAULT 'aset',
    saldo_normal ENUM('debet','kredit') NOT NULL DEFAULT 'debet',
    aktif        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_akun_skpd (skpd, kode_akun),
    INDEX idx_akun_kode (kode_akun)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS buku_kas (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd          VARCHAR(150) NOT NULL DEFAULT '',
    user_id       INT UNSIGNED DEFAULT NULL,
    tanggal       DATE         NULL,
    jenis         ENUM('penerimaan','pengeluaran') NOT NULL DEFAULT 'penerimaan',
    no_bukti      VARCHAR(80)  NOT NULL DEFAULT '',
    kode_rekening VARCHAR(50)  NOT NULL DEFAULT '',
    uraian        VARCHAR(255) NOT NULL DEFAULT '',
    jumlah        DECIMAL(18,2) NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bk_skpd (skpd),
    INDEX idx_bk_tanggal (tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS register_ppkd (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd        VARCHAR(150) NOT NULL DEFAULT '',
    buku_kas_id INT UNSIGNED NOT NULL,
    spp_tgl     DATE         NULL,
    spp_no      VARCHAR(64)  NOT NULL DEFAULT '',
    spm_tgl     DATE         NULL,
    spm_no      VARCHAR(64)  NOT NULL DEFAULT '',
    sp2d_tgl    DATE         NULL,
    sp2d_no     VARCHAR(64)  NOT NULL DEFAULT '',
    ket         VARCHAR(120) NOT NULL DEFAULT '',
    user_id     INT UNSIGNED DEFAULT NULL,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reg_bk (skpd, buku_kas_id),
    INDEX idx_reg_skpd (skpd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Laporan Posisi Kas Harian (BUD) - diisi MANUAL, satu dokumen per tanggal.
$pdo->exec("CREATE TABLE IF NOT EXISTS kas_harian (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd              VARCHAR(150) NOT NULL DEFAULT '',
    user_id           INT UNSIGNED DEFAULT NULL,
    tanggal           DATE         NULL,
    hari              VARCHAR(12)  NOT NULL DEFAULT '',
    periode           VARCHAR(20)  NOT NULL DEFAULT '',
    posisi_h1         DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_penerimaan  DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_pengeluaran DECIMAL(18,2) NOT NULL DEFAULT 0,
    posisi_h          DECIMAL(18,2) NOT NULL DEFAULT 0,
    bank1_nama        VARCHAR(80)  NOT NULL DEFAULT 'Saldo di Bank 1',
    bank1_saldo       DECIMAL(18,2) NOT NULL DEFAULT 0,
    bank2_nama        VARCHAR(80)  NOT NULL DEFAULT 'Saldo di Bank 2',
    bank2_saldo       DECIMAL(18,2) NOT NULL DEFAULT 0,
    catatan           VARCHAR(255) NOT NULL DEFAULT '',
    created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_kh_skpd (skpd, tanggal),
    INDEX idx_kh_skpd (skpd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS kas_harian_item (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kas_harian_id INT UNSIGNED NOT NULL,
    skpd          VARCHAR(150) NOT NULL DEFAULT '',
    urut          INT NOT NULL DEFAULT 0,
    no_sp2d       VARCHAR(60)  NOT NULL DEFAULT '',
    no_sts        VARCHAR(60)  NOT NULL DEFAULT '',
    no_lain       VARCHAR(60)  NOT NULL DEFAULT '',
    uraian        VARCHAR(255) NOT NULL DEFAULT '',
    penerimaan    DECIMAL(18,2) NOT NULL DEFAULT 0,
    pengeluaran   DECIMAL(18,2) NOT NULL DEFAULT 0,
    INDEX idx_khi_head (kas_harian_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Rekonsiliasi Bank (BUD) - diisi MANUAL, satu dokumen per bulan per SKPD.
$pdo->exec("CREATE TABLE IF NOT EXISTS rekonsiliasi_bank (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd        VARCHAR(150) NOT NULL DEFAULT '',
    user_id     INT UNSIGNED DEFAULT NULL,
    tahun       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    bulan       TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    periode     VARCHAR(20)  NOT NULL DEFAULT '',
    tgl_ttd     DATE         NULL,
    saldo_buku  DECIMAL(18,2) NOT NULL DEFAULT 0,
    saldo_bank  DECIMAL(18,2) NOT NULL DEFAULT 0,
    catatan     VARCHAR(255) NOT NULL DEFAULT '',
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rb_skpd (skpd, tahun, bulan),
    INDEX idx_rb_skpd (skpd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS rekonsiliasi_bank_item (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rekonsiliasi_id  INT UNSIGNED NOT NULL,
    skpd             VARCHAR(150) NOT NULL DEFAULT '',
    bagian           ENUM('A','B','C','D') NOT NULL DEFAULT 'A',
    urut             INT NOT NULL DEFAULT 0,
    uraian           VARCHAR(255) NOT NULL DEFAULT '',
    nilai            DECIMAL(18,2) NOT NULL DEFAULT 0,
    INDEX idx_rbi_head (rekonsiliasi_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Buku Jurnal Khusus (blanko Modul-3E-LK) - satu dokumen per
// (SKPD, jenis jurnal, tahun, bulan); baris diisi manual.
$pdo->exec("CREATE TABLE IF NOT EXISTS jurnal_khusus (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd       VARCHAR(150) NOT NULL DEFAULT '',
    user_id    INT UNSIGNED DEFAULT NULL,
    jenis      VARCHAR(30)  NOT NULL DEFAULT '',
    tahun      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    bulan      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    kode_extra TEXT NULL,
    skp_label  VARCHAR(80) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_jk (skpd, jenis, tahun, bulan),
    INDEX idx_jk_skpd (skpd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Nilai disimpan per POSISI kolom (debet_1..debet_6 / kredit_1..kredit_6)
// agar jumlah kolom tiap jenis blanko berbeda tetap tertampung.
$pdo->exec("CREATE TABLE IF NOT EXISTS jurnal_khusus_baris (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    jurnal_id INT UNSIGNED NOT NULL,
    skpd      VARCHAR(150) NOT NULL DEFAULT '',
    urut      INT NOT NULL DEFAULT 0,
    tanggal   DATE NULL,
    nomor_dok VARCHAR(60)  NOT NULL DEFAULT '',
    uraian    VARCHAR(255) NOT NULL DEFAULT '',
    debet_1  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_2  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_3  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_4  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_5  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_6  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_7  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_8  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_9  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_10 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_1 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_2 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_3 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_4 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_5 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_6 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_7 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_8 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_9 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_10 DECIMAL(18,2) NOT NULL DEFAULT 0,
    INDEX idx_jkb_jurnal (jurnal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS jurnal_umum (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd         VARCHAR(150) NOT NULL DEFAULT '',
    user_id      INT UNSIGNED DEFAULT NULL,
    nomor_jurnal VARCHAR(60)  NOT NULL DEFAULT '',
    tanggal      DATE         NULL,
    uraian       VARCHAR(255) NOT NULL DEFAULT '',
    sumber_tipe  ENUM('manual','sts','sp2d') NOT NULL DEFAULT 'manual',
    sumber_id    INT UNSIGNED DEFAULT NULL,
    status       ENUM('draft','sudah_approve','ditolak') NOT NULL DEFAULT 'draft',
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ju_skpd (skpd),
    INDEX idx_ju_tanggal (tanggal),
    INDEX idx_ju_sumber (sumber_tipe, sumber_id),
    INDEX idx_ju_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");


$pdo->exec("CREATE TABLE IF NOT EXISTS jurnal_umum_detail (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    jurnal_id  INT UNSIGNED NOT NULL,
    skpd       VARCHAR(150) NOT NULL DEFAULT '',
    kode_akun  VARCHAR(50)  NOT NULL DEFAULT '',
    nama_akun  VARCHAR(200) NOT NULL DEFAULT '',
    posisi     ENUM('debet','kredit') NOT NULL,
    jumlah     DECIMAL(18,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_jud_jurnal (jurnal_id),
    INDEX idx_jud_akun (skpd, kode_akun),
    INDEX idx_jud_posisi (posisi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Penanda sudah_dijurnal pada dokumen sumber (anti dobel jurnal).
// Kompatibel MySQL & MariaDB: cek information_schema, lalu ALTER biasa.
function pastikan_kolom(PDO $pdo, string $tbl, string $col, string $ddl): void
{
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $q->execute([$tbl, $col]);
        if ((int) $q->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN {$ddl}");
        }
    } catch (Throwable $e) {
        error_log('[AKUNTANSI] pastikan_kolom ' . $tbl . '.' . $col . ': ' . $e->getMessage());
    }
}
pastikan_kolom($pdo, 'sts',  'sudah_dijurnal', "`sudah_dijurnal` TINYINT(1) NOT NULL DEFAULT 0");
pastikan_kolom($pdo, 'sp2d', 'sudah_dijurnal', "`sudah_dijurnal` TINYINT(1) NOT NULL DEFAULT 0");

// Jurnal Khusus: perluas slot kolom 7-10 (blanko Belanja/Beban Pemda punya 8 kolom debet).
foreach (['debet', 'kredit'] as $grupJK) {
    for ($slotJK = 7; $slotJK <= 10; $slotJK++) {
        pastikan_kolom($pdo, 'jurnal_khusus_baris', $grupJK . '_' . $slotJK,
            "`{$grupJK}_{$slotJK}` DECIMAL(18,2) NOT NULL DEFAULT 0");
    }
}

// Jurnal Khusus: label SKPKD (blanko Konsolidator) yang diisi manual.
pastikan_kolom($pdo, 'jurnal_khusus', 'skp_label', "`skp_label` VARCHAR(80) NOT NULL DEFAULT ''");

// ============================================================
// 0b. Helper bersama
// ============================================================

/** Seed default Chart of Accounts (BSA ringkas, Perwali 112/2018) bila kosong. */
function akun_seed_default(PDO $pdo, string $skpd): void
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM akun_master WHERE skpd = ?");
    $st->execute([$skpd]);
    if ((int) $st->fetchColumn() > 0) return;

    $defaults = [
        // kode, nama, tipe, saldo_normal
        ['1.1.1.01', 'Kas di Bendahara Penerimaan',       'aset',       'debet'],
        ['1.1.1.02', 'Kas di Bendahara Pengeluaran',      'aset',       'debet'],
        ['1.1.1.03', 'Uang Persediaan',                   'aset',       'debet'],
        ['1.1.2.01', 'Rekening Bank SKPD',                'aset',       'debet'],
        ['1.1.3.01', 'Kas di Bendahara Umum Daerah',      'aset',       'debet'],
        ['2.1.1.01', 'Utang Pajak',                       'liabilitas', 'kredit'],
        ['2.1.1.02', 'Utang Potongan',                    'liabilitas', 'kredit'],
        ['4.1.1.01', 'Pendapatan Pajak Daerah',           'pendapatan', 'kredit'],
        ['4.1.2.01', 'Pendapatan Retribusi Daerah',       'pendapatan', 'kredit'],
        ['4.1.4.01', 'Pendapatan Lain-lain PAD yang Sah', 'pendapatan', 'kredit'],
        ['5.1.1.01', 'Belanja Gaji dan Tunjangan',        'belanja',    'debet'],
        ['5.1.2.01', 'Belanja Barang dan Jasa',           'belanja',    'debet'],
        ['5.1.3.01', 'Belanja Modal',                     'belanja',    'debet'],
    ];
    $ins = $pdo->prepare("INSERT IGNORE INTO akun_master (skpd, kode_akun, nama_akun, tipe, saldo_normal) VALUES (?, ?, ?, ?, ?)");
    foreach ($defaults as $d) {
        $ins->execute([$skpd, $d[0], $d[1], $d[2], $d[3]]);
    }
}

/** Tipe & saldo normal menurut digit pertama kode akun (fallback COA). */
function akun_tipe_dari_kode(string $kode): array
{
    $map = [
        '1' => ['aset', 'debet'],
        '2' => ['liabilitas', 'kredit'],
        '3' => ['ekuitas', 'kredit'],
        '4' => ['pendapatan', 'kredit'],
        '5' => ['belanja', 'debet'],
        '6' => ['belanja', 'debet'],
        '7' => ['ekuitas', 'kredit'],
    ];
    return $map[substr($kode, 0, 1)] ?? ['aset', 'debet'];
}

/**
 * Chart of Accounts (COA) LRA standar dari config/coa_lra.json.
 * Berkas ini menjadi SATU sumber kode+nama akun untuk Master Akun & Anggaran.
 * Gagal membaca -> array kosong (aman, tidak mematikan endpoint).
 */
function coa_lra(): array
{
    static $coa = null;
    if (is_array($coa)) return $coa;
    $coa = [];
    try {
        $berkas = __DIR__ . '/../config/coa_lra.json';
        if (!is_readable($berkas)) return $coa;
        $data = json_decode((string) file_get_contents($berkas), true);
        if (!is_array($data) || !isset($data['akun']) || !is_array($data['akun'])) return $coa;
        foreach ($data['akun'] as $a) {
            $kode = trim((string) ($a['kode_akun'] ?? ''));
            $nama = trim((string) ($a['nama_akun'] ?? ''));
            if ($kode === '' || $nama === '') continue;
            [$tipeKode, $normalKode] = akun_tipe_dari_kode($kode);
            $tipe   = trim((string) ($a['tipe'] ?? ''));
            $normal = trim((string) ($a['saldo_normal'] ?? ''));
            $coa[$kode] = [
                'kode_akun'    => $kode,
                'nama_akun'    => $nama,
                'tipe'         => $tipe !== '' ? $tipe : $tipeKode,
                'saldo_normal' => $normal !== '' ? $normal : $normalKode,
                'level'        => (int) ($a['level'] ?? (substr_count($kode, '.') + 1)),
            ];
        }
    } catch (Throwable $e) {
        error_log('[AKUNTANSI] coa_lra: ' . $e->getMessage());
    }
    return $coa;
}

/**
 * Pastikan seluruh COA LRA standar tersedia di akun_master SKPD ini.
 * Idempoten (INSERT IGNORE) dan cepat: hanya menulis bila masih ada kode
 * yang belum ada. Akun yang sudah ada TIDAK diubah namanya (aman untuk
 * penyesuaian manual pengguna). Mengembalikan jumlah akun yang ditambahkan.
 */
function akun_seed_coa(PDO $pdo, string $skpd): int
{
    $coa = coa_lra();
    if (!$coa) return 0;
    $kodes = array_keys($coa);

    $tanda = implode(',', array_fill(0, count($kodes), '?'));
    $cek = $pdo->prepare("SELECT COUNT(DISTINCT kode_akun) FROM akun_master WHERE skpd = ? AND kode_akun IN ({$tanda})");
    $cek->execute(array_merge([$skpd], $kodes));
    if ((int) $cek->fetchColumn() >= count($kodes)) return 0;

    $ditambah = 0;
    foreach (array_chunk($kodes, 60) as $potong) {
        $baris = [];
        $nilai = [];
        foreach ($potong as $kode) {
            $a = $coa[$kode];
            $baris[] = '(?, ?, ?, ?, ?)';
            $nilai[] = $skpd;
            $nilai[] = $a['kode_akun'];
            $nilai[] = $a['nama_akun'];
            $nilai[] = $a['tipe'];
            $nilai[] = $a['saldo_normal'];
        }
        $ins = $pdo->prepare("INSERT IGNORE INTO akun_master (skpd, kode_akun, nama_akun, tipe, saldo_normal) VALUES " . implode(',', $baris));
        $ins->execute($nilai);
        $ditambah += $ins->rowCount();
    }
    return $ditambah;
}

/** Nama akun dari kode: akun_master (skpd) -> akun_penerimaan -> ''. */
function akun_nama_of(PDO $pdo, string $skpd, string $kode): string
{
    if ($kode === '') return '';
    try {
        $q = $pdo->prepare("SELECT nama_akun FROM akun_master WHERE skpd = ? AND kode_akun = ? LIMIT 1");
        $q->execute([$skpd, $kode]);
        $n = (string) ($q->fetchColumn() ?: '');
        if ($n !== '') return $n;
    } catch (Throwable $e) {}
    try {
        $q = $pdo->prepare("SELECT nama_akun FROM akun_penerimaan WHERE kode_akun = ? ORDER BY id DESC LIMIT 1");
        $q->execute([$kode]);
        return (string) ($q->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    return '';
}

function tanggal_valid(string $t): bool
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) !== 1) return false;
    [$th, $bl, $hr] = array_map('intval', explode('-', $t));
    return checkdate($bl, $hr, $th);   // tolak tanggal mustahil (mis. 2026-13-99)
}

/**
 * NIP pengguna dari profil (Pengaturan > Profil) untuk blok tanda tangan.
 * Kolom `nip` dibuat otomatis bila belum ada (idempoten, tanpa SQL manual).
 * Kegagalan apa pun dikembalikan sebagai string kosong (aman untuk cetak).
 */
function ambilNipUser(PDO $pdo, int $userId): string
{
    static $kolomAda = null;
    if ($userId <= 0) {
        return '';
    }
    try {
        if ($kolomAda === null) {
            $kolomAda = (bool) $pdo->query("SHOW COLUMNS FROM users LIKE 'nip'")->fetch();
            if (!$kolomAda) {
                $pdo->exec("ALTER TABLE users ADD COLUMN nip VARCHAR(30) DEFAULT NULL AFTER nama_lengkap");
                $kolomAda = true;
            }
        }
        $st = $pdo->prepare("SELECT nip FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        return trim((string) ($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}


// ============================================================
// 1. Profil akun (untuk topbar halaman AKLAP)
// ============================================================
if ($action === 'profil') {
    $ttdGambar = '';
    try {
        $stT = $pdo->prepare("SELECT gambar FROM user_ttd WHERE user_id = ? LIMIT 1");
        $stT->execute([(int) ($user['id'] ?? 0)]);
        $ttdGambar = (string) ($stT->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    echo json_encode(['success' => true, 'user' => [
        'nama'       => (string) ($user['nama_lengkap'] ?? ''),
        'username'   => (string) ($user['username'] ?? ''),
        'instansi'   => $skpdUser,
        'kota'       => (string) ($user['kota'] ?? ''),
        'provinsi'   => (string) ($user['provinsi'] ?? ''),
        'peran'      => (string) ($user['peran'] ?? ''),
        'nip'        => ambilNipUser($pdo, (int) ($user['id'] ?? 0)),
        'ttd_gambar' => $ttdGambar,
    ]], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 2. Master Akun
// ============================================================
if ($action === 'akun_list') {
    akun_seed_default($pdo, $skpdUser);
    akun_seed_coa($pdo, $skpdUser);   // lengkapi COA LRA standar (idempoten)
    $q = trim((string) ($_GET['q'] ?? ''));
    $sql  = "SELECT id, kode_akun, nama_akun, tipe, saldo_normal, aktif FROM akun_master WHERE skpd = ?";
    $params = [$skpdUser];
    if ($q !== '') {
        $sql .= " AND (kode_akun LIKE ? OR nama_akun LIKE ?)";
        $params[] = "%{$q}%"; $params[] = "%{$q}%";
    }
    $sql .= " ORDER BY kode_akun ASC LIMIT 500";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'id'           => (int) $r['id'],
            'kode_akun'    => (string) $r['kode_akun'],
            'nama_akun'    => (string) $r['nama_akun'],
            'tipe'         => (string) $r['tipe'],
            'saldo_normal' => (string) $r['saldo_normal'],
            'aktif'        => (int) $r['aktif'],
        ];
    }
    echo json_encode(['success' => true, 'data' => $rows, 'total' => count($rows)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'akun_simpan' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $id     = (int) ($_POST['id'] ?? 0);
    $kode   = trim((string) ($_POST['kode_akun'] ?? ''));
    $nama   = trim((string) ($_POST['nama_akun'] ?? ''));
    $tipe   = (string) ($_POST['tipe'] ?? 'aset');
    $normal = (string) ($_POST['saldo_normal'] ?? 'debet');
    if ($kode === '' || $nama === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Kode akun dan nama akun wajib diisi.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!in_array($tipe, ['aset','liabilitas','ekuitas','pendapatan','belanja'], true)) $tipe = 'aset';
    if (!in_array($normal, ['debet','kredit'], true)) $normal = 'debet';

    if ($id > 0) {
        $st = $pdo->prepare("UPDATE akun_master SET kode_akun = ?, nama_akun = ?, tipe = ?, saldo_normal = ? WHERE id = ? AND skpd = ?");
        $st->execute([$kode, $nama, $tipe, $normal, $id, $skpdUser]);
        echo json_encode(['success' => true, 'message' => 'Akun diperbarui.', 'id' => $id], JSON_UNESCAPED_UNICODE);
    } else {
        try {
            $st = $pdo->prepare("INSERT INTO akun_master (skpd, kode_akun, nama_akun, tipe, saldo_normal) VALUES (?, ?, ?, ?, ?)");
            $st->execute([$skpdUser, $kode, $nama, $tipe, $normal]);
            echo json_encode(['success' => true, 'message' => 'Akun tersimpan.', 'id' => (int) $pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Kode akun sudah dipakai untuk instansi ini.'], JSON_UNESCAPED_UNICODE);
        }
    }
    exit;
}

if ($action === 'akun_hapus' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'ID akun tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $st = $pdo->prepare("DELETE FROM akun_master WHERE id = ? AND skpd = ?");
    $st->execute([$id, $skpdUser]);
    echo json_encode([
        'success' => true,
        'message' => $st->rowCount() > 0 ? 'Akun dihapus.' : 'Akun tidak ditemukan.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


// ============================================================
// 2b. Anggaran (Pagu) - BERBASIS MASTER AKUN
//     Kode & nama akun bersumber dari akun_master (COA LRA di
//     config/coa_lra.json). Penyimpanan pagu tetap di tabel
//     anggaran_lra supaya laporan lama (LRA, rekap dashboard AKLAP,
//     Buku Pembantu Penerimaan) tetap kompatibel tanpa perubahan.
// ============================================================
if ($action === 'anggaran_list') {
    $tahun = trim((string) ($_GET['tahun'] ?? date('Y')));
    if (preg_match('/^\d{4}$/', $tahun) !== 1) $tahun = date('Y');
    $tipe = (string) ($_GET['tipe'] ?? 'lra');
    $q    = trim((string) ($_GET['q'] ?? ''));

    akun_seed_default($pdo, $skpdUser);
    akun_seed_coa($pdo, $skpdUser);

    // Tahun yang sudah punya data (untuk pemilih tahun di halaman)
    $stTh = $pdo->prepare("SELECT DISTINCT tahun FROM anggaran_lra WHERE skpd = ? ORDER BY tahun DESC");
    $stTh->execute([$skpdUser]);
    $daftarTahun = [];
    foreach ($stTh->fetchAll(PDO::FETCH_COLUMN) as $t) { $daftarTahun[] = (string) $t; }

    // 1) Akun dari Master Akun + pagu tahun terpilih
    $sql = "SELECT m.kode_akun, m.nama_akun, m.tipe, m.saldo_normal,
                   a.id AS id_anggaran, a.nama_akun AS nama_anggaran, COALESCE(a.anggaran, 0) AS pagu
            FROM akun_master m
            LEFT JOIN anggaran_lra a
                   ON a.skpd = m.skpd AND a.kode_akun = m.kode_akun AND a.tahun = ?
            WHERE m.skpd = ?";
    $params = [$tahun, $skpdUser];
    if (in_array($tipe, ['pendapatan', 'belanja', 'aset', 'liabilitas', 'ekuitas'], true)) {
        $sql .= " AND m.tipe = ?";
        $params[] = $tipe;
    } elseif ($tipe === 'lra') {
        $sql .= " AND m.tipe IN ('pendapatan','belanja')";
    }
    if ($q !== '') {
        $sql .= " AND (m.kode_akun LIKE ? OR m.nama_akun LIKE ?)";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
    }
    $sql .= " ORDER BY m.kode_akun ASC LIMIT 400";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $rows = [];
    $terisi = 0;
    foreach ($st->fetchAll() as $r) {
        $adaBaris = $r['id_anggaran'] !== null;
        if ($adaBaris) $terisi++;
        $rows[] = [
            'kode_akun' => (string) $r['kode_akun'],
            'nama_akun' => (string) $r['nama_akun'],
            'nama_anggaran' => (string) ($r['nama_anggaran'] ?? ''),
            'tipe'      => (string) $r['tipe'],
            'saldo_normal' => (string) $r['saldo_normal'],
            'level'     => substr_count((string) $r['kode_akun'], '.') + 1,
            'pagu'      => (float) $r['pagu'],
            'id_anggaran' => $adaBaris ? (int) $r['id_anggaran'] : 0,
            'sumber'    => 'master',
        ];
    }

    // 2) Baris anggaran lama yang kodenya BELUM ada di Master Akun:
    //    tetap ditampilkan agar tidak "hilang", dengan opsi sinkronisasi.
    $sqlL = "SELECT a.kode_akun, a.nama_akun, a.anggaran, a.id
             FROM anggaran_lra a
             WHERE a.skpd = ? AND a.tahun = ?
               AND NOT EXISTS (SELECT 1 FROM akun_master m WHERE m.skpd = a.skpd AND m.kode_akun = a.kode_akun)";
    $paramsL = [$skpdUser, $tahun];
    if ($q !== '') {
        $sqlL .= " AND (a.kode_akun LIKE ? OR a.nama_akun LIKE ?)";
        $paramsL[] = "%{$q}%";
        $paramsL[] = "%{$q}%";
    }
    $sqlL .= " ORDER BY a.kode_akun ASC LIMIT 200";
    $stL = $pdo->prepare($sqlL);
    $stL->execute($paramsL);
    $luarMaster = [];
    $totalLuar = 0.0;
    foreach ($stL->fetchAll() as $r) {
        $pagu = (float) $r['anggaran'];
        [$tipeKode] = akun_tipe_dari_kode((string) $r['kode_akun']);
        $totalLuar += $pagu;
        $luarMaster[] = [
            'kode_akun' => (string) $r['kode_akun'],
            'nama_akun' => (string) $r['nama_akun'],
            'tipe'      => $tipeKode,
            'level'     => substr_count((string) $r['kode_akun'], '.') + 1,
            'pagu'      => $pagu,
            'id_anggaran' => (int) $r['id'],
            'sumber'    => 'luar_master',
        ];
    }

    // 3) Total pagu LRA (tanpa dipengaruhi filter pencarian/tipe)
    $stT = $pdo->prepare("SELECT m.tipe, COALESCE(SUM(a.anggaran), 0) AS total
                          FROM akun_master m
                          LEFT JOIN anggaran_lra a
                                 ON a.skpd = m.skpd AND a.kode_akun = m.kode_akun AND a.tahun = ?
                          WHERE m.skpd = ? AND m.tipe IN ('pendapatan','belanja')
                          GROUP BY m.tipe");
    $stT->execute([$tahun, $skpdUser]);
    $tPendapatan = 0.0;
    $tBelanja = 0.0;
    foreach ($stT->fetchAll() as $r) {
        if ((string) $r['tipe'] === 'pendapatan') $tPendapatan = (float) $r['total'];
        else $tBelanja = (float) $r['total'];
    }
    foreach ($luarMaster as $lm) {
        if ($lm['tipe'] === 'pendapatan') $tPendapatan += $lm['pagu'];
        else $tBelanja += $lm['pagu'];
    }

    echo json_encode([
        'success'          => true,
        'tahun'            => $tahun,
        'tipe'             => $tipe,
        'daftar_tahun'     => $daftarTahun ? $daftarTahun : [date('Y')],
        'data'             => $rows,
        'luar_master'      => $luarMaster,
        'total'            => count($rows) + count($luarMaster),
        'jumlah_terisi'    => $terisi,
        'coa_jumlah'       => count(coa_lra()),
        'total_pendapatan' => round($tPendapatan, 2),
        'total_belanja'    => round($tBelanja, 2),
        'total_luar_master' => round($totalLuar, 2),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'anggaran_simpan' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $tahun = trim((string) ($_POST['tahun'] ?? ''));
    if (preg_match('/^\d{4}$/', $tahun) !== 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Tahun anggaran tidak valid (format YYYY).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Terima batch: rows=[{kode_akun, anggaran}] (JSON) atau satu baris.
    $rows = [];
    if (isset($_POST['rows'])) {
        $dekode = json_decode((string) $_POST['rows'], true);
        if (is_array($dekode)) $rows = $dekode;
    }
    if (!$rows && isset($_POST['kode_akun'])) {
        $rows = [['kode_akun' => (string) $_POST['kode_akun'], 'anggaran' => $_POST['anggaran'] ?? 0]];
    }
    if (!$rows) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Tidak ada baris anggaran yang dikirim.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cek = $pdo->prepare("SELECT kode_akun, nama_akun FROM akun_master WHERE skpd = ?");
    $cek->execute([$skpdUser]);
    $namaMaster = [];
    foreach ($cek->fetchAll() as $r) { $namaMaster[(string) $r['kode_akun']] = (string) $r['nama_akun']; }

    $bersih = [];
    $invalid = [];
    foreach ($rows as $r) {
        $kode = trim((string) ($r['kode_akun'] ?? ''));
        if ($kode === '') continue;
        $pagu = $r['anggaran'] ?? 0;
        if (is_string($pagu)) $pagu = str_replace([' ', '.'], ['', ''], $pagu);
        if (is_string($pagu)) $pagu = str_replace(',', '.', $pagu);
        if (!is_numeric($pagu)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Anggaran akun ' . $kode . ' harus berupa angka.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ((float) $pagu < 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Anggaran akun ' . $kode . ' tidak boleh negatif.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!isset($namaMaster[$kode])) { $invalid[] = $kode; continue; }
        $bersih[$kode] = (float) $pagu;
    }
    if ($invalid) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Akun berikut belum ada di Master Akun: ' . implode(', ', array_slice($invalid, 0, 8))
                       . (count($invalid) > 8 ? ' …' : '') . '. Tambahkan dulu di halaman Master Akun.',
            'invalid' => $invalid,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$bersih) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Tidak ada akun valid untuk disimpan.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Upsert portabel per akun+tahun (tanpa DELETE semua baris tahun tsb).
    $cari = $pdo->prepare("SELECT id FROM anggaran_lra WHERE skpd = ? AND tahun = ? AND kode_akun = ? LIMIT 1");
    $up   = $pdo->prepare("UPDATE anggaran_lra SET nama_akun = ?, anggaran = ? WHERE id = ? AND skpd = ?");
    $ins  = $pdo->prepare("INSERT INTO anggaran_lra (skpd, tahun, kode_akun, nama_akun, anggaran) VALUES (?, ?, ?, ?, ?)");

    $simpan = 0;
    $pdo->beginTransaction();
    try {
        foreach ($bersih as $kode => $pagu) {
            $nama = $namaMaster[$kode];
            $cari->execute([$skpdUser, $tahun, $kode]);
            $id = $cari->fetchColumn();
            if ($id) $up->execute([$nama, $pagu, (int) $id, $skpdUser]);
            else $ins->execute([$skpdUser, $tahun, $kode, $nama, $pagu]);
            $simpan++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    echo json_encode([
        'success'   => true,
        'message'   => 'Anggaran tahun ' . $tahun . ' tersimpan (' . $simpan . ' akun).',
        'tersimpan' => $simpan,
        'tahun'     => $tahun,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'anggaran_hapus' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $tahun = trim((string) ($_POST['tahun'] ?? ''));
    $kode  = trim((string) ($_POST['kode_akun'] ?? ''));
    if (preg_match('/^\d{4}$/', $tahun) !== 1 || $kode === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Tahun / kode akun tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $st = $pdo->prepare("DELETE FROM anggaran_lra WHERE skpd = ? AND tahun = ? AND kode_akun = ?");
    $st->execute([$skpdUser, $tahun, $kode]);
    echo json_encode([
        'success' => true,
        'message' => $st->rowCount() > 0 ? 'Pagu akun ' . $kode . ' dihapus (menjadi 0).' : 'Belum ada pagu untuk akun tersebut.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Ambil kode akun dari data anggaran lama yang belum ada di Master Akun
// (mis. hasil impor lama), agar seluruh pagu bisa diisi lewat halaman
// Anggaran berbasis Master Akun. Nama akun diambil dari data anggaran.
if ($action === 'anggaran_sinkron_master' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $st = $pdo->prepare("SELECT DISTINCT a.kode_akun, MAX(a.nama_akun) AS nama_akun
                         FROM anggaran_lra a
                         WHERE a.skpd = ? AND a.kode_akun <> ''
                           AND NOT EXISTS (SELECT 1 FROM akun_master m WHERE m.skpd = a.skpd AND m.kode_akun = a.kode_akun)
                         GROUP BY a.kode_akun
                         ORDER BY a.kode_akun ASC");
    $st->execute([$skpdUser]);

    $ins = $pdo->prepare("INSERT IGNORE INTO akun_master (skpd, kode_akun, nama_akun, tipe, saldo_normal) VALUES (?, ?, ?, ?, ?)");
    $ditambah = [];
    foreach ($st->fetchAll() as $r) {
        $kode = (string) $r['kode_akun'];
        $nama = trim((string) $r['nama_akun']);
        if ($nama === '') $nama = $kode;
        [$tipe, $normal] = akun_tipe_dari_kode($kode);
        $ins->execute([$skpdUser, $kode, $nama, $tipe, $normal]);
        if ($ins->rowCount() > 0) $ditambah[] = $kode;
    }

    echo json_encode([
        'success'     => true,
        'message'     => $ditambah
            ? count($ditambah) . ' akun dari data anggaran ditambahkan ke Master Akun.'
            : 'Semua kode anggaran sudah ada di Master Akun.',
        'ditambahkan' => count($ditambah),
        'kode'        => array_slice($ditambah, 0, 20),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Seed ulang COA LRA standar ke Master Akun (mis. setelah akun terhapus).
if ($action === 'akun_seed_coa' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $n = akun_seed_coa($pdo, $skpdUser);
    echo json_encode([
        'success'     => true,
        'message'     => $n > 0 ? $n . ' akun COA LRA standar ditambahkan ke Master Akun.' : 'Seluruh COA LRA standar sudah tersedia.',
        'ditambahkan' => $n,
        'coa_jumlah'  => count(coa_lra()),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 3. Jurnal Umum - daftar (header + baris detail D/K)
// ============================================================
if ($action === 'jurnal_list') {
    $dari  = tanggal_valid((string) ($_GET['dari'] ?? ''))  ? (string) $_GET['dari']  : '';
    $akhir = tanggal_valid((string) ($_GET['akhir'] ?? '')) ? (string) $_GET['akhir'] : '';
    $q     = trim((string) ($_GET['q'] ?? ''));
    $st    = (string) ($_GET['status'] ?? '');

    $sql = "SELECT * FROM jurnal_umum WHERE skpd = ?";
    $params = [$skpdUser];
    if ($dari !== '')  { $sql .= " AND tanggal >= ?"; $params[] = $dari; }
    if ($akhir !== '') { $sql .= " AND tanggal <= ?"; $params[] = $akhir; }
    if ($st !== '' && in_array($st, ['draft','sudah_approve','ditolak'], true)) { $sql .= " AND status = ?"; $params[] = $st; }
    if ($q !== '') {
        $sql .= " AND (nomor_jurnal LIKE ? OR uraian LIKE ?)";
        $params[] = "%{$q}%"; $params[] = "%{$q}%";
    }
    $sql .= " ORDER BY tanggal ASC, id ASC LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $det = $pdo->prepare("SELECT id, kode_akun, nama_akun, posisi, jumlah FROM jurnal_umum_detail WHERE jurnal_id = ? ORDER BY posisi DESC, id ASC");
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $det->execute([(int) $r['id']]);
        $baris = [];
        $totalD = 0.0; $totalK = 0.0;
        foreach ($det->fetchAll() as $d) {
            $baris[] = [
                'kode_akun' => (string) $d['kode_akun'],
                'nama_akun' => (string) $d['nama_akun'],
                'posisi'    => (string) $d['posisi'],
                'jumlah'    => (float) $d['jumlah'],
            ];
            if ($d['posisi'] === 'debet') $totalD += (float) $d['jumlah']; else $totalK += (float) $d['jumlah'];
        }
        $rows[] = [
            'id'           => (int) $r['id'],
            'nomor_jurnal' => (string) $r['nomor_jurnal'],
            'tanggal'      => (string) ($r['tanggal'] ?? ''),
            'uraian'       => (string) $r['uraian'],
            'sumber_tipe'  => (string) $r['sumber_tipe'],
            'sumber_id'    => (int) ($r['sumber_id'] ?? 0),
            'status'       => (string) $r['status'],
            'total_debet'  => round($totalD, 2),
            'total_kredit' => round($totalK, 2),
            'detail'       => $baris,
        ];
    }
    echo json_encode(['success' => true, 'data' => $rows, 'total' => count($rows)], JSON_UNESCAPED_UNICODE);
    exit;
}


// ============================================================
// 4. Jurnal Umum - simpan (validasi kaidah D = K)
// ============================================================
if ($action === 'jurnal_simpan' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $id         = (int) ($_POST['id'] ?? 0);
    $tanggal    = trim((string) ($_POST['tanggal'] ?? ''));
    $uraian     = trim((string) ($_POST['uraian'] ?? ''));
    $sumberTipe = (string) ($_POST['sumber_tipe'] ?? 'manual');
    $sumberId   = (int) ($_POST['sumber_id'] ?? 0);
    $rawDetail  = (string) ($_POST['detail'] ?? '[]');

    if (!in_array($sumberTipe, ['manual','sts','sp2d'], true)) $sumberTipe = 'manual';
    if (!tanggal_valid($tanggal)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Tanggal jurnal wajib diisi (format YYYY-MM-DD).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $detail = json_decode($rawDetail, true);
    if (!is_array($detail) || count($detail) < 2) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Jurnal umum minimal memiliki 2 baris (Debet dan Kredit).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Normalisasi baris jurnal
    $baris = [];
    $totalD = 0.0; $totalK = 0.0;
    foreach ($detail as $d) {
        if (!is_array($d)) continue;
        $kode   = trim((string) ($d['kode_akun'] ?? ''));
        $posisi = strtolower(trim((string) ($d['posisi'] ?? '')));
        $jumlah = (float) ($d['jumlah'] ?? 0);
        if ($kode === '' || $jumlah <= 0) continue;
        if (!in_array($posisi, ['debet','kredit'], true)) continue;
        $baris[] = [
            'kode_akun' => $kode,
            'nama_akun' => akun_nama_of($pdo, $skpdUser, $kode),
            'posisi'    => $posisi,
            'jumlah'    => round($jumlah, 2),
        ];
        if ($posisi === 'debet') $totalD += $jumlah; else $totalK += $jumlah;
    }
    if (count($baris) < 2) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Setiap baris wajib memiliki kode akun, posisi D/K, dan jumlah > 0.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (abs($totalD - $totalK) > 0.01) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Jurnal TIDAK seimbang: Total Debet Rp ' . number_format($totalD, 2, ',', '.') . ' != Total Kredit Rp ' . number_format($totalK, 2, ',', '.') . '.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Verifikasi dokumen sumber (STS / SP2D) bila memakai template
    if ($sumberTipe !== 'manual' && $sumberId > 0) {
        $tabel = ($sumberTipe === 'sts') ? 'sts' : 'sp2d';
        $kondisi = ($sumberTipe === 'sts') ? "status = 'aktif'" : "status = 'sudah_dicairkan'";
        $st = $pdo->prepare("SELECT id FROM {$tabel} WHERE id = ? AND {$kondisi} AND COALESCE(sudah_dijurnal, 0) = 0 AND skpd = ?");
        $st->execute([$sumberId, $skpdUser]);
        if (!$st->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Dokumen sumber tidak tersedia / sudah pernah dibuatkan jurnal.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        $sumberTipe = 'manual'; $sumberId = 0;
    }

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            // Edit: pastikan milik SKPD, bebaskan penanda lama bila sumber berubah
            $old = $pdo->prepare("SELECT id, sumber_tipe, sumber_id FROM jurnal_umum WHERE id = ? AND skpd = ?");
            $old->execute([$id, $skpdUser]);
            $oldRow = $old->fetch();
            if (!$oldRow) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Jurnal tidak ditemukan.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $pdo->prepare("DELETE FROM jurnal_umum_detail WHERE jurnal_id = ?")->execute([$id]);
            $pdo->prepare("UPDATE jurnal_umum SET tanggal = ?, uraian = ?, sumber_tipe = ?, sumber_id = ? WHERE id = ?")
                ->execute([$tanggal, $uraian, $sumberTipe, $sumberId ?: null, $id]);
            if (!empty($oldRow['sumber_tipe']) && $oldRow['sumber_tipe'] !== 'manual'
                && (int) $oldRow['sumber_id'] !== $sumberId) {
                $pdo->prepare("UPDATE {$oldRow['sumber_tipe']} SET sudah_dijurnal = 0 WHERE id = ?")
                    ->execute([(int) $oldRow['sumber_id']]);
            }
            $jurnalId = $id;
            $pesan = 'Jurnal diperbarui.';
        } else {
            $st = $pdo->prepare("INSERT INTO jurnal_umum (skpd, user_id, tanggal, uraian, sumber_tipe, sumber_id) VALUES (?, ?, ?, ?, ?, ?)");
            $st->execute([$skpdUser, (int) ($user['id'] ?? 0), $tanggal, $uraian, $sumberTipe, $sumberId ?: null]);
            $jurnalId = (int) $pdo->lastInsertId();
            $nomor = 'JU-' . date('Ym') . '-' . str_pad((string) $jurnalId, 4, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE jurnal_umum SET nomor_jurnal = ? WHERE id = ?")->execute([$nomor, $jurnalId]);
            $pesan = 'Jurnal ' . $nomor . ' tersimpan.';
        }

        $insD = $pdo->prepare("INSERT INTO jurnal_umum_detail (jurnal_id, skpd, kode_akun, nama_akun, posisi, jumlah) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($baris as $b) {
            $insD->execute([$jurnalId, $skpdUser, $b['kode_akun'], $b['nama_akun'], $b['posisi'], $b['jumlah']]);
        }

        if ($sumberTipe !== 'manual' && $sumberId > 0) {
            $pdo->prepare("UPDATE {$sumberTipe} SET sudah_dijurnal = 1 WHERE id = ?")->execute([$sumberId]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => $pesan, 'id' => $jurnalId], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    exit;
}


// ============================================================
// 5. Jurnal Umum - hapus (bebaskan penanda dokumen sumber)
// ============================================================
if ($action === 'jurnal_hapus' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'ID jurnal tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $st = $pdo->prepare("SELECT sumber_tipe, sumber_id FROM jurnal_umum WHERE id = ? AND skpd = ?");
    $st->execute([$id, $skpdUser]);
    $row = $st->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Jurnal tidak ditemukan.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM jurnal_umum_detail WHERE jurnal_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM jurnal_umum WHERE id = ?")->execute([$id]);
        if (!empty($row['sumber_tipe']) && $row['sumber_tipe'] !== 'manual' && (int) $row['sumber_id'] > 0) {
            $pdo->prepare("UPDATE {$row['sumber_tipe']} SET sudah_dijurnal = 0 WHERE id = ?")->execute([(int) $row['sumber_id']]);
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Jurnal dihapus.'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    exit;
}

// ============================================================
// 6. Jurnal Umum - approve / reject (pembukuan: hanya yang
//    sudah_approve masuk Buku Besar, Neraca Saldo, LRA, Neraca)
// ============================================================
if ($action === 'jurnal_status' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $id     = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    if ($id <= 0 || !in_array($status, ['sudah_approve','ditolak','draft'], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Parameter status tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $st = $pdo->prepare("UPDATE jurnal_umum SET status = ? WHERE id = ? AND skpd = ?");
    $st->execute([$status, $id, $skpdUser]);
    $label = ['sudah_approve' => 'di-approve', 'ditolak' => 'ditolak', 'draft' => 'dikembalikan ke draft'][$status];
    echo json_encode([
        'success' => true,
        'message' => $st->rowCount() > 0 ? ('Jurnal ' . $label . '.') : 'Jurnal tidak ditemukan.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


// ============================================================
// 7. Saran Dokumen - daftar STS & SP2D yang siap dibuatkan jurnal
//    (status valid + belum pernah dijurnal)
// ============================================================
if ($action === 'saran_dokumen') {
    $sts = [];
    $sp2d = [];
    try {
        $st = $pdo->prepare("SELECT id, nomor_sts, tanggal_sts, total FROM sts
                             WHERE skpd = ? AND status = 'aktif' AND COALESCE(sudah_dijurnal, 0) = 0
                             ORDER BY tanggal_sts DESC, id DESC LIMIT 100");
        $st->execute([$skpdUser]);
        foreach ($st->fetchAll() as $r) {
            $sts[] = [
                'id'      => (int) $r['id'],
                'nomor'   => (string) $r['nomor_sts'],
                'tanggal' => (string) $r['tanggal_sts'],
                'jumlah'  => (float) $r['total'],
            ];
        }
    } catch (Throwable $e) {}

    try {
        $st = $pdo->prepare("SELECT sp2d.id, sp2d.nomor_sp2d, sp2d.tanggal, sp2d.jumlah, spp.jenis_spp
                             FROM sp2d
                             LEFT JOIN spm ON spm.id = sp2d.spm_id
                             LEFT JOIN spp ON spp.id = spm.spp_id
                             WHERE sp2d.skpd = ? AND sp2d.status = 'sudah_dicairkan' AND COALESCE(sp2d.sudah_dijurnal, 0) = 0
                             ORDER BY sp2d.tanggal DESC, sp2d.id DESC LIMIT 100");
        $st->execute([$skpdUser]);
        foreach ($st->fetchAll() as $r) {
            $sp2d[] = [
                'id'        => (int) $r['id'],
                'nomor'     => (string) $r['nomor_sp2d'],
                'tanggal'   => (string) ($r['tanggal'] ?? ''),
                'jumlah'    => (float) $r['jumlah'],
                'jenis_spp' => (string) ($r['jenis_spp'] ?? ''),
            ];
        }
    } catch (Throwable $e) {}

    echo json_encode(['success' => true, 'sts' => $sts, 'sp2d' => $sp2d], JSON_UNESCAPED_UNICODE);
    exit;
}


// ============================================================
// 8. Saran Jurnal - template baris Debet/Kredit dari dokumen
//    STS (Penerimaan) atau SP2D (Belanja), dapat diedit manual
//    sebelum disimpan di jurnal_simpan.
// ============================================================
if ($action === 'saran_jurnal') {
    $sumber = strtolower((string) ($_GET['sumber'] ?? ''));
    $id     = (int) ($_GET['id'] ?? 0);
    if ($id <= 0 || !in_array($sumber, ['sts','sp2d'], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Sumber dan ID dokumen wajib dipilih.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Kode akun bawaan (chart of accounts ringkas)
    $KAS_PENERIMAAN  = '1.1.1.01'; // Kas di Bendahara Penerimaan
    $KAS_PENGELUARAN = '1.1.1.02'; // Kas di Bendahara Pengeluaran
    $KAS_BUD         = '1.1.3.01'; // Kas di Bendahara Umum Daerah
    $BLJ_GAJI        = '5.1.1.01'; // Belanja Gaji dan Tunjangan
    $BLJ_BJ          = '5.1.2.01'; // Belanja Barang dan Jasa
    $BLJ_MODAL       = '5.1.3.01'; // Belanja Modal
    $UTANG_PAJAK     = '2.1.1.01'; // Utang Pajak
    $UTANG_POTONGAN  = '2.1.1.02'; // Utang Potongan

    $rows = [];      // ['kode_akun','posisi','jumlah','saran_nama']
    $tanggal = ''; $uraian = ''; $nomor = '';


    if ($sumber === 'sts') {
        // ---------- STS (Penerimaan) ----------
        // D Kas di Bendahara Penerimaan (total)
        // K Pendapatan per kode rekening pada sts_detail
        $st = $pdo->prepare("SELECT * FROM sts WHERE id = ? AND skpd = ? AND status = 'aktif' LIMIT 1");
        $st->execute([$id, $skpdUser]);
        $sts = $st->fetch();
        if (!$sts) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'STS tidak ditemukan / tidak aktif.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $total   = (float) $sts['total'];
        $tanggal = (string) $sts['tanggal_sts'];
        $nomor   = (string) $sts['nomor_sts'];
        $uraian  = 'Penerimaan disetor ke Kas Daerah - STS No. ' . $nomor;
        $rows[] = ['kode_akun' => $KAS_PENERIMAAN, 'posisi' => 'debet', 'jumlah' => $total, 'saran_nama' => akun_nama_of($pdo, $skpdUser, $KAS_PENERIMAAN)];

        $det = $pdo->prepare("SELECT akun_kode, akun_nama, jumlah FROM sts_detail WHERE sts_id = ? AND jumlah > 0 ORDER BY id ASC");
        $det->execute([$id]);
        $sumK = 0.0;
        foreach ($det->fetchAll() as $d) {
            $kode = trim((string) $d['akun_kode']);
            if ($kode === '') $kode = '4.1.4.01';
            $rows[] = ['kode_akun' => $kode, 'posisi' => 'kredit', 'jumlah' => (float) $d['jumlah'], 'saran_nama' => ((string) $d['akun_nama'] !== '' ? (string) $d['akun_nama'] : akun_nama_of($pdo, $skpdUser, $kode))];
            $sumK += (float) $d['jumlah'];
        }
        if (count($rows) === 1) {
            // Tidak ada detail: kredit pendapatan lain-lain senilai total
            $rows[] = ['kode_akun' => '4.1.4.01', 'posisi' => 'kredit', 'jumlah' => $total, 'saran_nama' => akun_nama_of($pdo, $skpdUser, '4.1.4.01')];
        } elseif (abs($sumK - $total) > 0.01) {
            $rows[] = ['kode_akun' => '4.1.4.01', 'posisi' => 'kredit', 'jumlah' => round($total - $sumK, 2), 'saran_nama' => akun_nama_of($pdo, $skpdUser, '4.1.4.01')];
        }
    } else {
        // ---------- SP2D (Belanja) ----------
        // UP/GU/TU : D Kas di Bendahara Pengeluaran, K Kas di BUD
        // LS       : D Belanja (bruto) + K Utang Pajak/Potongan, K Kas di BUD (neto)
        $st = $pdo->prepare("SELECT sp2d.*, spp.jenis_spp, spp.id AS spp_id
                             FROM sp2d
                             LEFT JOIN spm ON spm.id = sp2d.spm_id
                             LEFT JOIN spp ON spp.id = spm.spp_id
                             WHERE sp2d.id = ? AND sp2d.skpd = ? AND sp2d.status = 'sudah_dicairkan' LIMIT 1");
        $st->execute([$id, $skpdUser]);
        $sp2d = $st->fetch();
        if (!$sp2d) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'SP2D tidak ditemukan / belum dicairkan.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $jumlah  = (float) $sp2d['jumlah'];
        $jenis   = (string) ($sp2d['jenis_spp'] ?? '');
        $tanggal = (string) ($sp2d['tanggal'] ?? '');
        $nomor   = (string) $sp2d['nomor_sp2d'];

        $j = strtolower($jenis);
        if (strpos($j, 'ls') !== false && strpos($j, 'gaji') !== false) {
            $kodeBlj = $BLJ_GAJI;
        } elseif (strpos($j, 'modal') !== false) {
            $kodeBlj = $BLJ_MODAL;
        } else {
            $kodeBlj = $BLJ_BJ;
        }
        $isLS = strpos($j, 'ls') !== false;
        $uraian = 'Pencairan ' . ($jenis !== '' ? $jenis : 'Belanja') . ' - SP2D No. ' . $nomor;


        if ($isLS) {
            // D Belanja (bruto)
            $rows[] = ['kode_akun' => $kodeBlj, 'posisi' => 'debet', 'jumlah' => $jumlah, 'saran_nama' => akun_nama_of($pdo, $skpdUser, $kodeBlj)];
            // K Utang Pajak / Utang Potongan (dari spp_potongan_pajak)
            $neto = $jumlah;
            if (!empty($sp2d['spp_id'])) {
                $pot = $pdo->prepare("SELECT jenis, nama, nilai FROM spp_potongan_pajak WHERE spp_id = ? AND nilai > 0 ORDER BY id ASC");
                $pot->execute([(int) $sp2d['spp_id']]);
                foreach ($pot->fetchAll() as $p) {
                    $kode = ($p['jenis'] === 'pajak') ? $UTANG_PAJAK : $UTANG_POTONGAN;
                    $rows[] = ['kode_akun' => $kode, 'posisi' => 'kredit', 'jumlah' => (float) $p['nilai'], 'saran_nama' => akun_nama_of($pdo, $skpdUser, $kode)];
                    $neto -= (float) $p['nilai'];
                }
            }
            // K Kas di BUD (neto)
            $rows[] = ['kode_akun' => $KAS_BUD, 'posisi' => 'kredit', 'jumlah' => round($neto, 2), 'saran_nama' => akun_nama_of($pdo, $skpdUser, $KAS_BUD)];
        } else {
            // UP / GU / TU: kas masuk ke Bendahara Pengeluaran
            $rows[] = ['kode_akun' => $KAS_PENGELUARAN, 'posisi' => 'debet', 'jumlah' => $jumlah, 'saran_nama' => akun_nama_of($pdo, $skpdUser, $KAS_PENGELUARAN)];
            $rows[] = ['kode_akun' => $KAS_BUD, 'posisi' => 'kredit', 'jumlah' => $jumlah, 'saran_nama' => akun_nama_of($pdo, $skpdUser, $KAS_BUD)];
        }
    }

    echo json_encode([
        'success'   => true,
        'sumber'    => $sumber,
        'sumber_id' => $id,
        'nomor'     => $nomor,
        'tanggal'   => $tanggal,
        'uraian'    => $uraian,
        'detail'    => $rows,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


// ============================================================
// 9. Buku Besar - posting per akun (hanya jurnal sudah_approve)
//    Kolom mengikuti lembar kerja Modul 3: Tanggal, Keterangan,
//    Ref, Debet, Kredit, Saldo.
// ============================================================
if ($action === 'buku_besar') {
    $kode = trim((string) ($_GET['kode_akun'] ?? ''));
    $dari = tanggal_valid((string) ($_GET['dari'] ?? ''))  ? (string) $_GET['dari']  : '';
    $akhir = tanggal_valid((string) ($_GET['akhir'] ?? '')) ? (string) $_GET['akhir'] : '';
    if ($kode === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Pilih kode akun terlebih dahulu.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    akun_seed_default($pdo, $skpdUser);

    $nama = akun_nama_of($pdo, $skpdUser, $kode);
    $normal = 'debet';
    try {
        $q = $pdo->prepare("SELECT saldo_normal FROM akun_master WHERE skpd = ? AND kode_akun = ? LIMIT 1");
        $q->execute([$skpdUser, $kode]);
        $n = (string) ($q->fetchColumn() ?: '');
        if ($n !== '') $normal = $n;
    } catch (Throwable $e) {}

    // Saldo awal dari neraca_awal (bila tabel ada) - tahun diambil dari periode
    $saldoAwal = 0.0;
    try {
        $tahun = ($dari !== '') ? (int) substr($dari, 0, 4) : (int) date('Y');
        $q = $pdo->prepare("SELECT COALESCE(SUM(saldo), 0) FROM neraca_awal WHERE skpd = ? AND kode_akun = ? AND tahun = ?");
        $q->execute([$skpdUser, $kode, $tahun]);
        $saldoAwal = (float) $q->fetchColumn();
    } catch (Throwable $e) {}

    $sql = "SELECT j.nomor_jurnal, j.tanggal, j.uraian, j.sumber_tipe, d.posisi, d.jumlah
            FROM jurnal_umum_detail d
            INNER JOIN jurnal_umum j ON j.id = d.jurnal_id
            WHERE d.skpd = ? AND d.kode_akun = ? AND j.status = 'sudah_approve'";
    $params = [$skpdUser, $kode];
    if ($dari !== '')  { $sql .= " AND j.tanggal >= ?"; $params[] = $dari; }
    if ($akhir !== '') { $sql .= " AND j.tanggal <= ?"; $params[] = $akhir; }
    $sql .= " ORDER BY j.tanggal ASC, j.id ASC, d.id ASC LIMIT 1000";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $rows = []; $saldo = $saldoAwal; $totD = 0.0; $totK = 0.0;
    foreach ($st->fetchAll() as $r) {
        $d = ($r['posisi'] === 'debet') ? (float) $r['jumlah'] : 0.0;
        $k = ($r['posisi'] === 'kredit') ? (float) $r['jumlah'] : 0.0;
        $totD += $d; $totK += $k;
        $saldo += ($normal === 'debet') ? ($d - $k) : ($k - $d);
        $rows[] = [
            'nomor_jurnal' => (string) $r['nomor_jurnal'],
            'tanggal'      => (string) ($r['tanggal'] ?? ''),
            'uraian'       => (string) $r['uraian'],
            'ref'          => strtoupper((string) $r['sumber_tipe']),
            'debet'        => $d,
            'kredit'       => $k,
            'saldo'        => round($saldo, 2),
            'saldo_posisi' => $normal,
        ];
    }
    echo json_encode([
        'success'      => true,
        'kode_akun'    => $kode,
        'nama_akun'    => $nama,
        'saldo_normal' => $normal,
        'saldo_awal'   => $saldoAwal,
        'total_debet'  => round($totD, 2),
        'total_kredit' => round($totK, 2),
        'saldo_akhir'  => round($saldo, 2),
        'data'         => $rows,
        'total'        => count($rows),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


// ============================================================
// 10. Neraca Saldo - mutasi per akun (hanya jurnal sudah_approve)
// ============================================================
if ($action === 'neraca_saldo') {
    akun_seed_default($pdo, $skpdUser);
    $dari  = tanggal_valid((string) ($_GET['dari'] ?? ''))  ? (string) $_GET['dari']  : '';
    $akhir = tanggal_valid((string) ($_GET['akhir'] ?? '')) ? (string) $_GET['akhir'] : '';

    // Mutasi dari jurnal approved
    $sql = "SELECT d.kode_akun,
                   SUM(CASE WHEN d.posisi = 'debet'  THEN d.jumlah ELSE 0 END) AS mutasi_debet,
                   SUM(CASE WHEN d.posisi = 'kredit' THEN d.jumlah ELSE 0 END) AS mutasi_kredit
            FROM jurnal_umum_detail d
            INNER JOIN jurnal_umum j ON j.id = d.jurnal_id
            WHERE d.skpd = ? AND j.status = 'sudah_approve'";
    $params = [$skpdUser];
    if ($dari !== '')  { $sql .= " AND j.tanggal >= ?"; $params[] = $dari; }
    if ($akhir !== '') { $sql .= " AND j.tanggal <= ?"; $params[] = $akhir; }
    $sql .= " GROUP BY d.kode_akun";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $mutasi = [];
    foreach ($st->fetchAll() as $r) {
        $mutasi[(string) $r['kode_akun']] = [
            'debet'  => (float) $r['mutasi_debet'],
            'kredit' => (float) $r['mutasi_kredit'],
        ];
    }


    // Saldo awal dari neraca_awal (bila tabel ada)
    $awal = [];
    try {
        $tahun = ($dari !== '') ? (int) substr($dari, 0, 4) : (int) date('Y');
        $q = $pdo->prepare("SELECT kode_akun, COALESCE(SUM(saldo), 0) AS saldo FROM neraca_awal WHERE skpd = ? AND tahun = ? GROUP BY kode_akun");
        $q->execute([$skpdUser, $tahun]);
        foreach ($q->fetchAll() as $r) {
            $awal[(string) $r['kode_akun']] = (float) $r['saldo'];
        }
    } catch (Throwable $e) {}

    $rows = [];
    $st = $pdo->prepare("SELECT kode_akun, nama_akun, tipe, saldo_normal FROM akun_master WHERE skpd = ? AND aktif = 1 ORDER BY kode_akun ASC");
    $st->execute([$skpdUser]);
    foreach ($st->fetchAll() as $a) {
        $kode = (string) $a['kode_akun'];
        $m = $mutasi[$kode] ?? ['debet' => 0.0, 'kredit' => 0.0];
        $sa = $awal[$kode] ?? 0.0;
        $akhirSaldo = ($a['saldo_normal'] === 'debet') ? ($sa + $m['debet'] - $m['kredit']) : ($sa + $m['kredit'] - $m['debet']);
        $rows[] = [
            'kode_akun'     => $kode,
            'nama_akun'     => (string) $a['nama_akun'],
            'tipe'          => (string) $a['tipe'],
            'saldo_normal'  => (string) $a['saldo_normal'],
            'saldo_awal'    => $sa,
            'mutasi_debet'  => round($m['debet'], 2),
            'mutasi_kredit' => round($m['kredit'], 2),
            'saldo_akhir'   => round($akhirSaldo, 2),
        ];
    }

    // Akun ber-mutasi tapi belum terdaftar di akun_master (mis. pendapatan dari akun_penerimaan)
    foreach ($mutasi as $kode => $m) {
        $exists = false;
        foreach ($rows as $r) { if ($r['kode_akun'] === $kode) { $exists = true; break; } }
        if (!$exists) {
            $rows[] = [
                'kode_akun'     => $kode,
                'nama_akun'     => akun_nama_of($pdo, $skpdUser, $kode),
                'tipe'          => 'pendapatan',
                'saldo_normal'  => ($m['kredit'] >= $m['debet'] ? 'kredit' : 'debet'),
                'saldo_awal'    => 0.0,
                'mutasi_debet'  => round($m['debet'], 2),
                'mutasi_kredit' => round($m['kredit'], 2),
                'saldo_akhir'   => round(abs($m['debet'] - $m['kredit']), 2),
            ];
        }
    }
    usort($rows, function ($x, $z) { return strcmp((string) $x['kode_akun'], (string) $z['kode_akun']); });

    echo json_encode(['success' => true, 'data' => $rows, 'total' => count($rows)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 11. Buku Kas Umum (Penerimaan & Pengeluaran) - CRUD manual
// ============================================================
if ($action === 'buku_kas_list') {
    $dari  = tanggal_valid((string) ($_GET['dari'] ?? ''))  ? (string) $_GET['dari']  : '';
    $akhir = tanggal_valid((string) ($_GET['akhir'] ?? '')) ? (string) $_GET['akhir'] : '';
    $jenis = (string) ($_GET['jenis'] ?? '');

    $sql  = "SELECT id, tanggal, jenis, no_bukti, kode_rekening, uraian, jumlah
             FROM buku_kas WHERE skpd = ?";
    $params = [$skpdUser];
    if ($dari !== '')  { $sql .= " AND tanggal >= ?"; $params[] = $dari; }
    if ($akhir !== '') { $sql .= " AND tanggal <= ?"; $params[] = $akhir; }
    if (in_array($jenis, ['penerimaan', 'pengeluaran'], true)) { $sql .= " AND jenis = ?"; $params[] = $jenis; }
    $sql .= " ORDER BY tanggal ASC, id ASC";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $rows = [];
    $saldo = 0.0;
    $tp = 0.0; $tk = 0.0;
    foreach ($st->fetchAll() as $r) {
        $jml    = (float) $r['jumlah'];
        $jenisR = (string) $r['jenis'];
        $penerimaan = $jenisR === 'penerimaan' ? $jml : 0.0;
        $pengeluaran = $jenisR === 'pengeluaran' ? $jml : 0.0;
        $saldo += $penerimaan - $pengeluaran;
        if ($jenisR === 'penerimaan') $tp += $jml; else $tk += $jml;

        $rows[] = [
            'id'            => (int) $r['id'],
            'tanggal'       => (string) $r['tanggal'],
            'jenis'         => $jenisR,
            'no_bukti'      => (string) $r['no_bukti'],
            'kode_rekening' => (string) $r['kode_rekening'],
            'uraian'        => (string) $r['uraian'],
            'jumlah'        => $jml,
            'penerimaan'    => $penerimaan,
            'pengeluaran'   => $pengeluaran,
            'saldo'         => round($saldo, 2),
        ];
    }

    echo json_encode([
        'success'           => true,
        'data'              => $rows,
        'total'             => count($rows),
        'saldo_akhir'       => round($saldo, 2),
        'total_penerimaan'  => round($tp, 2),
        'total_pengeluaran' => round($tk, 2),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'buku_kas_simpan' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $id     = (int) ($_POST['id'] ?? 0);
    $tanggal = trim((string) ($_POST['tanggal'] ?? ''));
    $jenis  = (string) ($_POST['jenis'] ?? '');
    $noBukti = trim((string) ($_POST['no_bukti'] ?? ''));
    $kode   = trim((string) ($_POST['kode_rekening'] ?? ''));
    $uraian = trim((string) ($_POST['uraian'] ?? ''));
    $jumlah = (float) ($_POST['jumlah'] ?? 0);

    if (!tanggal_valid($tanggal)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Tanggal wajib diisi dengan benar.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!in_array($jenis, ['penerimaan', 'pengeluaran'], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Jenis transaksi wajib dipilih.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($uraian === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Uraian wajib diisi.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($jumlah <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Jumlah harus lebih besar dari nol.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($id > 0) {
        $st = $pdo->prepare("UPDATE buku_kas SET tanggal = ?, jenis = ?, no_bukti = ?, kode_rekening = ?, uraian = ?, jumlah = ? WHERE id = ? AND skpd = ?");
        $st->execute([$tanggal, $jenis, $noBukti, $kode, $uraian, $jumlah, $id, $skpdUser]);
        echo json_encode(['success' => true, 'message' => 'Transaksi diperbarui.', 'id' => $id], JSON_UNESCAPED_UNICODE);
    } else {
        $st = $pdo->prepare("INSERT INTO buku_kas (skpd, user_id, tanggal, jenis, no_bukti, kode_rekening, uraian, jumlah) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([$skpdUser, (int) ($user['id'] ?? 0), $tanggal, $jenis, $noBukti, $kode, $uraian, $jumlah]);
        echo json_encode(['success' => true, 'message' => 'Transaksi tersimpan.', 'id' => (int) $pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'buku_kas_hapus' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'ID transaksi tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $st = $pdo->prepare("DELETE FROM buku_kas WHERE id = ? AND skpd = ?");
    $st->execute([$id, $skpdUser]);
    echo json_encode([
        'success' => true,
        'message' => $st->rowCount() > 0 ? 'Transaksi dihapus.' : 'Transaksi tidak ditemukan.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 11a2. Buku Kas Umum Penerimaan & Pengeluaran - Bendahara Umum
//       Daerah (BUD). Gabungan buku kas penerimaan + pengeluaran
//       dalam satu daftar kronologis dengan SALDO BERJALAN, dan
//       baris "Saldo" (saldo awal) di baris pertama seperti blanko.
// ============================================================
if ($action === 'buku_kas_umum') {
    $dari  = tanggal_valid((string) ($_GET['dari'] ?? ''))  ? (string) $_GET['dari']  : '';
    $akhir = tanggal_valid((string) ($_GET['akhir'] ?? '')) ? (string) $_GET['akhir'] : '';

    // Saldo awal = posisi kas SEBELUM tanggal awal periode (bila periode dipilih).
    $saldoAwal = 0.0;
    if ($dari !== '') {
        $stA = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN jenis = 'penerimaan' THEN jumlah ELSE -jumlah END), 0)
                              FROM buku_kas WHERE skpd = ? AND tanggal < ?");
        $stA->execute([$skpdUser, $dari]);
        $saldoAwal = (float) $stA->fetchColumn();
    }
    // Bisa ditimpa manual (mis. saldo awal kas dari Neraca) lewat ?saldo_awal=
    $manual = str_replace([' ', '.'], ['', ''], trim((string) ($_GET['saldo_awal'] ?? '')));
    $manual = str_replace(',', '.', $manual);
    if ($manual !== '' && is_numeric($manual)) $saldoAwal = (float) $manual;

    $sql = "SELECT id, tanggal, jenis, no_bukti, kode_rekening, uraian, jumlah
            FROM buku_kas WHERE skpd = ?";
    $params = [$skpdUser];
    if ($dari !== '')  { $sql .= " AND tanggal >= ?"; $params[] = $dari; }
    if ($akhir !== '') { $sql .= " AND tanggal <= ?"; $params[] = $akhir; }
    $sql .= " ORDER BY tanggal ASC, id ASC LIMIT 1000";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $rows = [];
    $saldo = $saldoAwal;
    $tp = 0.0;
    $tk = 0.0;
    foreach ($st->fetchAll() as $r) {
        $jml = (float) $r['jumlah'];
        $penerimaan = (string) $r['jenis'] === 'penerimaan' ? $jml : 0.0;
        $pengeluaran = (string) $r['jenis'] === 'pengeluaran' ? $jml : 0.0;
        $saldo += $penerimaan - $pengeluaran;
        $tp += $penerimaan;
        $tk += $pengeluaran;

        $rows[] = [
            'id'            => (int) $r['id'],
            'tanggal'       => (string) $r['tanggal'],
            'jenis'         => (string) $r['jenis'],
            'no_bukti'      => (string) $r['no_bukti'],
            'kode_rekening' => (string) $r['kode_rekening'],
            'uraian'        => (string) $r['uraian'],
            'penerimaan'    => $penerimaan,
            'pengeluaran'   => $pengeluaran,
            'saldo'         => round($saldo, 2),
        ];
    }

    echo json_encode([
        'success'           => true,
        'dari'              => $dari,
        'akhir'             => $akhir,
        'saldo_awal'        => round($saldoAwal, 2),
        'data'              => $rows,
        'total'             => count($rows),
        'total_penerimaan'  => round($tp, 2),
        'total_pengeluaran' => round($tk, 2),
        'saldo_akhir'       => round($saldo, 2),
        'instansi'          => (string) ($user['instansi'] ?? ''),
        'kota'              => (string) ($user['kota'] ?? ''),
        'bendahara'         => (string) ($user['nama_lengkap'] ?? ''),
        'nip'               => ambilNipUser($pdo, (int) ($user['id'] ?? 0)),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 11a3. Laporan Posisi Kas Harian - Bendahara Umum Daerah (BUD)
//       Diisi MANUAL oleh pengguna (BUKAN turunan buku kas):
//       nomor SP2D / STS / Lain-lain, Uraian, Penerimaan,
//       Pengeluaran. Satu dokumen per tanggal per SKPD.
//       Posisi Kas (H-1) manual, disarankan otomatis dari
//       laporan terdekat sebelumnya agar saldo bersambung.
// ============================================================

/** Nama hari Bahasa Indonesia (kepala laporan kas harian). */
function kh_hari_id(string $tanggal): string
{
    $nama = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $ts = strtotime($tanggal);
    return $ts === false ? '' : $nama[(int) date('w', $ts)];
}

/** Nama bulan Bahasa Indonesia (periode laporan kas harian). */
function kh_periode_id(string $tanggal): string
{
    $nama = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
             'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $ts = strtotime($tanggal);
    return $ts === false ? '' : $nama[(int) date('n', $ts)];
}

/** Angka dari isian manual (menerima "1.234.500" maupun "1234500"). */
function kh_angka($teks): float
{
    $s = str_replace([' ', '.'], '', trim((string) $teks));
    if ($s === '' || $s === '-') return 0.0;
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float) $s : 0.0;
}

/** Batasi panjang teks isian manual agar aman terhadap kolom DB. */
function kh_potong($teks, int $maks): string
{
    $t = trim((string) $teks);
    return mb_strlen($t) > $maks ? mb_substr($t, 0, $maks) : $t;
}

/**
 * Bangun dokumen Laporan Posisi Kas Harian lengkap untuk satu tanggal.
 * Dipakai endpoint GET kas_harian dan sebagai respons kas_harian_simpan.
 */
function kh_dokumen(PDO $pdo, string $skpd, string $tanggal): array
{
    $st = $pdo->prepare("SELECT * FROM kas_harian WHERE skpd = ? AND tanggal = ? LIMIT 1");
    $st->execute([$skpd, $tanggal]);
    $head = $st->fetch() ?: null;

    $items  = [];
    $totalP = 0.0;
    $totalK = 0.0;
    if ($head) {
        $si = $pdo->prepare("SELECT no_sp2d, no_sts, no_lain, uraian, penerimaan, pengeluaran
                             FROM kas_harian_item WHERE kas_harian_id = ? ORDER BY urut ASC, id ASC LIMIT 300");
        $si->execute([(int) $head['id']]);
        foreach ($si->fetchAll() as $r) {
            $p = (float) $r['penerimaan'];
            $k = (float) $r['pengeluaran'];
            $totalP += $p;
            $totalK += $k;
            $items[] = [
                'no_sp2d'     => (string) $r['no_sp2d'],
                'no_sts'      => (string) $r['no_sts'],
                'no_lain'     => (string) $r['no_lain'],
                'uraian'      => (string) $r['uraian'],
                'penerimaan'  => round($p, 2),
                'pengeluaran' => round($k, 2),
            ];
        }
    }

    // Posisi Kas (H-1): nilai tersimpan bila laporan hari ini sudah ada;
    // bila belum ada, diambil dari Posisi Kas (H) laporan terdekat
    // sebelumnya sebagai SARAN (tetap bisa ditimpa manual oleh pengguna).
    $dari = '';
    if ($head) {
        $posisiH1 = (float) $head['posisi_h1'];
        $sumber   = 'tersimpan';
    } else {
        $sp = $pdo->prepare("SELECT tanggal, posisi_h FROM kas_harian
                             WHERE skpd = ? AND tanggal < ? ORDER BY tanggal DESC LIMIT 1");
        $sp->execute([$skpd, $tanggal]);
        $prev = $sp->fetch();
        if ($prev) {
            $posisiH1 = (float) $prev['posisi_h'];
            $sumber   = 'hari_sebelumnya';
            $dari     = (string) $prev['tanggal'];
        } else {
            $posisiH1 = 0.0;
            $sumber   = 'nol';
        }
    }

    $perubahan = $totalP - $totalK;
    $b1 = $head ? (float) $head['bank1_saldo'] : 0.0;
    $b2 = $head ? (float) $head['bank2_saldo'] : 0.0;

    return [
        'tanggal'           => $tanggal,
        'hari'              => $head ? (string) $head['hari'] : kh_hari_id($tanggal),
        'periode'           => $head ? (string) $head['periode'] : kh_periode_id($tanggal),
        'posisi_h1'         => round($posisiH1, 2),
        'sumber_posisi_h1'  => $sumber,
        'posisi_h1_dari'    => $dari,
        'items'             => $items,
        'total_penerimaan'  => round($totalP, 2),
        'total_pengeluaran' => round($totalK, 2),
        'perubahan'         => round($perubahan, 2),
        'posisi_h'          => round($posisiH1 + $perubahan, 2),
        'bank1_nama'        => (string) ($head['bank1_nama'] ?? 'Saldo di Bank 1'),
        'bank1_saldo'       => round($b1, 2),
        'bank2_nama'        => (string) ($head['bank2_nama'] ?? 'Saldo di Bank 2'),
        'bank2_saldo'       => round($b2, 2),
        'total_saldo'       => round($b1 + $b2, 2),
        'catatan'           => (string) ($head['catatan'] ?? ''),
        'tersimpan'         => $head !== null,
        'updated_at'        => (string) ($head['updated_at'] ?? ''),
    ];
}

if ($action === 'kas_harian') {
    $tanggal = trim((string) ($_GET['tanggal'] ?? ''));
    if ($tanggal === '') $tanggal = date('Y-m-d');
    if (!tanggal_valid($tanggal)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Tanggal laporan tidak valid (format YYYY-MM-DD).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dok = kh_dokumen($pdo, $skpdUser, $tanggal);
    echo json_encode(array_merge(['success' => true], $dok, [
        'instansi'  => $skpdUser,
        'kota'      => (string) ($user['kota'] ?? ''),
        'bendahara' => (string) ($user['nama_lengkap'] ?? ''),
        'nip'       => ambilNipUser($pdo, (int) ($user['id'] ?? 0)),
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'kas_harian_daftar') {
    $dari  = tanggal_valid((string) ($_GET['dari'] ?? ''))  ? (string) $_GET['dari']  : '';
    $akhir = tanggal_valid((string) ($_GET['akhir'] ?? '')) ? (string) $_GET['akhir'] : '';

    $sql = "SELECT k.id, k.tanggal, k.hari, k.periode, k.posisi_h1, k.posisi_h,
                   k.total_penerimaan, k.total_pengeluaran, k.updated_at,
                   (SELECT COUNT(*) FROM kas_harian_item i WHERE i.kas_harian_id = k.id) AS baris
            FROM kas_harian k WHERE k.skpd = ?";
    $params = [$skpdUser];
    if ($dari !== '')  { $sql .= " AND k.tanggal >= ?"; $params[] = $dari; }
    if ($akhir !== '') { $sql .= " AND k.tanggal <= ?"; $params[] = $akhir; }
    $sql .= " ORDER BY k.tanggal DESC LIMIT 400";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'id'                => (int) $r['id'],
            'tanggal'           => (string) $r['tanggal'],
            'hari'              => (string) $r['hari'],
            'periode'           => (string) $r['periode'],
            'baris'             => (int) $r['baris'],
            'posisi_h1'         => round((float) $r['posisi_h1'], 2),
            'posisi_h'          => round((float) $r['posisi_h'], 2),
            'total_penerimaan'  => round((float) $r['total_penerimaan'], 2),
            'total_pengeluaran' => round((float) $r['total_pengeluaran'], 2),
            'updated_at'        => (string) $r['updated_at'],
        ];
    }

    echo json_encode(['success' => true, 'data' => $rows, 'total' => count($rows)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'kas_harian_simpan' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $tanggal = trim((string) ($_POST['tanggal'] ?? ''));
    if (!tanggal_valid($tanggal)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Tanggal laporan wajib diisi dengan benar (YYYY-MM-DD).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $hari = kh_potong((string) ($_POST['hari'] ?? ''), 12);
    if ($hari === '') $hari = kh_hari_id($tanggal);
    $periode = kh_potong((string) ($_POST['periode'] ?? ''), 20);
    if ($periode === '') $periode = kh_periode_id($tanggal);

    $posisiH1   = kh_angka($_POST['posisi_h1'] ?? 0);
    $bank1Nama  = kh_potong((string) ($_POST['bank1_nama'] ?? ''), 80);
    $bank2Nama  = kh_potong((string) ($_POST['bank2_nama'] ?? ''), 80);
    if ($bank1Nama === '') $bank1Nama = 'Saldo di Bank 1';
    if ($bank2Nama === '') $bank2Nama = 'Saldo di Bank 2';
    $bank1Saldo = kh_angka($_POST['bank1_saldo'] ?? 0);
    $bank2Saldo = kh_angka($_POST['bank2_saldo'] ?? 0);
    $catatan    = kh_potong((string) ($_POST['catatan'] ?? ''), 255);

    $rawItems = json_decode((string) ($_POST['items'] ?? '[]'), true);
    if (!is_array($rawItems)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Data baris transaksi tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (count($rawItems) > 300) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Jumlah baris melebihi batas 300 baris.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $items  = [];
    $totalP = 0.0;
    $totalK = 0.0;
    foreach ($rawItems as $i => $r) {
        if (!is_array($r)) continue;
        $sp2d   = kh_potong((string) ($r['no_sp2d'] ?? ''), 60);
        $sts    = kh_potong((string) ($r['no_sts'] ?? ''), 60);
        $lain   = kh_potong((string) ($r['no_lain'] ?? ''), 60);
        $uraian = kh_potong((string) ($r['uraian'] ?? ''), 255);
        $p = kh_angka($r['penerimaan'] ?? 0);
        $k = kh_angka($r['pengeluaran'] ?? 0);
        if ($p < 0 || $k < 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Nilai penerimaan/pengeluaran tidak boleh negatif (baris ' . ($i + 1) . ').'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Baris yang benar-benar kosong tidak perlu disimpan.
        if ($sp2d === '' && $sts === '' && $lain === '' && $uraian === '' && $p === 0.0 && $k === 0.0) continue;
        $totalP += $p;
        $totalK += $k;
        $items[] = [
            'no_sp2d'     => $sp2d,
            'no_sts'      => $sts,
            'no_lain'     => $lain,
            'uraian'      => $uraian,
            'penerimaan'  => $p,
            'pengeluaran' => $k,
        ];
    }

    // Total & posisi kas dihitung SERVER (otoritatif), bukan dari kiriman klien.
    $posisiH = $posisiH1 + $totalP - $totalK;
    $userId  = (int) ($user['id'] ?? 0);

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id FROM kas_harian WHERE skpd = ? AND tanggal = ? LIMIT 1");
        $st->execute([$skpdUser, $tanggal]);
        $headId = (int) ($st->fetchColumn() ?: 0);

        if ($headId > 0) {
            $up = $pdo->prepare("UPDATE kas_harian
                 SET hari = ?, periode = ?, posisi_h1 = ?, total_penerimaan = ?, total_pengeluaran = ?,
                     posisi_h = ?, bank1_nama = ?, bank1_saldo = ?, bank2_nama = ?, bank2_saldo = ?,
                     catatan = ?, user_id = ?
                 WHERE id = ? AND skpd = ?");
            $up->execute([
                $hari, $periode, $posisiH1, round($totalP, 2), round($totalK, 2), round($posisiH, 2),
                $bank1Nama, $bank1Saldo, $bank2Nama, $bank2Saldo, $catatan, $userId, $headId, $skpdUser,
            ]);
            $del = $pdo->prepare("DELETE FROM kas_harian_item WHERE kas_harian_id = ? AND skpd = ?");
            $del->execute([$headId, $skpdUser]);
        } else {
            $in = $pdo->prepare("INSERT INTO kas_harian
                (skpd, user_id, tanggal, hari, periode, posisi_h1, total_penerimaan, total_pengeluaran, posisi_h,
                 bank1_nama, bank1_saldo, bank2_nama, bank2_saldo, catatan)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $in->execute([
                $skpdUser, $userId, $tanggal, $hari, $periode, $posisiH1, round($totalP, 2), round($totalK, 2),
                round($posisiH, 2), $bank1Nama, $bank1Saldo, $bank2Nama, $bank2Saldo, $catatan,
            ]);
            $headId = (int) $pdo->lastInsertId();
        }

        if ($items) {
            $ins = $pdo->prepare("INSERT INTO kas_harian_item
                (kas_harian_id, skpd, urut, no_sp2d, no_sts, no_lain, uraian, penerimaan, pengeluaran)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $urut = 0;
            foreach ($items as $it) {
                $urut++;
                $ins->execute([$headId, $skpdUser, $urut, $it['no_sp2d'], $it['no_sts'], $it['no_lain'],
                               $it['uraian'], $it['penerimaan'], $it['pengeluaran']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    echo json_encode(array_merge([
        'success'    => true,
        'message'    => 'Laporan Posisi Kas Harian tersimpan (' . count($items) . ' baris).',
        'laporan_id' => $headId,
    ], kh_dokumen($pdo, $skpdUser, $tanggal), [
        'instansi'  => $skpdUser,
        'kota'      => (string) ($user['kota'] ?? ''),
        'bendahara' => (string) ($user['nama_lengkap'] ?? ''),
        'nip'       => ambilNipUser($pdo, (int) ($user['id'] ?? 0)),
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'kas_harian_hapus' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $tanggal = trim((string) ($_POST['tanggal'] ?? ''));
    if (!tanggal_valid($tanggal)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Tanggal laporan tidak valid (format YYYY-MM-DD).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $st = $pdo->prepare("SELECT id FROM kas_harian WHERE skpd = ? AND tanggal = ? LIMIT 1");
    $st->execute([$skpdUser, $tanggal]);
    $headId = (int) ($st->fetchColumn() ?: 0);
    if ($headId <= 0) {
        echo json_encode(['success' => true, 'message' => 'Laporan tanggal tersebut belum ada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $d1 = $pdo->prepare("DELETE FROM kas_harian_item WHERE kas_harian_id = ? AND skpd = ?");
        $d1->execute([$headId, $skpdUser]);
        $d2 = $pdo->prepare("DELETE FROM kas_harian WHERE id = ? AND skpd = ?");
        $d2->execute([$headId, $skpdUser]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    echo json_encode(['success' => true, 'message' => 'Laporan tanggal ' . $tanggal . ' dihapus.'], JSON_UNESCAPED_UNICODE);
    exit;
}
// ============================================================
// 11b. Register SPP/SPM/SP2D - Bendahara Pengeluaran PPKD
//      Turunan dari Buku Pengeluaran PPKD (satu baris per transaksi).
//      Kolom Jenis Belanja / Tanggal / Uraian / Jumlah diambil dari
//      buku_kas; pengguna (mahasiswa) hanya mengisi Tanggal & Nomor
//      SPP, SPM, dan SP2D (+ Keterangan).
// ============================================================
if ($action === 'register_ppkd_list') {
    $dari  = tanggal_valid((string) ($_GET['dari'] ?? ''))  ? (string) $_GET['dari']  : '';
    $akhir = tanggal_valid((string) ($_GET['akhir'] ?? '')) ? (string) $_GET['akhir'] : '';

    $sql = "SELECT bk.id, bk.tanggal, bk.no_bukti, bk.kode_rekening, bk.uraian, bk.jumlah,
                   r.spp_tgl, r.spp_no, r.spm_tgl, r.spm_no, r.sp2d_tgl, r.sp2d_no, r.ket
            FROM buku_kas bk
            LEFT JOIN register_ppkd r ON r.buku_kas_id = bk.id AND r.skpd = bk.skpd
            WHERE bk.skpd = ? AND bk.jenis = 'pengeluaran'";
    $params = [$skpdUser];
    if ($dari !== '')  { $sql .= " AND bk.tanggal >= ?"; $params[] = $dari; }
    if ($akhir !== '') { $sql .= " AND bk.tanggal <= ?"; $params[] = $akhir; }
    $sql .= " ORDER BY bk.tanggal ASC, bk.id ASC";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $rows = [];
    $total = 0.0;
    $lengkap = 0;
    foreach ($st->fetchAll() as $r) {
        $jml = (float) $r['jumlah'];
        $total += $jml;

        $sppTgl  = (string) ($r['spp_tgl'] ?? '');
        $spmTgl  = (string) ($r['spm_tgl'] ?? '');
        $sp2dTgl = (string) ($r['sp2d_tgl'] ?? '');
        $sppNo   = (string) ($r['spp_no'] ?? '');
        $spmNo   = (string) ($r['spm_no'] ?? '');
        $sp2dNo  = (string) ($r['sp2d_no'] ?? '');

        $penuh = ($sppTgl !== '' && $sppNo !== '' && $spmTgl !== '' && $spmNo !== ''
                  && $sp2dTgl !== '' && $sp2dNo !== '');
        if ($penuh) $lengkap++;

        $rows[] = [
            'id'            => (int) $r['id'],
            'tanggal'       => (string) $r['tanggal'],
            'no_bukti'      => (string) $r['no_bukti'],
            'kode_rekening' => (string) $r['kode_rekening'],
            'uraian'        => (string) $r['uraian'],
            'jumlah'        => $jml,
            'spp_tgl'       => $sppTgl,
            'spp_no'        => $sppNo,
            'spm_tgl'       => $spmTgl,
            'spm_no'        => $spmNo,
            'sp2d_tgl'      => $sp2dTgl,
            'sp2d_no'       => $sp2dNo,
            'ket'           => (string) ($r['ket'] ?? ''),
            'lengkap'       => $penuh ? 1 : 0,
        ];
    }

    echo json_encode([
        'success'        => true,
        'data'           => $rows,
        'total'          => count($rows),
        'total_jumlah'   => round($total, 2),
        'jumlah_lengkap' => $lengkap,
        'instansi'       => (string) $skpdUser,
        'kota'           => (string) ($user['kota'] ?? ''),
        'bendahara'      => (string) ($user['nama_lengkap'] ?? ''),
        'nip'            => ambilNipUser($pdo, (int) ($user['id'] ?? 0)),
        'periode'        => ['dari' => $dari, 'sampai' => $akhir],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'register_ppkd_simpan' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $bkId = (int) ($_POST['buku_kas_id'] ?? 0);
    if ($bkId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Transaksi Buku Pengeluaran PPKD tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Fail-closed: hanya transaksi pengeluaran milik SKPD ini yang boleh diisi.
    $cek = $pdo->prepare("SELECT id FROM buku_kas WHERE id = ? AND skpd = ? AND jenis = 'pengeluaran' LIMIT 1");
    $cek->execute([$bkId, $skpdUser]);
    if (!$cek->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Transaksi tidak ditemukan pada Buku Pengeluaran PPKD Anda.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    foreach (['spp', 'spm', 'sp2d'] as $kode) {
        $tgl = trim((string) ($_POST[$kode . '_tgl'] ?? ''));
        if ($tgl !== '' && !tanggal_valid($tgl)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Format tanggal ' . strtoupper($kode) . ' tidak valid.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (mb_strlen(trim((string) ($_POST[$kode . '_no'] ?? ''))) > 64) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Nomor ' . strtoupper($kode) . ' maksimal 64 karakter.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    $ket = trim((string) ($_POST['ket'] ?? ''));
    if (mb_strlen($ket) > 120) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Keterangan maksimal 120 karakter.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tglAtauNull = function (string $kunci) {
        $t = trim((string) ($_POST[$kunci] ?? ''));
        return $t !== '' ? $t : null;
    };
    $noBersih = function (string $kunci) {
        return trim((string) ($_POST[$kunci] ?? ''));
    };

    $nilai = [
        $tglAtauNull('spp_tgl'),  $noBersih('spp_no'),
        $tglAtauNull('spm_tgl'),  $noBersih('spm_no'),
        $tglAtauNull('sp2d_tgl'), $noBersih('sp2d_no'),
        $ket,
    ];
    $userId = (int) ($user['id'] ?? 0);

    // Upsert portabel: cek dulu -> UPDATE, jika belum ada -> INSERT
    // (menghindari VALUES() yang usang di MySQL 8.0.20+ / alias khusus MySQL).
    $ada = $pdo->prepare("SELECT id FROM register_ppkd WHERE skpd = ? AND buku_kas_id = ? LIMIT 1");
    $ada->execute([$skpdUser, $bkId]);
    if ($lama = $ada->fetch()) {
        $up = $pdo->prepare("UPDATE register_ppkd
                             SET spp_tgl = ?, spp_no = ?, spm_tgl = ?, spm_no = ?,
                                 sp2d_tgl = ?, sp2d_no = ?, ket = ?, user_id = ?
                             WHERE id = ? AND skpd = ?");
        $up->execute(array_merge($nilai, [$userId], [(int) $lama['id'], $skpdUser]));
    } else {
        $ins = $pdo->prepare("INSERT INTO register_ppkd
                              (skpd, buku_kas_id, spp_tgl, spp_no, spm_tgl, spm_no, sp2d_tgl, sp2d_no, ket, user_id)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute(array_merge([$skpdUser, $bkId], $nilai, [$userId]));
    }

    echo json_encode([
        'success'     => true,
        'message'     => 'Register SPP/SPM/SP2D tersimpan.',
        'buku_kas_id' => $bkId,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 11c. LPJ Bendahara Pengeluaran PPKD (blanko Modul-3E-LK)
//      Sumber: buku_kas (Buku Pengeluaran PPKD + Buku Penerimaan PPKD),
//      pagu dari anggaran_lra, nama akun dari akun_master.
//      Hanya kode rekening yang SUDAH ADA realisasinya yang ditampilkan.
// ============================================================
/**
 * Kelompok baris rekonsiliasi LPJ: potongan pajak / PFK / utama (SP2D-SPJ).
 * Dipakai untuk menempatkan tiap transaksi buku kas ke baris blanko LPJ.
 */
function lpj_bucket_ppkd(string $uraian, string $kode, string $jenis): string
{
    $t = mb_strtolower($uraian . ' ' . $kode);
    foreach (['ppn' => 'ppn', 'pph21' => 'pph-21', 'pph22' => 'pph-22', 'pph23' => 'pph-23'] as $kunci => $cari) {
        if (mb_strpos($t, $cari) !== false) return $kunci;
    }
    if (mb_strpos($t, 'pph 21') !== false || mb_strpos($t, 'pph-21') !== false) return 'pph21';
    if (mb_strpos($t, 'pph 22') !== false) return 'pph22';
    if (mb_strpos($t, 'pph 23') !== false) return 'pph23';
    if (mb_strpos($t, 'pfk') !== false || mb_strpos($t, 'pihak ketiga') !== false) return 'pfk';
    return $jenis === 'penerimaan' ? 'sp2d' : 'spj';
}

if ($action === 'lpj_ppkd') {
    $tahun = trim((string) ($_GET['tahun'] ?? date('Y')));
    if (preg_match('/^\d{4}$/', $tahun) !== 1) $tahun = date('Y');
    $bulan = (int) ($_GET['bulan'] ?? date('n'));
    if ($bulan < 1 || $bulan > 12) $bulan = (int) date('n');

    // ---------- 1) Rincian pengeluaran per kode rekening ----------
    $st = $pdo->prepare(
        "SELECT kode_rekening,
                SUM(CASE WHEN MONTH(tanggal) < ? THEN jumlah ELSE 0 END) AS sd_lalu,
                SUM(CASE WHEN MONTH(tanggal) = ? THEN jumlah ELSE 0 END) AS bulan_ini,
                MAX(uraian) AS uraian_terakhir
         FROM buku_kas
         WHERE skpd = ? AND jenis = 'pengeluaran' AND YEAR(tanggal) = ?
           AND jumlah > 0 AND kode_rekening <> ''
         GROUP BY kode_rekening
         ORDER BY kode_rekening ASC"
    );
    $st->execute([$bulan, $bulan, $skpdUser, $tahun]);
    $kelompok = $st->fetchAll();

    $paguAkun = [];
    $stP = $pdo->prepare("SELECT kode_akun, MAX(anggaran) AS pagu, MAX(nama_akun) AS nama
                          FROM anggaran_lra WHERE skpd = ? AND tahun = ? GROUP BY kode_akun");
    $stP->execute([$skpdUser, $tahun]);
    foreach ($stP->fetchAll() as $r) {
        $paguAkun[(string) $r['kode_akun']] = ['pagu' => (float) $r['pagu'], 'nama' => trim((string) $r['nama'])];
    }
    $namaAkun = [];
    $stN = $pdo->prepare("SELECT kode_akun, nama_akun FROM akun_master WHERE skpd = ?");
    $stN->execute([$skpdUser]);
    foreach ($stN->fetchAll() as $r) { $namaAkun[(string) $r['kode_akun']] = trim((string) $r['nama_akun']); }

    // Pagu: cocok persis; bila tidak ada, pakai kode induk (5.1.2.01.04 -> 5.1.2.01).
    $cariPagu = function (string $kode) use ($paguAkun) {
        $k = $kode;
        while ($k !== '') {
            if (isset($paguAkun[$k])) return $paguAkun[$k];
            $pos = strrpos($k, '.');
            if ($pos === false) break;
            $k = substr($k, 0, $pos);
        }
        return ['pagu' => 0.0, 'nama' => ''];
    };

    $rincian = [];
    $jmlAnggaran = 0.0;
    $jmlSdLalu = 0.0;
    $jmlBulanIni = 0.0;
    $tanpaPagu = 0;
    foreach ($kelompok as $r) {
        $kode = (string) $r['kode_rekening'];
        $sdLalu = (float) $r['sd_lalu'];
        $bulanIni = (float) $r['bulan_ini'];
        $sdIni = $sdLalu + $bulanIni;
        if ($sdIni <= 0) continue;               // hanya yang sudah ada angkanya

        $p = $cariPagu($kode);
        $anggaran = (float) $p['pagu'];
        $uraian = $namaAkun[$kode] ?? '';
        if ($uraian === '') $uraian = $p['nama'];
        if ($uraian === '') $uraian = trim((string) $r['uraian_terakhir']);
        if ($anggaran <= 0) $tanpaPagu++;

        $rincian[] = [
            'kode_rekening' => $kode,
            'uraian'        => $uraian,
            'anggaran'      => $anggaran,
            'sd_lalu'       => $sdLalu,
            'bulan_ini'     => $bulanIni,
            'sd_ini'        => $sdIni,
            'sisa'          => $anggaran - $sdIni,
        ];
        $jmlAnggaran += $anggaran;
        $jmlSdLalu += $sdLalu;
        $jmlBulanIni += $bulanIni;
    }
    // ---------- 2) Rekonsiliasi Penerimaan & Pengeluaran ----------
    $stR = $pdo->prepare(
        "SELECT jenis, kode_rekening, uraian, jumlah, MONTH(tanggal) AS bln
         FROM buku_kas
         WHERE skpd = ? AND YEAR(tanggal) = ? AND jumlah > 0
         ORDER BY tanggal ASC, id ASC"
    );
    $stR->execute([$skpdUser, $tahun]);

    $buckets = [
        'penerimaan'  => ['sp2d' => [0.0, 0.0], 'ppn' => [0.0, 0.0], 'pph21' => [0.0, 0.0], 'pph22' => [0.0, 0.0], 'pph23' => [0.0, 0.0], 'pfk' => [0.0, 0.0]],
        'pengeluaran' => ['spj' => [0.0, 0.0], 'ppn' => [0.0, 0.0], 'pph21' => [0.0, 0.0], 'pph22' => [0.0, 0.0], 'pph23' => [0.0, 0.0], 'pfk' => [0.0, 0.0]],
    ];
    foreach ($stR->fetchAll() as $r) {
        $jenis = (string) $r['jenis'] === 'penerimaan' ? 'penerimaan' : 'pengeluaran';
        $kunci = lpj_bucket_ppkd((string) $r['uraian'], (string) $r['kode_rekening'], $jenis);
        $bln = (int) $r['bln'];
        $nilai = (float) $r['jumlah'];
        if ($bln < $bulan)       $buckets[$jenis][$kunci][0] += $nilai;
        elseif ($bln === $bulan) $buckets[$jenis][$kunci][1] += $nilai;
    }

    $ringkas = function (array $b) {
        $out = [];
        $totalSdLalu = 0.0;
        $totalBulanIni = 0.0;
        foreach ($b as $kunci => $v) {
            $sdLalu   = round($v[0], 2);
            $bulanIni = round($v[1], 2);
            $out[$kunci] = [
                'sd_lalu'   => $sdLalu,
                'bulan_ini' => $bulanIni,
                'sd_ini'    => round($sdLalu + $bulanIni, 2),
            ];
            $totalSdLalu += $sdLalu;
            $totalBulanIni += $bulanIni;
        }
        $out['jumlah'] = [
            'sd_lalu'   => round($totalSdLalu, 2),
            'bulan_ini' => round($totalBulanIni, 2),
            'sd_ini'    => round($totalSdLalu + $totalBulanIni, 2),
        ];
        return $out;
    };
    $penerimaan  = $ringkas($buckets['penerimaan']);
    $pengeluaran = $ringkas($buckets['pengeluaran']);

    // ---------- 3) Tanggal & identitas ----------
    $akhirBulan = (int) date('t', mktime(0, 0, 0, $bulan, 1, (int) $tahun));
    $tglLaporan = $tahun . '-' . str_pad((string) $bulan, 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) $akhirBulan, 2, '0', STR_PAD_LEFT);
    if ($tglLaporan > date('Y-m-d')) $tglLaporan = date('Y-m-d');   // bulan berjalan -> s.d. hari ini
    $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
                  'Agustus', 'September', 'Oktober', 'November', 'Desember'][$bulan];

    echo json_encode([
        'success'     => true,
        'tahun'       => $tahun,
        'bulan'       => $bulan,
        'bulan_nama'  => $namaBulan,
        'tgl_laporan' => $tglLaporan,
        'instansi'    => (string) ($user['instansi'] ?? ''),
        'kota'        => (string) ($user['kota'] ?? ''),
        'bendahara'   => (string) ($user['nama_lengkap'] ?? ''),
        'nip'         => ambilNipUser($pdo, (int) ($user['id'] ?? 0)),
        'rincian'     => $rincian,
        'jumlah_rincian' => [
            'anggaran'  => round($jmlAnggaran, 2),
            'sd_lalu'   => round($jmlSdLalu, 2),
            'bulan_ini' => round($jmlBulanIni, 2),
            'sd_ini'    => round($jmlSdLalu + $jmlBulanIni, 2),
            'sisa'      => round($jmlAnggaran - $jmlSdLalu - $jmlBulanIni, 2),
        ],
        'penerimaan'  => $penerimaan,
        'pengeluaran' => $pengeluaran,
        'saldo_kas'   => [
            'bulan_ini' => round($penerimaan['jumlah']['bulan_ini'] - $pengeluaran['jumlah']['bulan_ini'], 2),
            'sd_ini'    => round($penerimaan['jumlah']['sd_ini'] - $pengeluaran['jumlah']['sd_ini'], 2),
        ],
        'jumlah_baris' => count($rincian),
        'tanpa_pagu'   => $tanpaPagu,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 12. Kirim dokumen ke antrean tanda tangan (doc.simtkd.com)

//     Token-based (modul peta memakai ?token=, bukan sesi PHP).
// ============================================================
if ($action === 'dokumen_kirim' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $jenis   = trim((string) ($_POST['jenis'] ?? ''));
    $nomor   = trim((string) ($_POST['nomor'] ?? ''));
    $judul   = trim((string) ($_POST['judul'] ?? ''));
    $tanggal = trim((string) ($_POST['tanggal'] ?? ''));
    $konten  = (string) ($_POST['konten_html'] ?? '');

    if ($jenis === '') $jenis = 'Dokumen';
    if ($judul === '') $judul = $jenis;
    if (mb_strlen($konten) < 200) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Konten dokumen kosong / tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strlen($konten) > 4 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['success' => false, 'message' => 'Konten dokumen terlalu besar (maks ±4 MB).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($tanggal !== '' && !tanggal_valid($tanggal)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Format tanggal tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $hash = hash('sha256', $konten);

    // Kirim ulang bila sudah ada dokumen sejenis yang masih menunggu TTD
    $cekB = $pdo->prepare("SELECT id, status FROM dokumen WHERE jenis = ? AND judul = ? AND skpd = ? AND status = 'menunggu_ttd' ORDER BY id DESC LIMIT 1");
    $cekB->execute([$jenis, $judul, $skpdUser]);
    if ($lama = $cekB->fetch()) {
        $up = $pdo->prepare("UPDATE dokumen SET nomor = ?, tanggal = ?, konten_html = ?, hash_original = ? WHERE id = ?");
        $up->execute([$nomor, ($tanggal !== '' ? $tanggal : null), $konten, $hash, (int) $lama['id']]);
        echo json_encode(['success' => true, 'message' => 'Dokumen diperbarui di antrean tanda tangan.', 'id' => (int) $lama['id']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $kode = '';
    for ($i = 0; $i < 5; $i++) {
        $c = 'TTD-' . strtoupper(bin2hex(random_bytes(4)));
        $cek = $pdo->prepare("SELECT id FROM dokumen WHERE kode_verifikasi = ? LIMIT 1");
        $cek->execute([$c]);
        if (!$cek->fetch()) { $kode = $c; break; }
    }
    if ($kode === '') {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Gagal membuat kode verifikasi. Silakan coba lagi.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ins = $pdo->prepare(
        "INSERT INTO dokumen (user_id, skpd, jenis, nomor, judul, tanggal, konten_html, hash_original, kode_verifikasi, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'menunggu_ttd')"
    );
    $ins->execute([
        (int) ($user['id'] ?? 0), $skpdUser, $jenis, $nomor, $judul,
        ($tanggal !== '' ? $tanggal : null), $konten, $hash, $kode,
    ]);
    $dokId = (int) $pdo->lastInsertId();

    // Penandatangan (opsional, JSON): [{user:'self', urutan, jabatan, nama}, ...]
    $penandatangan = [];
    $rawPttd = trim((string) ($_POST['penandatangan'] ?? ''));
    if ($rawPttd !== '') {
        $decoded = json_decode($rawPttd, true);
        if (is_array($decoded)) $penandatangan = $decoded;
    }
    $nipDoc = trim((string) ($_POST['nip'] ?? ''));
    if ($penandatangan === [] && (string) ($user['nama_lengkap'] ?? '') !== '') {
        $penandatangan = [['user' => 'self', 'urutan' => 1, 'jabatan' => 'Bendahara', 'nama' => (string) $user['nama_lengkap']]];
    }
    foreach ($penandatangan as $pt) {
        if (!is_array($pt)) continue;
        $ins2 = $pdo->prepare("INSERT INTO dokumen_ttd (dokumen_id, user_id, urutan, jabatan, nama, nip, status) VALUES (?, ?, ?, ?, ?, ?, 'menunggu')");
        $ins2->execute([
            $dokId,
            ((string) ($pt['user'] ?? '') === 'self') ? (int) ($user['id'] ?? 0) : null,
            max(1, min(20, (int) ($pt['urutan'] ?? 1))),
            mb_substr(trim((string) ($pt['jabatan'] ?? 'Bendahara')), 0, 100),
            mb_substr(trim((string) ($pt['nama'] ?? '')), 0, 100),
            mb_substr($nipDoc, 0, 50),
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Dokumen terkirim ke antrean tanda tangan (doc.simtkd.com).', 'id' => $dokId, 'kode_verifikasi' => $kode], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 13. Buku Pembantu per Rincian Objek (dari tabel buku_kas)
//     Sumber data = entri Buku Penerimaan/Pengeluaran PPKD.
// ============================================================
if ($action === 'buku_pembantu_list') {
    $jenis = (string) ($_GET['jenis'] ?? '');
    $kode  = trim((string) ($_GET['kode_akun'] ?? ''));
    $bulan = (int) ($_GET['bulan'] ?? (int) date('n'));
    $tahun = (int) ($_GET['tahun'] ?? (int) date('Y'));
    if ($bulan < 1 || $bulan > 12) $bulan = (int) date('n');
    if ($tahun < 2000 || $tahun > 2100) $tahun = (int) date('Y');
    if (!in_array($jenis, ['penerimaan', 'pengeluaran'], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Pilih jenis (penerimaan / pengeluaran).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($kode === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Pilih kode rekening rincian objek.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $st = $pdo->prepare(
        "SELECT tanggal, no_bukti, kode_rekening, uraian, jumlah
         FROM buku_kas
         WHERE skpd = ? AND jenis = ? AND kode_rekening = ?
           AND YEAR(tanggal) = ? AND MONTH(tanggal) = ?
         ORDER BY tanggal ASC, id ASC"
    );
    $st->execute([$skpdUser, $jenis, $kode, $tahun, $bulan]);
    $rows = [];
    $jumlahBulanIni = 0.0;
    foreach ($st->fetchAll() as $r) {
        $jml = (float) $r['jumlah'];
        $jumlahBulanIni += $jml;
        $rows[] = [
            'tanggal'       => (string) $r['tanggal'],
            'no_bukti'      => (string) $r['no_bukti'],
            'kode_rekening' => (string) $r['kode_rekening'],
            'uraian'        => (string) $r['uraian'],
            'jumlah'        => round($jml, 2),
        ];
    }

    $sum = function (int $bln) use ($pdo, $skpdUser, $jenis, $kode, $tahun): float {
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(jumlah), 0) FROM buku_kas
             WHERE skpd = ? AND jenis = ? AND kode_rekening = ? AND YEAR(tanggal) = ? AND MONTH(tanggal) <= ?"
        );
        $st->execute([$skpdUser, $jenis, $kode, $tahun, $bln]);
        return (float) $st->fetchColumn();
    };
    $jumlahSdBulanIni  = $sum($bulan);
    $jumlahSdBulanLalu = $bulan > 1 ? $sum($bulan - 1) : 0.0;

    $namaAkun = '';
    $stN = $pdo->prepare("SELECT nama_akun FROM akun_master WHERE skpd = ? AND kode_akun = ? LIMIT 1");
    $stN->execute([$skpdUser, $kode]);
    $namaAkun = (string) ($stN->fetchColumn() ?: '');
    if ($namaAkun === '') {
        $stK = $pdo->prepare("SELECT uraian FROM buku_kas WHERE skpd = ? AND kode_rekening = ? ORDER BY id DESC LIMIT 1");
        $stK->execute([$skpdUser, $kode]);
        $namaAkun = (string) ($stK->fetchColumn() ?: '');
    }

    // Jumlah anggaran (pagu) dari anggaran_lra bila tersedia
    $anggaran = 0.0;
    try {
        $stA = $pdo->prepare("SELECT anggaran FROM anggaran_lra WHERE skpd = ? AND tahun = ? AND kode_akun = ? ORDER BY id DESC LIMIT 1");
        $stA->execute([$skpdUser, (string) $tahun, $kode]);
        $anggaran = (float) ($stA->fetchColumn() ?: 0);
    } catch (Throwable $e) {}

    // Gambar tanda tangan user (user_ttd)
    $ttdGambar = '';
    try {
        $stT = $pdo->prepare("SELECT gambar FROM user_ttd WHERE user_id = ? LIMIT 1");
        $stT->execute([(int) ($user['id'] ?? 0)]);
        $ttdGambar = (string) ($stT->fetchColumn() ?: '');
    } catch (Throwable $e) {}

    echo json_encode([
        'success'       => true,
        'jenis'         => $jenis,
        'kode_akun'     => $kode,
        'nama_akun'     => $namaAkun,
        'bulan'         => $bulan,
        'tahun'         => $tahun,
        'skpd'          => $skpdUser,
        'bendahara'     => (string) ($user['nama_lengkap'] ?? ''),
        'anggaran'      => round($anggaran, 2),
        'nip'           => ambilNipUser($pdo, (int) ($user['id'] ?? 0)),
        'ttd_gambar'    => $ttdGambar,
        'rows'          => $rows,
        'jumlah_bulan_ini'     => round($jumlahBulanIni, 2),
        'jumlah_sd_bulan_lalu' => round($jumlahSdBulanLalu, 2),
        'jumlah_sd_bulan_ini'  => round($jumlahSdBulanIni, 2),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'buku_pembantu_obyek_list') {
    $jenis = (string) ($_GET['jenis'] ?? '');
    if (!in_array($jenis, ['penerimaan', 'pengeluaran'], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Pilih jenis (penerimaan / pengeluaran).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $st = $pdo->prepare(
        "SELECT kode_rekening, MAX(uraian) AS nama, SUM(jumlah) AS total
         FROM buku_kas WHERE skpd = ? AND jenis = ? AND kode_rekening <> ''
         GROUP BY kode_rekening ORDER BY kode_rekening ASC"
    );
    $st->execute([$skpdUser, $jenis]);
    $obyek = [];
    foreach ($st->fetchAll() as $r) {
        $obyek[] = [
            'kode_akun' => (string) $r['kode_rekening'],
            'nama_akun' => (string) $r['nama'],
            'total'     => round((float) $r['total'], 2),
        ];
    }
    echo json_encode(['success' => true, 'obyek' => $obyek, 'total' => count($obyek)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 11d. Rekonsiliasi Bank - Bendahara Umum Daerah (BUD)
//      Diisi MANUAL (blanko Modul-3E-LK): saldo kas menurut
//      buku & menurut bank, lalu rincian keterangan selisih
//      bagian A/B/C/D. Satu dokumen per BULAN per SKPD.
// ============================================================

/** Nama bulan Bahasa Indonesia (1-12) untuk periode rekonsiliasi. */
function rb_bulan_id(int $bulan): string
{
    $nama = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
             'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return $nama[$bulan] ?? '';
}

/** Tanggal terakhir sebuah bulan (YYYY-MM-DD) - default tanggal tanda tangan. */
function rb_akhir_bulan(int $tahun, int $bulan): string
{
    $t = strtotime(sprintf('%04d-%02d-01', $tahun, $bulan));
    return $t === false ? '' : date('Y-m-t', $t);
}

/** Bangun dokumen rekonsiliasi bank (kepala + rincian A/B/C/D + subtotal). */
function rb_dokumen(PDO $pdo, string $skpd, int $tahun, int $bulan): array
{
    $st = $pdo->prepare("SELECT * FROM rekonsiliasi_bank WHERE skpd = ? AND tahun = ? AND bulan = ? LIMIT 1");
    $st->execute([$skpd, $tahun, $bulan]);
    $head = $st->fetch() ?: null;

    $bagian = ['A' => [], 'B' => [], 'C' => [], 'D' => []];
    $nilai  = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0, 'D' => 0.0];
    if ($head) {
        $si = $pdo->prepare("SELECT bagian, uraian, nilai FROM rekonsiliasi_bank_item
                             WHERE rekonsiliasi_id = ? ORDER BY bagian ASC, urut ASC, id ASC LIMIT 400");
        $si->execute([(int) $head['id']]);
        foreach ($si->fetchAll() as $r) {
            $b = strtoupper((string) $r['bagian']);
            if (!isset($bagian[$b])) continue;
            $n = (float) $r['nilai'];
            $bagian[$b][] = ['uraian' => (string) $r['uraian'], 'nilai' => round($n, 2)];
            $nilai[$b] += $n;
        }
    }

    // Saran "Saldo Kas umum daerah Menurut Buku" = saldo kumulatif Buku Kas
    // sampai akhir bulan terpilih (dari Buku Kas Penerimaan/Pengeluaran).
    $saran = 0.0;
    $akhir = rb_akhir_bulan($tahun, $bulan);
    if ($akhir !== '') {
        try {
            $sb = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN jenis = 'penerimaan' THEN jumlah ELSE -jumlah END), 0)
                                 FROM buku_kas WHERE skpd = ? AND tanggal <= ?");
            $sb->execute([$skpd, $akhir]);
            $saran = (float) $sb->fetchColumn();
        } catch (Throwable $e) {}
    }

    $saldoBuku = $head ? (float) $head['saldo_buku'] : 0.0;
    $saldoBank = $head ? (float) $head['saldo_bank'] : 0.0;

    return [
        'tahun'            => $tahun,
        'bulan'            => $bulan,
        'periode'          => $head ? (string) $head['periode'] : rb_bulan_id($bulan),
        'tgl_ttd'          => (string) ($head['tgl_ttd'] ?? $akhir),
        'saldo_buku'       => round($saldoBuku, 2),
        'saldo_bank'       => round($saldoBank, 2),
        'selisih'          => round($saldoBuku - $saldoBank, 2),
        'saran_saldo_buku' => round($saran, 2),
        'bagian'           => $bagian,
        'subtotal'         => [
            'A' => round($nilai['A'], 2),
            'B' => round($nilai['B'], 2),
            'C' => round($nilai['C'], 2),
            'D' => round($nilai['D'], 2),
        ],
        'total_buku'       => round($nilai['A'] + $nilai['B'], 2),
        'total_bank'       => round($nilai['C'] + $nilai['D'], 2),
        'catatan'          => (string) ($head['catatan'] ?? ''),
        'tersimpan'        => $head !== null,
        'updated_at'       => (string) ($head['updated_at'] ?? ''),
    ];
}

if ($action === 'rekonsiliasi_bank') {
    $tahun = trim((string) ($_GET['tahun'] ?? date('Y')));
    if (preg_match('/^\d{4}$/', $tahun) !== 1) $tahun = date('Y');
    $bulan = (int) ($_GET['bulan'] ?? date('n'));
    if ($bulan < 1 || $bulan > 12) $bulan = (int) date('n');

    $dok = rb_dokumen($pdo, $skpdUser, (int) $tahun, $bulan);
    echo json_encode(array_merge(['success' => true], $dok, [
        'instansi'  => $skpdUser,
        'kota'      => (string) ($user['kota'] ?? ''),
        'bendahara' => (string) ($user['nama_lengkap'] ?? ''),
        'nip'       => ambilNipUser($pdo, (int) ($user['id'] ?? 0)),
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'rekonsiliasi_bank_daftar') {
    $st = $pdo->prepare("SELECT k.id, k.tahun, k.bulan, k.periode, k.saldo_buku, k.saldo_bank, k.updated_at,
                                (SELECT COUNT(*) FROM rekonsiliasi_bank_item i WHERE i.rekonsiliasi_id = k.id) AS baris
                         FROM rekonsiliasi_bank k WHERE k.skpd = ?
                         ORDER BY k.tahun DESC, k.bulan DESC LIMIT 120");
    $st->execute([$skpdUser]);

    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'id'         => (int) $r['id'],
            'tahun'      => (int) $r['tahun'],
            'bulan'      => (int) $r['bulan'],
            'periode'    => (string) $r['periode'],
            'baris'      => (int) $r['baris'],
            'selisih'    => round((float) $r['saldo_buku'] - (float) $r['saldo_bank'], 2),
            'updated_at' => (string) $r['updated_at'],
        ];
    }
    echo json_encode(['success' => true, 'data' => $rows, 'total' => count($rows)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'rekonsiliasi_bank_simpan' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $tahun = (int) ($_POST['tahun'] ?? 0);
    $bulan = (int) ($_POST['bulan'] ?? 0);
    if ($tahun < 2000 || $tahun > 2100 || $bulan < 1 || $bulan > 12) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Periode (tahun / bulan) tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $periode = kh_potong((string) ($_POST['periode'] ?? ''), 20);
    if ($periode === '') $periode = rb_bulan_id($bulan);
    $tglTtd = trim((string) ($_POST['tgl_ttd'] ?? ''));
    if (!tanggal_valid($tglTtd)) $tglTtd = rb_akhir_bulan($tahun, $bulan);
    $catatan = kh_potong((string) ($_POST['catatan'] ?? ''), 255);

    $saldoBuku = kh_angka($_POST['saldo_buku'] ?? 0);
    $saldoBank = kh_angka($_POST['saldo_bank'] ?? 0);

    $raw = json_decode((string) ($_POST['items'] ?? '{}'), true);
    if (!is_array($raw)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Data rincian keterangan selisih tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $items = [];
    foreach (['A', 'B', 'C', 'D'] as $b) {
        $daftar = (isset($raw[$b]) && is_array($raw[$b])) ? $raw[$b] : [];
        if (count($daftar) > 60) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Baris bagian ' . $b . ' melebihi batas 60 baris.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $urut = 0;
        foreach ($daftar as $i => $r) {
            if (!is_array($r)) continue;
            $uraian = kh_potong((string) ($r['uraian'] ?? ''), 255);
            $nilai  = kh_angka($r['nilai'] ?? 0);
            if ($nilai < 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Nilai tidak boleh negatif (bagian ' . $b . ' baris ' . ($i + 1) . ').'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($uraian === '' && $nilai === 0.0) continue;   // baris kosong tidak disimpan
            $urut++;
            $items[] = ['bagian' => $b, 'urut' => $urut, 'uraian' => $uraian, 'nilai' => $nilai];
        }
    }

    $userId = (int) ($user['id'] ?? 0);
    $headId = 0;

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id FROM rekonsiliasi_bank WHERE skpd = ? AND tahun = ? AND bulan = ? LIMIT 1");
        $st->execute([$skpdUser, $tahun, $bulan]);
        $headId = (int) ($st->fetchColumn() ?: 0);

        if ($headId > 0) {
            $up = $pdo->prepare("UPDATE rekonsiliasi_bank
                 SET periode = ?, tgl_ttd = ?, saldo_buku = ?, saldo_bank = ?, catatan = ?, user_id = ?
                 WHERE id = ? AND skpd = ?");
            $up->execute([$periode, $tglTtd, $saldoBuku, $saldoBank, $catatan, $userId, $headId, $skpdUser]);
            $del = $pdo->prepare("DELETE FROM rekonsiliasi_bank_item WHERE rekonsiliasi_id = ? AND skpd = ?");
            $del->execute([$headId, $skpdUser]);
        } else {
            $in = $pdo->prepare("INSERT INTO rekonsiliasi_bank
                (skpd, user_id, tahun, bulan, periode, tgl_ttd, saldo_buku, saldo_bank, catatan)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $in->execute([$skpdUser, $userId, $tahun, $bulan, $periode, $tglTtd, $saldoBuku, $saldoBank, $catatan]);
            $headId = (int) $pdo->lastInsertId();
        }

        if ($items) {
            $ins = $pdo->prepare("INSERT INTO rekonsiliasi_bank_item (rekonsiliasi_id, skpd, bagian, urut, uraian, nilai)
                                  VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($items as $it) {
                $ins->execute([$headId, $skpdUser, $it['bagian'], $it['urut'], $it['uraian'], $it['nilai']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    echo json_encode(array_merge([
        'success'    => true,
        'message'    => 'Rekonsiliasi Bank tersimpan (' . count($items) . ' baris rincian).',
        'laporan_id' => $headId,
    ], rb_dokumen($pdo, $skpdUser, $tahun, $bulan), [
        'instansi'  => $skpdUser,
        'kota'      => (string) ($user['kota'] ?? ''),
        'bendahara' => (string) ($user['nama_lengkap'] ?? ''),
        'nip'       => ambilNipUser($pdo, (int) ($user['id'] ?? 0)),
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'rekonsiliasi_bank_hapus' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $tahun = (int) ($_POST['tahun'] ?? 0);
    $bulan = (int) ($_POST['bulan'] ?? 0);
    if ($tahun < 2000 || $tahun > 2100 || $bulan < 1 || $bulan > 12) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Periode (tahun / bulan) tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $st = $pdo->prepare("SELECT id FROM rekonsiliasi_bank WHERE skpd = ? AND tahun = ? AND bulan = ? LIMIT 1");
    $st->execute([$skpdUser, $tahun, $bulan]);
    $headId = (int) ($st->fetchColumn() ?: 0);
    if ($headId <= 0) {
        echo json_encode(['success' => true, 'message' => 'Rekonsiliasi periode tersebut belum ada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $d1 = $pdo->prepare("DELETE FROM rekonsiliasi_bank_item WHERE rekonsiliasi_id = ? AND skpd = ?");
        $d1->execute([$headId, $skpdUser]);
        $d2 = $pdo->prepare("DELETE FROM rekonsiliasi_bank WHERE id = ? AND skpd = ?");
        $d2->execute([$headId, $skpdUser]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    echo json_encode(['success' => true,
        'message' => 'Rekonsiliasi Bank periode ' . rb_bulan_id($bulan) . ' ' . $tahun . ' dihapus.'], JSON_UNESCAPED_UNICODE);
    exit;
}
// ============================================================
// 18. Buku Jurnal Khusus (blanko Modul-3E-LK) - diisi MANUAL
//     Pemilihan jenis jurnal:
//       - pendapatan_lo               : Buku Jurnal Khusus Pendapatan- LO
//       - penerimaan_kas_pendapatan_lo: Buku Jurnal Khusus Penerimaan Kas - Pendapatan LO
//       - pendapatan_lra              : Buku Jurnal Khusus Pendapatan LRA
//       - belanja_pemda               : Buku Jurnal Khusus Belanja Pemda
//       - beban_pemda                 : Buku Jurnal Khusus Beban Pemda
//     Kolom kode akun mengikuti blanko; kolom KOSONG di ujung tiap
//     grup = kolom bebas yang kode akunnya boleh diisi manual.
// ============================================================
if (!defined('JK_MAX_SLOT')) define('JK_MAX_SLOT', 10);

/** Definisi blanko Buku Jurnal Khusus (satu sumber: judul, kolom, satuan). */
function jk_template(): array
{
    static $tpl = null;
    if (is_array($tpl)) return $tpl;

    // Kolom piutang yang sama dipakai blanko LO & Penerimaan Kas.
    $piutang = ['1.1.3.01.11.01', '1.1.3.01.13.04', '1.1.3.01.15', '1.1.3.01.12'];
    $contohLo = [
        'Pendapatan Bunga Obligasi BUMN A',
        'Pendapatan Bagi Hasil PPN',
        'Dana Alokasi Umum',
        'Pendapatan Jasa Giro Dana Cadangan',
    ];

    $tpl = [
        'pendapatan_lo' => [
            'judul'       => 'Buku Jurnal Khusus Pendapatan- LO',
            'satuan'      => '(Rp)',
            'nomor_label' => ['Nomor', 'DOK'],
            'debet'       => ['label' => "Debet ('000) piutang", 'kode' => $piutang, 'kosong' => 1],
            'kredit'      => ['label' => "Kredit ('000) pendapatan",
                              'kode' => ['8.1.3.04.01', '8.2.1.01.04', '8.2.2.01.01', '8.1.4.02.03'], 'kosong' => 1],
            'contoh'      => $contohLo,
        ],
        'penerimaan_kas_pendapatan_lo' => [
            'judul'       => 'Buku Jurnal Khusus Penerimaan Kas – Pendapatan LO',
            'satuan'      => "(Rp '000)",
            'nomor_label' => ['Nomor Dokumen'],
            'debet'       => ['label' => 'Debet (kas)',      'kode' => ['1.1.1.1'], 'kosong' => 0],
            'kredit'      => ['label' => 'Kredit (piutang)', 'kode' => $piutang,    'kosong' => 1],
            'contoh'      => $contohLo,
        ],
        'pendapatan_lra' => [
            'judul'       => 'Buku Jurnal Khusus Pendapatan LRA',
            'satuan'      => "(Rp '000)",
            'nomor_label' => ['Nomor Dokumen'],
            'debet'       => ['label' => 'Debet epsal', 'kode' => ['3.1.2.01.01'], 'kosong' => 0],
            'kredit'      => ['label' => 'Kredit',
                              'kode' => ['4.1.3.04.01', '4.2.1.01.04', '4.2.2.01.01', '4.1.4.02.03'], 'kosong' => 1],
            'contoh'      => [
                'Realisasi Pendapatan Bunga Obligasi BUMN A',
                'Realisasi Pendapatan Bagi Hasil PPN',
                'Realisasi Dana Alokasi Umum',
                'Realisasi Pendapatan Jasa Giro Dana Cadangan',
            ],
        ],
        // Belanja Pemda: Debet belanja (5.x) 4 kolom + 4 kolom bebas, Kredit EPSAL
        'belanja_pemda' => [
            'judul'       => 'Buku Jurnal Khusus Belanja Pemda',
            'satuan'      => "(Rp '000)",
            'nomor_label' => ['No', 'BKU'],
            'label_id'    => 'NIK',
            'debet'       => [
                'label'  => 'Debet',
                'kode'   => ['5.1.2.01.01', '5.1.3.01.01', '5.1.4.03.01', '5.1.5.01.01'],
                'nama'   => ['', '', '', ''],
                'kosong' => 4,
            ],
            'kredit'      => [
                'label'  => 'Kredit',
                'kode'   => ['3.1.2.01.01'],
                'nama'   => ['EPSAL'],
                'kosong' => 0,
            ],
            'contoh'      => [
                'Bel. Bunga utang pinjaman kepada Pempus',
                'Belj. Subsidi kpd PDAM',
                'Belj. Hibah kpd Pemdes',
                'Belj. Bantuan sosial kpd organisasi sosial keagamaan',
            ],
        ],
        // Beban Pemda: Debet beban (9.x) 4 kolom + 4 kolom bebas, Kredit KAS
        'beban_pemda' => [
            'judul'       => 'Buku Jurnal Khusus Beban Pemda',
            'satuan'      => "(Rp '000)",
            'nomor_label' => ['No', 'BKU'],
            'label_id'    => 'NIK',
            'debet'       => [
                'label'  => 'Debet',
                'kode'   => ['9.1.2.01.01', '9.1.3.01.01', '9.1.4.03.01', '9.1.5.01.01'],
                'nama'   => ['', '', '', ''],
                'kosong' => 4,
            ],
            'kredit'      => [
                'label'  => 'Kredit',
                'kode'   => ['1.1.1.1'],
                'nama'   => ['KAS'],
                'kosong' => 0,
            ],
            'contoh'      => [
                'Beban Bunga utang pinjaman kepada Pempus',
                'Beban Subsidi kpd PDAM',
                'Beban Hibah kpd Pemdes',
                'Beban Bantuan sosial kpd organisasi sosial keagamaan',
            ],
        ],
        // Konsolidator: dipakai halaman khusus "Jurnal Khusus Konsolidator".
        // SKPKD diisi manual (kolom skp_label), seluruh kode akun juga manual.
        'penerimaan_konsolidator' => [
            'judul'        => 'Buku Jurnal Khusus Penerimaan Konsolidator',
            'satuan'       => '',
            'grup'         => 'konsolidator',
            'uraian_label' => 'Keterangan',
            'nomor_label'  => ['No BKU'],
            'label_id'     => 'NIP',
            'debet'        => ['label' => 'Debet',  'kode' => ['1.1.1.1'],     'nama' => [''],        'kosong' => 0],
            'kredit'       => ['label' => 'Kredit', 'kode' => ['3.1.4.01.01'], 'nama' => ['RK SKPD'], 'kosong' => 0],
            'contoh'       => [
                'Retribusi Pemakaian Kekayaan Daerah',
                'Retribusi Pemakaian Kekayaan Daerah',
                'Denda Keterlambatan Pekerjaan Bidang PU',
                'Retribusi Pemakaian Kekayaan Daerah',
                'Retribusi Pemakaian Kekayaan Daerah',
            ],
        ],
    ];
    return $tpl;
}

/** Kunci jenis jurnal pertama (fallback bila jenis tidak dikenal). */
function jk_jenis_default(): string
{
    $kunci = array_keys(jk_template());
    return (string) ($kunci[0] ?? 'pendapatan_lo');
}

/** Daftar kolom satu grup: kode tetap + kolom kosong bebas (maks JK_MAX_SLOT). */
function jk_slot(array $grup): array
{
    $kode = [];
    foreach ((array) ($grup['kode'] ?? []) as $c) $kode[] = (string) $c;
    for ($i = 0, $n = max(0, (int) ($grup['kosong'] ?? 0)); $i < $n; $i++) $kode[] = '';
    return array_slice($kode, 0, JK_MAX_SLOT);
}

/** Nama akun tiap kolom (opsional, mis. "EPSAL" / "KAS") sejajar dengan jk_slot(). */
function jk_nama_slot(array $grup): array
{
    $nama = [];
    foreach ((array) ($grup['nama'] ?? []) as $n) $nama[] = (string) $n;
    $slot = count(jk_slot($grup));
    while (count($nama) < $slot) $nama[] = '';
    return array_slice($nama, 0, $slot);
}

/** Template siap kirim ke frontend (kolom kosong dikirim sebagai ''). */
function jk_template_publik(): array
{
    $out = [];
    foreach (jk_template() as $key => $t) {
        $out[] = [
            'key'         => $key,
            'judul'       => (string) $t['judul'],
            'satuan'      => (string) $t['satuan'],
            'nomor_label' => array_values($t['nomor_label']),
            'label_id'    => (string) ($t['label_id'] ?? 'NIP'),
            'grup'        => (string) ($t['grup'] ?? ''),
            'uraian_label' => (string) ($t['uraian_label'] ?? 'Uraian'),
            'maks_slot'   => JK_MAX_SLOT,
            'debet'       => ['label' => (string) $t['debet']['label'],  'kode' => jk_slot($t['debet']),  'nama' => jk_nama_slot($t['debet'])],
            'kredit'      => ['label' => (string) $t['kredit']['label'], 'kode' => jk_slot($t['kredit']), 'nama' => jk_nama_slot($t['kredit'])],
            'contoh'      => array_values($t['contoh']),
        ];
    }
    return $out;
}

/** Daftar kolom uang pada tabel baris (debet_1, kredit_1, ...). */
function jk_kolom_uang(): array
{
    $kolom = [];
    for ($i = 1; $i <= JK_MAX_SLOT; $i++) { $kolom[] = "debet_{$i}"; $kolom[] = "kredit_{$i}"; }
    return $kolom;
}

/**
 * Bangun dokumen jurnal khusus 1 periode (kepala + baris + total kolom).
 * `baris` kosong bila periode belum pernah disimpan.
 */
function jk_dokumen(PDO $pdo, string $skpd, string $jenis, int $tahun, int $bulan): array
{
    $tpl = jk_template();
    if (!isset($tpl[$jenis])) $jenis = jk_jenis_default();
    $t = $tpl[$jenis];
    $slotD = jk_slot($t['debet']);
    $slotK = jk_slot($t['kredit']);

    $st = $pdo->prepare("SELECT * FROM jurnal_khusus WHERE skpd = ? AND jenis = ? AND tahun = ? AND bulan = ? LIMIT 1");
    $st->execute([$skpd, $jenis, $tahun, $bulan]);
    $head = $st->fetch() ?: null;

    $baris  = [];
    $totD   = array_fill(0, count($slotD), 0.0);
    $totK   = array_fill(0, count($slotK), 0.0);
    $timpang = 0;

    if ($head) {
        $sb = $pdo->prepare("SELECT tanggal, nomor_dok, uraian, " . implode(', ', jk_kolom_uang()) . "
                             FROM jurnal_khusus_baris WHERE jurnal_id = ? AND skpd = ?
                             ORDER BY urut ASC, id ASC LIMIT 400");
        $sb->execute([(int) $head['id'], $skpd]);
        foreach ($sb->fetchAll() as $r) {
            $d = []; $k = []; $td = 0.0; $tk = 0.0;
            for ($i = 1; $i <= JK_MAX_SLOT; $i++) {
                if ($i <= count($slotD)) { $v = (float) $r["debet_{$i}"];  $d[] = round($v, 2); $totD[$i - 1] += $v; $td += $v; }
                if ($i <= count($slotK)) { $v = (float) $r["kredit_{$i}"]; $k[] = round($v, 2); $totK[$i - 1] += $v; $tk += $v; }
            }
            if (round($td, 2) !== round($tk, 2)) $timpang++;
            $baris[] = [
                'tanggal'      => (string) ($r['tanggal'] ?? ''),
                'nomor_dok'    => (string) $r['nomor_dok'],
                'uraian'       => (string) $r['uraian'],
                'debet'        => $d,
                'kredit'       => $k,
                'total_debet'  => round($td, 2),
                'total_kredit' => round($tk, 2),
            ];
        }
    }

    // Kode akun pada kolom bebas (diisi manual pengguna) - hanya slot valid.
    $kodeExtra = ['debet' => [], 'kredit' => []];
    if ($head && !empty($head['kode_extra'])) {
        $dec = json_decode((string) $head['kode_extra'], true);
        if (is_array($dec)) {
            foreach (['debet', 'kredit'] as $grup) {
                if (!isset($dec[$grup]) || !is_array($dec[$grup])) continue;
                foreach ($dec[$grup] as $slot => $kode) {
                    $s  = (int) $slot;
                    $kc = trim((string) $kode);
                    if ($s < 1 || $s > JK_MAX_SLOT || $kc === '') continue;
                    $kodeExtra[$grup][(string) $s] = $kc;
                }
            }
        }
    }

    $totalD = array_sum($totD);
    $totalK = array_sum($totK);

    return [
        'jenis'                => $jenis,
        'judul'                => (string) $t['judul'],
        'tahun'                => $tahun,
        'bulan'                => $bulan,
        'bulan_nama'           => rb_bulan_id($bulan),
        'tgl_ttd'              => rb_akhir_bulan($tahun, $bulan),
        'tersimpan'            => $head !== null,
        'updated_at'           => (string) ($head['updated_at'] ?? ''),
        'kode_extra'           => $kodeExtra,
        'skp'                  => (string) ($head['skp_label'] ?? ''),
        'baris'                => $baris,
        'jumlah_baris'         => count($baris),
        'baris_tidak_seimbang' => $timpang,
        'total_kolom'          => ['debet' => $totD, 'kredit' => $totK],
        'total_debet'          => round($totalD, 2),
        'total_kredit'         => round($totalK, 2),
        'selisih'              => round($totalD - $totalK, 2),
    ];
}

// ---- Buku Jurnal Khusus: daftar jenis blanko (untuk dropdown) ----
if ($action === 'jurnal_khusus_template') {
    echo json_encode([
        'success'   => true,
        'maks_slot' => JK_MAX_SLOT,
        'data'      => jk_template_publik(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Buku Jurnal Khusus: 1 dokumen (jenis + periode) ----
if ($action === 'jurnal_khusus') {
    $jenis = trim((string) ($_GET['jenis'] ?? ''));
    if (!isset(jk_template()[$jenis])) $jenis = jk_jenis_default();
    $tahun = trim((string) ($_GET['tahun'] ?? date('Y')));
    if (preg_match('/^\d{4}$/', $tahun) !== 1) $tahun = date('Y');
    $bulan = (int) ($_GET['bulan'] ?? date('n'));
    if ($bulan < 1 || $bulan > 12) $bulan = (int) date('n');

    $dok = jk_dokumen($pdo, $skpdUser, $jenis, (int) $tahun, $bulan);
    echo json_encode(array_merge(['success' => true], $dok, [
        'instansi' => $skpdUser,
        'kota'     => (string) ($user['kota'] ?? ''),
        'nama'     => (string) ($user['nama_lengkap'] ?? ''),
        'nip'      => ambilNipUser($pdo, (int) ($user['id'] ?? 0)),
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Buku Jurnal Khusus: riwayat periode tersimpan per jenis ----
if ($action === 'jurnal_khusus_daftar') {
    $jenis = trim((string) ($_GET['jenis'] ?? ''));
    if (!isset(jk_template()[$jenis])) $jenis = jk_jenis_default();

    $st = $pdo->prepare("SELECT k.tahun, k.bulan, k.updated_at,
                                (SELECT COUNT(*) FROM jurnal_khusus_baris b WHERE b.jurnal_id = k.id) AS baris
                         FROM jurnal_khusus k WHERE k.skpd = ? AND k.jenis = ?
                         ORDER BY k.tahun DESC, k.bulan DESC LIMIT 120");
    $st->execute([$skpdUser, $jenis]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'tahun'      => (int) $r['tahun'],
            'bulan'      => (int) $r['bulan'],
            'bulan_nama' => rb_bulan_id((int) $r['bulan']),
            'baris'      => (int) $r['baris'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }
    echo json_encode(['success' => true, 'jenis' => $jenis, 'data' => $rows, 'total' => count($rows)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Buku Jurnal Khusus: simpan 1 periode (ganti seluruh baris) ----
if ($action === 'jurnal_khusus_simpan' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $jenis = trim((string) ($_POST['jenis'] ?? ''));
    $tahun = (int) ($_POST['tahun'] ?? 0);
    $bulan = (int) ($_POST['bulan'] ?? 0);
    $tpl   = jk_template();

    if (!isset($tpl[$jenis])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Jenis jurnal tidak dikenal.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($tahun < 2000 || $tahun > 2100) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Tahun tidak valid (2000 - 2100).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($bulan < 1 || $bulan > 12) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Bulan tidak valid (1 - 12).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $t     = $tpl[$jenis];
    $slotD = count(jk_slot($t['debet']));
    $slotK = count(jk_slot($t['kredit']));

    $data = json_decode((string) ($_POST['baris'] ?? '[]'), true);
    if (!is_array($data)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Data baris tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (count($data) > 300) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Maksimal 300 baris per dokumen.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Kode akun pada kolom bebas: slot valid saja, panjang sewajarnya (tidak fatal).
    $kodeExtra = ['debet' => [], 'kredit' => []];
    $decKode = json_decode((string) ($_POST['kode_extra'] ?? '{}'), true);
    if (is_array($decKode)) {
        foreach (['debet', 'kredit'] as $grup) {
            if (!isset($decKode[$grup]) || !is_array($decKode[$grup])) continue;
            $maks = ($grup === 'debet') ? $slotD : $slotK;
            foreach ($decKode[$grup] as $slot => $kode) {
                $s = (int) $slot;
                $kc = trim((string) $kode);
                if ($s < 1 || $s > $maks || $kc === '') continue;
                $kodeExtra[$grup][(string) $s] = mb_substr($kc, 0, 50);
            }
        }
    }

    // SKPKD (blanko Konsolidator) diisi manual; bila tidak dikirim, nilai lama dipertahankan.
    $skp = null;
    if (array_key_exists('skp', $_POST)) {
        $skp = trim((string) $_POST['skp']);
        if (mb_strlen($skp) > 80) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'SKPKD maksimal 80 karakter.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $bersih = []; $timpang = []; $pesanBaris = '';
    foreach ($data as $i => $r) {
        if (!is_array($r)) continue;
        $no = $i + 1;
        $tanggal = trim((string) ($r['tanggal'] ?? ''));
        if ($tanggal !== '' && !tanggal_valid($tanggal)) { $pesanBaris = "Baris ke-{$no}: tanggal tidak valid (format YYYY-MM-DD)."; break; }
        $nomor = trim((string) ($r['nomor_dok'] ?? ''));
        if (mb_strlen($nomor) > 60) { $pesanBaris = "Baris ke-{$no}: nomor dokumen maksimal 60 karakter."; break; }
        $uraian = trim((string) ($r['uraian'] ?? ''));
        if (mb_strlen($uraian) > 255) { $pesanBaris = "Baris ke-{$no}: uraian maksimal 255 karakter."; break; }

        $batal = false;
        $nilai = [];
        foreach (['debet', 'kredit'] as $grup) {
            $maks = ($grup === 'debet') ? $slotD : $slotK;
            $arr  = (isset($r[$grup]) && is_array($r[$grup])) ? array_values($r[$grup]) : [];
            $out  = [];
            for ($s = 0; $s < JK_MAX_SLOT; $s++) {
                if ($s >= $maks) { $out[] = 0.0; continue; }
                $mentah = $arr[$s] ?? 0;
                if ($mentah === null || $mentah === '') { $out[] = 0.0; continue; }
                if (is_string($mentah)) $mentah = str_replace(['.', ',', ' '], '', trim($mentah));
                if (!is_numeric($mentah)) {
                    $pesanBaris = 'Baris ke-' . $no . ': nilai kolom ' . ucfirst($grup) . ' ke-' . ($s + 1) . ' bukan angka.';
                    $batal = true; break;
                }
                $v = (float) $mentah;
                if ($v < 0) {
                    $pesanBaris = 'Baris ke-' . $no . ': nilai kolom ' . ucfirst($grup) . ' ke-' . ($s + 1) . ' tidak boleh negatif.';
                    $batal = true; break;
                }
                $out[] = round($v, 2);
            }
            if ($batal) break;
            $nilai[$grup] = $out;
        }
        if ($batal) break;

        $td = array_sum($nilai['debet']);
        $tk = array_sum($nilai['kredit']);
        // Baris kosong tidak disimpan.
        if ($tanggal === '' && $nomor === '' && $uraian === '' && round($td, 2) === 0.0 && round($tk, 2) === 0.0) continue;
        if (round($td, 2) !== round($tk, 2)) $timpang[] = $no;

        $bersih[] = [
            'urut'      => count($bersih) + 1,
            'tanggal'   => $tanggal,
            'nomor_dok' => $nomor,
            'uraian'    => $uraian,
            'debet'     => $nilai['debet'],
            'kredit'    => $nilai['kredit'],
        ];
    }
    if ($pesanBaris !== '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $pesanBaris], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$bersih) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Jurnal belum berisi data (minimal satu baris berisi angka atau uraian).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id FROM jurnal_khusus WHERE skpd = ? AND jenis = ? AND tahun = ? AND bulan = ? LIMIT 1");
        $st->execute([$skpdUser, $jenis, $tahun, $bulan]);
        $headId = (int) ($st->fetchColumn() ?: 0);
        $jsonExtra = json_encode($kodeExtra, JSON_UNESCAPED_UNICODE);

        if ($headId > 0) {
            $pdo->prepare("UPDATE jurnal_khusus SET user_id = ?, kode_extra = ?, skp_label = COALESCE(?, skp_label) WHERE id = ? AND skpd = ?")
                ->execute([(int) ($user['id'] ?? 0), $jsonExtra, $skp, $headId, $skpdUser]);
            $pdo->prepare("DELETE FROM jurnal_khusus_baris WHERE jurnal_id = ? AND skpd = ?")
                ->execute([$headId, $skpdUser]);
        } else {
            $pdo->prepare("INSERT INTO jurnal_khusus (skpd, user_id, jenis, tahun, bulan, kode_extra, skp_label) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$skpdUser, (int) ($user['id'] ?? 0), $jenis, $tahun, $bulan, $jsonExtra, (string) $skp]);
            $headId = (int) $pdo->lastInsertId();
        }

        $kolom = ['jurnal_id', 'skpd', 'urut', 'tanggal', 'nomor_dok', 'uraian'];
        for ($i = 1; $i <= JK_MAX_SLOT; $i++) { $kolom[] = "debet_{$i}"; $kolom[] = "kredit_{$i}"; }
        $ins = $pdo->prepare("INSERT INTO jurnal_khusus_baris (" . implode(', ', $kolom) . ") VALUES (" .
            implode(', ', array_fill(0, count($kolom), '?')) . ")");
        foreach ($bersih as $b) {
            $nilai = [$headId, $skpdUser, (int) $b['urut'], ($b['tanggal'] !== '' ? $b['tanggal'] : null),
                      $b['nomor_dok'], $b['uraian']];
            for ($i = 0; $i < JK_MAX_SLOT; $i++) {
                $nilai[] = $b['debet'][$i] ?? 0;
                $nilai[] = $b['kredit'][$i] ?? 0;
            }
            $ins->execute($nilai);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $pesan = $t['judul'] . ' ' . rb_bulan_id($bulan) . ' ' . $tahun . ' tersimpan (' . count($bersih) . ' baris).';
    if ($timpang) {
        $pesan .= ' Catatan: baris ke-' . implode(', ', $timpang) . ' belum seimbang (Jumlah Debet <> Jumlah Kredit).';
    }
    echo json_encode([
        'success'              => true,
        'message'              => $pesan,
        'id'                   => $headId,
        'baris'                => count($bersih),
        'baris_tidak_seimbang' => count($timpang),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Buku Jurnal Khusus: hapus 1 periode ----
if ($action === 'jurnal_khusus_hapus' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $jenis = trim((string) ($_POST['jenis'] ?? ''));
    $tahun = (int) ($_POST['tahun'] ?? 0);
    $bulan = (int) ($_POST['bulan'] ?? 0);
    $tpl   = jk_template();

    if (!isset($tpl[$jenis]) || $tahun < 2000 || $tahun > 2100 || $bulan < 1 || $bulan > 12) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Jenis / periode jurnal tidak valid.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $st = $pdo->prepare("SELECT id FROM jurnal_khusus WHERE skpd = ? AND jenis = ? AND tahun = ? AND bulan = ? LIMIT 1");
    $st->execute([$skpdUser, $jenis, $tahun, $bulan]);
    $headId = (int) ($st->fetchColumn() ?: 0);
    if ($headId <= 0) {
        echo json_encode(['success' => true, 'message' => 'Jurnal periode tersebut belum ada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM jurnal_khusus_baris WHERE jurnal_id = ? AND skpd = ?")->execute([$headId, $skpdUser]);
        $pdo->prepare("DELETE FROM jurnal_khusus WHERE id = ? AND skpd = ?")->execute([$headId, $skpdUser]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    echo json_encode([
        'success' => true,
        'message' => $tpl[$jenis]['judul'] . ' ' . rb_bulan_id($bulan) . ' ' . $tahun . ' dihapus.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// Fallback
// ============================================================
http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.'], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Jangan biarkan error database mematikan seluruh API.
    // Detail error HANYA ke log server - tidak dikirim ke client (keamanan).
    error_log('[AKUNTANSI] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Kesalahan pada server. Silakan coba beberapa saat lagi atau hubungi administrator.',
    ], JSON_UNESCAPED_UNICODE);
}

