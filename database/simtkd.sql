-- ============================================
-- SIM-TKD Database Schema
-- Sistem Informasi Manajemen Tata Kelola Daerah
-- Politeknik Negeri Bandung (Modul Edukasi)
-- ============================================

CREATE DATABASE IF NOT EXISTS simtkd
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE simtkd;

-- ============================================
-- Table: users
-- ============================================
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nama_lengkap  VARCHAR(100)  NOT NULL,
    username      VARCHAR(50)   NOT NULL UNIQUE,
    email         VARCHAR(100)  NOT NULL UNIQUE,
    password      VARCHAR(255)  NOT NULL,
    instansi      VARCHAR(150)  DEFAULT NULL,
    peran         ENUM('Admin Dinas','Operator','Bendahara','Verifikator','Kepala Dinas','Dosen','Pengguna Umum')
                  DEFAULT 'Pengguna Umum',
    status        ENUM('pending','aktif','nonaktif') DEFAULT 'pending',
    created_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_peran (peran)
) ENGINE=InnoDB;

-- ============================================
-- Table: kegiatan (contoh untuk dashboard / modul edukasi)
-- ============================================
DROP TABLE IF EXISTS kegiatan;

CREATE TABLE kegiatan (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nama_kegiatan VARCHAR(200) NOT NULL,
    tahun         SMALLINT UNSIGNED NOT NULL,
    pagu          DECIMAL(18,2) NOT NULL DEFAULT 0,
    realisasi     DECIMAL(18,2) NOT NULL DEFAULT 0,
    status        ENUM('berjalan','selesai','batal') DEFAULT 'berjalan',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tahun (tahun)
) ENGINE=InnoDB;

-- ============================================
-- Table: permohonan (rekening bank penerimaan)
-- ============================================
DROP TABLE IF EXISTS permohonan;

