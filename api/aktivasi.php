<?php
/**
 * SIM-TKD - API Aktivasi Akun (Verifikasi Email via Kode OTP)
 * ============================================
 * Endpoint untuk mengaktifkan akun hasil pendaftaran yang masih berstatus
 * 'pending' (lihat api/register.php). Metode POST, respons JSON.
 *
 * Alur (dipakai aktivasi.html):
 *   1. action=cek         -> cek email/username, tampilkan nama akun & instansi
 *   2. action=kirim_otp   -> bangkitkan kode OTP + kirim ke email pengguna
 *   3. action=verifikasi  -> kode OTP benar -> status akun menjadi 'aktif'
 *
 * Contoh pemanggilan (fetch):
 *   fetch('api/aktivasi.php', {
 *       method: 'POST',
 *       headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
 *       body: new URLSearchParams({ action: 'cek', email })
 *   })
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

// Kode OTP hanya boleh dikirim lewat POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
}

$pdo    = db();
$action = input('action');
$kunci  = strtolower(input('email'));   // email ATAU username (kolom "email" di form)
$ip     = $_SERVER['REMOTE_ADDR'] ?? '-';

// Selaraskan skema kolom `users` (termasuk kolom OTP) — idempoten,
// agar basis data lama tetap bisa dipakai tanpa impor SQL manual.
ensureUsersColumns($pdo);

/**
 * Ambil baris pengguna (beserta kolom OTP) berdasarkan email ATAU username.
 * Fallback tanpa kolom OTP bila ALTER TABLE tidak diizinkan di server.
 */
