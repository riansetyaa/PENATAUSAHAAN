-- ============================================================
-- SIM-TKD - MODUL DOKUMEN & TANDA TANGAN ELEKTRONIK (doc.simtkd.com)
-- ============================================================
-- Dokumen hasil laman cetak disimpan di tabel `dokumen` (konten HTML
-- mandiri, CSS tertanam). Penandatangan dicatat di `dokumen_ttd` —
-- skema mendukung multi-pihak berjenjang via kolom `urutan`, saat ini
-- dipakai 1 penandatangan (pembuat dokumen). Gambar tanda tangan tangan
-- milik user (digambar di doc.simtkd.com) disimpan di `user_ttd`.
--
-- Cara pakai (MySQL/MariaDB):
--   mysql -u USER -p NAMA_DATABASE < dokumen_ttd.sql
-- atau tempel langsung di phpMyAdmin (tab SQL). Idempoten (aman diulang).
-- ============================================================

-- 1) Dokumen yang dikirim dari laman cetak ke antrean tanda tangan
CREATE TABLE IF NOT EXISTS dokumen (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id            INT UNSIGNED DEFAULT NULL,      -- pembuat / pemilik dokumen
    skpd               VARCHAR(150) DEFAULT NULL,      -- instansi (multi-tenant)
    jenis              VARCHAR(50)  DEFAULT NULL,      -- SPP / SPD / SPM / SP2D / LPJ / NPD / dll
    ref_id             INT UNSIGNED DEFAULT NULL,      -- id baris sumber (spp.id, spd.id, dst)
    nomor              VARCHAR(100) DEFAULT NULL,
    judul              VARCHAR(255) DEFAULT NULL,
    tanggal            DATE DEFAULT NULL,
    konten_html        LONGTEXT,                       -- HTML dokumen asli (CSS tertanam)
    konten_html_signed LONGTEXT DEFAULT NULL,          -- HTML final setelah ditandatangani
    hash_original      CHAR(64) DEFAULT NULL,          -- SHA-256 konten_html saat dikirim
    hash_signed        CHAR(64) DEFAULT NULL,          -- SHA-256 konten_html_signed
    kode_verifikasi    VARCHAR(20) DEFAULT NULL,       -- kode unik utk cek keaslian
    status             ENUM('menunggu_ttd','ditandatangani') DEFAULT 'menunggu_ttd',
    signed_at          DATETIME DEFAULT NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dokumen_skpd (skpd),
    INDEX idx_dokumen_user (user_id),
    UNIQUE KEY uq_dokumen_kode (kode_verifikasi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Catatan penandatangan per dokumen (kolom urutan siap alur berjenjang)
CREATE TABLE IF NOT EXISTS dokumen_ttd (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dokumen_id INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED DEFAULT NULL,
    urutan     TINYINT UNSIGNED DEFAULT 1,
    jabatan    VARCHAR(100) DEFAULT NULL,
    nama       VARCHAR(100) DEFAULT NULL,
    nip        VARCHAR(50)  DEFAULT NULL,
    status     ENUM('menunggu','ditandatangani','ditolak') DEFAULT 'menunggu',
    signed_at  DATETIME DEFAULT NULL,
    ip         VARCHAR(45)  DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    INDEX idx_dttd_dokumen (dokumen_id),
    UNIQUE KEY uq_dttd_urutan (dokumen_id, urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Gambar tanda tangan tangan milik user (PNG base64 / data URI)
CREATE TABLE IF NOT EXISTS user_ttd (
    user_id         INT UNSIGNED PRIMARY KEY,
    gambar          MEDIUMTEXT,
    dibuat_pada     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    diperbarui_pada TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
