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
