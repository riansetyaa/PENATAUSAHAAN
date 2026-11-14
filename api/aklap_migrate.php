<?php
/**
 * SIM-TKD - Migrasi Skema untuk Modul AKLAP (satu kali)
 * ============================================
 * Menambahkan kolom/tabel yang dibutuhkan API AKLAP (jurnal, lra_rekap,
 * approve/reject) jika belum ada:
 *   - stbp.jurnal_status, sts.jurnal_status, sp2d.jurnal_status
 *   - kegiatan.skpd (+ index)
 *   - tabel sp2d & spm (dibuat minimal bila belum ada)
 *
 * AMAN: setiap ALTER/TABLE hanya dijalankan bila belum ada (dicek via
 * SHOW COLUMNS / SELECT 1), sehingga boleh dijalankan berulang.
 *
 * Cara pakai: login sebagai admin, buka:
 *   https://HOST/api/aklap_migrate.php?run=1
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Silakan login terlebih dahulu.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$peran = (string) ($_SESSION['peran'] ?? '');
$username = (string) ($_SESSION['username'] ?? '');
if ($peran !== 'Admin Dinas' && $username !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Akses hanya untuk admin.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_GET['run'] ?? '') !== '1') {
    echo json_encode(['success' => false, 'message' => 'Tambahkan ?run=1 untuk menjalankan migrasi (contoh: api/aklap_migrate.php?run=1).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$report = [];
$ok = true;

function hasTable(PDO $pdo, string $t): bool {
    try { $pdo->query("SELECT 1 FROM `{$t}` LIMIT 1"); return true; }
    catch (Throwable $e) { return false; }
}
function hasColumn(PDO $pdo, string $t, string $c): bool {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$t, $c]);
        return (int) $st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
function run(string $sql, string $desc, array &$report): void {
    global $pdo;
    try { $pdo->exec($sql); $report[] = 'OK: ' . $desc; }
    catch (Throwable $e) { $report[] = 'GAGAL: ' . $desc . ' — ' . $e->getMessage(); }
}

// 1. jurnal_status pada stbp / sts / sp2d
foreach (['stbp', 'sts', 'sp2d'] as $t) {
    if (!hasTable($pdo, $t)) { $report[] = 'SKIP: tabel ' . $t . ' belum ada'; continue; }
    if (hasColumn($pdo, $t, 'jurnal_status')) { $report[] = 'ADA: jurnal_status di ' . $t; continue; }
    run("ALTER TABLE `{$t}` ADD COLUMN jurnal_status ENUM('belum_approve','sudah_approve','ditolak') DEFAULT 'belum_approve'",
        "kolom jurnal_status di {$t}", $report);
}

// 2. kegiatan.skpd
if (!hasTable($pdo, 'kegiatan')) {
    $report[] = 'SKIP: tabel kegiatan belum ada';
} elseif (hasColumn($pdo, 'kegiatan', 'skpd')) {
    $report[] = 'ADA: kegiatan.skpd';
} else {
    run("ALTER TABLE kegiatan ADD COLUMN skpd VARCHAR(150) DEFAULT NULL AFTER id", 'kolom skpd di kegiatan', $report);
    run("ALTER TABLE kegiatan ADD INDEX idx_kegiatan_skpd (skpd)", 'index skpd di kegiatan', $report);
}

// 3. tabel sp2d (minimal untuk API AKLAP)
if (!hasTable($pdo, 'sp2d')) {
    run("CREATE TABLE sp2d (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        skpd VARCHAR(150) DEFAULT NULL,
        user_id INT UNSIGNED DEFAULT NULL,
        nomor_sp2d VARCHAR(50) DEFAULT NULL,
        tanggal DATE DEFAULT NULL,
        spm_id INT UNSIGNED DEFAULT NULL,
        rekening VARCHAR(150) DEFAULT NULL,
        jumlah DECIMAL(18,2) DEFAULT 0,
        status VARCHAR(30) DEFAULT 'belum_diverifikasi',
        jurnal_status ENUM('belum_approve','sudah_approve','ditolak') DEFAULT 'belum_approve',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_sp2d_skpd (skpd)
    ) ENGINE=InnoDB", 'tabel sp2d', $report);
} else {
    $report[] = 'ADA: tabel sp2d';
}

// 4. tabel spm (minimal untuk JOIN nomor_spm)
if (!hasTable($pdo, 'spm')) {
    run("CREATE TABLE spm (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        skpd VARCHAR(150) DEFAULT NULL,
        user_id INT UNSIGNED DEFAULT NULL,
        nomor_spm VARCHAR(50) DEFAULT NULL,
        tanggal DATE DEFAULT NULL,
        spp_id INT UNSIGNED DEFAULT NULL,
        jumlah DECIMAL(18,2) DEFAULT 0,
        status VARCHAR(30) DEFAULT 'belum_disetujui',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_spm_skpd (skpd)
    ) ENGINE=InnoDB", 'tabel spm', $report);
} else {
    $report[] = 'ADA: tabel spm';
}

echo json_encode(['success' => true, 'report' => $report], JSON_UNESCAPED_UNICODE);
