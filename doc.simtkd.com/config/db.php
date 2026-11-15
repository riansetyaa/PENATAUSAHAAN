<?php
/**
 * SIM-TKD (doc.simtkd.com) - Database Connection (PDO)
 * ============================================================
 * Kloning dari config/db.php aplikasi utama: subdomain berbagi
 * DATABASE YANG SAMA, sehingga dokumen yang dikirim dari simtkd.com
 * bisa langsung ditandatangani di doc.simtkd.com.
 * Kredensial TIDAK disimpan di file ini. Urutan pembacaan:
 *   1. config/credentials.php  (file terpisah, TIDAK di-commit ke git)
 *   2. Environment variable: SIMTKD_DB_HOST / SIMTKD_DB_NAME / SIMTKD_DB_USER / SIMTKD_DB_PASS
 */

declare(strict_types=1);

// Cegah akses langsung ke file ini
if (basename($_SERVER['PHP_SELF'] ?? '') === 'db.php') {
    http_response_code(403);
    exit('Akses ditolak.');
}

/** Muat kredensial database dari credentials.php / env. */
function db_credentials(): array
{
    $credFile = __DIR__ . '/credentials.php';
    if (is_file($credFile)) {
        $cred = require $credFile;
        if (is_array($cred) && isset($cred['host'], $cred['name'], $cred['user'])) {
            return [
                'host' => (string) $cred['host'],
                'name' => (string) $cred['name'],
                'user' => (string) $cred['user'],
                'pass' => (string) ($cred['pass'] ?? ''),
            ];
        }
    }

    $envHost = getenv('SIMTKD_DB_HOST');
    $envName = getenv('SIMTKD_DB_NAME');
    $envUser = getenv('SIMTKD_DB_USER');
    if ($envHost !== false && $envName !== false && $envUser !== false) {
        return [
            'host' => $envHost,
            'name' => $envName,
            'user' => $envUser,
            'pass' => (string) (getenv('SIMTKD_DB_PASS') ?: ''),
        ];
    }

    // ---- Fallback terakhir: konfigurasi lokal bawaan XAMPP ----
    // Dulu jalur ini memanggil die() sehingga client menerima HTML/ teks
    // biasa (bukan JSON) saat kredensial belum diatur.
    error_log('[SIM-TKD/DOC] Kredensial DB tidak dikonfigurasi (credentials.php / env); memakai fallback lokal. Set credentials.php di produksi.');
    return [
        'host' => '127.0.0.1',
        'name' => 'simtkd',
        'user' => 'root',
        'pass' => '',
    ];
}

/**
 * Mengembalikan koneksi PDO (singleton).
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $c = db_credentials();
        try {
            $pdo = new PDO(
                'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4',
                $c['user'],
                $c['pass'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            // Jangan bocorkan detail error DB ke client (log saja di server)
            error_log('[DOC] Koneksi DB gagal: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Koneksi ke database gagal. Silakan coba beberapa saat lagi atau hubungi administrator.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    return $pdo;
}
