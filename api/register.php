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
 *       body: new URLSearchParams({ nama_lengkap, username, email, password, instansi, peran })
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
$instansi   = input('instansi');
$peran      = input('peran', 'Bendahara');
$kota       = input('kota');
$provinsi   = input('provinsi');

// --- Validasi server-side ---
if (!isValidNama($nama)) {
    jsonResponse(false, 'Nama lengkap minimal 3 karakter.', ['field' => 'nama_lengkap'], 422);
}

if (!isValidUsername($username)) {
    jsonResponse(false, 'Username 3-20 karakter (huruf, angka, . _ -).', ['field' => 'username'], 422);
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

$allowedRoles = ['Admin Dinas', 'Operator', 'Bendahara', 'Verifikator', 'Kepala Dinas', 'Pengguna Umum'];
if (!in_array($peran, $allowedRoles, true)) {
    $peran = 'Pengguna Umum';
}

$pdo = db();

// Selaraskan skema kolom `users` (basis data lama belum tentu punya
// kolom `api_token`/`nip`) — lihat includes/functions.php: ensureUsersColumns().
ensureUsersColumns($pdo);

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

// Simpan pengguna baru (status langsung 'aktif' -> tanpa verifikasi admin)
try {
    $stmt = $pdo->prepare("
        INSERT INTO users (nama_lengkap, username, email, password, instansi, kota, provinsi, api_token, peran, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'aktif')
    ");
    $stmt->execute([$nama, $username, $email, $hashed, $instansi, $kota, $provinsi, hashApiToken($apiToken), $peran]);

    $userId = (int) $pdo->lastInsertId();
} catch (PDOException $e) {
    jsonResponse(false, 'Terjadi kesalahan saat menyimpan data. Silakan coba lagi.', [], 500);
}

jsonResponse(true, 'Pendaftaran berhasil! Akun Anda langsung aktif. Silakan login menggunakan username atau email.', [
    'user_id' => $userId,
], 201);
