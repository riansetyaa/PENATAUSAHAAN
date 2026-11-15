-- ============================================================
-- SIM-TKD - Migrasi Pelacakan Login (Monitoring Pengguna)
-- ============================================================
-- Halaman Pengaturan -> Monitoring Pengguna membutuhkan catatan:
--   - kapan akun terakhir login,
--   - berapa kali akun login,
--   - IP terakhir,
--   - aktivitas terakhir.
--
-- Sebelum fitur ini, api/login.php hanya merotasi api_token sehingga
-- tidak ada catatan waktu masuk. Kolom di bawah diisi otomatis pada
-- setiap login (api/login.php) dan heartbeat sesi (api/session.php).
--
-- Catatan: ensureLoginTracking() di includes/functions.php juga
-- menambahkan kolom ini otomatis saat login/sesi diakses. Script ini
-- hanya diperlukan bila ingin migrasi manual lebih dulu.
--
-- Cara pakai (MySQL/MariaDB):
--   mysql -u USER -p NAMA_DATABASE < users_login_tracking.sql
-- atau tempel di phpMyAdmin (tab SQL). Jalankan SEKALI.
-- ============================================================

ALTER TABLE users
    ADD COLUMN last_login_at    DATETIME     DEFAULT NULL AFTER otp_verifikasi_at,
    ADD COLUMN login_count      INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_login_at,
    ADD COLUMN last_ip          VARCHAR(45)  DEFAULT NULL AFTER login_count,
    ADD COLUMN last_activity_at DATETIME     DEFAULT NULL AFTER last_ip;

-- Backfill perkiraan untuk akun lama.
-- Login lama merotasi api_token (UPDATE users), sehingga updated_at ikut
-- berubah. Akun yang belum pernah login memiliki updated_at = created_at
-- (atau selisih sangat kecil), jadi hanya selisih > 1 menit yang diambil.
-- Akun berstatus pending sengaja dilewati agar aktivasi OTP tidak
-- dianggap sebagai login.
UPDATE users
SET last_login_at    = updated_at,
    last_activity_at = updated_at,
    login_count      = 1
WHERE status = 'aktif'
  AND api_token IS NOT NULL
  AND api_token <> ''
  AND updated_at > DATE_ADD(created_at, INTERVAL 1 MINUTE);
