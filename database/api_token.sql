-- ============================================================
-- SIM-TKD - TAMBAH KOLOM API TOKEN (multi-tenant AKLAP)
-- ============================================================
-- File khusus untuk menambahkan kolom api_token pada tabel users.
-- Aman dijalankan berulang (idempoten).
--
-- Cara pakai (MariaDB/MySQL):
--   mysql -u USER -p simtkdco_sipd < api_token.sql
-- atau tempel di phpMyAdmin (tab SQL) pada database simtkdco_sipd.
-- ============================================================

-- 1) Tambahkan kolom api_token bila belum ada (mendukung MariaDB 10.0.2+)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS api_token VARCHAR(64) DEFAULT NULL;

-- 2) Isi token unik untuk semua user yang masih kosong.
--    PENTING: kolom api_token menyimpan HASH SHA-256 (64 hex), bukan token mentah.
--    SHA2() menghasilkan hex sha256 dengan format yang sama dengan
--    hash('sha256', $token) di PHP (includes/functions.php). Token mentah
--    hanya dipegang user pada saat login (api/login.php) lalu dirotasi
--    setiap kali login.
--    Versi lama memakai UUID mentah (32 hex) sehingga bergantung pada jalur
--    migrasi token legacy yang kini sudah dinonaktifkan.
UPDATE users SET api_token = SHA2(REPLACE(UUID(), '-', ''), 256)
 WHERE api_token IS NULL OR api_token = '' OR CHAR_LENGTH(api_token) <> 64;

-- 3) Token admin TIDAK lagi di-hardcode di sini.
--    Setiap admin mendapat token unik pada login pertama (rotasi per-login
--    di api/login.php). Token yang pernah bocor ke riwayat git sudah tidak
--    berlaku karena jalur token legacy dinonaktifkan.

-- 4) Index unik agar token tidak kembar (abaikan bila sudah ada)
SET @s = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_api_token') = 0,
    'ALTER TABLE users ADD UNIQUE KEY idx_api_token (api_token)',
    'SELECT 1'
);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
