<?php

/**
 * SIM-TKD — PROKSI CUTOVER (sisi legacy)
 * ======================================
 *
 * Jembatan untuk MIGRASI BERTAHAP TANPA DOWNTIME.
 *
 * CARA KERJA
 * ----------
 *   1. Setiap file API legacy yang modulnya sudah selesai diporting memanggil
 *      `cutover_maybe_proxy(__FILE__);` di awal (setelah bootstrap sesi).
 *   2. Bila endpoint tersebut AKTIF di config/cutover.php, request diteruskan
 *      (proxy) ke aplikasi Laravel beserta TIKET SSO ber-HMAC yang berisi
 *      identitas user dari sesi legacy -> pengguna tidak perlu login ulang.
 *   3. Bila TIDAK aktif (bawaan), fungsi ini langsung kembali dan file legacy
 *      bekerja seperti biasa. Tidak ada perubahan perilaku apa pun.
 *
 * JAMINAN "TANPA DOWNTIME"
 * ------------------------
 *   - Mati secara bawaan; hanya endpoint yang didaftarkan & dinyalakan yang
 *     dialihkan.
 *   - Rollback instan: `enabled => false` (atau env SIMTKD_CUTOVER=0).
 *   - Fail-safe: bila Laravel tidak terjangkau, endpoint baca-saja
 *     (`fallback => true`) dilayani legacy lagi; endpoint tulis dibalas 503
 *     (bukan diam-diam ditulis dua kali).
 *   - Canary: `rollout` persen + "sticky" per user sehingga satu pengguna
 *     tidak berpindah-pindah sistem dalam satu sesi.
 *   - Tidak ada perubahan skema/data: legacy & Laravel memakai database yang
 *     sama.
 *
 * LOG: logs/cutover.log (dilindungi logs/.htaccess) + audit trail di sisi
 * Laravel (storage/logs/laravel.log).
 */

declare(strict_types=1);

if (function_exists('cutover_maybe_proxy')) {
    return;
}

/**
 * Konfigurasi cutover (+ override dari environment, untuk kill switch
 * tanpa perlu mengubah file di server).
 */
function cutover_config(): array
{
    static $cfg = null;

    if ($cfg !== null) {
        return $cfg;
    }

    $file = __DIR__ . '/../config/cutover.php';
    $cfg  = is_file($file) ? (array) require $file : [];

    $env = static function (string $key) {
        $v = getenv($key);

        return ($v === false || $v === '') ? null : (string) $v;
    };

    if (($on = $env('SIMTKD_CUTOVER')) !== null) {
        $cfg['enabled'] = in_array(strtolower($on), ['1', 'true', 'on', 'ya'], true);
    }

    if (($rollout = $env('SIMTKD_CUTOVER_ROLLOUT')) !== null) {
        $cfg['rollout'] = max(0, min(100, (int) $rollout));
    }

    if (($base = $env('SIMTKD_CUTOVER_BASE')) !== null) {
        $cfg['base_url'] = rtrim($base, '/');
    }

    if (($secret = $env('SIMTKD_CUTOVER_SECRET')) !== null) {
        $cfg['sso_secret'] = $secret;
    }

    $cfg['base_url'] = rtrim((string) ($cfg['base_url'] ?? ''), '/');

    return $cfg;
}

/**
 * Siap melakukan pengalihan? (saklar utama + secret + alamat tujuan)
 */
function cutover_siap(): bool
{
    $cfg = cutover_config();

    return !empty($cfg['enabled'])
        && (string) ($cfg['sso_secret'] ?? '') !== ''
        && (string) ($cfg['base_url'] ?? '') !== '';
}

/** Catat aktivitas cutover ke logs/cutover.log (tidak pernah menghentikan request). */
function cutover_log(string $pesan, array $konteks = []): void
{
    $cfg = cutover_config();

    if (empty($cfg['log'])) {
        return;
    }

    $dir = __DIR__ . '/../logs';

    if (!is_dir($dir)) {
        return;
    }

    $baris = date('Y-m-d H:i:s')
        . ' ' . ($_SERVER['REMOTE_ADDR'] ?? '-')
        . ' ' . ($_SERVER['REQUEST_METHOD'] ?? '-')
        . ' ' . ($_SERVER['REQUEST_URI'] ?? '-')
        . ' | ' . $pesan;

    if ($konteks !== []) {
        $json = json_encode($konteks, JSON_UNESCAPED_UNICODE);
        $baris .= ' | ' . ($json === false ? '' : $json);
    }

    @file_put_contents($dir . '/cutover.log', $baris . "\n", FILE_APPEND | LOCK_EX);
}

