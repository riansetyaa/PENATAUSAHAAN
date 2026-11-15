-- ============================================================================
-- SIM-TKD - MIGRASI SKEMA LENGKAP (skip jika sudah ada)
-- ============================================================================
-- Satu file untuk melengkapi database produksi agar semua modul jalan:
--   * AKLAP (peta.simtkd.com): jurnal approve + LRA
--   * Modul Belanja (SPD/SPP/SPM/SP2D/LPJ/TU/NPD/Rekening/UP/Kebijakan)
--   * Multi-tenant (users.kota/provinsi/api_token, kegiatan.skpd)
--
-- SIFAT FILE:
--   * Kolom / index HANYA ditambah bila belum ada (dicek via information_schema).
--   * Tabel memakai CREATE TABLE IF NOT EXISTS (otomatis dilewati bila ada).
--   * Aman dijalankan berulang-ulang.
--
-- CARA PAKAI:
--   mysql -u USER -p simtkdco_sipd < database/migrasi_lengkap.sql
--   atau tempel seluruh isi di phpMyAdmin (tab SQL) pada database simtkdco_sipd.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 0) Prosedur bantu (dibuang lagi di akhir)
--    - _mig_add_col : tambah KOLOM bila belum ada
--    - _mig_add_idx : tambah INDEX bila belum ada
--    Keduanya punya penanganan error (CONTINUE HANDLER) sehingga bila sebuah
--    ALTER gagal (mis. tabel memang belum ada), migrasi tetap lanjut ke baris
--    berikutnya dan hanya mencatat "GAGAL (dilewati)".
-- ----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _mig_add_col;
DROP PROCEDURE IF EXISTS _mig_add_idx;

DELIMITER //

CREATE PROCEDURE _mig_add_col(IN tbl VARCHAR(64), IN col VARCHAR(64), IN ddl TEXT)
BEGIN
    DECLARE gagal INT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION SET gagal = 1;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN ', ddl);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        IF gagal = 1 THEN
            SELECT CONCAT('GAGAL (dilewati): kolom ', tbl, '.', col) AS hasil;
        ELSE
            SELECT CONCAT('DITAMBAHKAN: kolom ', tbl, '.', col) AS hasil;
        END IF;
    ELSE
        SELECT CONCAT('DI-SKIP (sudah ada): kolom ', tbl, '.', col) AS hasil;
    END IF;
END//

CREATE PROCEDURE _mig_add_idx(IN tbl VARCHAR(64), IN idx VARCHAR(64), IN ddl TEXT)
BEGIN
    DECLARE gagal INT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION SET gagal = 1;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND INDEX_NAME = idx
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD ', ddl);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        IF gagal = 1 THEN
            SELECT CONCAT('GAGAL (dilewati): index ', tbl, '.', idx) AS hasil;
        ELSE
            SELECT CONCAT('DITAMBAHKAN: index ', tbl, '.', idx) AS hasil;
        END IF;
    ELSE
        SELECT CONCAT('DI-SKIP (sudah ada): index ', tbl, '.', idx) AS hasil;
    END IF;
END//

CREATE PROCEDURE _mig_add_enum(IN tbl VARCHAR(64), IN col VARCHAR(64), IN ddl TEXT)
BEGIN
    DECLARE gagal INT DEFAULT 0;
    DECLARE vtype TEXT DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION SET gagal = 1;
    SELECT COLUMN_TYPE INTO vtype FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col;
    IF vtype IS NOT NULL AND vtype NOT LIKE '%ditolak%' THEN
        SET @s = CONCAT('ALTER TABLE `', tbl, '` MODIFY COLUMN ', ddl);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
        IF gagal = 1 THEN
            SELECT CONCAT('GAGAL (dilewati): enum ', tbl, '.', col) AS hasil;
        ELSE
            SELECT CONCAT('DITAMBAHKAN: nilai ditolak ', tbl, '.', col) AS hasil;
        END IF;
    ELSE
        SELECT CONCAT('DI-SKIP (sudah ada ditolak): enum ', tbl, '.', col) AS hasil;
    END IF;
END//

DELIMITER ;

-- ============================================================================
-- 1) USERS : kota, provinsi, api_token (multi-tenant / AKLAP)
-- ============================================================================
CALL _mig_add_col('users', 'kota', 'kota VARCHAR(100) DEFAULT NULL AFTER instansi');
CALL _mig_add_col('users', 'provinsi', 'provinsi VARCHAR(100) DEFAULT NULL AFTER kota');
CALL _mig_add_col('users', 'api_token', 'api_token VARCHAR(64) DEFAULT NULL AFTER provinsi');
CALL _mig_add_idx('users', 'idx_api_token', 'UNIQUE KEY idx_api_token (api_token)');

