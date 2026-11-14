<?php
/**
 * Webhook Deploy - PRODUCTION
 * ===========================
 * Keamanan:
 *  1. Wajib verifikasi signature GitHub HMAC-SHA256 (header X-Hub-Signature-256).
 *     Secret diambil dari env GITHUB_WEBHOOK_SECRET atau file /home/simtkdco/.webhook_secret
 *     (di luar webroot, chmod 600). Contoh membuat secret:
 *       php -r "echo bin2hex(random_bytes(32));"
 *     Pasang nilai yang sama di GitHub repo > Settings > Webhooks > Secret.
 *  2. Output perintah TIDAK dikirim ke client (hanya ke log).
 */

// ---- Muat secret ----
$secret = getenv('GITHUB_WEBHOOK_SECRET');
if ($secret === false || $secret === '') {
    $secret = @file_get_contents('/home/simtkdco/.webhook_secret');
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
        '/home/simtkdco/webhook/production.log',
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

if ($data['ref'] !== 'refs/heads/main') {
    http_response_code(200);
    exit('Ignored');
}

$output = shell_exec(
    'cd /home/simtkdco/public_html && git pull origin main 2>&1'
);

file_put_contents(
    '/home/simtkdco/webhook/production.log',
    date('Y-m-d H:i:s') . "\n" . $output . "\n\n",
    FILE_APPEND
);

http_response_code(200);
echo 'OK';