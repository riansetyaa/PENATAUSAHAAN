<?php
/**
 * SIM-TKD - Migrasi Skema Modul SKP Daerah & Tanda Tangan (satu kali)
 * ================================================================
 * Menambahkan struktur yang dibutuhkan alur Penerimaan:
 *   - tabel skp_daerah (Surat Ketetapan Pajak Daerah)
 *   - stbp.skp_daerah_id (+ index)  -> STBP merujuk SKP Daerah
 *   - sts.kuasa_pengguna_anggaran    -> nama penandatangan ke-2 STS
 *   - dokumen, dokumen_ttd, user_ttd -> antrean TTD doc.simtkd.com
 *
 * AMAN: setiap ALTER/CREATE hanya dijalankan bila belum ada (dicek via
 * information_schema / SELECT 1), sehingga boleh dijalankan berulang.
 *
 * Cara pakai:
 *   - Web (harus login sebagai Admin Dinas):  https://HOST/api/skp_migrate.php?run=1
 *   - CLI (tanpa login):                      php api/skp_migrate.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Silakan login terlebih dahulu.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $peran    = (string) ($_SESSION['peran'] ?? '');
    $username = (string) ($_SESSION['username'] ?? '');
    if ($peran !== 'Admin Dinas' && $username !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Akses hanya untuk admin.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (($_GET['run'] ?? '') !== '1') {
        echo json_encode(['success' => false, 'message' => 'Tambahkan ?run=1 untuk menjalankan migrasi (contoh: api/skp_migrate.php?run=1).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$pdo    = db();
$report = [];

function skpHasTable(PDO $pdo, string $t): bool
{
    try {
        $pdo->query("SELECT 1 FROM `{$t}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
function skpHasColumn(PDO $pdo, string $t, string $c): bool
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$t, $c]);
        return (int) $st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
function skpRun(string $sql, string $desc, array &$report): void
{
    global $pdo;
    try {
        $pdo->exec($sql);
        $report[] = 'OK: ' . $desc;
    } catch (Throwable $e) {
        $report[] = 'GAGAL: ' . $desc . ' — ' . $e->getMessage();
    }
}

// 1. Tabel skp_daerah
if (!skpHasTable($pdo, 'skp_daerah')) {
    skpRun(
        "CREATE TABLE skp_daerah (
            id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id          INT UNSIGNED      DEFAULT NULL,
            skpd             VARCHAR(150)      NOT NULL DEFAULT '',
            nomor_skp        VARCHAR(50)       NOT NULL,
            tanggal          DATE              NOT NULL,
            jenis_pajak      VARCHAR(120)      NOT NULL DEFAULT '',
            nama_penyetor    VARCHAR(150)      NOT NULL DEFAULT '',
            objek_pajak      VARCHAR(255)      NOT NULL DEFAULT '',
            nilai_keputusan  DECIMAL(18,2)     NOT NULL DEFAULT 0,
            masa_pajak_dari  DATE              DEFAULT NULL,
            masa_pajak_akhir DATE              DEFAULT NULL,
            jatuh_tempo      DATE              DEFAULT NULL,
            keterangan       VARCHAR(255)      NOT NULL DEFAULT '',
            status           ENUM('aktif','terpakai','dihapus') NOT NULL DEFAULT 'aktif',
            created_at       TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
            updated_at       TIMESTAMP         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_tanggal (tanggal),
            INDEX idx_user (user_id),
            INDEX idx_nomor (nomor_skp)
        ) ENGINE=InnoDB",
        'tabel skp_daerah',
        $report
    );
} else {
    $report[] = 'ADA: tabel skp_daerah';
}

// 1b. skp_daerah.akun_kode — jenis pajak SKP terikat ke Akun Penerimaan (Pengaturan)
if (skpHasColumn($pdo, 'skp_daerah', 'akun_kode')) {
    $report[] = 'ADA: skp_daerah.akun_kode';
} else {
    skpRun("ALTER TABLE skp_daerah ADD COLUMN akun_kode VARCHAR(50) NOT NULL DEFAULT '' AFTER jenis_pajak", 'kolom akun_kode di skp_daerah', $report);
}

// 1c. skp_daerah.alamat & npwpd — identitas wajib pajak untuk dokumen resmi SKP
if (skpHasColumn($pdo, 'skp_daerah', 'alamat')) {
    $report[] = 'ADA: skp_daerah.alamat';
} else {
    skpRun("ALTER TABLE skp_daerah ADD COLUMN alamat VARCHAR(255) NOT NULL DEFAULT '' AFTER nama_penyetor", 'kolom alamat di skp_daerah', $report);
}
if (skpHasColumn($pdo, 'skp_daerah', 'npwpd')) {
    $report[] = 'ADA: skp_daerah.npwpd';
} else {
    skpRun("ALTER TABLE skp_daerah ADD COLUMN npwpd VARCHAR(50) NOT NULL DEFAULT '' AFTER alamat", 'kolom npwpd di skp_daerah', $report);
}

// 2. stbp.skp_daerah_id
if (!skpHasTable($pdo, 'stbp')) {
    $report[] = 'SKIP: tabel stbp belum ada';
} elseif (skpHasColumn($pdo, 'stbp', 'skp_daerah_id')) {
    $report[] = 'ADA: stbp.skp_daerah_id';
} else {
    skpRun("ALTER TABLE stbp ADD COLUMN skp_daerah_id INT UNSIGNED DEFAULT NULL AFTER user_id", 'kolom skp_daerah_id di stbp', $report);
    skpRun("ALTER TABLE stbp ADD INDEX idx_skp (skp_daerah_id)", 'index skp di stbp', $report);
}

// 3. sts.kuasa_pengguna_anggaran
if (!skpHasTable($pdo, 'sts')) {
    $report[] = 'SKIP: tabel sts belum ada';
} elseif (skpHasColumn($pdo, 'sts', 'kuasa_pengguna_anggaran')) {
    $report[] = 'ADA: sts.kuasa_pengguna_anggaran';
} else {
    skpRun("ALTER TABLE sts ADD COLUMN kuasa_pengguna_anggaran VARCHAR(150) NOT NULL DEFAULT '' AFTER mengetahui", 'kolom kuasa_pengguna_anggaran di sts', $report);
}

// 4. Tabel antrean tanda tangan (doc.simtkd.com)
if (!skpHasTable($pdo, 'dokumen')) {
    skpRun(
        "CREATE TABLE dokumen (
            id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id            INT UNSIGNED      DEFAULT NULL,
            skpd               VARCHAR(150)      NOT NULL DEFAULT '',
            jenis              VARCHAR(50)       NOT NULL DEFAULT 'Dokumen',
            ref_id             INT UNSIGNED      DEFAULT NULL,
            nomor              VARCHAR(100)      NOT NULL DEFAULT '',
            judul              VARCHAR(200)      NOT NULL DEFAULT '',
            tanggal            DATE              DEFAULT NULL,
            konten_html        LONGTEXT          NOT NULL,
            hash_original      CHAR(64)          NOT NULL DEFAULT '',
            kode_verifikasi    VARCHAR(20)       NOT NULL,
            status             ENUM('menunggu_ttd','ditandatangani') NOT NULL DEFAULT 'menunggu_ttd',
            konten_html_signed LONGTEXT          DEFAULT NULL,
            hash_signed        CHAR(64)          DEFAULT NULL,
            signed_at          DATETIME          DEFAULT NULL,
            created_at         TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
            updated_at         TIMESTAMP         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_kode (kode_verifikasi),
            INDEX idx_user (user_id),
            INDEX idx_status (status),
            INDEX idx_ref (jenis, ref_id)
        ) ENGINE=InnoDB",
        'tabel dokumen (antrean TTD)',
        $report
    );
} else {
    $report[] = 'ADA: tabel dokumen';
}

if (!skpHasTable($pdo, 'dokumen_ttd')) {
    skpRun(
        "CREATE TABLE dokumen_ttd (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dokumen_id INT UNSIGNED      NOT NULL,
            user_id    INT UNSIGNED      DEFAULT NULL,
            urutan     INT UNSIGNED      NOT NULL DEFAULT 1,
            jabatan    VARCHAR(100)      NOT NULL DEFAULT '',
            nama       VARCHAR(150)      NOT NULL DEFAULT '',
            status     ENUM('menunggu','ditandatangani') NOT NULL DEFAULT 'menunggu',
            signed_at  DATETIME          DEFAULT NULL,
            ip         VARCHAR(45)       DEFAULT NULL,
            user_agent VARCHAR(255)      DEFAULT NULL,
            UNIQUE KEY uq_dokumen_user_urutan (dokumen_id, user_id, urutan),
            INDEX idx_dokumen (dokumen_id)
        ) ENGINE=InnoDB",
        'tabel dokumen_ttd (slot penandatangan)',
        $report
    );
} else {
    $report[] = 'ADA: tabel dokumen_ttd';
}

if (!skpHasTable($pdo, 'user_ttd')) {
    skpRun(
        "CREATE TABLE user_ttd (
            user_id         INT UNSIGNED PRIMARY KEY,
            gambar          LONGTEXT      NOT NULL,
            dibuat_pada     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            diperbarui_pada TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
        'tabel user_ttd (gambar tanda tangan user)',
        $report
    );
} else {
    $report[] = 'ADA: tabel user_ttd';
}

echo json_encode(['success' => true, 'message' => 'Migrasi selesai.', 'report' => $report], JSON_UNESCAPED_UNICODE);
