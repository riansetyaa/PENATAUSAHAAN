-- ============================================================
-- SIM-TKD / AKLAP - Tabel "Jurnal Biasa" (pengganti Jurnal to Approve)
-- ============================================================
-- Fitur: jurnal dimasukkan MANUAL (nomor dokumen + nomor akun)
-- melalui halaman peta.simtkd.com/akuntansi.html -> menu "Jurnal Biasa".
--
-- CATATAN: API (peta.simtkd.com/api/aklap.php aksi jurnal_biasa)
-- sudah otomatis menjalankan CREATE TABLE IF NOT EXISTS saat dipanggil,
-- jadi file ini hanya dokumentasi/referensi (opsional di-import).
-- ============================================================

CREATE TABLE IF NOT EXISTS jurnal_manual (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED      DEFAULT NULL,
    skpd          VARCHAR(150)      NOT NULL DEFAULT '',
    nomor_jurnal  VARCHAR(60)       NOT NULL DEFAULT '',   -- dibuat otomatis: JB-YYYYMM-0001
    nomor_dokumen VARCHAR(100)      NOT NULL DEFAULT '',   -- input manual
    kode_akun     VARCHAR(50)       NOT NULL DEFAULT '',   -- input manual / saran dari akun_penerimaan
    nama_akun     VARCHAR(200)      NOT NULL DEFAULT '',   -- terisi otomatis dari kode akun
    tanggal       DATE              NULL,
    uraian        VARCHAR(255)      NOT NULL DEFAULT '',
    jumlah        DECIMAL(15,2)     NOT NULL DEFAULT 0,
    created_at    TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_skpd (skpd),
    INDEX idx_user (user_id),
    INDEX idx_tanggal (tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel pendukung saran nomor akun (sudah ada dari modul penerimaan):
-- akun_penerimaan (kode_akun, nama_akun) -> API aksi akun_list

require __DIR__.'/../Vendor/autolad.php';