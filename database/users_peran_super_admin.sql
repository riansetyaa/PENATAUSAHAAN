-- ============================================================
-- SIM-TKD - Tambahkan peran "Super Admin" ke ENUM users.peran
-- ============================================================
-- Peran Super Admin dipakai akun pengelola utama (username `admin`) agar
-- dapat melihat dan mengelola pengguna lintas instansi, termasuk
-- memberikan peran Super Admin kepada akun lain.
-- Idempoten: aman dijalankan berulang.
--
-- Cara pakai:
--   mysql -u USER -p NAMA_DATABASE < database/users_peran_super_admin.sql
-- atau tempel di phpMyAdmin (tab SQL).
--
-- Aplikasi juga menjalankan ALTER yang sama secara otomatis lewat
-- ensurePeranSuperAdmin() di includes/functions.php saat endpoint
-- memanggil ensureUsersColumns(), lalu memastikan akun `admin` berperan
-- Super Admin.
-- ============================================================

SET @ada_super_admin = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'peran'
      AND COLUMN_TYPE LIKE '%''Super Admin''%'
);

SET @sql = IF(
    @ada_super_admin = 0,
    "ALTER TABLE `users` MODIFY COLUMN `peran` ENUM('Super Admin','Admin Dinas','Operator','Bendahara','Verifikator','Kepala Dinas','Dosen','Pengguna Umum') DEFAULT 'Pengguna Umum'",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Naikkan akun pengelola utama menjadi Super Admin.
UPDATE `users` SET `peran` = 'Super Admin' WHERE `username` = 'admin';