-- Isi token unik untuk pengguna yang belum memilikinya
UPDATE users SET api_token = REPLACE(UUID(), '-', '') WHERE api_token IS NULL OR api_token = '';

-- ============================================================================
-- 2) KEGIATAN : kolom skpd (pemisahan data per instansi)
-- ============================================================================
CALL _mig_add_col('kegiatan', 'skpd', 'skpd VARCHAR(150) DEFAULT NULL AFTER id');
CALL _mig_add_idx('kegiatan', 'idx_kegiatan_skpd', 'INDEX idx_kegiatan_skpd (skpd)');

-- ============================================================================
-- 3) STBP & STS : jurnal_status (persetujuan jurnal AKLAP)
-- ============================================================================
CALL _mig_add_col('stbp', 'jurnal_status', "jurnal_status ENUM('belum_approve','sudah_approve','ditolak') DEFAULT 'belum_approve'");
CALL _mig_add_col('sts',  'jurnal_status', "jurnal_status ENUM('belum_approve','sudah_approve','ditolak') DEFAULT 'belum_approve'");

-- ============================================================================
-- 4) TABEL MODUL BELANJA  (CREATE TABLE IF NOT EXISTS -> otomatis di-skip)
-- ============================================================================

-- 4.1 Daftar Rekanan
CREATE TABLE IF NOT EXISTS rekanan (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED DEFAULT NULL,
    skpd            VARCHAR(150) DEFAULT NULL,
    nama_rekanan    VARCHAR(200) NOT NULL,
    npwp            VARCHAR(30)  DEFAULT NULL,
    alamat          VARCHAR(255) DEFAULT NULL,
    bank            VARCHAR(100) DEFAULT NULL,
    nomor_rekening  VARCHAR(50)  DEFAULT NULL,
    nama_rekening   VARCHAR(150) DEFAULT NULL,
    jenis           ENUM('perusahaan','perseorangan') DEFAULT 'perusahaan',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rekanan_skpd (skpd)
) ENGINE=InnoDB;

-- 4.2 SPD - Surat Penyediaan Dana
CREATE TABLE IF NOT EXISTS spd (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED DEFAULT NULL,
    skpd            VARCHAR(150) DEFAULT NULL,
    nomor_spd       VARCHAR(50)  NOT NULL,
    tanggal         DATE         DEFAULT NULL,
    jenis           VARCHAR(50)  DEFAULT NULL,
    periode         VARCHAR(50)  DEFAULT NULL,
    jumlah          DECIMAL(18,2) DEFAULT 0,
    status          ENUM('belum_otorisasi','sudah_otorisasi') DEFAULT 'belum_otorisasi',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_spd_skpd (skpd)
) ENGINE=InnoDB;

-- 4.3 SPP - Surat Permintaan Pembayaran
CREATE TABLE IF NOT EXISTS spp (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED DEFAULT NULL,
    skpd            VARCHAR(150) DEFAULT NULL,
    nomor_spp       VARCHAR(50)  NOT NULL,
    tanggal         DATE         DEFAULT NULL,
    jenis_spp       VARCHAR(50)  DEFAULT NULL,
    spd_id          INT UNSIGNED DEFAULT NULL,
    rekanan_id      INT UNSIGNED DEFAULT NULL,
    keperluan       VARCHAR(255) DEFAULT NULL,
    jumlah          DECIMAL(18,2) DEFAULT 0,
    status          ENUM('belum_diverifikasi','sudah_diverifikasi') DEFAULT 'belum_diverifikasi',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_spp_skpd (skpd)
) ENGINE=InnoDB;

-- 4.4 SPM - Surat Perintah Membayar
CREATE TABLE IF NOT EXISTS spm (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED DEFAULT NULL,
    skpd            VARCHAR(150) DEFAULT NULL,
    nomor_spm       VARCHAR(50)  NOT NULL,
    tanggal         DATE         DEFAULT NULL,
    spp_id          INT UNSIGNED DEFAULT NULL,
    jumlah          DECIMAL(18,2) DEFAULT 0,
    status          ENUM('belum_disetujui','belum_diverifikasi','sudah_diverifikasi') DEFAULT 'belum_disetujui',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_spm_skpd (skpd)
) ENGINE=InnoDB;

