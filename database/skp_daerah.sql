-- ============================================================
-- SIM-TKD - MODUL SKP DAERAH & TANDA TANGAN (alur Penerimaan)
-- ============================================================
-- Menambahkan struktur yang dibutuhkan alur:
--   SKP Daerah -> STBP -> STS -> BKU Penerimaan
--
--   1. tabel skp_daerah  (Surat Ketetapan Pajak Daerah)
--   2. stbp.skp_daerah_id (+ index idx_skp) -> STBP merujuk SKP Daerah
--   3. sts.kuasa_pengguna_anggaran          -> penandatangan ke-2 STS
--   4. dokumen, dokumen_ttd, user_ttd       -> antrean TTD doc.simtkd.com
--
-- Cara pakai (MySQL 5.7+ / MySQL 8 / MariaDB):
--   mysql -u USER -p NAMA_DATABASE < skp_daerah.sql
-- atau impor file ini lewat phpMyAdmin (tab Import / tab SQL).
--
-- IDEMPOTEN: setiap CREATE/ALTER hanya dijalankan bila struktur belum
-- ada (dicek via information_schema), sehingga aman diimpor berulang.
-- Catatan: tabel dokumen/dokumen_ttd/user_ttd juga tercakup di
-- dokumen_ttd.sql — karena memakai CREATE TABLE IF NOT EXISTS, keduanya
-- tidak saling bertabrakan.
-- ============================================================

SET NAMES utf8mb4;
SET @db := DATABASE();

