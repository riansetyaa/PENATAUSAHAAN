<?php
/**
 * SIM-TKD - Helper Functions
 * ============================================
 * Fungsi bantu untuk validasi, sanitasi, dan respons JSON API.
 */

declare(strict_types=1);

if (basename($_SERVER['PHP_SELF'] ?? '') === 'functions.php') {
    http_response_code(403);
    exit('Akses ditolak.');
}

/**
 * Kirim respons JSON dan hentikan eksekusi.
 */
function jsonResponse(bool $success, string $message, array $extra = [], int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(
        ['success' => $success, 'message' => $message],
        $extra
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Ambil nilai input (POST/GET) lalu trim.
 */
function input(string $key, string $default = ''): string
{
    if (isset($_POST[$key])) {
        return trim((string) $_POST[$key]);
    }
    if (isset($_GET[$key])) {
        return trim((string) $_GET[$key]);
    }
    return $default;
}

/**
 * Validasi format email.
 */
function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validasi username: 3-20 karakter, huruf/angka/._-
 */
function isValidUsername(string $username): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9._-]{3,20}$/', $username);
}

/**
 * Validasi nama lengkap: minimal 3 karakter.
 */
function isValidNama(string $nama): bool
{
    return mb_strlen($nama) >= 3;
}

/**
 * Generate captcha sederhana untuk edukasi (opsional, sisi server).
 */
function generateCaptchaCode(int $length = 5): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, $max)];
    }
    return $code;
}

/**
 * Ambil instansi (tenant key) dari sesi — WAJIB terisi.
 * Fail-closed: jika kosong, akses ditolak (bukan melewatkan filter skpd).
 * Return: string instansi. Menghentikan eksekusi dengan 403 jika kosong.
 */
function requireInstansi(): string
{
    $skpd = trim((string) ($_SESSION['instansi'] ?? ''));
    if ($skpd === '') {
        jsonResponse(false, 'Instansi belum diatur pada akun Anda. Hubungi administrator.', [], 403);
    }
    return $skpd;
}

/**
 * Normalisasi nama instansi (tenant key).
 * ---------------------------------------
 * `users.instansi` adalah kunci pemisahan data multi-dinas, jadi ejaan yang
 * berbeda sedikit ("Dinas  X", " Dinas X ", "Dinas X&Y") dapat membuat data
 * terlihat terpisah/tercampur. Fungsi ini:
 *   - memangkas spasi di awal/akhir,
 *   - merapatkan spasi ganda/tab/karakter tak terlihat menjadi satu spasi,
 *   - menormalkan spasi di sekitar '&' menjadi " & ".
 * Huruf besar/kecil TIDAK diubah agar nama instansi tetap enak dibaca;
 * perbandingan di MySQL sudah case-insensitive (collation *_ci).
 */
function normalizeInstansi(string $instansi): string
{
    $s = trim($instansi);
    // Buang karakter kontrol (termasuk NBSP) dan rapikan semua whitespace.
    $s = (string) preg_replace('/[\x00-\x1F\x7F\x{00A0}]+/u', ' ', $s);
    $s = (string) preg_replace('/\s+/u', ' ', $s);
    // "Dinas X&Y" / "Dinas X &Y" -> "Dinas X & Y".
    $s = (string) preg_replace('/\s*&\s*/u', ' & ', $s);

    return trim($s);
}

/**
 * Samakan ejaan instansi dengan ejaan yang sudah dipakai akun lain.
 * ----------------------------------------------------------------
 * Mencegah dua akun yang sebenarnya satu dinas tersimpan dengan ejaan
 * berbeda ("Dinas Kesehatan" vs "dinas kesehatan" vs "Dinas  Kesehatan").
 * Perbandingan mengikuti collation kolom (case-insensitive).
 */