-- 4.5 SP2D - Surat Perintah Pencairan Dana
CREATE TABLE IF NOT EXISTS sp2d (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED DEFAULT NULL,
    skpd            VARCHAR(150) DEFAULT NULL,
    nomor_sp2d      VARCHAR(50)  NOT NULL,
    tanggal         DATE         DEFAULT NULL,
    spm_id          INT UNSIGNED DEFAULT NULL,
    rekening        VARCHAR(100) DEFAULT NULL,
    jumlah          DECIMAL(18,2) DEFAULT 0,
    status          ENUM('belum_diverifikasi','sudah_diverifikasi','sudah_dicairkan') DEFAULT 'belum_diverifikasi',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sp2d_skpd (skpd)
) ENGINE=InnoDB;

-- 4.6 LPJ - Laporan Pertanggungjawaban (referensi SPP GU)
CREATE TABLE IF NOT EXISTS lpj (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd        VARCHAR(150) DEFAULT NULL,
    nomor_lpj   VARCHAR(50)  NOT NULL,
    tanggal     DATE         DEFAULT NULL,
    uraian      VARCHAR(255) DEFAULT NULL,
    jumlah      DECIMAL(18,2) DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lpj_skpd (skpd)
) ENGINE=InnoDB;

-- 4.7 Pengajuan TU - Tambah Uang (referensi SPP TU)
CREATE TABLE IF NOT EXISTS pengajuan_tu (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd            VARCHAR(150) DEFAULT NULL,
    nomor_pengajuan VARCHAR(50)  NOT NULL,
    tanggal         DATE         DEFAULT NULL,
    keterangan      VARCHAR(255) DEFAULT NULL,
    jumlah          DECIMAL(18,2) DEFAULT 0,
    status          ENUM('belum_otorisasi','sudah_otorisasi','sudah_divalidasi') DEFAULT 'belum_otorisasi',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ptu_skpd (skpd)
) ENGINE=InnoDB;

-- 4.8 Potongan & Pajak detail per SPP (LS Barang & Jasa)
CREATE TABLE IF NOT EXISTS spp_potongan_pajak (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd        VARCHAR(150) DEFAULT NULL,
    spp_id      INT UNSIGNED DEFAULT NULL,
    jenis       ENUM('potongan','pajak') DEFAULT 'potongan',
    nama        VARCHAR(150) DEFAULT NULL,
    nilai_persen DECIMAL(5,2) DEFAULT 0,
    nilai       DECIMAL(18,2) DEFAULT 0,
    id_billing  VARCHAR(100) DEFAULT NULL,
    tgl_billing DATE         DEFAULT NULL,
    ntpn        VARCHAR(100) DEFAULT NULL,
    tgl_ntpn    DATE         DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pp_spp (spp_id)
) ENGINE=InnoDB;

-- 4.8b Rincian detail per SPP (kode rekening belanja + uraian + jumlah) -- SPP LS Gaji multi-baris
CREATE TABLE IF NOT EXISTS spp_detail (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd          VARCHAR(150) DEFAULT NULL,
    spp_id        INT UNSIGNED DEFAULT NULL,
    kode_rekening VARCHAR(50)  DEFAULT NULL,
    uraian        VARCHAR(255) DEFAULT NULL,
    jumlah        DECIMAL(18,2) DEFAULT 0,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sd_spp (spp_id)
) ENGINE=InnoDB;

-- 4.9 Rekening Bank SKPD
CREATE TABLE IF NOT EXISTS rekening_skpd (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd           VARCHAR(150) DEFAULT NULL,
    user_id        INT UNSIGNED DEFAULT NULL,
    bank           VARCHAR(100) DEFAULT NULL,
    nama_pemilik   VARCHAR(150) DEFAULT NULL,
    nomor_rekening VARCHAR(50)  DEFAULT NULL,
    status         ENUM('permohonan','pengajuan','pembuatan','aktif','ditolak') DEFAULT 'permohonan',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rek_skpd (skpd)
) ENGINE=InnoDB;

-- 4.10 Besaran UP (Uang Persediaan)
CREATE TABLE IF NOT EXISTS besaran_up (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd           VARCHAR(150) DEFAULT NULL,
    tahun          VARCHAR(10)  DEFAULT NULL,
    besaran_up     DECIMAL(18,2) DEFAULT 0,
    besaran_up_kkpd DECIMAL(18,2) DEFAULT 0,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_bu_skpd (skpd)
) ENGINE=InnoDB;

-- 4.11 Kebijakan SPD
CREATE TABLE IF NOT EXISTS kebijakan_spd (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd            VARCHAR(150) DEFAULT NULL,
    jenis_penerbitan VARCHAR(100) DEFAULT NULL,
    jenis_periode   VARCHAR(100) DEFAULT NULL,
    tanggal         DATE         DEFAULT NULL,
    status          ENUM('aktif','nonaktif') DEFAULT 'aktif',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_kspd_skpd (skpd)
) ENGINE=InnoDB;

