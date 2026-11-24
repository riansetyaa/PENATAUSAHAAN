-- ============================================================
-- SIM-TKD - ALTER Tabel Users (tambahkan kolom yang belum ada)
-- ============================================================
-- Untuk database yang SUDAH punya tabel users (mis. produksi simtkdco_sipd)
-- tapi belum memiliki kolom kota, provinsi, dan api_token.
-- Idempoten (IF NOT EXISTS) -> aman dijalankan berulang.
--
-- Cara pakai (MySQL/MariaDB 10+):
--   mysql -u USER -p simtkdco_sipd < users_alter.sql
-- atau tempel di phpMyAdmin (tab SQL) pada database simtkdco_sipd.
-- ============================================================

-- Tambahkan kolom yang belum ada
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS kota      VARCHAR(100) DEFAULT NULL AFTER instansi,
    ADD COLUMN IF NOT EXISTS provinsi  VARCHAR(100) DEFAULT NULL AFTER kota,
    ADD COLUMN IF NOT EXISTS api_token VARCHAR(64)  DEFAULT NULL AFTER provinsi;

-- Isi token API unik untuk pengguna yang belum memilikinya.
-- PENTING: yang disimpan adalah HASH SHA-256 (64 hex), bukan token mentah.
-- SHA2() menghasilkan hex sha256 dengan format yang sama dengan
-- hash('sha256', $token) di PHP (includes/functions.php).
-- Versi lama memakai UUID mentah (32 hex) sehingga bergantung pada jalur
-- migrasi token legacy yang kini sudah dinonaktifkan.
UPDATE users SET api_token = SHA2(REPLACE(UUID(), '-', ''), 256)
 WHERE api_token IS NULL OR api_token = '' OR CHAR_LENGTH(api_token) <> 64;

-- Token admin TIDAK lagi di-hardcode di sini.
-- Setiap admin mendapat token unik pada login pertama (rotasi per-login
-- di api/login.php). Token yang pernah bocor ke riwayat git sudah tidak
-- berlaku karena jalur token legacy dinonaktifkan.

-- Index unik api_token (jalankan sekali; abaikan error "Duplicate key name" bila sudah ada)
SET @s = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_api_token') = 0,
    'ALTER TABLE users ADD UNIQUE KEY idx_api_token (api_token)',
    'SELECT 1'
);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