function samakanInstansi(PDO $pdo, string $instansi, ?int $kecualiUserId = null): string
{
    if ($instansi === '') {
        return '';
    }

    $sql    = "SELECT instansi FROM users
               WHERE instansi IS NOT NULL AND TRIM(instansi) <> '' AND instansi = ?";
    $params = [$instansi];

    if ($kecualiUserId !== null) {
        $sql     .= " AND id <> ?";
        $params[] = $kecualiUserId;
    }

    $sql .= " ORDER BY id ASC LIMIT 1";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $ada = $st->fetchColumn();
    } catch (Throwable $e) {
        error_log('[SIM-TKD] samakanInstansi: ' . $e->getMessage());
        return $instansi;
    }

    return $ada === false ? $instansi : normalizeInstansi((string) $ada);
}

/**
 * Validasi tahun anggaran (int, rentang wajar).
 */
function isValidTahun($tahun): bool
{
    return is_numeric($tahun) && (int) $tahun >= 2000 && (int) $tahun <= 2100;
}

/**
 * Validasi tanggal format YYYY-MM-DD.
 */
function isValidTanggal(string $tgl): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $tgl));
    return checkdate($m, $d, $y);
}

/**
 * Generate token API acak (32 hex char). Token MENTAH hanya diketahui user;
 * yang disimpan di DB adalah HASH-nya (lihat hashApiToken()).
 */
