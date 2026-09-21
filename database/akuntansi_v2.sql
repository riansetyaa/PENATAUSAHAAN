-- ============================================================
-- SIM-TKD - AKLAP v2.0: MIGRASI MODUL BARU (Kas, Register, LPJ, Jurnal Khusus)
-- ============================================================
-- Berisi SELURUH penambahan basis data pada pembaruan AKLAP v2.0:
--
--   Tabel baru (7):
--     1. register_ppkd            - Register SPP/SPM/SP2D (dari Buku Kas Pengeluaran)
--     2. kas_harian               - Laporan Posisi Kas Harian (header, per tanggal)
--     3. kas_harian_item          - baris laporan kas harian
--     4. rekonsiliasi_bank        - Rekonsiliasi Bank (header, per bulan)
--     5. rekonsiliasi_bank_item   - baris keterangan selisih A/B/C/D
--     6. jurnal_khusus            - Buku Jurnal Khusus & Jurnal Khusus Konsolidator (header)
--     7. jurnal_khusus_baris      - baris jurnal khusus (nilai per posisi kolom)
--
--   Kolom baru pada tabel yang sudah ada (9):
--     1. jurnal_khusus.skp_label  - isian SKPKD manual (blanko Konsolidator)
--     2. jurnal_khusus_baris.debet_7..debet_10   - slot kolom debet 7-10 (blanko Belanja/Beban)
--     3. jurnal_khusus_baris.kredit_7..kredit_10 - slot kolom kredit 7-10
--
-- TIDAK ada perubahan pada tabel lama (buku_kas, anggaran_lra, akun_master,
-- jurnal_umum, jurnal_umum_detail, neraca_awal, dst) - modul baru membaca/
-- menulis ke tabel yang sama sehingga data & laporan lama tetap utuh.
--
-- Catatan: API (peta.simtkd.com/api/akuntansi.php) SUDAH membuat tabel &
-- kolom di atas secara otomatis saat halaman pertama dibuka, jadi berkas ini
-- hanya diperlukan bila ingin menyiapkan/memeriksa skema lebih dulu
-- (mis. deploy ke server produksi atau audit basis data).
--
-- Idempoten: aman dijalankan berulang-ulang.
-- Jalankan setelah database/simtkd.sql, belanja.sql, belanja_v2.sql,
-- dan akuntansi_manual.sql.
--
-- Cara pakai:
--   mysql -u USER -p NAMADB < akuntansi_v2.sql
-- atau tempel di phpMyAdmin (tab SQL).
-- ============================================================


