-- ============================================================
-- SIM-TKD - DEPRECATED: Aktivasi Otomatis Akun
-- ============================================================
-- ⚠️  JANGAN dijalankan lagi.
--
-- Skrip ini berasal dari alur LAMA (pendaftaran langsung aktif / semua akun
-- lama dipaksa aktif). Sejak alur verifikasi OTP diterapkan, akun baru
-- berstatus 'pending' dan hanya boleh aktif setelah kode OTP yang dikirim
-- ke email pengguna diverifikasi.
--
-- Menjalankan UPDATE di bawah akan MENGAKTIFKAN kembali akun yang belum
-- terverifikasi — jadi perintahnya sudah DIHAPUS.
--
-- Gunakan sebagai gantinya:
--   database/users_otp.sql   -> kolom OTP + default status 'pending'
--   api/aktivasi.php         -> endpoint verifikasi kode OTP
--   aktivasi.html            -> halaman aktivasi akun bagi pengguna
--
-- Setiap pengguna yang sah dapat mengaktifkan akunnya sendiri di aktivasi.html
-- (masukkan email -> periksa nama & instansi -> konfirmasi -> kode OTP).
-- ============================================================

-- 1. Default kolom status untuk pendaftaran baru: menunggu verifikasi OTP
ALTER TABLE users ALTER COLUMN status SET DEFAULT 'pending';

-- 2. (DIHAPUS) Aktifkan seluruh akun lama yang masih 'pending'
--    UPDATE users SET status = 'aktif' WHERE status = 'pending';

-- 3. (OPSIONAL) Bila ingin seluruh akun lama ikut verifikasi OTP:
--    UPDATE users SET status = 'pending', otp_hash = NULL, otp_expired_at = NULL,
--                     otp_percobaan = 0
--    WHERE status = 'aktif';