function generateApiToken(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Hash token API untuk penyimpanan di DB (SHA-256 hex, 64 char).
 * DB tidak pernah menyimpan token mentah -> kebocoran DB tidak langsung
 * memungkinkan impersonasi akun.
 */
function hashApiToken(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Bangkitkan kode OTP numerik (default 6 digit) memakai CSPRNG.
 * Dipakai untuk verifikasi/aktivasi akun (lihat api/aktivasi.php).
 */
function generateOtp(int $length = 6): string
{
    $otp = '';
    for ($i = 0; $i < $length; $i++) {
        $otp .= (string) random_int(0, 9);
    }
    return $otp;
}

/**
 * Hash kode OTP untuk disimpan di DB.
 * Memakai password_hash() (bcrypt) — bukan hash cepat — supaya bila DB
 * bocor, kode OTP 6 digit tidak bisa dibalik/di-brute force secara instan.
 */
function hashOtp(string $otp): string
{
    return password_hash($otp, PASSWORD_DEFAULT);
}

/** Jumlah maksimal percobaan salah memasukkan satu kode OTP. */
function otpTebakMaksimal(): int
{
    return 5;
}

/** Masa berlaku kode OTP (detik). */
function otpBerlakuDetik(): int
{
    return 600; // 10 menit
}

/**
 * Samarkan email untuk ditampilkan di layar (mis. "ri***@polban.ac.id").
 * Dipakai agar halaman aktivasi tidak membocorkan alamat email lengkap.
 */
function maskEmail(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false || $at === 0) {
        return $email;
    }

    $user   = substr($email, 0, $at);
    $domain = substr($email, $at);
    $awal   = substr($user, 0, min(2, strlen($user)));

    return $awal . str_repeat('*', max(1, strlen($user) - strlen($awal))) . $domain;
}

/**
 * Rate limit sederhana berbasis file (tanpa DB/Redis).
 * Key bebas (mis. "login:user" atau "login:ip"). Max $max percobaan dalam $windowDetik.
 * Return true jika DIIZINKAN (dan mencatat percobaan), false jika melebihi batas.
 */
function rateLimitCheck(string $key, int $max = 5, int $windowDetik = 300): bool
{
    $dir = sys_get_temp_dir() . '/simtkd_ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . '/' . hash('sha256', $key) . '.json';
    $now = time();
    $attempts = [];
    if (is_file($file)) {
        $attempts = json_decode((string) @file_get_contents($file), true) ?: [];
        // buang entri di luar jendela waktu
        $attempts = array_values(array_filter($attempts, function ($t) use ($now, $windowDetik) {
            return ($now - (int) $t) < $windowDetik;
        }));
    }
    if (count($attempts) >= $max) {
        return false;
    }
    $attempts[] = $now;
    @file_put_contents($file, json_encode($attempts), LOCK_EX);
    return true;
}

/**
 * Hapus catatan rate limit untuk key (mis. setelah login sukses).
 */
function rateLimitClear(string $key): void
{
    $path = sys_get_temp_dir() . '/simtkd_ratelimit/' . hash('sha256', $key) . '.json';
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Pastikan sebuah kolom ada pada tabel (idempoten & aman diulang).
 * ---------------------------------------------------------------
 * Dipakai untuk MENYELARASKAN SKEMA basis data lama dengan kode terbaru
 * tanpa perlu impor SQL manual. Contoh: kolom `users.nip` (dipakai blok
 * tanda tangan) dan `users.api_token` (token multi-tenant).
 *
 * Tanpa penjagaan ini, endpoint yang memilih kolom tersebut (mis. login)
 * langsung gagal dengan PDOException "Unknown column" sehingga pengguna
 * TIDAK BISA LOGIN sama sekali — dan halaman Profil (yang punya
 * penambah-kolom) tidak pernah terjangkau.
 *
 * Kompatibel MySQL & MariaDB: cek information_schema lewat SHOW COLUMNS
 * (tanpa ADD COLUMN IF NOT EXISTS), hanya ALTER bila kolom belum ada.
 *
 * @param PDO    $pdo    Koneksi basis data
 * @param string $tabel  Nama tabel  (mis. 'users')
 * @param string $kolom  Nama kolom  (mis. 'nip')
 * @param string $ddl    Definisi kolom lengkap, mis. "`nip` VARCHAR(30) DEFAULT NULL AFTER `nama_lengkap`"
 */
function ensureColumn(PDO $pdo, string $tabel, string $kolom, string $ddl): void
{
    static $sudahDicek = [];

    $kunci = $tabel . '.' . $kolom;
    if (isset($sudahDicek[$kunci])) {
        return;
    }
    $sudahDicek[$kunci] = true;

    try {
        $ada = $pdo->query('SHOW COLUMNS FROM `' . $tabel . '` LIKE ' . $pdo->quote($kolom))->fetch();
        if (!$ada) {
            $pdo->exec('ALTER TABLE `' . $tabel . '` ADD COLUMN ' . $ddl);
        }
    } catch (Throwable $e) {
        // Bila gagal (mis. hak akses ALTER ditolak), cukup dicatat di log —
        // pemanggil tetap melanjutkan agar tidak mematikan seluruh endpoint.
        error_log('[SIM-TKD] ensureColumn ' . $kunci . ': ' . $e->getMessage());
    }
}

/**
 * Selaraskan kolom wajib pada tabel `users`.
 * Dipanggil oleh endpoint yang bergantung pada kolom tersebut
 * (login, register, profil, aktivasi) agar basis data lama tetap bisa dipakai.
 */
function ensureUsersColumns(PDO $pdo): void
{
    ensureColumn($pdo, 'users', 'nip', '`nip` VARCHAR(30) DEFAULT NULL AFTER `nama_lengkap`');
    ensureColumn($pdo, 'users', 'api_token', '`api_token` VARCHAR(64) DEFAULT NULL AFTER `provinsi`');

    // Kolom verifikasi OTP (aktivasi akun berstatus 'pending').
    // Lihat api/aktivasi.php & database/users_otp.sql.
    ensureColumn($pdo, 'users', 'otp_hash', '`otp_hash` VARCHAR(255) DEFAULT NULL AFTER `api_token`');
    ensureColumn($pdo, 'users', 'otp_expired_at', '`otp_expired_at` DATETIME DEFAULT NULL AFTER `otp_hash`');
    ensureColumn($pdo, 'users', 'otp_terkirim_at', '`otp_terkirim_at` DATETIME DEFAULT NULL AFTER `otp_expired_at`');
    ensureColumn($pdo, 'users', 'otp_percobaan', '`otp_percobaan` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `otp_terkirim_at`');
    ensureColumn($pdo, 'users', 'otp_verifikasi_at', '`otp_verifikasi_at` DATETIME DEFAULT NULL AFTER `otp_percobaan`');
}

