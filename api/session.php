<?php
/**
 * SIM-TKD - API Session Check
 * ============================================
 * Endpoint untuk mengecek status login dari frontend (GET).
 * Digunakan halaman .html (vanilla JS) untuk melindungi dashboard.
 *
 * Contoh:
 *   fetch('api/session.php')
 *     .then(r => r.json())
 *     .then(d => d.success ? d.user : redirect ke login.html)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    jsonResponse(false, 'Tidak terautentikasi.', [], 401);
}

$pdo = db();

// Pastikan kolom monitoring login tersedia (idempoten).
ensureLoginTracking($pdo);

// Sinkronkan data sesi dengan basis data.
// Peran bisa diubah admin lewat Pengaturan -> Monitoring Pengguna; tanpa
// sinkronisasi ini, sesi lama tetap memakai peran lama sampai logout.
try {
    $stmtSesi = $pdo->prepare("
        SELECT nama_lengkap, nip, email, instansi, peran, status
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmtSesi->execute([(int) $_SESSION['user_id']]);
    $sesiTerbaru = $stmtSesi->fetch();

    if (!$sesiTerbaru) {
        destroySession();
        jsonResponse(false, 'Sesi tidak valid. Silakan login kembali.', [], 401);
    }
    if ($sesiTerbaru['status'] !== 'aktif') {
        destroySession();
        jsonResponse(false, 'Akun Anda tidak aktif. Silakan hubungi administrator.', [], 401);
    }

    $_SESSION['nama']     = (string) $sesiTerbaru['nama_lengkap'];
    $_SESSION['nip']      = (string) ($sesiTerbaru['nip'] ?? '');
    $_SESSION['email']    = (string) $sesiTerbaru['email'];
    $_SESSION['instansi'] = (string) ($sesiTerbaru['instansi'] ?? '');
    $_SESSION['peran']    = (string) $sesiTerbaru['peran'];
} catch (Throwable $e) {
    // Jangan mematikan sesi hanya karena query sinkronisasi gagal.
    error_log('[SIM-TKD] session: gagal menyinkronkan data pengguna: ' . $e->getMessage());
}

// Auto-provisi token API: jika user yang login belum punya api_token
// (mis. akun lama yang dibuat sebelum sistem token), buatkan sekarang
// agar link AKLAP selalu bisa membawa ?token= tanpa migrasi manual.
if (empty($_SESSION['api_token'])) {
    $newToken = generateApiToken();
    try {
        $stmt = $pdo->prepare("UPDATE users SET api_token = ? WHERE id = ?");
        $stmt->execute([hashApiToken($newToken), (int) $_SESSION['user_id']]);
        $_SESSION['api_token'] = $newToken;
    } catch (Throwable $e) {
        // abaikan; biarkan kosong bila gagal (tetap login)
    }
}

// Catat aktivitas terakhir untuk Monitoring Pengguna.
// Dibatasi maksimal sekali per 5 menit agar tidak menulis di setiap request.
try {
    $stmtAktif = $pdo->prepare("
        UPDATE users
        SET last_activity_at = NOW(),
            last_login_at    = COALESCE(last_login_at, NOW()),
            last_ip          = ?
        WHERE id = ?
          AND (last_activity_at IS NULL OR last_activity_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
    ");
    $stmtAktif->execute([
        $_SERVER['REMOTE_ADDR'] ?? '-',
        (int) $_SESSION['user_id'],
    ]);
} catch (Throwable $e) {
    error_log('[SIM-TKD] session: gagal mencatat aktivitas: ' . $e->getMessage());
}

jsonResponse(true, 'Autentikasi valid.', [
    'user' => [
        'id'       => $_SESSION['user_id'] ?? null,
        'nama'     => $_SESSION['nama'] ?? '',
        'nip'      => $_SESSION['nip'] ?? '',
        'username' => $_SESSION['username'] ?? '',
        'email'    => $_SESSION['email'] ?? '',
        'instansi' => $_SESSION['instansi'] ?? '',
        'peran'    => $_SESSION['peran'] ?? '',
        'api_token'=> $_SESSION['api_token'] ?? '',
    ],
]);
