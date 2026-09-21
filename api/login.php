<?php
/**
 * SIM-TKD - API Login
 * ============================================
 * Endpoint autentikasi login (metode POST, respons JSON).
 * Kolom "username" menerima username ATAU email yang terdaftar.
 *
 * Contoh pemanggilan (fetch):
 *   fetch('api/login.php', {
 *       method: 'POST',
 *       headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
 *       body: new URLSearchParams({ username, password, tahun_anggaran })
 *   })
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Hanya terima metode POST (kredensial tidak boleh lewat query string)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
}

$username = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
$password = isset($_POST['password']) ? (string) $_POST['password'] : '';

if ($username === '' || $password === '') {
    jsonResponse(false, 'Username/Email dan password wajib diisi.', [], 422);
}

// ---- Rate limit: maks 8 percobaan / 5 menit per username+IP ----
$ip      = $_SERVER['REMOTE_ADDR'] ?? '-';
$rlKey   = 'login:' . strtolower($username) . ':' . $ip;
if (!rateLimitCheck($rlKey, 8, 300)) {
    jsonResponse(false, 'Terlalu banyak percobaan login. Coba lagi dalam beberapa menit.', [], 429);
}

$pdo = db();

// Cari pengguna berdasarkan username ATAU email
$stmt = $pdo->prepare("
    SELECT id, nama_lengkap, nip, username, email, password, instansi, kota, provinsi, api_token, peran, status
    FROM users
    WHERE username = ? OR email = ?
    LIMIT 1
");
$stmt->execute([$username, $username]);
$user = $stmt->fetch();

// Verifikasi password
if (!$user || !password_verify($password, $user['password'])) {
    jsonResponse(false, 'Username/Email atau password tidak valid.', [], 401);
}

// Cek status akun.
// Registrasi kini langsung mengaktifkan akun; akun lama yang masih 'pending'
// otomatis diaktifkan saat login pertama (tanpa verifikasi admin).
if ($user['status'] === 'pending') {
    try {
        $pdo->prepare("UPDATE users SET status = 'aktif' WHERE id = ? AND status = 'pending'")
            ->execute([(int) $user['id']]);
    } catch (PDOException $e) {
        // abaikan kegagalan auto-aktivasi; login tetap dilanjutkan
    }
    $user['status'] = 'aktif';
}

if ($user['status'] === 'nonaktif') {
    jsonResponse(false, 'Akun Anda dinonaktifkan. Hubungi administrator.', [], 403);
}

// Login sukses -> reset counter rate limit
rateLimitClear($rlKey);

// Set sesi login
// Rotasi token API setiap login (keamanan): token lama otomatis tidak berlaku.
// DB menyimpan HASH SHA-256; token mentah hanya dikirim ke user saat ini.
$newToken = generateApiToken();
$stmtUp = $pdo->prepare("UPDATE users SET api_token = ? WHERE id = ?");
$stmtUp->execute([hashApiToken($newToken), (int) $user['id']]);
$user['api_token'] = $newToken;

setUserSession($user);

jsonResponse(true, 'Login berhasil. Mengalihkan ke dashboard...', [
    'redirect' => 'dashboard.html',
    'user' => [
        'nama'     => $user['nama_lengkap'],
        'username' => $user['username'],
        'peran'    => $user['peran'],
        'instansi' => $user['instansi'],
    ],
]);
