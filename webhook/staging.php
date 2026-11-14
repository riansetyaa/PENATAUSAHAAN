<?php
/**
 * Webhook Deploy - STAGING
 * ===========================
 * Keamanan: sama dengan production.php — wajib signature HMAC-SHA256 GitHub,
 * secret dari env GITHUB_WEBHOOK_SECRET_STAGING atau /home/simtkdco/.webhook_secret_staging.
 */

// ---- Muat secret ----
$secret = getenv('GITHUB_WEBHOOK_SECRET_STAGING');
if ($secret === false || $secret === '') {
    $secret = @file_get_contents('/home/simtkdco/.webhook_secret_staging');
    $secret = $secret === false ? '' : trim($secret);
}
if ($secret === '') {
    http_response_code(500);
    exit('Webhook belum dikonfigurasi.');
}

$payload = file_get_contents('php://input');

// ---- Verifikasi signature (wajib, constant-time) ----
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$calc = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if ($sig === '' || !hash_equals($calc, $sig)) {
    http_response_code(403);
    @file_put_contents(
        '/home/simtkdco/webhook/staging.log',
        date('Y-m-d H:i:s') . " DITOLAK: signature tidak valid dari " . ($_SERVER['REMOTE_ADDR'] ?? '-') . "\n",
        FILE_APPEND
    );
    exit('Forbidden');
}

$data = json_decode($payload, true);

if (!isset($data['ref'])) {
    http_response_code(400);
    exit('Invalid request');
}

if ($data['ref'] !== 'refs/heads/Staging') {
    http_response_code(200);
    exit('Ignored');
}

$output = shell_exec(
    'cd /home/simtkdco/public_html/testing.simtkd.com && git pull origin Staging 2>&1'
);

file_put_contents(
    '/home/simtkdco/webhook/staging.log',
    date('Y-m-d H:i:s') . "\n" . $output . "\n\n",
    FILE_APPEND
);

http_response_code(200);
echo 'OK';