/** Path relatif file legacy dari akar repo (kunci peta rute di config). */
function cutover_relative_file(string $file): string
{
    $file = str_replace('\\', '/', $file);

    if (strpos($file, '/doc.simtkd.com/api/') !== false) {
        return 'doc.simtkd.com/api/' . basename($file);
    }

    if (strpos($file, '/peta.simtkd.com/api/') !== false) {
        return 'peta.simtkd.com/api/' . basename($file);
    }

    return 'api/' . basename($file);
}

/** Action yang diminta (query/form/JSON body). '' bila tidak ada. */
function cutover_action(): string
{
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

    if ($action !== '') {
        return $action;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET' || $method === 'HEAD') {
        return '';
    }

    // Body JSON: php://input dapat dibaca ulang oleh file legacy setelah ini
    // (diverifikasi pada PHP 8), jadi pembacaan di sini aman.
    $tipe = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

    if (strpos($tipe, 'json') === false) {
        return '';
    }

    $raw  = (string) file_get_contents('php://input');
    $body = json_decode($raw, true);

    return is_array($body) ? (string) ($body['action'] ?? '') : '';
}

/**
 * Entri peta rute untuk file legacy ini (null bila tidak dialihkan).
 */
function cutover_route(string $file): ?array
{
    $cfg    = cutover_config();
    $rel    = cutover_relative_file($file);
    $action = cutover_action();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    $spesifik = null;
    $umum     = null;

    foreach ((array) ($cfg['routes'] ?? []) as $rute) {
        if (!is_array($rute) || (string) ($rute['file'] ?? '') !== $rel) {
            continue;
        }

        $metode = array_map('strtoupper', (array) ($rute['methods'] ?? ['GET']));

        if (!in_array($method, $metode, true)) {
            continue;
        }

        $aksi = (string) ($rute['action'] ?? '');

        if ($aksi === $action) {
            $spesifik = $rute;
            break;
        }

        if ($aksi === '*' && $umum === null) {
            $umum = $rute;
        }
    }

    return $spesifik ?? $umum;
}

/**
 * Identitas user untuk tiket SSO.
 *
 * Sumber (berurutan):
 *   1. Sesi legacy ($_SESSION) — halaman aplikasi utama.
 *   2. Token API legacy (?token= / Bearer) — dipakai doc.simtkd.com, dicari
 *      lewat hash SHA-256 pada kolom users.api_token.
 *
 * @return array<string, mixed>|null
 */
