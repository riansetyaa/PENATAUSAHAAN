-- ============================================================
-- SIM-TKD - ALTER Tabel Users (tambahkan kolom NIP)
-- ============================================================
-- Menambahkan kolom `nip` pada tabel users. NIP dipakai sebagai
-- identitas penandatangan pada SEMUA blok tanda tangan dokumen
-- (BKU, Buku Pembantu, SKP, STS, STBP, SPP/SPM/SP2D, dst) dan
-- dapat diisi pengguna melalui halaman Pengaturan > Profil.
--
-- Idempoten (IF NOT EXISTS) -> aman dijalankan berulang.
--
-- Cara pakai:
--   mysql -u USER -p NAMADB < users_nip.sql
-- atau tempel di phpMyAdmin (tab SQL).
-- ============================================================

-- Tambahkan kolom nip bila belum ada (idempoten, aman diulang)
SET @ada := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'nip'
);
SET @s := IF(@ada = 0,
    'ALTER TABLE users ADD COLUMN nip VARCHAR(30) DEFAULT NULL AFTER nama_lengkap',
    'SELECT ''kolom nip sudah ada'' AS info'
);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
