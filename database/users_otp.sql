-- ============================================================
-- SIM-TKD - Migrasi Verifikasi OTP (Aktivasi Akun)
-- ============================================================
-- Akun hasil pendaftaran kini berstatus 'pending' dan diaktifkan
-- melalui halaman Aktivasi Akun (aktivasi.html -> api/aktivasi.php):
--   1. pengguna memasukkan email  -> data nama akun & instansi ditampilkan
--   2. klik Konfirmasi            -> kode OTP dikirim ke email
--   3. kode OTP benar             -> status akun menjadi 'aktif'
--
-- Script ini menambahkan kolom penyimpan kode OTP + mengubah default
-- kolom status menjadi 'pending'. Akun yang SUDAH ada TIDAK diubah
-- (tetap aktif) — baris UPDATE di bagian akhir sengaja dikomentari.
--
-- Catatan: api/login.php, api/register.php, dan api/aktivasi.php juga
-- menambahkan kolom ini otomatis lewat ensureUsersColumns() saat diakses,
-- jadi script ini hanya diperlukan bila ingin migrasi manual lebih dulu.
--
-- Cara pakai (MySQL/MariaDB):
--   mysql -u USER -p NAMA_DATABASE < users_otp.sql
-- atau tempel di phpMyAdmin (tab SQL).
-- ============================================================

-- 1. Default status: pendaftaran baru menunggu verifikasi OTP
ALTER TABLE users ALTER COLUMN status SET DEFAULT 'pending';

-- 2. Kolom penyimpanan kode OTP aktivasi
ALTER TABLE users
    ADD COLUMN otp_hash        VARCHAR(255) DEFAULT NULL AFTER api_token,
    ADD COLUMN otp_expired_at  DATETIME     DEFAULT NULL AFTER otp_hash,
    ADD COLUMN otp_terkirim_at DATETIME     DEFAULT NULL AFTER otp_expired_at,
    ADD COLUMN otp_percobaan   TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER otp_terkirim_at,
    ADD COLUMN otp_verifikasi_at DATETIME   DEFAULT NULL AFTER otp_percobaan;

-- 3. (OPSIONAL) Bila ingin seluruh akun lama ikut diverifikasi ulang lewat OTP:
-- UPDATE users SET status = 'pending', otp_hash = NULL, otp_expired_at = NULL,
--                  otp_percobaan = 0
-- WHERE status = 'aktif';
--
-- 4. (OPSIONAL) Bersihkan sisa kode OTP yang tidak terpakai:
-- UPDATE users SET otp_hash = NULL, otp_expired_at = NULL, otp_percobaan = 0
-- WHERE status <> 'pending';