function cutover_identitas(): ?array
{
    // Catatan: isset() dipakai (bukan empty()) agar aman pada file yang tidak
    // menjalankan session_start(), mis. api doc.simtkd.com.
    if (isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0) {
        return [
            'uid'   => (int) $_SESSION['user_id'],
            'skpd'  => (string) ($_SESSION['instansi'] ?? ''),
            'peran' => (string) ($_SESSION['peran'] ?? ''),
        ];
    }

    $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

    if ($token === '') {
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

        if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            $token = trim($m[1]);
        }
    }

    if ($token === '') {
        return null;
    }

    try {
        // PENTING: file pemanggil (api/*.php maupun doc.simtkd.com) SUDAH
        // memuat koneksi databasenya sendiri. Memuat config/db.php aplikasi
        // utama di sini akan menabrak fungsi db_credentials() milik subdomain
        // (fatal error "Cannot redeclare") — karena itu hanya dimuat bila
        // benar-benar belum ada.
        if (!function_exists('db')) {
            require_once __DIR__ . '/../config/db.php';
        }

        $st = db()->prepare('SELECT id, instansi, peran FROM users WHERE api_token = ? LIMIT 1');
        $st->execute([hash('sha256', $token)]);
        $u = $st->fetch();

        if ($u) {
            return [
                'uid'   => (int) $u['id'],
                'skpd'  => (string) ($u['instansi'] ?? ''),
                'peran' => (string) ($u['peran'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        cutover_log('gagal membaca identitas dari token: ' . $e->getMessage());
    }

    return null;
}

/** base64url tanpa padding (format tiket harus sama dengan sisi Laravel). */
function cutover_b64(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/**
 * Buat TIKET SSO (kompatibel dengan App\Support\CutoverTicket di Laravel).
 *
 * Format: base64url(JSON klaim) . '.' . base64url(HMAC-SHA256)
 *
 * @param  array<string, mixed>  $klaim
 */
function cutover_tiket(array $klaim): string
{
    $cfg = cutover_config();

    $payload = [
        'v'   => 1,
        'iat' => time(),
        'exp' => time() + max(5, (int) ($cfg['ttl'] ?? 90)),
        'jti' => bin2hex(random_bytes(8)),
    ] + $klaim;

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new RuntimeException('Gagal membuat tiket cutover.');
    }

    $body = cutover_b64($json);

    return $body . '.' . cutover_b64(hash_hmac('sha256', $body, (string) $cfg['sso_secret'], true));
}

/**
 * Apakah request ini ikut dialihkan menurut `rollout` (persen)?
 *
 * Bersifat "sticky": ditentukan oleh hash user_id, sehingga satu pengguna
 * selalu mendapat sistem yang sama selama periode cutover.
 *
 * @param  array<string, mixed>|null  $identitas
 */
function cutover_ikut_rollout(?array $identitas): bool
{
    $rollout = (int) (cutover_config()['rollout'] ?? 0);

    if ($rollout >= 100) {
        return true;
    }

    if ($rollout <= 0) {
        return false;
    }

    $uid = (int) ($identitas['uid'] ?? 0);
    $kunci = $uid > 0 ? 'u' . $uid : 'ip' . (string) ($_SERVER['REMOTE_ADDR'] ?? '-');

    $nilai = (int) hexdec(substr(hash('sha256', 'simtkd-cutover:' . $kunci), 0, 4)); // 0..65535

    return ($nilai % 100) < $rollout;
}

/**
 * Ganti placeholder {id} pada path tujuan dengan nilai dari request.
 *
 * @param  array<string, string>  $params
 */
function cutover_path_tujuan(array $rute, array $params): string
{
    $path = (string) ($rute['path'] ?? '');

    return (string) preg_replace_callback(
        '/\{([a-zA-Z0-9_]+)\}/',
        static function (array $m) use ($params): string {
            return rawurlencode((string) ($params[$m[1]] ?? '0'));
        },
        $path
    );
}

/** Ambil parameter request (query + form + body JSON) sebagai array. */
function cutover_params(): array
{
    $params = array_merge($_GET, $_POST);

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method !== 'GET' && $method !== 'HEAD') {
        $tipe = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        if (strpos($tipe, 'json') !== false) {
            $body = json_decode((string) file_get_contents('php://input'), true);

            if (is_array($body)) {
                $params = array_merge($params, $body);
            }
        }
    }

    return $params;
}

/**
 * Teruskan request ke aplikasi Laravel.
 *
 * @param  array<string, mixed>  $rute
 * @param  array<string, mixed>  $params
 * @return array<string, mixed>  ['ok'=>bool, ...]
 */
function cutover_proxy(array $rute, string $tiket, array $params): array
{
    $cfg    = cutover_config();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $url    = (string) $cfg['base_url'] . cutover_path_tujuan($rute, $params);

    // Query diteruskan apa adanya; `token` dibuang karena Laravel memakai tiket.
    $query = $_GET;
    unset($query['token']);

    $body  = null;
    $ctype = null;

    if ($method !== 'GET' && $method !== 'HEAD') {
        $tipe = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        if (strpos($tipe, 'json') !== false) {
            $body  = (string) file_get_contents('php://input');
            $ctype = 'application/json';
        } else {
            $body  = http_build_query($_POST);
            $ctype = 'application/x-www-form-urlencoded';
        }
    }

    if ($query !== []) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }

    $headers = [
        'Accept: ' . (string) ($_SERVER['HTTP_ACCEPT'] ?? 'application/json'),
        'X-SIMTKD-Proxy-By: ' . (string) ($_SERVER['HTTP_HOST'] ?? '-'),
        'X-Forwarded-For: ' . (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
        'X-Forwarded-Proto: ' . (!empty($_SERVER['HTTPS']) ? 'https' : 'http'),
    ];

    // Penanda "dari proksi legacy" HANYA dikirim bersama tiket. Bila tidak ada
    // identitas (mis. endpoint publik), request diteruskan apa adanya sehingga
    // tidak memicu penolakan fail-closed di sisi Laravel.
    if ($tiket !== '') {
        $headers[] = 'X-SIMTKD-Ticket: ' . $tiket;
        $headers[] = 'X-SIMTKD-Cutover: 1';
    }

    if (!empty($_SERVER['HTTP_COOKIE'])) {
        $headers[] = 'Cookie: ' . (string) $_SERVER['HTTP_COOKIE'];
    }

    if (!empty($_SERVER['HTTP_USER_AGENT'])) {
        $headers[] = 'User-Agent: ' . (string) $_SERVER['HTTP_USER_AGENT'];
    }

    if (!empty($_SERVER['HTTP_REFERER'])) {
        $headers[] = 'Referer: ' . (string) $_SERVER['HTTP_REFERER'];
    }

    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        $headers[] = 'Origin: ' . (string) $_SERVER['HTTP_ORIGIN'];
    }

    if ($ctype !== null) {
        $headers[] = 'Content-Type: ' . $ctype;
    }

    $balik = [];
    $ch    = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => (int) ($cfg['connect_timeout'] ?? 2),
        CURLOPT_TIMEOUT        => (int) ($cfg['timeout'] ?? 15),
        CURLOPT_SSL_VERIFYPEER => (bool) ($cfg['verify_tls'] ?? true),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $baris) use (&$balik): int {
            if (strpos($baris, ':') !== false) {
                $balik[] = $baris;
            }

            return strlen($baris);
        },
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $isi    = curl_exec($ch);
    $errno  = curl_errno($ch);
    $pesan  = (string) curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (PHP_VERSION_ID < 80000) {
        curl_close($ch);
    }

    if ($errno !== 0 || $isi === false) {
        return ['ok' => false, 'pesan' => 'koneksi ke ' . $url . ' gagal (' . $errno . '): ' . $pesan];
    }

    return [
        'ok'      => true,
        'status'  => $status > 0 ? $status : 502,
        'body'    => (string) $isi,
        'headers' => $balik,
    ];
}