CREATE TABLE permohonan (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           INT UNSIGNED      DEFAULT NULL,
    skpd              VARCHAR(150)      NOT NULL DEFAULT '',
    bank              VARCHAR(100)      NOT NULL,
    nama_rekening     VARCHAR(150)      NOT NULL,
    nomor_rekening    VARCHAR(50)       NOT NULL DEFAULT '',
    status_terbit     TINYINT(1)        NOT NULL DEFAULT 0,
    status_disetujui  TINYINT(1)        NOT NULL DEFAULT 0,
    status_aktif      TINYINT(1)        NOT NULL DEFAULT 0,
    created_at        TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_bank (bank),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ============================================
-- Table: akun_penerimaan (setup akun penerimaan - Pengaturan)
-- ============================================
DROP TABLE IF EXISTS akun_penerimaan;

CREATE TABLE akun_penerimaan (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED      DEFAULT NULL,
    skpd          VARCHAR(150)      NOT NULL DEFAULT '',
    kode_akun     VARCHAR(50)       NOT NULL,
    nama_akun     VARCHAR(200)      NOT NULL,
    metode_input  VARCHAR(30)       NOT NULL DEFAULT 'harian',
    status_aktif  TINYINT(1)        NOT NULL DEFAULT 1,
    created_at    TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_kode (kode_akun),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ============================================
-- Table: stbp (Surat Tanda Bukti Penerimaan)
-- ============================================
DROP TABLE IF EXISTS stbp;

CREATE TABLE stbp (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED      DEFAULT NULL,
    skpd          VARCHAR(150)      NOT NULL DEFAULT '',
    nomor_stbp    VARCHAR(50)       NOT NULL,
    tanggal       DATE              NOT NULL,
    akun_kode     VARCHAR(50)       NOT NULL DEFAULT '',
    akun_nama     VARCHAR(200)      NOT NULL DEFAULT '',
    jumlah        DECIMAL(18,2)     NOT NULL DEFAULT 0,
    uraian        VARCHAR(255)      NOT NULL DEFAULT '',
    status        ENUM('belum_diverifikasi','sudah_diverifikasi','sudah_diotorisasi','sudah_divalidasi','dihapus')
                  NOT NULL DEFAULT 'belum_diverifikasi',
    created_at    TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_tanggal (tanggal),
    INDEX idx_user (user_id)
) ENGINE=InnoDB;

-- ============================================
-- Table: stbp_pembayaran (data pembayaran STBP)
-- ============================================
DROP TABLE IF EXISTS stbp_pembayaran;

CREATE TABLE stbp_pembayaran (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stbp_id           INT UNSIGNED      NOT NULL,
    metode_penyetoran VARCHAR(20)       NOT NULL DEFAULT 'non_tunai',
    nama_penyetor     VARCHAR(150)      NOT NULL DEFAULT '',
    nama_bank         VARCHAR(100)      NOT NULL DEFAULT '',
    nomor_rekening    VARCHAR(50)       NOT NULL DEFAULT '',
    created_at        TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stbp (stbp_id)
) ENGINE=InnoDB;

-- ============================================
-- Table: stbp_pendapatan (baris data pendapatan STBP)
-- ============================================
DROP TABLE IF EXISTS stbp_pendapatan;

CREATE TABLE stbp_pendapatan (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stbp_id       INT UNSIGNED      NOT NULL,
    akun_kode     VARCHAR(50)       NOT NULL DEFAULT '',
    akun_nama     VARCHAR(200)      NOT NULL DEFAULT '',
    rekening_bank VARCHAR(100)      NOT NULL DEFAULT '',
    rekening_nama VARCHAR(150)      NOT NULL DEFAULT '',
    rekening_nomor VARCHAR(50)      NOT NULL DEFAULT '',
    nominal       DECIMAL(18,2)     NOT NULL DEFAULT 0,
    created_at    TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stbp (stbp_id)
) ENGINE=InnoDB;

-- ============================================
-- Table: sts (Surat Tanda Setoran)
-- ============================================
DROP TABLE IF EXISTS sts_detail;
DROP TABLE IF EXISTS sts;

CREATE TABLE sts (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id            INT UNSIGNED      DEFAULT NULL,
    skpd               VARCHAR(150)      NOT NULL DEFAULT '',
    nomor_sts          VARCHAR(50)       NOT NULL,
    nama_penyetor      VARCHAR(150)      NOT NULL DEFAULT '',
    tanggal_sts        DATE              NOT NULL,
    tanggal_acuan_dari DATE              DEFAULT NULL,
    tanggal_acuan_akhir DATE             DEFAULT NULL,
    mengetahui         VARCHAR(150)      NOT NULL DEFAULT '',
    nama_bank          VARCHAR(100)      NOT NULL DEFAULT '',
    nomor_rekening     VARCHAR(50)       NOT NULL DEFAULT '',
    nama_rekening      VARCHAR(150)      NOT NULL DEFAULT '',
    keterangan         VARCHAR(255)      NOT NULL DEFAULT '',
    total              DECIMAL(18,2)     NOT NULL DEFAULT 0,
    status             ENUM('aktif','dihapus') NOT NULL DEFAULT 'aktif',
    created_at         TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_tanggal (tanggal_sts),
    INDEX idx_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE sts_detail (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sts_id     INT UNSIGNED      NOT NULL,
    stbp_id    INT UNSIGNED      NOT NULL DEFAULT 0,
    nomor_stbp VARCHAR(50)       NOT NULL DEFAULT '',
    tanggal    DATE              DEFAULT NULL,
    akun_kode  VARCHAR(50)       NOT NULL DEFAULT '',
    akun_nama  VARCHAR(200)      NOT NULL DEFAULT '',
    jumlah     DECIMAL(18,2)     NOT NULL DEFAULT 0,
    uraian     VARCHAR(255)      NOT NULL DEFAULT '',
    created_at TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sts (sts_id)
) ENGINE=InnoDB;

-- ============================================
-- Catatan
-- ============================================

-- Tidak ada data dummy yang diisi.
-- Akun admin (username=admin, password=admin123) dibuat otomatis
-- oleh setup.php menggunakan password_hash() agar hash selalu valid.
-- Jalankan setup.php di browser setelah mengimpor skema ini.
