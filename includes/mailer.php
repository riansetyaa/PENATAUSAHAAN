<?php
/**
 * SIM-TKD - Pengirim Email (Kode OTP Aktivasi Akun)
 * ============================================
 * Dipakai api/aktivasi.php untuk mengirim kode OTP ke email pengguna.
 *
 * Konfigurasi (opsional, buat file config/mail.php — lihat config/mail.example.php):
 *   driver       : 'smtp' | 'mail'   (default 'mail' bila config tidak ada)
 *   host/port    : server SMTP, mis. mail.simtkd.com : 465
 *   secure       : 'ssl' | 'tls' | '' (STARTTLS)
 *   user/pass    : kredensial SMTP (kosongkan bila server tanpa AUTH)
 *   from_email   : alamat pengirim (mis. no-reply@simtkd.com)
 *   from_name    : nama pengirim  (mis. "SIM-TKD")
 *   debug_otp    : true -> kode OTP ikut dikembalikan di respons JSON API
 *                  (HANYA untuk pengujian lokal, JANGAN diaktifkan di produksi)
 *
 * Bila pengiriman gagal, kode OTP tetap dicatat ke logs/otp.log.php agar
 * administrator masih bisa membantu pengguna (mis. server email belum siap).
 */

declare(strict_types=1);

if (basename($_SERVER['PHP_SELF'] ?? '') === 'mailer.php') {
    http_response_code(403);
    exit('Akses ditolak.');
}

/**
 * Ambil konfigurasi email: config/mail.php -> environment -> default aman.
 */
function mailerConfig(): array
{
    $default = [
        'driver'     => 'mail',
        'host'       => '',
        'port'       => 587,
        'secure'     => 'tls',
        'user'       => '',
        'pass'       => '',
        'from_email' => '',
        'from_name'  => 'SIM-TKD',
        'debug_otp'  => false,
    ];

    $cfg = $default;

    $file = __DIR__ . '/../config/mail.php';
    if (is_file($file)) {
        $dariFile = require $file;
        if (is_array($dariFile)) {
            $cfg = array_merge($cfg, $dariFile);
        }
    }

    // Environment variable boleh menimpa config/mail.php (khusus driver smtp)
    $envHost = getenv('SIMTKD_SMTP_HOST');
    if ($envHost !== false && $envHost !== '') {
        $cfg['driver'] = 'smtp';
        $cfg['host']   = $envHost;
        $envPort = getenv('SIMTKD_SMTP_PORT');
        if ($envPort !== false && $envPort !== '') {
            $cfg['port'] = (int) $envPort;
        }
        $envUser = getenv('SIMTKD_SMTP_USER');
        if ($envUser !== false) {
            $cfg['user'] = $envUser;
        }
        $envPass = getenv('SIMTKD_SMTP_PASS');
        if ($envPass !== false) {
            $cfg['pass'] = $envPass;
        }
        $envSecure = getenv('SIMTKD_SMTP_SECURE');
        if ($envSecure !== false && $envSecure !== '') {
            $cfg['secure'] = $envSecure;
        }
    }

    // Pengirim default: kalau tidak diatur, pakai alamat SMTP / domain situs
    if ((string) $cfg['from_email'] === '') {
        $cfg['from_email'] = (string) $cfg['user'] !== ''
            ? (string) $cfg['user']
            : 'no-reply@' . preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? 'simtkd.com'));
    }

    return $cfg;
}

/** True bila kode OTP boleh dikembalikan di respons API (mode uji). */
function otpDebugAktif(): bool
{
    return (bool) mailerConfig()['debug_otp'];
}

/**
 * Catat kode OTP yang dibangkitkan ke berkas log server.
 * Tujuan: bila pengiriman email gagal (mis. SMTP belum dikonfigurasi),
 * administrator masih bisa membacakan kode kepada pengguna.
 *
 * Berkas log diberi ekstensi .php + baris penjaga "exit" sehingga isinya
 * TIDAK pernah tampil mentah di browser (dieksekusi, bukan diunduh), dan
 * tetap dilindungi logs/.htaccess.
 */
