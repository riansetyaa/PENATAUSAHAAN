<?php
/**
 * SIM-TKD - Ubah Peran Pengguna (Monitoring Pengguna)
 * ============================================
 * Endpoint khusus admin untuk mengubah peran akun dari halaman
 * Pengaturan -> Monitoring Pengguna.
 *
 * Hak ubah peran LEBIH KETAT daripada hak melihat monitoring:
 * hanya Admin Dinas atau username "admin". Kepala Dinas/Dosen tetap
 * dapat melihat data, tetapi tidak dapat mengubah peran.
 *
 * Pengaman:
 *   - Peran akun sendiri tidak dapat diubah (mencegah lockout).
 *   - Akun superuser "admin" tidak dapat diubah dari halaman ini.
 *   - Peran harus termasuk ENUM users.peran yang tersedia.
 *
 * Metode: POST. Respons JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
}

if (!isLoggedIn()) {
    jsonResponse(false, 'Tidak terautentikasi.', [], 401);
}

$pdo = db();
ensureUsersColumns($pdo);

// ---------------------------------------------------------------
// Otorisasi: baca ulang peran dari basis data, jangan hanya percaya
// sesi. Dengan begitu akun yang baru diturunkan perannya langsung
// kehilangan hak ubah peran pada request berikutnya.
// ---------------------------------------------------------------
$aktorId = (int) ($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT id, username, peran, status
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$aktorId]);
$aktor = $stmt->fetch();

$boleh = $aktor
    && $aktor['status'] === 'aktif'
    && ($aktor['peran'] === 'Admin Dinas' || $aktor['username'] === 'admin');

if (!$boleh) {
    jsonResponse(
        false,
        'Akses ditolak. Hanya Admin Dinas yang dapat mengubah peran pengguna.',
        [],
        403
    );
}

$targetId  = (int) ($_POST['user_id'] ?? 0);
$peranBaru = trim((string) ($_POST['peran'] ?? ''));

if ($targetId <= 0) {
    jsonResponse(false, 'Pengguna yang akan diubah tidak valid.', [], 422);
}
if ($peranBaru === '') {
    jsonResponse(false, 'Peran baru wajib dipilih.', [], 422);
}
if (!in_array($peranBaru, daftarPeran($pdo), true)) {
    jsonResponse(false, 'Peran yang dipilih tidak tersedia.', [], 422);
}
if ($targetId === $aktorId) {
    jsonResponse(
        false,
        'Peran akun sendiri tidak dapat diubah dari halaman ini.',
        [],
        422
    );
}

$stmt = $pdo->prepare("
    SELECT id, nama_lengkap, username, peran
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$targetId]);
$target = $stmt->fetch();

if (!$target) {
    jsonResponse(false, 'Pengguna tidak ditemukan.', [], 404);
}
if ($target['username'] === 'admin') {
    jsonResponse(
        false,
        'Akun admin utama tidak dapat diubah perannya dari halaman ini.',
        [],
        403
    );
}

if ($target['peran'] === $peranBaru) {
    jsonResponse(true, 'Peran pengguna tidak berubah.', [
        'user' => [
            'id'       => (int) $target['id'],
            'nama'     => (string) $target['nama_lengkap'],
            'username' => (string) $target['username'],
            'peran'    => (string) $target['peran'],
        ],
        'peran_lama' => (string) $target['peran'],
        'peran_baru' => (string) $target['peran'],
    ]);
}

try {
    $stmt = $pdo->prepare("UPDATE users SET peran = ? WHERE id = ?");
    $stmt->execute([$peranBaru, $targetId]);
} catch (Throwable $e) {
    error_log('[SIM-TKD] monitoring_peran: gagal mengubah peran: ' . $e->getMessage());
    jsonResponse(false, 'Gagal menyimpan perubahan peran. Coba lagi.', [], 500);
}

jsonResponse(
    true,
    'Peran ' . $target['nama_lengkap'] . ' berhasil diubah menjadi ' . $peranBaru . '.',
    [
        'user' => [
            'id'       => (int) $target['id'],
            'nama'     => (string) $target['nama_lengkap'],
            'username' => (string) $target['username'],
            'peran'    => $peranBaru,
        ],
        'peran_lama' => (string) $target['peran'],
        'peran_baru' => $peranBaru,
    ]
);
