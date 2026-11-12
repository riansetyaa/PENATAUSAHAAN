<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Tiket SSO untuk migrasi bertahap (cutover) dari sistem legacy ke Laravel.
 *
 * MASALAH YANG DISELESAIKAN
 * -------------------------
 * Sesi legacy adalah sesi PHP native (`PHPSESSID`) yang terikat pada satu
 * host (mis. simtkd.com), sedangkan Laravel (mis. pada subdomain terpisah)
 * memiliki sesi sendiri. Tanpa jembatan, setiap endpoint yang dipindahkan
 * ke Laravel akan menganggap pengguna belum login -> pengguna ter-logout di
 * tengah pemakaian (bukan "tanpa downtime").
 *
 * JEMBATANNYA
 * -----------
 * Proksi legacy (includes/cutover.php) menandatangani klaim identitas user
 * dari sesi legacy yang masih berlaku, lalu mengirimnya pada header
 * `X-SIMTKD-Ticket` di setiap request yang dialihkan ke Laravel.
 * Middleware App\Http\Middleware\TerimaTiketCutover memverifikasinya dan
 * membuat request tersebut terautentikasi — tanpa cookie lintas-subdomain,
 * tanpa query database tambahan (klaim sudah lengkap).
 *
 * FORMAT
 * ------
 *     base64url(JSON klaim) . '.' . hmac_sha256_base64url(payload, secret)
 *
 * Perbandingan tanda tangan memakai hash_equals() (constant-time) dan tiket
 * berumur pendek (`cutover_ttl`) sehingga tidak dapat dipakai ulang di lain
 * waktu.
 */
final class CutoverTicket
{
    /** Header berisi tiket dari proksi legacy. */
    public const HEADER = 'X-SIMTKD-Ticket';

    /** Header penanda "request ini datang dari proksi legacy". */
    public const HEADER_REQUIRED = 'X-SIMTKD-Cutover';

    /** Versi format tiket (untuk perubahan ke depan). */
    public const VERSI = 1;

    /**
     * Secret HMAC (harus sama dengan sisi legacy).
     */
    public static function secret(): string
    {
        return (string) config('simtkd.cutover_secret', '');
    }

    /**
     * Apakah fitur tiket cutover dikonfigurasi & aktif?
     */
    public static function aktif(): bool
    {
        return (bool) config('simtkd.cutover_aktif', false) && self::secret() !== '';
    }

    /**
     * Umur tiket (detik).
     */
    public static function ttl(): int
    {
        return max(5, (int) config('simtkd.cutover_ttl', 90));
    }

    /**
     * Buat tiket dari klaim identitas.
     *
     * @param  array<string, mixed>  $claims  mis. ['uid' => 12, 'skpd' => 'Dinas X']
     */
    public static function mint(array $claims, ?int $now = null): string
    {
        $secret = self::secret();

        if ($secret === '') {
            throw new \RuntimeException('Secret tiket cutover belum dikonfigurasi.');
        }

        $now ??= time();

        $payload = [
            'v'    => self::VERSI,
            'iat'  => $now,
            'exp'  => $now + self::ttl(),
            'jti'  => bin2hex(random_bytes(8)),
        ] + $claims;

        $body = self::b64(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}');

        return $body . '.' . self::b64(hash_hmac('sha256', $body, $secret, true));
    }

    /**
     * Verifikasi tiket. Mengembalikan klaim bila sah, atau null bila tidak.
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $ticket, ?int $now = null): ?array
    {
        $secret = self::secret();

        if ($secret === '' || $ticket === '' || ! str_contains($ticket, '.')) {
            return null;
        }

        [$body, $sig] = explode('.', $ticket, 2);

        $harapan = self::b64(hash_hmac('sha256', $body, $secret, true));

        if (! hash_equals($harapan, $sig)) {
            return null;
        }

        $json = self::unb64($body);

        if ($json === null) {
            return null;
        }

        $claims = json_decode($json, true);

        if (! is_array($claims) || (int) ($claims['v'] ?? 0) !== self::VERSI) {
            return null;
        }

        $now ??= time();
        $skew = max(0, (int) config('simtkd.cutover_skew', 15));

        $iat = (int) ($claims['iat'] ?? 0);
        $exp = (int) ($claims['exp'] ?? 0);

        if ($iat > $now + $skew) {
            return null; // tiket dari masa depan (jam server tidak sinkron)
        }

        if ($exp + $skew < $now) {
            return null; // kedaluwarsa
        }

        if ((int) ($claims['uid'] ?? 0) <= 0) {
            return null;
        }

        return $claims;
    }

    /**
     * Catat pemakaian tiket (audit trail cutover).
     *
     * @param  array<string, mixed>  $claims
     */
    public static function catat(array $claims, string $path, string $ip): void
    {
        if (! (bool) config('simtkd.cutover_log', true)) {
            return;
        }

        Log::channel('single')->info('[CUTOVER] request via tiket SSO', [
            'uid'  => (int) ($claims['uid'] ?? 0),
            'jti'  => (string) ($claims['jti'] ?? ''),
            'path' => $path,
            'ip'   => $ip,
        ]);
    }

    /** base64url tanpa padding. */
    private static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** Dekode base64url; null bila tidak valid. */
    private static function unb64(string $b64): ?string
    {
        $pad = strlen($b64) % 4;

        if ($pad !== 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $bin = base64_decode(strtr($b64, '-_', '+/'), true);

        return $bin === false ? null : $bin;
    }
}