-- ------------------------------------------------------------
-- 1) Register SPP/SPM/SP2D
--    Satu baris register terikat pada satu transaksi Buku Kas
--    Pengeluaran (unik per SKPD + buku_kas_id) -> anti dobel daftar.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS register_ppkd (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd        VARCHAR(150) NOT NULL DEFAULT '',
    buku_kas_id INT UNSIGNED NOT NULL,
    spp_tgl     DATE         NULL,
    spp_no      VARCHAR(64)  NOT NULL DEFAULT '',
    spm_tgl     DATE         NULL,
    spm_no      VARCHAR(64)  NOT NULL DEFAULT '',
    sp2d_tgl    DATE         NULL,
    sp2d_no     VARCHAR(64)  NOT NULL DEFAULT '',
    ket         VARCHAR(120) NOT NULL DEFAULT '',
    user_id     INT UNSIGNED DEFAULT NULL,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reg_bk (skpd, buku_kas_id),
    INDEX idx_reg_skpd (skpd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 2) Laporan Posisi Kas Harian (diisi MANUAL, satu dokumen per tanggal)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kas_harian (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd              VARCHAR(150) NOT NULL DEFAULT '',
    user_id           INT UNSIGNED DEFAULT NULL,
    tanggal           DATE         NULL,
    hari              VARCHAR(12)  NOT NULL DEFAULT '',
    periode           VARCHAR(20)  NOT NULL DEFAULT '',
    posisi_h1         DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_penerimaan  DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_pengeluaran DECIMAL(18,2) NOT NULL DEFAULT 0,
    posisi_h          DECIMAL(18,2) NOT NULL DEFAULT 0,
    bank1_nama        VARCHAR(80)  NOT NULL DEFAULT 'Saldo di Bank 1',
    bank1_saldo       DECIMAL(18,2) NOT NULL DEFAULT 0,
    bank2_nama        VARCHAR(80)  NOT NULL DEFAULT 'Saldo di Bank 2',
    bank2_saldo       DECIMAL(18,2) NOT NULL DEFAULT 0,
    catatan           VARCHAR(255) NOT NULL DEFAULT '',
    created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_kh_skpd (skpd, tanggal),
    INDEX idx_kh_skpd (skpd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kas_harian_item (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kas_harian_id INT UNSIGNED NOT NULL,
    skpd          VARCHAR(150) NOT NULL DEFAULT '',
    urut          INT NOT NULL DEFAULT 0,
    no_sp2d       VARCHAR(60)  NOT NULL DEFAULT '',
    no_sts        VARCHAR(60)  NOT NULL DEFAULT '',
    no_lain       VARCHAR(60)  NOT NULL DEFAULT '',
    uraian        VARCHAR(255) NOT NULL DEFAULT '',
    penerimaan    DECIMAL(18,2) NOT NULL DEFAULT 0,
    pengeluaran   DECIMAL(18,2) NOT NULL DEFAULT 0,
    INDEX idx_khi_head (kas_harian_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 3) Rekonsiliasi Bank (diisi MANUAL, satu dokumen per bulan)
--    bagian_item: A/B/C/D = keterangan selisih sesuai blanko
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rekonsiliasi_bank (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd        VARCHAR(150) NOT NULL DEFAULT '',
    user_id     INT UNSIGNED DEFAULT NULL,
    tahun       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    bulan       TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    periode     VARCHAR(20)  NOT NULL DEFAULT '',
    tgl_ttd     DATE         NULL,
    saldo_buku  DECIMAL(18,2) NOT NULL DEFAULT 0,
    saldo_bank  DECIMAL(18,2) NOT NULL DEFAULT 0,
    catatan     VARCHAR(255) NOT NULL DEFAULT '',
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rb_skpd (skpd, tahun, bulan),
    INDEX idx_rb_skpd (skpd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rekonsiliasi_bank_item (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rekonsiliasi_id    INT UNSIGNED NOT NULL,
    skpd               VARCHAR(150) NOT NULL DEFAULT '',
    bagian             ENUM('A','B','C','D') NOT NULL DEFAULT 'A',
    urut               INT NOT NULL DEFAULT 0,
    uraian             VARCHAR(255) NOT NULL DEFAULT '',
    jumlah             DECIMAL(18,2) NOT NULL DEFAULT 0,
    INDEX idx_rbi_head (rekonsiliasi_id),
    INDEX idx_rbi_bagian (bagian)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 4) Buku Jurnal Khusus & Jurnal Khusus Konsolidator
--    Satu dokumen per SKPD + jenis blanko + tahun + bulan.
--    kode_extra = kode akun manual tiap kolom (JSON).
--    skp_label  = isian SKPKD manual (khusus blanko Konsolidator).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jurnal_khusus (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skpd       VARCHAR(150) NOT NULL DEFAULT '',
    user_id    INT UNSIGNED DEFAULT NULL,
    jenis      VARCHAR(30)  NOT NULL DEFAULT '',
    tahun      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    bulan      TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    kode_extra TEXT NULL,
    skp_label  VARCHAR(80) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_jk (skpd, jenis, tahun, bulan),
    INDEX idx_jk_skpd (skpd)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nilai disimpan per POSISI kolom (debet_1..debet_10 / kredit_1..kredit_10)
-- agar jumlah kolom tiap jenis blanko berbeda tetap tertampung.
CREATE TABLE IF NOT EXISTS jurnal_khusus_baris (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    jurnal_id INT UNSIGNED NOT NULL,
    skpd      VARCHAR(150) NOT NULL DEFAULT '',
    urut      INT NOT NULL DEFAULT 0,
    tanggal   DATE NULL,
    nomor_dok VARCHAR(60)  NOT NULL DEFAULT '',
    uraian    VARCHAR(255) NOT NULL DEFAULT '',
    debet_1  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_2  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_3  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_4  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_5  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_6  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_7  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_8  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_9  DECIMAL(18,2) NOT NULL DEFAULT 0,
    debet_10 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_1 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_2 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_3 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_4 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_5 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_6 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_7 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_8 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_9 DECIMAL(18,2) NOT NULL DEFAULT 0,
    kredit_10 DECIMAL(18,2) NOT NULL DEFAULT 0,
    INDEX idx_jkb_jurnal (jurnal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- 5) KOLOM BARU pada tabel yang sudah ada (idempoten)
--    Pola: cek information_schema -> ALTER hanya bila belum ada.
--    Kompatibel MySQL & MariaDB (tanpa ADD COLUMN IF NOT EXISTS).
-- ------------------------------------------------------------

-- 5a) jurnal_khusus.skp_label -> isian SKPKD manual (blanko Konsolidator)
SET @ada := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE table_schema = DATABASE() AND table_name = 'jurnal_khusus'
               AND column_name = 'skp_label');
SET @s := IF(@ada = 0,
    'ALTER TABLE jurnal_khusus ADD COLUMN `skp_label` VARCHAR(80) NOT NULL DEFAULT '''' AFTER kode_extra',
    'SELECT ''kolom jurnal_khusus.skp_label sudah ada'' AS info');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5b) jurnal_khusus_baris.debet_7..debet_10 & kredit_7..kredit_10
--     (dibutuhkan blanko Belanja Pemda & Beban Pemda yang berkolom 8 debet)
--     Ditambahkan sekaligus hanya untuk kolom yang masih kurang.
SET @tambah := (
    SELECT GROUP_CONCAT(CONCAT('ADD COLUMN `', k.col, '` DECIMAL(18,2) NOT NULL DEFAULT 0')
                        SEPARATOR ', ')
    FROM (
        SELECT 'debet_7' AS col UNION ALL SELECT 'debet_8' UNION ALL
        SELECT 'debet_9' UNION ALL SELECT 'debet_10' UNION ALL
        SELECT 'kredit_7' UNION ALL SELECT 'kredit_8' UNION ALL
        SELECT 'kredit_9' UNION ALL SELECT 'kredit_10'
    ) k
    WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS c
        WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'jurnal_khusus_baris'
          AND c.COLUMN_NAME = k.col
    )
);
SET @s := IF(@tambah IS NULL,
    'SELECT ''slot kolom 7-10 jurnal_khusus_baris sudah lengkap'' AS info',
    CONCAT('ALTER TABLE jurnal_khusus_baris ', @tambah));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 6) PEMERIKSAAN: daftar tabel & kolom hasil migrasi v2.0
--    (jalankan manual bila ingin memastikan migrasi berhasil)
-- ------------------------------------------------------------
-- SELECT table_name FROM information_schema.tables
--  WHERE table_schema = DATABASE()
--    AND table_name IN ('register_ppkd','kas_harian','kas_harian_item',
--                       'rekonsiliasi_bank','rekonsiliasi_bank_item',
--                       'jurnal_khusus','jurnal_khusus_baris');
--
-- SELECT table_name, column_name FROM information_schema.columns
--  WHERE table_schema = DATABASE()
--    AND (   (table_name = 'jurnal_khusus' AND column_name = 'skp_label')
--         OR (table_name = 'jurnal_khusus_baris'
--             AND column_name IN ('debet_7','debet_8','debet_9','debet_10',
--                                 'kredit_7','kredit_8','kredit_9','kredit_10')))
--  ORDER BY table_name, column_name;
-- ============================================================
-- SELESAI - AKLAP v2.0 schema
-- ============================================================

