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

// ---- Selaraskan skema kolom `users` ----
// Basis data hasil deploy lama (atau yang belum menjalankan
// database/users_nip.sql / api_token.sql) belum punya kolom `nip`
// dan/atau `api_token`. Query di bawah MEMILIH kolom tersebut, jadi
// tanpa penjagaan ini login gagal total dengan PDOException
// "Unknown column 'nip'" dan pengguna tidak bisa masuk sama sekali.
// ensureUsersColumns() menambahkan kolom yang belum ada (idempoten).
ensureUsersColumns($pdo);

// Cari pengguna berdasarkan username ATAU email
// Dibungkus try/catch agar kegagalan basis data dikembalikan sebagai JSON
// yang jelas (bukan halaman HTML "Fatal error") — memudahkan diagnosis
// sekaligus mencegah laman login hanya menampilkan pesan koneksi gagal.
try {
    $stmt = $pdo->prepare("
        SELECT id, nama_lengkap, nip, username, email, password, instansi, kota, provinsi, api_token, peran, status
        FROM users
        WHERE username = ? OR email = ?
        LIMIT 1
    ");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();
} catch (Throwable $e) {
    error_log('[SIM-TKD] login query gagal: ' . $e->getMessage());
    jsonResponse(
        false,
        'Server gagal membaca data pengguna. Coba lagi beberapa saat lagi; bila berlanjut hubungi administrator.',
        [],
        500
    );
}

// Verifikasi password
if (!$user || !password_verify($password, $user['password'])) {
    jsonResponse(false, 'Username/Email atau password tidak valid.', [], 401);
}

// Cek status akun.
// Akun hasil pendaftaran berstatus 'pending' sampai diverifikasi melalui
// halaman Aktivasi Akun (kode OTP ke email) — lihat api/aktivasi.php.
// Akun 'pending' TIDAK boleh login, tetapi frontend diberi tahu agar
// menampilkan tombol menuju halaman aktivasi.
if ($user['status'] === 'pending') {
    jsonResponse(
        false,
        'Akun Anda belum diverifikasi. Silakan aktifkan akun terlebih dahulu dengan kode OTP yang dikirim ke email Anda.',
        [
            'status'         => 'pending',
            'need_activation' => true,
            'aktivasi'       => 'aktivasi.html?email=' . urlencode((string) $user['email']),
        ],
        403
    );
}

if ($user['status'] === 'nonaktif') {
    jsonResponse(false, 'Akun Anda dinonaktifkan. Hubungi administrator.', [], 403);
}

// Login sukses -> reset counter rate limit
rateLimitClear($rlKey);

// Set sesi login
// Rotasi token API setiap login (keamanan): token lama otomatis tidak berlaku.
// DB menyimpan HASH SHA-256; token mentah hanya dikirim ke user saat ini.
//
// Sekaligus catat aktivitas login untuk halaman Monitoring Pengguna
// (Pengaturan -> Monitoring Pengguna). Bila kolom pelacakan belum ada
// (mis. hak ALTER ditolak), login TETAP berhasil: token dirotasi lewat
// query cadangan, sedangkan kegagalan pencatatan hanya masuk log server.
$newToken = generateApiToken();
try {
    $stmtUp = $pdo->prepare("
        UPDATE users
        SET api_token       = ?,
            last_login_at   = NOW(),
            last_activity_at = NOW(),
            last_ip         = ?,
            login_count     = login_count + 1
        WHERE id = ?
    ");
    $stmtUp->execute([hashApiToken($newToken), $ip, (int) $user['id']]);
} catch (Throwable $e) {
    error_log('[SIM-TKD] login: gagal mencatat aktivitas login: ' . $e->getMessage());
    try {
        $stmtUp = $pdo->prepare("UPDATE users SET api_token = ? WHERE id = ?");
        $stmtUp->execute([hashApiToken($newToken), (int) $user['id']]);
    } catch (Throwable $e2) {
        error_log('[SIM-TKD] login: gagal merotasi token: ' . $e2->getMessage());
    }
}
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
