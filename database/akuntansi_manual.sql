-- ============================================================
-- SIM-TKD - MODEL AKUNTANSI MANUAL (Double-Entry) / AKLAP
-- ============================================================
-- Jurnal Umum dua sisi (Debet/Kredit) yang diinput MANUAL, dengan
-- saran template jurnal dari dokumen modul Penerimaan (STS) dan
-- modul Belanja (SP2D) yang sudah ada.
--
-- Mengikuti lembar kerja Modul 3 AKLAP SKPKD:
--   * Tugas 1 - Jurnal Umum   (Debet / Kredit)
--   * Tugas 2 - Buku Besar    (posting per akun + saldo)
--   * Neraca Saldo            (rekap mutasi per akun)
--
-- Referensi kode akun: Lampiran 1 Perwali No. 112 Th. 2018 (BSA).
--
-- Multi-tenant: setiap baris memakai kolom skpd = instansi user.
-- Jalankan setelah database/simtkd.sql, belanja.sql, belanja_v2.sql.
-- File ini idempoten (aman dijalankan berulang-ulang).
-- ============================================================

-- ------------------------------------------------------------
-- 1) Master Akun (Chart of Accounts, seed default oleh API)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS akun_master (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd         VARCHAR(150) NOT NULL DEFAULT '',
    kode_akun    VARCHAR(50)  NOT NULL,
    nama_akun    VARCHAR(200) NOT NULL,
    tipe         ENUM('aset','liabilitas','ekuitas','pendapatan','belanja') NOT NULL DEFAULT 'aset',
    saldo_normal ENUM('debet','kredit') NOT NULL DEFAULT 'debet',
    aktif        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_akun_skpd (skpd, kode_akun),
    INDEX idx_akun_kode (kode_akun)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 2) Jurnal Umum (header)
--    sumber_tipe  : manual (diketik penuh) / sts / sp2d (template)
--    sumber_id    : id dokumen sumber di tabel sts / sp2d
--    status       : draft -> sudah_approve / ditolak (pembukuan)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jurnal_umum (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd         VARCHAR(150) NOT NULL DEFAULT '',
    user_id      INT UNSIGNED DEFAULT NULL,
    nomor_jurnal VARCHAR(60)  NOT NULL DEFAULT '',   -- otomatis: JU-YYYYMM-0001
    tanggal      DATE         NULL,
    uraian       VARCHAR(255) NOT NULL DEFAULT '',
    sumber_tipe  ENUM('manual','sts','sp2d') NOT NULL DEFAULT 'manual',
    sumber_id    INT UNSIGNED DEFAULT NULL,
    status       ENUM('draft','sudah_approve','ditolak') NOT NULL DEFAULT 'draft',
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ju_skpd (skpd),
    INDEX idx_ju_tanggal (tanggal),
    INDEX idx_ju_sumber (sumber_tipe, sumber_id),
    INDEX idx_ju_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 3) Jurnal Umum - Detail (baris Debet/Kredit)
--    Kaidah: minimal 2 baris, SUM(debet) = SUM(kredit).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jurnal_umum_detail (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    jurnal_id  INT UNSIGNED NOT NULL,
    skpd       VARCHAR(150) NOT NULL DEFAULT '',
    kode_akun  VARCHAR(50)  NOT NULL DEFAULT '',
    nama_akun  VARCHAR(200) NOT NULL DEFAULT '',
    posisi     ENUM('debet','kredit') NOT NULL,
    jumlah     DECIMAL(18,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_jud_jurnal (jurnal_id),
    INDEX idx_jud_akun (skpd, kode_akun),
    INDEX idx_jud_posisi (posisi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 4) Penanda "sudah dijurnal" pada dokumen sumber
--    agar satu STS / SP2D tidak dibuatkan jurnal dobel.
--    Kompatibel MySQL & MariaDB (cek information_schema dulu,
--    pola sama dengan api/akuntansi.php).
-- ------------------------------------------------------------
SET @kolom_ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sts' AND COLUMN_NAME = 'sudah_dijurnal');
SET @ddl := IF(@kolom_ada = 0,
               'ALTER TABLE `sts` ADD COLUMN `sudah_dijurnal` TINYINT(1) NOT NULL DEFAULT 0',
               'SELECT ''skip: sts.sudah_dijurnal sudah ada''');
PREPARE _st FROM @ddl; EXECUTE _st; DEALLOCATE PREPARE _st;

SET @kolom_ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sp2d' AND COLUMN_NAME = 'sudah_dijurnal');
SET @ddl := IF(@kolom_ada = 0,
               'ALTER TABLE `sp2d` ADD COLUMN `sudah_dijurnal` TINYINT(1) NOT NULL DEFAULT 0',
               'SELECT ''skip: sp2d.sudah_dijurnal sudah ada''');
PREPARE _st FROM @ddl; EXECUTE _st; DEALLOCATE PREPARE _st;

