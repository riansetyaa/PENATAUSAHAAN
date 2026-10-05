<?php
/**
 * SIM-TKD - API Lupa Password (Reset via Kode OTP Email)
 * Alur: cek -> kirim_otp -> reset. Hanya akun 'aktif'.
 * Memakai ulang kolom otp_* (sama seperti aktivasi).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
}

$pdo    = db();
$action = input('action');
$kunci  = strtolower(input('email'));
$ip     = $_SERVER['REMOTE_ADDR'] ?? '-';

ensureUsersColumns($pdo);

function ambilUserReset(PDO $pdo, string $kunci): ?array
{
    try {
        $st = $pdo->prepare(
            "SELECT id, nama_lengkap, username, email, instansi, kota, provinsi, peran, status,
                    otp_hash, otp_expired_at, otp_terkirim_at, otp_percobaan
             FROM users WHERE email = ? OR username = ? LIMIT 1"
        );
        $st->execute([$kunci, $kunci]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('[SIM-TKD] lupa-password ambilUser: ' . $e->getMessage());
        $st = $pdo->prepare(
            "SELECT id, nama_lengkap, username, email, instansi, kota, provinsi, peran, status
             FROM users WHERE email = ? OR username = ? LIMIT 1"
        );
        $st->execute([$kunci, $kunci]);
        $u = $st->fetch();
        if (!$u) return null;
        $u['otp_hash'] = null; $u['otp_expired_at'] = null;
        $u['otp_terkirim_at'] = null; $u['otp_percobaan'] = 0;
        return $u;
    }
}

function dataUserReset(array $u): array
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

function sisaOtpReset(array $u): int
{
    if (empty($u['otp_hash']) || empty($u['otp_expired_at'])) return 0;
    $sisa = strtotime((string) $u['otp_expired_at']) - time();
    return $sisa > 0 ? $sisa : 0;
}
function kirimEmailReset(string $to, string $nama, string $otp): bool
{
    $cfg   = mailerConfig();
    $menit = (int) ceil(otpBerlakuDetik() / 60);
    $namaEsc = htmlspecialchars($nama, ENT_QUOTES, 'UTF-8');
    $otpEsc  = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    $tahun   = date('Y');
    $body = '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#eef2f7;font-family:Segoe UI,Helvetica,Arial,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
        . 'style="max-width:520px;background:#ffffff;border-radius:12px;overflow:hidden;'
        . 'box-shadow:0 6px 24px rgba(15,36,68,.12);">'
        . '<tr><td style="background:linear-gradient(180deg,#1a3a6b 0%,#0f2444 100%);padding:22px 26px;color:#fff;">'
        . '<div style="font-size:12px;letter-spacing:2px;opacity:.85;">SIM-TKD &middot; MODUL EDUKASI</div>'
        . '<div style="font-size:18px;font-weight:700;margin-top:4px;">Reset Kata Sandi</div>'
        . '</td></tr><tr><td style="padding:26px;">'
        . '<p style="margin:0 0 14px;font-size:14px;color:#2c3e50;">Halo <strong>' . $namaEsc . '</strong>,</p>'
        . '<p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#4a5a6a;">'
        . 'Kami menerima permintaan reset kata sandi untuk akun SIM-TKD Anda. '
        . 'Gunakan kode OTP berikut. Kode berlaku <strong>' . $menit . ' menit</strong>.</p>'
        . '<div style="text-align:center;margin:0 0 18px;">'
        . '<div style="display:inline-block;padding:16px 26px;border:2px dashed #1a3a6b;border-radius:10px;'
        . 'background:#f5f8fc;font-size:32px;font-weight:700;letter-spacing:10px;color:#16305a;">' . $otpEsc . '</div>'
        . '</div>'
        . '<p style="margin:0 0 8px;font-size:13px;color:#7d6608;background:#fef9e7;border:1px solid #f9e79f;'
        . 'border-radius:8px;padding:10px 14px;">Jangan bagikan kode ini kepada siapa pun.</p>'
        . '<p style="margin:16px 0 0;font-size:12px;color:#9098a5;">Bila Anda tidak meminta reset, abaikan email ini.</p>'
        . '</td></tr><tr><td style="padding:16px 26px;background:#f5f8fc;font-size:11px;color:#9098a5;">'
        . '&copy; ' . $tahun . ' Politeknik Negeri Bandung &mdash; Jurusan Akuntansi'
        . '</td></tr></table></td></tr></table></body></html>';
    $subjek = 'Kode OTP Reset Kata Sandi SIM-TKD: ' . $otp;
    catatLogOtp($to, $otp, 'RESET-DIBUAT');
    $error = ''; $ok = false;
    if (strtolower((string) $cfg['driver']) === 'smtp' && (string) $cfg['host'] !== '') {
        $ok = smtpKirim($cfg, $to, $subjek, $body, $error);
        if (!$ok) {
            $errorSmtp = $error; $error = '';
            $ok = mailKirim($cfg, $to, $subjek, $body, $error);
            if (!$ok) $error = 'SMTP: ' . $errorSmtp . ' | mail(): ' . $error;
        }
    } else {
        $ok = mailKirim($cfg, $to, $subjek, $body, $error);
    }
    if (!$ok) {
        error_log('[SIM-TKD] Kirim OTP reset ke ' . $to . ' gagal: ' . $error);
        catatLogOtp($to, $otp, 'RESET-GAGAL-KIRIM', $error);
    }
    return $ok;
}

if ($kunci === '') {
    jsonResponse(false, 'Email wajib diisi.', ['field' => 'email'], 422);
}
if (!isValidEmail($kunci) && !isValidUsername($kunci)) {
    jsonResponse(false, 'Format email tidak valid.', ['field' => 'email'], 422);
}

if ($action === 'cek') {
    if (!rateLimitCheck('reset_cek_' . $ip, 30, 300)) {
        jsonResponse(false, 'Terlalu banyak percobaan. Coba lagi dalam beberapa menit.', [], 429);
    }
    $user = ambilUserReset($pdo, $kunci);
    if (!$user) {
        jsonResponse(false, 'Email belum terdaftar. Pastikan email sama dengan saat mendaftar.', ['field' => 'email'], 404);
    }
    if ($user['status'] === 'nonaktif') {
        jsonResponse(false, 'Akun dinonaktifkan administrator. Hubungi administrator SIM-TKD.', ['status' => 'nonaktif'], 403);
    }
    if ($user['status'] === 'pending') {
        jsonResponse(false, 'Akun belum diverifikasi. Aktifkan akun dulu via halaman Aktivasi Akun.', [
            'status' => 'pending', 'need_activation' => true,
            'aktivasi' => 'aktivasi.html?email=' . urlencode((string) $user['email']),
        ], 403);
    }
    $sisa = sisaOtpReset($user);
    jsonResponse(true, 'Data akun ditemukan. Periksa nama & instansi, lalu klik Konfirmasi untuk menerima kode OTP reset.', [
        'status' => 'aktif', 'user' => dataUserReset($user),
        'otp_aktif' => $sisa > 0, 'otp_sisa_detik' => $sisa,
    ]);
}

if ($action === 'kirim_otp') {
    if (!rateLimitCheck('reset_kirim_' . $kunci, 5, 900)) {
        jsonResponse(false, 'Permintaan kode OTP terlalu sering. Coba lagi dalam beberapa menit.', [], 429);
    }
    $user = ambilUserReset($pdo, $kunci);
    if (!$user) {
        jsonResponse(false, 'Email belum terdaftar.', ['field' => 'email'], 404);
    }
    if ($user['status'] === 'nonaktif') {
        jsonResponse(false, 'Akun dinonaktifkan administrator.', [], 403);
    }
    if ($user['status'] === 'pending') {
        jsonResponse(false, 'Akun belum diverifikasi. Aktifkan dulu via halaman Aktivasi Akun.', [
            'status' => 'pending', 'need_activation' => true,
            'aktivasi' => 'aktivasi.html?email=' . urlencode((string) $user['email']),
        ], 403);
    }
    if (!empty($user['otp_terkirim_at'])) {
        $lewat = time() - strtotime((string) $user['otp_terkirim_at']);
        if ($lewat >= 0 && $lewat < 60 && sisaOtpReset($user) > 0) {
            $tunggu = 60 - $lewat;
            jsonResponse(false, 'Kode baru saja dikirim. Tunggu ' . $tunggu . ' detik lagi.', ['tunggu_detik' => $tunggu], 429);
        }
    }
    $otp = generateOtp(6);
    $berlaku = otpBerlakuDetik();
    $sekarang = date('Y-m-d H:i:s');
    $kedaluwarsa = date('Y-m-d H:i:s', time() + $berlaku);
    try {
        $st = $pdo->prepare(
            "UPDATE users SET otp_hash = ?, otp_expired_at = ?, otp_terkirim_at = ?, otp_percobaan = 0
             WHERE id = ? AND status = 'aktif'"
        );
        $st->execute([hashOtp($otp), $kedaluwarsa, $sekarang, (int) $user['id']]);
    } catch (Throwable $e) {
        error_log('[SIM-TKD] reset simpan OTP gagal: ' . $e->getMessage());
        jsonResponse(false, 'Server gagal menyimpan kode OTP.', [], 500);
    }
    $terkirim = kirimEmailReset((string) $user['email'], (string) $user['nama_lengkap'], $otp);
    $menit = (int) ceil($berlaku / 60);
    if (!$terkirim) {
        $extra = ['email_samar' => maskEmail((string) $user['email'])];
        if (otpDebugAktif()) $extra['dev_otp'] = $otp;
        jsonResponse(false, 'Kode gagal dikirim ke ' . maskEmail((string) $user['email']) . '. Hubungi administrator.', $extra, 500);
    }
    $extra = ['email_samar' => maskEmail((string) $user['email']), 'berlaku_menit' => $menit, 'panjang_otp' => 6];
    if (otpDebugAktif()) $extra['dev_otp'] = $otp;
    jsonResponse(true, 'Kode OTP reset dikirim ke ' . maskEmail((string) $user['email']) . '. Berlaku ' . $menit . ' menit.', $extra);
}
if ($action === 'reset') {
    $otp = preg_replace('/\D/', '', input('otp'));
    $baru = (string) ($_POST['password_baru'] ?? '');
    $ulangi = (string) ($_POST['password_ulangi'] ?? '');
    if ($otp === '') {
        jsonResponse(false, 'Kode OTP wajib diisi.', ['field' => 'otp'], 422);
    }
    if (strlen($otp) !== 6) {
        jsonResponse(false, 'Kode OTP terdiri dari 6 angka.', ['field' => 'otp'], 422);
    }
    if (strlen($baru) < 8) {
        jsonResponse(false, 'Kata sandi baru minimal 8 karakter.', ['field' => 'password_baru'], 422);
    }
    if ($baru !== $ulangi) {
        jsonResponse(false, 'Konfirmasi kata sandi tidak sama.', ['field' => 'password_ulangi'], 422);
    }
    if (!rateLimitCheck('reset_verif_' . $kunci . '_' . $ip, 15, 900)) {
        jsonResponse(false, 'Terlalu banyak percobaan. Minta kode baru atau coba lagi nanti.', [], 429);
    }
    $user = ambilUserReset($pdo, $kunci);
    if (!$user) {
        jsonResponse(false, 'Email belum terdaftar.', ['field' => 'email'], 404);
    }
    if ($user['status'] === 'nonaktif') {
        jsonResponse(false, 'Akun dinonaktifkan administrator.', [], 403);
    }
    if ($user['status'] === 'pending') {
        jsonResponse(false, 'Akun belum diverifikasi. Aktifkan dulu via halaman Aktivasi Akun.', [
            'status' => 'pending', 'need_activation' => true,
            'aktivasi' => 'aktivasi.html?email=' . urlencode((string) $user['email']),
        ], 403);
    }
    if (empty($user['otp_hash'])) {
        jsonResponse(false, 'Kode OTP belum dibuat. Klik Konfirmasi dulu.', ['field' => 'otp'], 422);
    }
    if (sisaOtpReset($user) <= 0) {
        try {
            $pdo->prepare("UPDATE users SET otp_hash = NULL, otp_expired_at = NULL, otp_percobaan = 0 WHERE id = ?")
                ->execute([(int) $user['id']]);
        } catch (Throwable $e) { /* abaikan */ }
        jsonResponse(false, 'Kode OTP kedaluwarsa. Klik "Kirim Ulang Kode".', ['kedaluwarsa' => true], 422);
    }
    $percobaan = (int) ($user['otp_percobaan'] ?? 0);
    if ($percobaan >= otpTebakMaksimal()) {
        jsonResponse(false, 'Terlalu banyak kode salah. Klik "Kirim Ulang Kode".', ['harus_kirim_ulang' => true], 429);
    }
    if (!password_verify($otp, (string) $user['otp_hash'])) {
        $sisa = otpTebakMaksimal() - ($percobaan + 1);
        try {
            $pdo->prepare("UPDATE users SET otp_percobaan = otp_percobaan + 1 WHERE id = ?")
                ->execute([(int) $user['id']]);
        } catch (Throwable $e) { /* abaikan */ }
        jsonResponse(false, 'Kode OTP salah. Sisa percobaan: ' . max(0, $sisa) . '.', [
            'field' => 'otp', 'sisa_percobaan' => max(0, $sisa),
        ], 401);
    }
    try {
        $st = $pdo->prepare(
            "UPDATE users SET password = ?, api_token = NULL,
                 otp_hash = NULL, otp_expired_at = NULL,
                 otp_percobaan = 0, otp_verifikasi_at = ?
             WHERE id = ? AND status = 'aktif'"
        );
        $st->execute([password_hash($baru, PASSWORD_DEFAULT), date('Y-m-d H:i:s'), (int) $user['id']]);
    } catch (Throwable $e) {
        error_log('[SIM-TKD] reset password gagal: ' . $e->getMessage());
        jsonResponse(false, 'Server gagal menyimpan kata sandi baru.', [], 500);
    }
    rateLimitClear('reset_verif_' . $kunci . '_' . $ip);
    jsonResponse(true, 'Kata sandi berhasil diubah. Silakan login dengan kata sandi baru.', [
        'status' => 'aktif', 'redirect' => 'login.html',
    ]);
}

jsonResponse(false, 'Aksi tidak dikenal.', [], 400);


