<?php
/**
 * SIM-TKD - API Profil Pengguna
 * ============================================
 * Endpoint pengelolaan profil user yang sedang login (dipakai halaman
 * Pengaturan > Profil).
 *
 *   GET  api/profil.php                     : data profil user
 *   POST api/profil.php action=simpan       : update nama_lengkap, email, instansi
 *   POST api/profil.php action=ganti_password : ganti password (verifikasi password lama)
 *
 * Wajib login. Respons JSON. Username & peran TIDAK bisa diubah lewat sini.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    jsonResponse(false, 'Tidak terautentikasi.', [], 401);
}

$pdo    = db();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'];

if ($userId <= 0) {
    jsonResponse(false, 'Sesi tidak valid. Silakan login ulang.', [], 401);
}

/**
 * Selaraskan kolom wajib tabel `users` (nip, api_token) — migrasi otomatis,
 * idempoten. Berguna untuk instalasi lama agar halaman Profil langsung bisa
 * menyimpan NIP tanpa menjalankan SQL manual lebih dulu.
 * (Implementasi terpusat di includes/functions.php: ensureUsersColumns().)
 */
ensureUsersColumns($pdo);

/**
 * Ambil baris user dari DB.
 */
function ambilUserProfil(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(
        "SELECT id, nama_lengkap, nip, username, email, instansi, peran, status, created_at
         FROM users WHERE id = ? LIMIT 1"
    );
    $st->execute([$id]);
    $u = $st->fetch();
    return $u ?: null;
}

// ============================================
// GET - Data profil
// ============================================
if ($method === 'GET') {
    $u = ambilUserProfil($pdo, $userId);
    if (!$u) {
        jsonResponse(false, 'Pengguna tidak ditemukan.', [], 404);
    }

    jsonResponse(true, 'Data profil.', [
        'profil' => [
            'nama_lengkap' => (string) $u['nama_lengkap'],
            'nip'          => (string) ($u['nip'] ?? ''),
            'username'     => (string) $u['username'],
            'email'        => (string) $u['email'],
            'instansi'     => (string) ($u['instansi'] ?? ''),
            'peran'        => (string) $u['peran'],
            'status'       => (string) $u['status'],
            'dibuat'       => substr((string) $u['created_at'], 0, 10), // YYYY-MM-DD
        ],
    ]);
}

if ($method !== 'POST') {
    jsonResponse(false, 'Metode tidak didukung.', [], 405);
}

$action = input('action');

// ============================================
// POST action=simpan - Update nama, email, instansi
// ============================================
if ($action === 'simpan') {
    if (!rateLimitCheck('profil_simpan_' . $userId, 10, 300)) {
        jsonResponse(false, 'Terlalu banyak percobaan. Coba lagi beberapa saat lagi.', [], 429);
    }

    $nama     = input('nama_lengkap');
    $email    = strtolower(input('email'));
    $instansi = input('instansi');
    $nip      = preg_replace('/[^0-9A-Za-z.\-\/ ]/', '', input('nip'));
    $nip      = trim(preg_replace('/\s+/', ' ', (string) $nip));

    if (!isValidNama($nama)) {
        jsonResponse(false, 'Nama lengkap tidak valid (3-100 karakter huruf).', [], 422);
    }
    if (!isValidEmail($email)) {
        jsonResponse(false, 'Format email tidak valid.', [], 422);
    }
    if (mb_strlen($instansi) > 150) {
        jsonResponse(false, 'Nama instansi maksimal 150 karakter.', [], 422);
    }
    if (mb_strlen($nip) > 30) {
        jsonResponse(false, 'NIP maksimal 30 karakter.', [], 422);
    }

    // Email harus unik (kecuali milik sendiri)
    $st = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
    $st->execute([$email, $userId]);
    if ($st->fetch()) {
        jsonResponse(false, 'Email sudah dipakai oleh akun lain.', [], 409);
    }

    $st = $pdo->prepare("UPDATE users SET nama_lengkap = ?, email = ?, instansi = ?, nip = ? WHERE id = ?");
    $st->execute([
        $nama,
        $email,
        $instansi !== '' ? $instansi : null,
        $nip !== '' ? $nip : null,
        $userId,
    ]);

    // Refresh sesi agar sidebar/topbar di semua halaman langsung menampilkan data baru
    $_SESSION['nama']     = $nama;
    $_SESSION['email']    = $email;
    $_SESSION['instansi'] = $instansi;
    $_SESSION['nip']      = $nip;

    jsonResponse(true, 'Profil berhasil diperbarui.', [
        'profil' => [
            'nama_lengkap' => $nama,
            'nip'          => $nip,
            'email'        => $email,
            'instansi'     => $instansi,
        ],
    ]);
}

// ============================================
// POST action=ganti_password - Ganti password
// ============================================
if ($action === 'ganti_password') {
    if (!rateLimitCheck('profil_pw_' . $userId, 5, 300)) {
        jsonResponse(false, 'Terlalu banyak percobaan. Coba lagi beberapa saat lagi.', [], 429);
    }

    $lama = (string) ($_POST['password_lama'] ?? '');
    $baru = (string) ($_POST['password_baru'] ?? '');
    $konf = (string) ($_POST['password_konfirmasi'] ?? '');

    if ($lama === '' || $baru === '') {
        jsonResponse(false, 'Password lama dan password baru wajib diisi.', [], 422);
    }
    if (strlen($baru) < 6) {
        jsonResponse(false, 'Password baru minimal 6 karakter.', [], 422);
    }
    if ($baru !== $konf) {
        jsonResponse(false, 'Konfirmasi password tidak cocok.', [], 422);
    }
    if ($baru === $lama) {
        jsonResponse(false, 'Password baru tidak boleh sama dengan password lama.', [], 422);
    }

    $st = $pdo->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $row = $st->fetch();

    if (!$row || !password_verify($lama, (string) $row['password'])) {
        jsonResponse(false, 'Password lama salah.', [], 401);
    }

    $st = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $st->execute([password_hash($baru, PASSWORD_DEFAULT), $userId]);

    jsonResponse(true, 'Password berhasil diubah.');
}

jsonResponse(false, 'Aksi tidak dikenal.', [], 400);