-- 4.12 NPD - Nota Pencairan Dana
CREATE TABLE IF NOT EXISTS npd (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd         VARCHAR(150) DEFAULT NULL,
    user_id      INT UNSIGNED DEFAULT NULL,
    nomor_npd    VARCHAR(50)  NOT NULL,
    tanggal      DATE         DEFAULT NULL,
    metode       ENUM('panjar','non_panjar') DEFAULT 'panjar',
    keterangan   VARCHAR(255) DEFAULT NULL,
    kegiatan     VARCHAR(200) DEFAULT NULL,
    sub_kegiatan VARCHAR(200) DEFAULT NULL,
    jumlah       DECIMAL(18,2) DEFAULT 0,
    status       ENUM('diajukan','divalidasi_pa','divalidasi_bp','ditolak') DEFAULT 'diajukan',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_npd_skpd (skpd)
) ENGINE=InnoDB;

-- 4.13 Anggaran LRA (setting anggaran per akun utk Laporan Realisasi Anggaran)
CREATE TABLE IF NOT EXISTS anggaran_lra (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd        VARCHAR(150) DEFAULT NULL,
    tahun       VARCHAR(10)  DEFAULT NULL,
    kode_akun   VARCHAR(50)  DEFAULT NULL,
    nama_akun   VARCHAR(200) DEFAULT NULL,
    anggaran    DECIMAL(18,2) DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_al_skpd (skpd)
) ENGINE=InnoDB;

-- 4.14 Neraca Awal (setting saldo awal utk Neraca / saldo pembukuan)
CREATE TABLE IF NOT EXISTS neraca_awal (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd        VARCHAR(150) DEFAULT NULL,
    tahun       VARCHAR(10)  DEFAULT NULL,
    kode_akun   VARCHAR(50)  DEFAULT NULL,
    nama_akun   VARCHAR(200) DEFAULT NULL,
    saldo       DECIMAL(18,2) DEFAULT 0,
    jenis       ENUM('aset','kewajiban','ekuitas') DEFAULT 'aset',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_na_skpd (skpd)
) ENGINE=InnoDB;

-- ============================================================================
-- 5) KOLOM TAMBAHAN (Belanja v2 + jurnal belanja AKLAP)
--    dijalankan SETELAH tabel di atas dibuat, agar tabel tersedia.
-- ============================================================================

-- 5.1 SPP : kolom GU/TU + total potongan/pajak
CALL _mig_add_col('spp', 'lpj_id', 'lpj_id INT UNSIGNED DEFAULT NULL AFTER rekanan_id');
CALL _mig_add_col('spp', 'pengajuan_tu_id', 'pengajuan_tu_id INT UNSIGNED DEFAULT NULL AFTER lpj_id');
CALL _mig_add_col('spp', 'total_potongan', 'total_potongan DECIMAL(18,2) DEFAULT 0 AFTER jumlah');
CALL _mig_add_col('spp', 'total_pajak', 'total_pajak DECIMAL(18,2) DEFAULT 0 AFTER total_potongan');

-- 5.2 SP2D : jurnal_status (persetujuan jurnal belanja di AKLAP)
CALL _mig_add_col('sp2d', 'jurnal_status', "jurnal_status ENUM('belum_approve','sudah_approve','ditolak') DEFAULT 'belum_approve'");

-- 5.3 Status SPP/SPM/SP2D : tambah nilai 'ditolak' (fitur tolak)
CALL _mig_add_enum('spp',  'status', "status ENUM('belum_diverifikasi','sudah_diverifikasi','ditolak') DEFAULT 'belum_diverifikasi'");
CALL _mig_add_enum('spm',  'status', "status ENUM('belum_disetujui','belum_diverifikasi','sudah_diverifikasi','ditolak') DEFAULT 'belum_disetujui'");
CALL _mig_add_enum('sp2d', 'status', "status ENUM('belum_diverifikasi','sudah_diverifikasi','sudah_dicairkan','ditolak') DEFAULT 'belum_diverifikasi'");

-- ============================================================================
-- 6) BERSIHKAN PROSEDUR BANTU
-- ============================================================================
DROP PROCEDURE IF EXISTS _mig_add_col;
DROP PROCEDURE IF EXISTS _mig_add_idx;
DROP PROCEDURE IF EXISTS _mig_add_enum;

-- ============================================================================
-- SELESAI. Periksa laporan hasil di atas (DITAMBAHKAN / DI-SKIP / GAGAL).
-- ============================================================================