/**
 * Titik masuk proksi. Panggil di awal file API legacy:
 *
 *     require_once __DIR__ . '/../includes/cutover.php';
 *     cutover_maybe_proxy(__FILE__);
 *
 * Bila endpoint tidak dialihkan, fungsi ini langsung kembali tanpa efek
 * samping apa pun (file legacy melanjutkan seperti biasa).
 */
function cutover_maybe_proxy(string $file): void
{
    $cfg = cutover_config();

    if (!cutover_siap()) {
        return;
    }

    $rute = cutover_route($file);

    if ($rute === null) {
        return;
    }

    $identitas  = cutover_identitas();
    $perluLogin = !array_key_exists('perlu_login', $rute) || !empty($rute['perlu_login']);

    // Belum login pada endpoint terproteksi -> biarkan legacy menjawab, agar
    // pesan error yang diterima pengguna identik dan tidak ada request
    // tambahan yang sia-sia.
    if ($perluLogin && $identitas === null) {
        return;
    }

    if (!cutover_ikut_rollout($identitas)) {
        return;
    }

    $tiket = $identitas === null ? '' : cutover_tiket($identitas);

    cutover_log('ALIHKAN -> ' . (string) $rute['path'], [
        'uid' => (int) ($identitas['uid'] ?? 0),
    ]);

    $hasil = cutover_proxy((array) $rute, $tiket, cutover_params());

    if (!$hasil['ok']) {
        // Fail-safe: endpoint baca-saja dilayani legacy lagi. Untuk endpoint
        // tulis, TIDAK ada fallback (mencegah penulisan ganda bila Laravel
        // sebenarnya sempat memproses request tersebut).
        if (!empty($rute['fallback'])) {
            cutover_log('GAGAL dialihkan -> fallback ke legacy', ['sebab' => $hasil['pesan']]);

            return;
        }

        cutover_log('GAGAL dialihkan (endpoint tulis) -> 503', ['sebab' => $hasil['pesan']]);

        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Layanan sedang diperbarui. Silakan coba lagi beberapa saat lagi.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Laravel membalas 5xx pada endpoint baca-saja -> pengguna lebih baik
    // dilayani legacy daripada menerima halaman error.
    if ($hasil['status'] >= 500 && !empty($rute['fallback'])) {
        cutover_log('Laravel membalas ' . $hasil['status'] . ' -> fallback ke legacy');

        return;
    }

    http_response_code((int) $hasil['status']);

    // Penanda diagnostik: memudahkan ops memastikan request dilayani sistem
    // baru (mis. `curl -I`), tanpa membocorkan detail internal.
    if (!empty($cfg['tandai_respons'])) {
        header('X-Simtkd-Served-By: cutover');
    }

    foreach ($hasil['headers'] as $baris) {
        $bagian = explode(':', (string) $baris, 2);
        $nama   = strtolower(trim($bagian[0]));

        // Header hop-by-hop / bukan untuk klien tidak diteruskan.
        if (in_array($nama, [
            'content-length', 'connection', 'keep-alive', 'transfer-encoding',
            'date', 'server', 'x-powered-by', 'set-cookie',
        ], true)) {
            continue;
        }

        header(rtrim((string) $baris));
    }

    echo $hasil['body'];
    exit;
}
