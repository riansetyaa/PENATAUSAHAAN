<?php
/**
 * SIM-TKD - API Register
 * ============================================
 * Endpoint pendaftaran akun baru (metode POST, respons JSON).
 *
 * Contoh pemanggilan (fetch):
 *   fetch('api/register.php', {
 *       method: 'POST',
 *       headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
 *       body: new URLSearchParams({ nama_lengkap, username, email, password, instansi })
 *
 * PENTING (keamanan): peran TIDAK dibaca dari input. Akun baru selalu
 * berperan "Bendahara" (peran operasional bawaan aplikasi). Perubahan peran
 * hanya dapat dilakukan Admin Dinas lewat Pengaturan -> Monitoring Pengguna.
 * Tanpa aturan ini, siapa pun bisa mendaftar sebagai "Admin Dinas".
 *   })
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Hanya terima metode POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
}

// Ambil dan validasi input
$nama       = input('nama_lengkap');
$username   = input('username');
$email      = input('email');
$password   = input('password');
$instansi   = normalizeInstansi(input('instansi'));
// Peran selalu ditetapkan server. Jangan pernah mengambil dari input:
// sebelumnya pendaftar bisa mengirim peran=Admin Dinas dan langsung
// mendapat akses Monitoring Pengguna / ubah peran.
$peran      = 'Bendahara';
$kota       = input('kota');
$provinsi   = input('provinsi');

// --- Validasi server-side ---
if (!isValidNama($nama)) {
    jsonResponse(false, 'Nama lengkap minimal 3 karakter.', ['field' => 'nama_lengkap'], 422);
}

if (!isValidUsername($username)) {
    jsonResponse(false, 'Username 3-20 karakter (huruf, angka, . _ -).', ['field' => 'username'], 422);
}

// Username yang dipakai sistem tidak boleh didaftarkan publik. Tanpa ini,
// pendaftar pertama dengan username "admin" otomatis menjadi superuser
// (beberapa endpoint memperlakukan username "admin" sebagai superuser).
$usernameTerlarang = ['admin', 'administrator', 'root', 'superadmin', 'sysadmin', 'system'];
if (in_array(strtolower($username), $usernameTerlarang, true)) {
    jsonResponse(false, 'Username tersebut tidak dapat digunakan.', ['field' => 'username'], 422);
}

if (!isValidEmail($email)) {
    jsonResponse(false, 'Format email tidak valid.', ['field' => 'email'], 422);
}

// Pendaftaran hanya diizinkan via email domain POLBAN
$allowedDomain = 'polban.ac.id';
$emailDomain = strtolower(substr(strrchr($email, '@') ?: '', 1));
if ($emailDomain !== $allowedDomain) {
    jsonResponse(false, 'Pendaftaran hanya menggunakan email domain @' . $allowedDomain . '.', ['field' => 'email'], 422);
}

if (strlen($password) < 8) {
    jsonResponse(false, 'Kata sandi minimal 8 karakter.', ['field' => 'password'], 422);
}

// Instansi adalah kunci pemisahan data multi-dinas: wajib & dinormalisasi.
if ($instansi === '') {
    jsonResponse(false, 'Nama instansi wajib diisi.', ['field' => 'instansi'], 422);
}
if (mb_strlen($instansi) < 3) {
    jsonResponse(false, 'Nama instansi minimal 3 karakter.', ['field' => 'instansi'], 422);
}
if (mb_strlen($instansi) > 150) {
    jsonResponse(false, 'Nama instansi maksimal 150 karakter.', ['field' => 'instansi'], 422);
}

$pdo = db();

// Selaraskan skema kolom `users` (basis data lama belum tentu punya
// kolom `api_token`/`nip`) — lihat includes/functions.php: ensureUsersColumns().
ensureUsersColumns($pdo);

// Pakai ejaan instansi yang sudah ada (bila ada) agar tidak terbentuk
// varian tenant baru hanya karena perbedaan spasi/huruf besar-kecil.
$instansi = samakanInstansi($pdo, $instansi);

// Cek duplikasi username & email
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
$stmt->execute([$username, $email]);
if ($stmt->fetchColumn() > 0) {
    $stmt = $pdo->prepare("SELECT username, email FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    $existing = $stmt->fetch();

    if ($existing['username'] === $username) {
        jsonResponse(false, 'Username sudah digunakan.', ['field' => 'username'], 409);
    }
    if ($existing['email'] === $email) {
        jsonResponse(false, 'Email sudah terdaftar.', ['field' => 'email'], 409);
    }
}

// Hash password
$hashed = password_hash($password, PASSWORD_DEFAULT);

// Token API unik per pengguna (multi-tenant). DB menyimpan HASH-nya.
$apiToken = generateApiToken();

// Simpan pengguna baru dengan status 'pending'.
// Akun baru HARUS diverifikasi lebih dulu melalui halaman Aktivasi Akun
// (aktivasi.html -> api/aktivasi.php): cek email -> konfirmasi nama & instansi
// -> kode OTP dikirim ke email -> setelah kode benar status menjadi 'aktif'
// dan pengguna baru bisa login (lihat api/login.php).
try {
    $stmt = $pdo->prepare("
        INSERT INTO users (nama_lengkap, username, email, password, instansi, kota, provinsi, api_token, peran, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([$nama, $username, $email, $hashed, $instansi, $kota, $provinsi, hashApiToken($apiToken), $peran]);

    $userId = (int) $pdo->lastInsertId();
} catch (PDOException $e) {
    jsonResponse(false, 'Terjadi kesalahan saat menyimpan data. Silakan coba lagi.', [], 500);
}

jsonResponse(true, 'Pendaftaran berhasil! Akun Anda berstatus PENDING. Silakan verifikasi dengan kode OTP yang dikirim ke email Anda untuk mengaktifkan akun.', [
    'user_id'  => $userId,
    'status'   => 'pending',
    'email'    => $email,
    'aktivasi' => 'aktivasi.html?email=' . urlencode($email),
], 201);
