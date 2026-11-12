<?php

declare(strict_types=1);

/**
 * Verifikasi CUTOVER (migrasi bertahap tanpa downtime) — CLI
 * =========================================================
 *
 * Dipakai SEBELUM dan SESUDAH menyalakan pengalihan endpoint ke Laravel.
 * Bersifat READ-ONLY: hanya memanggil endpoint baca-saja (tidak mengubah data).
 *
 * Pemakaian:
 *   php laravel/tools/cutover/verifikasi.php \
 *       --laravel=https://next.simtkd.com \
 *       --legacy=https://simtkd.com \
 *       [--proxy=https://server-yang-sudah-mengalihkan] \
 *       [--uid=1] [--secret=...] [--user=akun --pass=rahasia]
 *
 * Pemeriksaan:
 *   1. Health check Laravel : GET /api/v1/health harus 200 & database ok.
 *   2. Rantai tiket SSO     : tiket dibuat dengan secret SISI LEGACY lalu
 *                             dipakai memanggil endpoint terproteksi di
 *                             Laravel. Bila ini gagal, pengguna akan
 *                             ter-logout begitu endpoint dialihkan.
 *   3. (opsional, --proxy)  : bandingkan respons endpoint baca-saja antara
 *                             server legacy langsung vs server yang sudah
 *                             mengalihkan -> wajib identik.
 *
 * Exit code 0 = semua lulus, 1 = ada yang gagal.
 */

require_once dirname(__DIR__, 2) . '/tools/difftest/Client.php';
require_once dirname(__DIR__, 2) . '/tools/difftest/Response.php';
require_once dirname(__DIR__, 2) . '/tools/difftest/Normalizer.php';

use Simtkd\Difftest\Client;
use Simtkd\Difftest\Normalizer;

$opts = getopt('', ['laravel:', 'legacy::', 'proxy::', 'uid::', 'user::', 'pass::', 'secret::', 'endpoint::', 'help']);

if (array_key_exists('help', $opts) || ! isset($opts['laravel'])) {
    echo "Verifikasi cutover SIM-TKD.\n\n"
        . "  php laravel/tools/cutover/verifikasi.php --laravel=https://next.simtkd.com\n"
        . "      [--legacy=https://simtkd.com] [--proxy=https://server-proksi]\n"
        . "      [--uid=1] [--secret=...] [--user=akun --pass=rahasia]\n\n"
        . "Lihat komentar di berkas ini untuk detail setiap pemeriksaan.\n";
    exit(isset($opts['laravel']) ? 0 : 1);
}

$laravelUrl = rtrim((string) $opts['laravel'], '/');
$legacyUrl  = rtrim((string) ($opts['legacy'] ?? ''), '/');
$proxyUrl   = rtrim((string) ($opts['proxy'] ?? ''), '/');
$uid        = (int) ($opts['uid'] ?? 0);
$userUji    = (string) ($opts['user'] ?? '');
$passUji    = (string) ($opts['pass'] ?? '');
$endpoint   = (string) ($opts['endpoint'] ?? '/api/v1/dokumen/list');
$repoRoot   = dirname(__DIR__, 3);

// Secret diambil dari config/cutover.php (SISI LEGACY) supaya pengujian
// benar-benar membuktikan kedua sisi memakai rahasia yang sama.
$secret = (string) ($opts['secret'] ?? '');

if ($secret === '') {
    $cfgFile = $repoRoot . '/config/cutover.php';
    $cfg     = is_file($cfgFile) ? (array) require $cfgFile : [];
    $secret  = (string) ($cfg['sso_secret'] ?? '');
}

$gagal = 0;

$lulus = static function (string $judul): void {
    echo "  \033[32mLULUS\033[0m {$judul}\n";
};

$salah = static function (string $judul, string $detail = '') use (&$gagal): void {
    $gagal++;
    echo "  \033[31mGAGAL\033[0m {$judul}\n";

    if ($detail !== '') {
        echo "         {$detail}\n";
    }
};

echo "Verifikasi CUTOVER SIM-TKD\n";
echo str_repeat('=', 62) . "\n";
echo "Laravel : {$laravelUrl}\n";

if ($legacyUrl !== '') {
    echo "Legacy  : {$legacyUrl}\n";
}

if ($proxyUrl !== '') {
    echo "Proksi  : {$proxyUrl}\n";
}

echo "\n";

$klien = new Client($laravelUrl);

// ---------------------------------------------------------------------------
// 1) Health check
// ---------------------------------------------------------------------------
echo "1) Health check aplikasi baru\n";

$health = $klien->request('GET', '/api/v1/health');

if (! $health->isJson()) {
    $salah('GET /api/v1/health tidak mengembalikan JSON', 'HTTP ' . $health->status);
} elseif ($health->status !== 200) {
    $salah(
        'Aplikasi baru belum siap menerima lalu lintas',
        'HTTP ' . $health->status . ' / masalah: ' . json_encode($health->get('masalah'), JSON_UNESCAPED_UNICODE)
    );
} else {
    $lulus('Aplikasi baru sehat (database ok, tabel lengkap)');

    if ($health->get('cutover.aktif') !== true) {
        $salah(
            'Tiket cutover BELUM aktif di aplikasi baru',
            'Setel SIMTKD_CUTOVER_AKTIF=true dan SIMTKD_CUTOVER_SECRET di .env Laravel.'
        );
    } else {
        $lulus('Tiket cutover aktif (SIMTKD_CUTOVER_AKTIF=true)');
    }
}

