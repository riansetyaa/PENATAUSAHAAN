<?php

declare(strict_types=1);

namespace Simtkd\Difftest;

/**
 * Klien HTTP minimal dengan dukungan cookie jar + Bearer token.
 *
 * Dibuat tanpa dependensi eksternal (hanya cURL bawaan PHP) agar bisa
 * dijalankan di server produksi/hosting tanpa composer install.
 */
class Client
{
    private string $cookieJar;

    private ?string $bearerToken = null;

    public function __construct(private readonly string $baseUrl)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'simtkd_difftest_cookies_');
    }

    /**
     * Pasang token yang dipakai pada header `Authorization: Bearer`.
     */
    public function withToken(?string $token): self
    {
        $this->bearerToken = $token;

        return $this;
    }

    /**
     * Bersihkan cookie (simulasi pengguna baru / login ulang).
     */
    public function resetCookies(): void
    {
        if (is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'simtkd_difftest_cookies_');
        $this->bearerToken = null;
    }

    public function __destruct()
    {
        if (is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }

    /**
     * Kirim request.
     *
     * @param  array<string, mixed>  $params
     */
    public function request(string $method, string $path, array $params = []): Response
    {
        $url = rtrim($this->baseUrl, '/') . $path;

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $headers = ['Accept: application/json'];

        if ($this->bearerToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->bearerToken;
        }

        $method = strtoupper($method);

        // Bila ada nilai array/object, body dikirim sebagai JSON. Endpoint
        // legacy (permohonan.php, akun_penerimaan.php) membaca body JSON
        // lebih dulu (`json_decode(file_get_contents('php://input'))`) dan
        // baru jatuh ke $_POST, sehingga kedua format sama-sama didukung.
        $adaArray = false;
        array_walk_recursive($params, static function ($v) use (&$adaArray): void {
            if (is_array($v)) {
                $adaArray = true;
            }
        });

        if ($method === 'GET' && $params !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
            curl_setopt($ch, CURLOPT_URL, $url);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POST, true);

            if ($adaArray) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
                $headers[] = 'Content-Type: application/json';
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // curl_close() deprecated sejak PHP 8.5 (tidak lagi berpengaruh).
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($raw === false) {
            return new Response($status, null, 'Kesalahan koneksi: ' . $err);
        }

        $decoded = json_decode((string) $raw, true);

        return new Response($status, is_array($decoded) ? $decoded : null, (string) $raw);
    }
}