function catatLogOtp(string $email, string $otp, string $status, string $catatan = ''): void
{
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $file = $dir . '/otp.log.php';
    if (is_dir($dir) && !is_file($file)) {
        @file_put_contents(
            $file,
            "<?php exit('Akses ditolak.'); ?>\n# SIM-TKD - catatan kode OTP (bantuan administrator)\n"
        );
    }

    $baris = sprintf(
        "[%s] %s | to=%s | otp=%s | %s%s\n",
        date('Y-m-d H:i:s'),
        $status,
        $email,
        $otp,
        ($_SERVER['REMOTE_ADDR'] ?? '-'),
        $catatan !== '' ? ' | ' . $catatan : ''
    );

    if (!@file_put_contents($file, $baris, FILE_APPEND | LOCK_EX)) {
        // Fallback: direktori proyek tidak bisa ditulis -> pakai temp dir
        @file_put_contents(sys_get_temp_dir() . '/simtkd_otp.log', $baris, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Susun isi email HTML berisi kode OTP aktivasi akun.
 */
function bodyEmailOtp(string $nama, string $otp, string $instansi, int $berlakuMenit): string
{
    $nama     = htmlspecialchars($nama, ENT_QUOTES, 'UTF-8');
    $instansi = htmlspecialchars($instansi !== '' ? $instansi : '-', ENT_QUOTES, 'UTF-8');
    $otp      = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    $tahun    = date('Y');

    return '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#eef2f7;font-family:Segoe UI,Helvetica,Arial,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
        . 'style="max-width:520px;background:#ffffff;border-radius:12px;overflow:hidden;'
        . 'box-shadow:0 6px 24px rgba(15,36,68,.12);">'
        . '<tr><td style="background:linear-gradient(180deg,#1a3a6b 0%,#0f2444 100%);padding:22px 26px;color:#fff;">'
        . '<div style="font-size:12px;letter-spacing:2px;opacity:.85;">SIM-TKD &middot; MODUL EDUKASI</div>'
        . '<div style="font-size:18px;font-weight:700;margin-top:4px;">Verifikasi Aktivasi Akun</div>'
        . '</td></tr>'
        . '<tr><td style="padding:26px;">'
        . '<p style="margin:0 0 14px;font-size:14px;color:#2c3e50;">Halo <strong>' . $nama . '</strong>,</p>'
        . '<p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#4a5a6a;">'
        . 'Gunakan kode OTP berikut untuk mengaktifkan akun SIM-TKD Anda'
        . ' (<em>' . $instansi . '</em>). Kode berlaku <strong>' . $berlakuMenit . ' menit</strong> '
        . 'sejak email ini dikirim.</p>'
        . '<div style="text-align:center;margin:0 0 18px;">'
        . '<div style="display:inline-block;padding:16px 26px;border:2px dashed #1a3a6b;border-radius:10px;'
        . 'background:#f5f8fc;font-size:32px;font-weight:700;letter-spacing:10px;color:#16305a;">' . $otp . '</div>'
        . '</div>'
        . '<p style="margin:0 0 8px;font-size:13px;color:#7d6608;background:#fef9e7;border:1px solid #f9e79f;'
        . 'border-radius:8px;padding:10px 14px;">Jangan bagikan kode ini kepada siapa pun. '
        . 'Petugas SIM-TKD tidak pernah meminta kode OTP Anda.</p>'
        . '<p style="margin:16px 0 0;font-size:12px;color:#9098a5;">Bila Anda tidak merasa mendaftar akun '
        . 'SIM-TKD, abaikan saja email ini.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 26px;background:#f5f8fc;font-size:11px;color:#9098a5;">'
        . '&copy; ' . $tahun . ' Politeknik Negeri Bandung &mdash; Jurusan Akuntansi'
        . '</td></tr></table></td></tr></table></body></html>';
}

/**
 * Kirim email lewat server SMTP (implementasi ringan tanpa dependensi).
 * Mendukung SSL langsung (port 465) dan STARTTLS (port 587), serta AUTH LOGIN.
 *
 * @param array  $cfg      Konfigurasi (lihat mailerConfig())
 * @param string $to       Alamat tujuan
 * @param string $subject  Subjek
 * @param string $htmlBody Isi HTML
 * @param string $error    Pesan kesalahan (by reference)
 */
function smtpKirim(array $cfg, string $to, string $subject, string $htmlBody, string &$error = ''): bool
{
    $host    = (string) $cfg['host'];
    $port    = (int) $cfg['port'];
    $secure  = strtolower((string) $cfg['secure']);
    $user    = (string) $cfg['user'];
    $pass    = (string) $cfg['pass'];
    $from    = (string) $cfg['from_email'];
    $nama    = (string) $cfg['from_name'];
    $timeout = 20;

    // Bersihkan header dari karakter bahaya (header injection)
    $to      = str_replace(["\r", "\n"], '', $to);
    $from    = str_replace(["\r", "\n"], '', $from);
    $nama    = str_replace(["\r", "\n"], '', $nama);
    $subject = str_replace(["\r", "\n"], ' ', $subject);

    $transport = $secure === 'ssl' ? 'ssl://' : 'tcp://';
    $errno  = 0;
    $errstr = '';
    $fp = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, $timeout);

    if (!$fp) {
        $error = 'Koneksi SMTP gagal (' . $errno . '): ' . $errstr;
        return false;
    }

    stream_set_timeout($fp, $timeout);

    $baca = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            // Baris terakhir respons ditandai spasi pada posisi ke-4 ("250 OK")
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $data;
    };

    $perintah = function (string $cmd, array $kodeOk) use ($fp, $baca, &$error): bool {
        fwrite($fp, $cmd . "\r\n");
        $resp = $baca();
        $kode = (int) substr($resp, 0, 3);
        if (!in_array($kode, $kodeOk, true)) {
            $error = trim($resp);
            return false;
        }
        return true;
    };

    $hasil = false;

    try {
        // 1. Salam pembuka server
        $salam = $baca();
        if ((int) substr($salam, 0, 3) !== 220) {
            $error = 'SMTP tidak merespons: ' . trim($salam);
            fclose($fp);
            return false;
        }

        $ehloHost = preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? 'simtkd.com')) ?: 'simtkd.com';

        // 2. EHLO (+ STARTTLS bila diminta)
        if (!$perintah('EHLO ' . $ehloHost, [250, 220])) {
            fclose($fp);
            return false;
        }

        if ($secure === 'tls') {
            if (!$perintah('STARTTLS', [220])) {
                fclose($fp);
                return false;
            }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $error = 'Gagal memulai enkripsi TLS.';
                fclose($fp);
                return false;
            }
            if (!$perintah('EHLO ' . $ehloHost, [250, 220])) {
                fclose($fp);
                return false;
            }
        }

        // 3. Autentikasi (bila kredensial diisi)
        if ($user !== '') {
            if (!$perintah('AUTH LOGIN', [334])) {
                fclose($fp);
                return false;
            }
            if (!$perintah(base64_encode($user), [334])) {
                fclose($fp);
                return false;
            }
            if (!$perintah(base64_encode($pass), [235])) {
                fclose($fp);
                return false;
            }
        }

        // 4. Amplop pengirim & penerima
        if (!$perintah('MAIL FROM:<' . $from . '>', [250])) {
            fclose($fp);
            return false;
        }
        if (!$perintah('RCPT TO:<' . $to . '>', [250, 251])) {
            fclose($fp);
            return false;
        }

        // 5. Data email (header + isi base64 -> aman dari dot-stuffing & 8-bit)
        if (!$perintah('DATA', [354])) {
            fclose($fp);
            return false;
        }

        $headers = [
            'Date: ' . date('r'),
            'From: ' . ('' !== $nama ? '=?UTF-8?B?' . base64_encode($nama) . '?= <' . $from . '>' : $from),
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: SIM-TKD',
        ];

        fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n");
        fwrite($fp, chunk_split(base64_encode($htmlBody), 76, "\r\n"));
        fwrite($fp, "\r\n.\r\n");

        $resp  = $baca();
        $hasil = (int) substr($resp, 0, 3) === 250;
        if (!$hasil) {
            $error = trim($resp);
        }

        @fwrite($fp, "QUIT\r\n");
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $hasil = false;
    }

    @fclose($fp);
    return $hasil;
}