function ambilUserAktivasi(PDO $pdo, string $kunci): ?array
{
    try {
        $st = $pdo->prepare(
            "SELECT id, nama_lengkap, username, email, instansi, kota, provinsi, peran, status,
                    otp_hash, otp_expired_at, otp_terkirim_at, otp_percobaan
             FROM users
             WHERE email = ? OR username = ?
             LIMIT 1"
        );
        $st->execute([$kunci, $kunci]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('[SIM-TKD] aktivasi ambilUser (kolom OTP?): ' . $e->getMessage());
        $st = $pdo->prepare(
            "SELECT id, nama_lengkap, username, email, instansi, kota, provinsi, peran, status
             FROM users
             WHERE email = ? OR username = ?
             LIMIT 1"
        );
        $st->execute([$kunci, $kunci]);
        $u = $st->fetch();
        if (!$u) {
            return null;
        }
        $u['otp_hash']        = null;
        $u['otp_expired_at']  = null;
        $u['otp_terkirim_at'] = null;
        $u['otp_percobaan']   = 0;
        return $u;
    }
}

/** Data pengguna yang aman dikirim ke halaman aktivasi (email disamarkan). */
function dataUserAktivasi(array $u): array
{
    return [
        'nama_lengkap' => (string) $u['nama_lengkap'],
        'username'     => (string) $u['username'],
        'email_samar'  => maskEmail((string) $u['email']),
        'instansi'     => (string) ($u['instansi'] ?? ''),
        'kota'         => (string) ($u['kota'] ?? ''),
        'provinsi'     => (string) ($u['provinsi'] ?? ''),
        'peran'        => (string) ($u['peran'] ?? ''),
        'status'       => (string) $u['status'],
    ];
}

/** Sisa detik berlakunya kode OTP (0 bila tidak ada/kedaluwarsa). */
function sisaOtpAktif(array $u): int
{
    if (empty($u['otp_hash']) || empty($u['otp_expired_at'])) {
        return 0;
    }
    $sisa = strtotime((string) $u['otp_expired_at']) - time();
    return $sisa > 0 ? $sisa : 0;
}

// Validasi dasar kunci pencarian akun
if ($kunci === '') {
    jsonResponse(false, 'Email wajib diisi.', ['field' => 'email'], 422);
}

if (!isValidEmail($kunci) && !isValidUsername($kunci)) {
    jsonResponse(false, 'Format email tidak valid.', ['field' => 'email'], 422);
}

// ============================================
// 1. action=cek — cek akun & tampilkan nama + instansi
// ============================================
if ($action === 'cek') {
    if (!rateLimitCheck('aktivasi_cek_' . $ip, 30, 300)) {
        jsonResponse(false, 'Terlalu banyak percobaan. Coba lagi dalam beberapa menit.', [], 429);
    }

    $user = ambilUserAktivasi($pdo, $kunci);

    if (!$user) {
        jsonResponse(
            false,
            'Email belum terdaftar. Pastikan email yang Anda masukkan sama dengan email saat mendaftar.',
            ['field' => 'email'],
            404
        );
    }

    if ($user['status'] === 'aktif') {
        jsonResponse(true, 'Akun Anda sudah aktif. Silakan login menggunakan email atau username Anda.', [
            'status'   => 'aktif',
            'redirect' => 'login.html',
            'user'     => dataUserAktivasi($user),
        ]);
    }

    if ($user['status'] === 'nonaktif') {
        jsonResponse(
            false,
            'Akun Anda dinonaktifkan oleh administrator. Hubungi administrator SIM-TKD untuk bantuan.',
            ['status' => 'nonaktif'],
            403
        );
    }

    $sisa = sisaOtpAktif($user);

    jsonResponse(true, 'Data akun ditemukan. Periksa nama akun dan instansi berikut, lalu klik Konfirmasi untuk menerima kode OTP.', [
        'status'         => 'pending',
        'user'           => dataUserAktivasi($user),
        'otp_aktif'      => $sisa > 0,
        'otp_sisa_detik' => $sisa,
    ]);
}

// ============================================
// 2. action=kirim_otp — kirim kode OTP ke email pengguna
// ============================================
if ($action === 'kirim_otp') {
    // Maks 5 kali kirim per 15 menit untuk satu email
    if (!rateLimitCheck('aktivasi_kirim_' . $kunci, 5, 900)) {
        jsonResponse(false, 'Permintaan kode OTP terlalu sering. Coba lagi dalam beberapa menit.', [], 429);
    }

    $user = ambilUserAktivasi($pdo, $kunci);

    if (!$user) {
        jsonResponse(false, 'Email belum terdaftar. Pastikan email yang Anda masukkan benar.', ['field' => 'email'], 404);
    }

    if ($user['status'] === 'aktif') {
        jsonResponse(true, 'Akun Anda sudah aktif. Silakan login.', [
            'status'   => 'aktif',
            'redirect' => 'login.html',
        ]);
    }

    if ($user['status'] === 'nonaktif') {
        jsonResponse(false, 'Akun Anda dinonaktifkan oleh administrator. Hubungi administrator SIM-TKD.', [], 403);
    }

    // Jeda minimal 60 detik antar pengiriman (mencegah spam & kuota email habis)
    if (!empty($user['otp_terkirim_at'])) {
        $lewat = time() - strtotime((string) $user['otp_terkirim_at']);
        if ($lewat >= 0 && $lewat < 60 && sisaOtpAktif($user) > 0) {
            $tunggu = 60 - $lewat;
            jsonResponse(false, 'Kode OTP baru saja dikirim. Tunggu ' . $tunggu . ' detik lagi untuk mengirim ulang.', [
                'tunggu_detik' => $tunggu,
            ], 429);
        }
    }

    // Bangkitkan kode OTP baru (6 digit) dan simpan HASH-nya (bukan kode mentah).
    // Timestamp dihitung di sisi PHP (bukan NOW() MySQL) agar zona waktu
    // penyimpanan sama dengan waktu pembanding di sisaOtpAktif() — server
    // hosting sering memakai zona waktu MySQL yang berbeda dari PHP.
    $otp      = generateOtp(6);
    $berlaku  = otpBerlakuDetik();
    $sekarang = date('Y-m-d H:i:s');
    $kedaluwarsa = date('Y-m-d H:i:s', time() + $berlaku);

    try {
        $st = $pdo->prepare(
            "UPDATE users
             SET otp_hash = ?, otp_expired_at = ?, otp_terkirim_at = ?, otp_percobaan = 0
             WHERE id = ? AND status = 'pending'"
        );
        $st->execute([hashOtp($otp), $kedaluwarsa, $sekarang, (int) $user['id']]);
    } catch (Throwable $e) {
        error_log('[SIM-TKD] aktivasi simpan OTP gagal: ' . $e->getMessage());
        jsonResponse(false, 'Server gagal menyimpan kode OTP. Coba lagi beberapa saat lagi.', [], 500);
    }

    // Kirim kode OTP ke email terdaftar
    $terkirim = kirimEmailOtp((string) $user['email'], (string) $user['nama_lengkap'], $otp, (string) ($user['instansi'] ?? ''));
    $menit    = (int) ceil($berlaku / 60);

    if (!$terkirim) {
        // Server email belum siap (mis. SMTP belum dikonfigurasi). Kode tetap
        // tercatat di logs/otp.log.php agar administrator bisa membantu pengguna.
        $extra = ['email_samar' => maskEmail((string) $user['email'])];
        if (otpDebugAktif()) {
            $extra['dev_otp'] = $otp;
        }
        jsonResponse(
            false,
            'Kode OTP gagal dikirim ke email ' . maskEmail((string) $user['email'])
                . '. Hubungi administrator SIM-TKD (kode tercatat di log server).',
            $extra,
            500
        );
    }

    $extra = [
        'email_samar'   => maskEmail((string) $user['email']),
        'berlaku_menit' => $menit,
        'panjang_otp'   => 6,
    ];
    // Mode uji (config/mail.php: debug_otp) -> kode ikut dikembalikan
    if (otpDebugAktif()) {
        $extra['dev_otp'] = $otp;
    }

    jsonResponse(
        true,
        'Kode OTP telah dikirim ke email ' . maskEmail((string) $user['email'])
            . '. Kode berlaku ' . $menit . ' menit.',
        $extra
    );
}

// ============================================
// 3. action=verifikasi — cocokkan kode OTP lalu aktifkan akun
// ============================================
if ($action === 'verifikasi') {
    $otp = preg_replace('/\D/', '', input('otp'));

    if ($otp === '') {
        jsonResponse(false, 'Kode OTP wajib diisi.', ['field' => 'otp'], 422);
    }

    if (strlen($otp) !== 6) {
        jsonResponse(false, 'Kode OTP terdiri dari 6 angka.', ['field' => 'otp'], 422);
    }

    // Batas percobaan tebak kode (per email + IP) untuk mencegah brute force
    if (!rateLimitCheck('aktivasi_verif_' . $kunci . '_' . $ip, 15, 900)) {
        jsonResponse(false, 'Terlalu banyak percobaan verifikasi. Minta kode OTP baru atau coba lagi nanti.', [], 429);
    }

    $user = ambilUserAktivasi($pdo, $kunci);

    if (!$user) {
        jsonResponse(false, 'Email belum terdaftar.', ['field' => 'email'], 404);
    }

    if ($user['status'] === 'aktif') {
        jsonResponse(true, 'Akun Anda sudah aktif. Silakan login.', [
            'status'   => 'aktif',
            'redirect' => 'login.html',
        ]);
    }

    if ($user['status'] === 'nonaktif') {
        jsonResponse(false, 'Akun Anda dinonaktifkan oleh administrator. Hubungi administrator SIM-TKD.', [], 403);
    }

    if (empty($user['otp_hash'])) {
        jsonResponse(false, 'Kode OTP belum dibuat. Klik Konfirmasi untuk menerima kode OTP lebih dulu.', ['field' => 'otp'], 422);
    }

    if (sisaOtpAktif($user) <= 0) {
        // Kode kedaluwarsa -> hapus agar tidak bisa dipakai lagi
        try {
            $pdo->prepare("UPDATE users SET otp_hash = NULL, otp_expired_at = NULL, otp_percobaan = 0 WHERE id = ?")
                ->execute([(int) $user['id']]);
        } catch (Throwable $e) {
            // abaikan
        }
        jsonResponse(false, 'Kode OTP sudah kedaluwarsa. Klik "Kirim Ulang Kode" untuk meminta kode baru.', [
            'kedaluwarsa' => true,
        ], 422);
    }

    $percobaan = (int) ($user['otp_percobaan'] ?? 0);
    if ($percobaan >= otpTebakMaksimal()) {
        jsonResponse(false, 'Terlalu banyak kode salah untuk kode OTP ini. Klik "Kirim Ulang Kode" untuk meminta kode baru.', [
            'harus_kirim_ulang' => true,
        ], 429);
    }

    if (!password_verify($otp, (string) $user['otp_hash'])) {
        $sisaPercobaan = otpTebakMaksimal() - ($percobaan + 1);
        try {
            $pdo->prepare("UPDATE users SET otp_percobaan = otp_percobaan + 1 WHERE id = ?")
                ->execute([(int) $user['id']]);
        } catch (Throwable $e) {
            // abaikan
        }
        jsonResponse(false, 'Kode OTP salah. Sisa percobaan: ' . max(0, $sisaPercobaan) . '.', [
            'field'          => 'otp',
            'sisa_percobaan' => max(0, $sisaPercobaan),
        ], 401);
    }

    // Kode benar -> aktifkan akun & bersihkan kolom OTP
    try {
        $st = $pdo->prepare(
            "UPDATE users
             SET status = 'aktif', otp_hash = NULL, otp_expired_at = NULL,
                 otp_percobaan = 0, otp_verifikasi_at = ?
             WHERE id = ? AND status = 'pending'"
        );
        $st->execute([date('Y-m-d H:i:s'), (int) $user['id']]);
    } catch (Throwable $e) {
        error_log('[SIM-TKD] aktivasi akun gagal: ' . $e->getMessage());
        jsonResponse(false, 'Server gagal mengaktifkan akun. Coba lagi beberapa saat lagi.', [], 500);
    }

    rateLimitClear('aktivasi_verif_' . $kunci . '_' . $ip);

    jsonResponse(true, 'Akun Anda berhasil diaktifkan! Silakan login menggunakan username atau email Anda.', [
        'status'   => 'aktif',
        'redirect' => 'login.html',
        'user'     => dataUserAktivasi(array_merge($user, ['status' => 'aktif'])),
    ]);
}

jsonResponse(false, 'Aksi tidak dikenal.', [], 400);