// ---------------------------------------------------------------------------
// 2) Rantai tiket SSO (kritis: mencegah pengguna ter-logout)
// ---------------------------------------------------------------------------
echo "\n2) Rantai tiket SSO (sisi legacy -> Laravel)\n";

if ($secret === '') {
    $salah(
        'Secret cutover belum diisi',
        'Isi sso_secret pada config/cutover.php (sisi legacy) atau pakai --secret=. '
            . 'Tanpa secret, pengalihan endpoint akan membuat pengguna ter-logout.'
    );
} elseif ($uid <= 0) {
    echo "  \033[33mLEWAT\033[0m Isi --uid=<id user uji> untuk menguji rantai tiket.\n";
} else {
    // Tiket dibuat dengan implementasi MANDIRI (bukan kelas Laravel) supaya
    // pengujian membuktikan kedua sisi benar-benar sepakat.
    $klaim = ['v' => 1, 'iat' => time(), 'exp' => time() + 90, 'jti' => bin2hex(random_bytes(8)), 'uid' => $uid];

    $b64   = static fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    $body  = $b64((string) json_encode($klaim));
    $tiket = $body . '.' . $b64(hash_hmac('sha256', $body, $secret, true));

    $uji = $klien->request('GET', $endpoint, [], [
        'X-SIMTKD-Ticket'  => $tiket,
        'X-SIMTKD-Cutover' => '1',
    ]);

    if ($uji->status === 200 && $uji->get('success') === true) {
        $lulus('Tiket dari sisi legacy DITERIMA Laravel (' . $endpoint . ')');
    } elseif ($uji->status === 401) {
        $salah(
            'Tiket DITOLAK Laravel (HTTP 401)',
            'Pesan: ' . json_encode($uji->get('message'), JSON_UNESCAPED_UNICODE)
                . ' — periksa: secret sama, jam server sinkron, umur tiket cukup (SIMTKD_CUTOVER_TTL).'
        );
    } elseif ($uji->status === 503) {
        $salah('Fitur cutover belum aktif di Laravel (HTTP 503)', 'Selaraskan SIMTKD_CUTOVER_AKTIF/SECRET lalu ulangi.');
    } else {
        $salah('Balasan tak terduga pada uji tiket', 'HTTP ' . $uji->status . ': ' . substr((string) $uji->raw, 0, 200));
    }

    $bucket = (int) hexdec(substr(hash('sha256', 'simtkd-cutover:u' . $uid), 0, 4)) % 100;
    echo "         info: user id {$uid} ada di bucket {$bucket};"
        . " bila rollout <= {$bucket} maka user ini tetap dilayani legacy.\n";
}

// ---------------------------------------------------------------------------
// 3) Bandingkan legacy (langsung) vs server yang sudah mengalihkan
// ---------------------------------------------------------------------------
echo "\n3) Perbandingan respons legacy vs proksi\n";

if ($proxyUrl === '' || $legacyUrl === '') {
    echo "  \033[33mLEWAT\033[0m Isi --legacy= dan --proxy= untuk membandingkan respons.\n";
} else {
    $klienLegacy = new Client($legacyUrl);
    $klienProxy  = new Client($proxyUrl);

    $ujiBanding = [
        'dashboard'  => ['GET', '/api/summary.php', []],
        'profil'     => ['GET', '/api/profil.php', []],
        'permohonan' => ['GET', '/api/permohonan.php', []],
        'skp'        => ['GET', '/api/skp.php', []],
        'laporan bku' => ['GET', '/api/laporan.php', ['action' => 'bku']],
    ];

    // Bila kredensial diberikan, login dulu di kedua server supaya yang
    // dibandingkan adalah data sesungguhnya (bukan sekadar balasan 401).
    if ($userUji !== '' && $passUji !== '') {
        foreach ([$klienLegacy, $klienProxy] as $k) {
            $login = $k->request('POST', '/api/login.php', ['username' => $userUji, 'password' => $passUji]);

            if (! $login->isJson() || $login->get('success') !== true) {
                $salah('Login uji gagal', 'HTTP ' . $login->status . ' ' . substr((string) $login->raw, 0, 160));
                break;
            }
        }
    } else {
        echo "  \033[33mcatatan\033[0m tanpa --user/--pass, hanya balasan 401 (tanpa login) yang dibandingkan.\n";
    }

    foreach ($ujiBanding as $nama => $spec) {
        $a = $klienLegacy->request($spec[0], $spec[1], $spec[2]);
        $b = $klienProxy->request($spec[0], $spec[1], $spec[2]);

        if (! $a->isJson() || ! $b->isJson()) {
            $salah($nama, 'respons bukan JSON (legacy ' . $a->status . ' / proksi ' . $b->status . ')');
            continue;
        }

        $selisih = Normalizer::diff(
            Normalizer::normalize($a->data ?? []),
            Normalizer::normalize($b->data ?? [])
        );

        if ($selisih === []) {
            $lulus($nama . ' identik');
        } else {
            $salah($nama . ' BERBEDA', implode('; ', array_slice($selisih, 0, 3)));
        }
    }

    echo "         catatan: bandingkan setelah rollout server proksi menyala untuk user uji ini.\n";
}

echo "\n" . str_repeat('=', 62) . "\n";
echo $gagal === 0
    ? "SEMUA PEMERIKSAAN LULUS — aman melanjutkan cutover.\n"
    : "ADA {$gagal} PEMERIKSAAN GAGAL — JANGAN nyalakan pengalihan sebelum diperbaiki.\n";

exit($gagal === 0 ? 0 : 1);
