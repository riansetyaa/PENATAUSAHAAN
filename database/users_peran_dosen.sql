-- ============================================================
-- SIM-TKD - Tambahkan peran "Dosen" ke ENUM users.peran
-- ============================================================
-- Manual book dan halaman Pengaturan -> Monitoring Pengguna memakai peran
-- Dosen, tetapi ENUM lama belum memuatnya. Tanpa ALTER ini opsi Dosen
-- tidak bisa disimpan. Idempoten: aman dijalankan berulang.
--
-- Cara pakai:
--   mysql -u USER -p NAMA_DATABASE < database/users_peran_dosen.sql
-- atau tempel di phpMyAdmin (tab SQL).
--
-- Aplikasi juga menjalankan ALTER yang sama secara otomatis lewat
-- ensurePeranDosen() di includes/functions.php saat endpoint memanggil
-- ensureUsersColumns().
-- ============================================================

SET @ada_dosen = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'peran'
      AND COLUMN_TYPE LIKE '%''Dosen''%'
);

SET @sql = IF(
    @ada_dosen = 0,
    "ALTER TABLE `users` MODIFY COLUMN `peran` ENUM('Admin Dinas','Operator','Bendahara','Verifikator','Kepala Dinas','Dosen','Pengguna Umum') DEFAULT 'Pengguna Umum'",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
