-- ============================================================
-- SIM-TKD - SQL TAMBAHAN (PATCH) MODUL SKP DAERAH
-- ============================================================
-- Kolom tambahan untuk dokumen SKP Daerah format resmi:
--   1. skp_daerah.akun_kode -> kode akun penerimaan terpilih (Jenis Pajak)
--   2. skp_daerah.alamat    -> alamat wajib pajak (kop dokumen)
--   3. skp_daerah.npwpd     -> NPWPD wajib pajak (kop dokumen + tanda terima)
--
-- Untuk database yang SUDAH pernah mengimpor skp_daerah.sql versi awal.
-- (Database baru/bersih cukup mengimpor skp_daerah.sql lengkap.)
--
-- Cara pakai (MySQL 5.7+ / MySQL 8 / MariaDB):
--   mysql -u USER -p NAMA_DATABASE < skp_daerah_tambahan.sql
-- atau impor lewat phpMyAdmin (tab Import / tab SQL).
--
-- IDEMPOTEN: setiap ALTER hanya dijalankan bila kolom belum ada
-- (dicek via information_schema), sehingga aman diimpor berulang.
-- ============================================================

SET NAMES utf8mb4;
SET @db := DATABASE();

-- ------------------------------------------------------------
-- 1) skp_daerah.alamat — alamat wajib pajak (kop dokumen SKP)
--    WAJIB dijalankan lebih dulu (npwpd ditempatkan AFTER alamat)
-- ------------------------------------------------------------
SET @kolom_ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skp_daerah' AND COLUMN_NAME = 'alamat');
SET @tbl_ada := (SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skp_daerah');
SET @sql := IF(@tbl_ada = 1 AND @kolom_ada = 0,
    'ALTER TABLE `skp_daerah` ADD COLUMN `alamat` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `nama_penyetor`',
    'SELECT ''-- lewati: skp_daerah.alamat sudah ada / tabel skp_daerah belum ada'' AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2) skp_daerah.npwpd — Nomor Pokok Wajib Pajak Daerah
-- ------------------------------------------------------------
SET @kolom_ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skp_daerah' AND COLUMN_NAME = 'npwpd');
SET @sql := IF(@tbl_ada = 1 AND @kolom_ada = 0,
    'ALTER TABLE `skp_daerah` ADD COLUMN `npwpd` VARCHAR(50) NOT NULL DEFAULT '''' AFTER `alamat`',
    'SELECT ''-- lewati: skp_daerah.npwpd sudah ada / tabel skp_daerah belum ada'' AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3) skp_daerah.akun_kode — kode akun penerimaan dari Pengaturan
-- ------------------------------------------------------------
SET @kolom_ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skp_daerah' AND COLUMN_NAME = 'akun_kode');
SET @sql := IF(@tbl_ada = 1 AND @kolom_ada = 0,
    'ALTER TABLE `skp_daerah` ADD COLUMN `akun_kode` VARCHAR(50) NOT NULL DEFAULT '''' AFTER `jenis_pajak`',
    'SELECT ''-- lewati: skp_daerah.akun_kode sudah ada / tabel skp_daerah belum ada'' AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- Verifikasi ringkas (nilai `ada` = 1 berarti kolom siap dipakai)
-- ------------------------------------------------------------
SELECT 'skp_daerah.alamat'    AS struktur, COUNT(*) AS ada FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skp_daerah' AND COLUMN_NAME = 'alamat'
UNION ALL
SELECT 'skp_daerah.npwpd',           COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skp_daerah' AND COLUMN_NAME = 'npwpd'
UNION ALL
SELECT 'skp_daerah.akun_kode',       COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'skp_daerah' AND COLUMN_NAME = 'akun_kode';