/**
 * Kirim email lewat fungsi mail() bawaan PHP (butuh sendmail/MTA di server).
 */
function mailKirim(array $cfg, string $to, string $subject, string $htmlBody, string &$error = ''): bool
{
    $from = str_replace(["\r", "\n"], '', (string) $cfg['from_email']);
    $nama = str_replace(["\r", "\n"], '', (string) $cfg['from_name']);
    $to   = str_replace(["\r", "\n"], '', $to);
    $subject = str_replace(["\r", "\n"], ' ', $subject);

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'From: ' . ('' !== $nama ? '=?UTF-8?B?' . base64_encode($nama) . '?= <' . $from . '>' : $from),
        'X-Mailer: SIM-TKD',
    ];

    $ok = @mail(
        $to,
        '=?UTF-8?B?' . base64_encode($subject) . '?=',
        chunk_split(base64_encode($htmlBody), 76, "\r\n"),
        implode("\r\n", $headers),
        '-f' . $from
    );

    if (!$ok) {
        $error = 'Fungsi mail() gagal mengirim email (MTA/sendmail tidak tersedia).';
    }

    return (bool) $ok;
}

/**
 * Kirim kode OTP aktivasi akun ke email pengguna.
 * Urutan percobaan: SMTP (bila dikonfigurasi) -> mail() -> catat ke log.
 *
 * @return bool true bila email berhasil diserahkan ke server pengiriman.
 */
function kirimEmailOtp(string $to, string $nama, string $otp, string $instansi = ''): bool
{
    $cfg    = mailerConfig();
    $menit  = (int) ceil(otpBerlakuDetik() / 60);
    $subjek = 'Kode OTP Aktivasi Akun SIM-TKD: ' . $otp;
    $body   = bodyEmailOtp($nama, $otp, $instansi, $menit);

    // OTP selalu dicatat di log server sebagai jalur bantuan administrator
    catatLogOtp($to, $otp, 'DIBUAT');

    $error = '';
    $ok    = false;

    if (strtolower((string) $cfg['driver']) === 'smtp' && (string) $cfg['host'] !== '') {
        $ok = smtpKirim($cfg, $to, $subjek, $body, $error);
        if (!$ok) {
            // Server SMTP gagal -> coba jalur mail() sebelum menyerah
            $errorSmtp = $error;
            $error     = '';
            $ok        = mailKirim($cfg, $to, $subjek, $body, $error);
            if (!$ok) {
                $error = 'SMTP: ' . $errorSmtp . ' | mail(): ' . $error;
            }
        }
    } else {
        $ok = mailKirim($cfg, $to, $subjek, $body, $error);
    }

    if (!$ok) {
        error_log('[SIM-TKD] Kirim OTP ke ' . $to . ' gagal: ' . $error);
        catatLogOtp($to, $otp, 'GAGAL-KIRIM', $error);
    }

    return $ok;
}