-- ------------------------------------------------------------
-- 1) Tabel skp_daerah (Surat Ketetapan Pajak Daerah)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS skp_daerah (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED      DEFAULT NULL,          -- petugas pembuat SKP
    skpd             VARCHAR(150)      NOT NULL DEFAULT '',   -- instansi (multi-tenant)
    nomor_skp        VARCHAR(50)       NOT NULL,
    tanggal          DATE              NOT NULL,
    jenis_pajak      VARCHAR(120)      NOT NULL DEFAULT '',
    nama_penyetor    VARCHAR(150)      NOT NULL DEFAULT '',   -- WAJIB; sumber nama penyetor STBP/STS
    objek_pajak      VARCHAR(255)      NOT NULL DEFAULT '',
    nilai_keputusan  DECIMAL(18,2)     NOT NULL DEFAULT 0,
    masa_pajak_dari  DATE              DEFAULT NULL,
    masa_pajak_akhir DATE              DEFAULT NULL,
    jatuh_tempo      DATE              DEFAULT NULL,
    keterangan       VARCHAR(255)      NOT NULL DEFAULT '',
    status           ENUM('aktif','terpakai','dihapus') NOT NULL DEFAULT 'aktif',
    created_at       TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_tanggal (tanggal),
    INDEX idx_user (user_id),
    INDEX idx_nomor (nomor_skp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1b) skp_daerah.akun_kode — jenis pajak SKP terikat ke Akun Penerimaan (Pengaturan)
SET @kolom_ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skp_daerah' AND COLUMN_NAME = 'akun_kode');
SET @sql := IF(@kolom_ada = 0,
    'ALTER TABLE `skp_daerah` ADD COLUMN `akun_kode` VARCHAR(50) NOT NULL DEFAULT '''' AFTER `jenis_pajak`',
    'SELECT ''-- lewati: skp_daerah.akun_kode sudah ada'' AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1c) skp_daerah.alamat & npwpd — identitas wajib pajak untuk dokumen resmi SKP
SET @kolom_ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skp_daerah' AND COLUMN_NAME = 'alamat');
SET @sql := IF(@kolom_ada = 0,
    'ALTER TABLE `skp_daerah` ADD COLUMN `alamat` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `nama_penyetor`',
    'SELECT ''-- lewati: skp_daerah.alamat sudah ada'' AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @kolom_ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skp_daerah' AND COLUMN_NAME = 'npwpd');
SET @sql := IF(@kolom_ada = 0,
    'ALTER TABLE `skp_daerah` ADD COLUMN `npwpd` VARCHAR(50) NOT NULL DEFAULT '''' AFTER `alamat`',
    'SELECT ''-- lewati: skp_daerah.npwpd sudah ada'' AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2) stbp.skp_daerah_id + index (STBP merujuk SKP Daerah)
-- ------------------------------------------------------------
SET @stbp_ada := (SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'stbp');
SET @kolom_ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'stbp' AND COLUMN_NAME = 'skp_daerah_id');
SET @sql := IF(@stbp_ada = 1 AND @kolom_ada = 0,
    'ALTER TABLE `stbp` ADD COLUMN `skp_daerah_id` INT UNSIGNED DEFAULT NULL AFTER `user_id`',
    'SELECT ''-- lewati: stbp.skp_daerah_id sudah ada / tabel stbp belum ada'' AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @index_ada := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'stbp' AND INDEX_NAME = 'idx_skp');
SET @sql := IF(@stbp_ada = 1 AND @index_ada = 0,
    'ALTER TABLE `stbp` ADD INDEX `idx_skp` (`skp_daerah_id`)',
    'SELECT ''-- lewati: index idx_skp di stbp sudah ada / tabel stbp belum ada'' AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3) sts.kuasa_pengguna_anggaran (penandatangan ke-2 STS)
-- ------------------------------------------------------------
SET @sts_ada := (SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sts');
SET @kolom_ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sts' AND COLUMN_NAME = 'kuasa_pengguna_anggaran');
SET @sql := IF(@sts_ada = 1 AND @kolom_ada = 0,
    'ALTER TABLE `sts` ADD COLUMN `kuasa_pengguna_anggaran` VARCHAR(150) NOT NULL DEFAULT '''' AFTER `mengetahui`',
    'SELECT ''-- lewati: sts.kuasa_pengguna_anggaran sudah ada / tabel sts belum ada'' AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 4) Tabel antrean tanda tangan elektronik (doc.simtkd.com)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dokumen (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id            INT UNSIGNED      DEFAULT NULL,              -- pembuat / pemilik dokumen
    skpd               VARCHAR(150)      NOT NULL DEFAULT '',       -- instansi (multi-tenant)
    jenis              VARCHAR(50)       NOT NULL DEFAULT 'Dokumen',-- SKP / STBP / STS / dst
    ref_id             INT UNSIGNED      DEFAULT NULL,              -- id baris sumber (stbp.id, sts.id, dst)
    nomor              VARCHAR(100)      NOT NULL DEFAULT '',
    judul              VARCHAR(200)      NOT NULL DEFAULT '',
    tanggal            DATE              DEFAULT NULL,
    konten_html        LONGTEXT          NOT NULL,                  -- HTML dokumen (CSS tertanam)
    hash_original      CHAR(64)          NOT NULL DEFAULT '',       -- SHA-256 konten_html saat dikirim
    kode_verifikasi    VARCHAR(20)       NOT NULL,                  -- kode unik cek keaslian
    status             ENUM('menunggu_ttd','ditandatangani') NOT NULL DEFAULT 'menunggu_ttd',
    konten_html_signed LONGTEXT          DEFAULT NULL,              -- HTML final setelah ditandatangani
    hash_signed        CHAR(64)          DEFAULT NULL,
    signed_at          DATETIME          DEFAULT NULL,
    created_at         TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_kode (kode_verifikasi),
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_ref (jenis, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dokumen_ttd (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dokumen_id INT UNSIGNED      NOT NULL,
    user_id    INT UNSIGNED      DEFAULT NULL,     -- NULL = penandatangan non-user (mis. Penyetor / KPA)
    urutan     INT UNSIGNED      NOT NULL DEFAULT 1,
    jabatan    VARCHAR(100)      NOT NULL DEFAULT '',
    nama       VARCHAR(150)      NOT NULL DEFAULT '',
    status     ENUM('menunggu','ditandatangani') NOT NULL DEFAULT 'menunggu',
    signed_at  DATETIME          DEFAULT NULL,
    ip         VARCHAR(45)       DEFAULT NULL,
    user_agent VARCHAR(255)      DEFAULT NULL,
    UNIQUE KEY uq_dokumen_user_urutan (dokumen_id, user_id, urutan),
    INDEX idx_dokumen (dokumen_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_ttd (
    user_id         INT UNSIGNED PRIMARY KEY,   -- pemilik gambar tanda tangan
    gambar          LONGTEXT      NOT NULL,     -- data URL (PNG) tanda tangan
    dibuat_pada     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    diperbarui_pada TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Verifikasi ringkas (hasil terlihat di output impor)
-- ------------------------------------------------------------
SELECT 'skp_daerah'                    AS struktur, COUNT(*) AS ada FROM information_schema.TABLES  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skp_daerah'
UNION ALL
SELECT 'skp_daerah.akun_kode',                COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skp_daerah' AND COLUMN_NAME = 'akun_kode'
UNION ALL
SELECT 'skp_daerah.alamat',                  COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skp_daerah' AND COLUMN_NAME = 'alamat'
UNION ALL
SELECT 'skp_daerah.npwpd',                   COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skp_daerah' AND COLUMN_NAME = 'npwpd'
UNION ALL
SELECT 'stbp.skp_daerah_id',                  COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'stbp' AND COLUMN_NAME = 'skp_daerah_id'
UNION ALL
SELECT 'stbp INDEX idx_skp',                  COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'stbp' AND INDEX_NAME = 'idx_skp'
UNION ALL
SELECT 'sts.kuasa_pengguna_anggaran',         COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sts' AND COLUMN_NAME = 'kuasa_pengguna_anggaran'
UNION ALL
SELECT 'dokumen',                             COUNT(*) FROM information_schema.TABLES  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'dokumen'
UNION ALL
SELECT 'dokumen_ttd',                         COUNT(*) FROM information_schema.TABLES  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'dokumen_ttd'
UNION ALL
SELECT 'user_ttd',                            COUNT(*) FROM information_schema.TABLES  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'user_ttd';
-- nilai `ada` = 1 berarti struktur sudah siap dipakai.
