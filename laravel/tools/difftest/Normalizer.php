<?php

declare(strict_types=1);

namespace Simtkd\Difftest;

/**
 * Normalisasi respons JSON agar perbandingan meaningful.
 *
 * Dua sumber perbedaan yang HARUS diabaikan:
 *
 *  1. Kunci yang memang berbeda disengaja. Contoh: API baru mengembalikan
 *     `token` (Sanctum) sedangkan API legacy tidak. Kunci seperti ini
 *     didaftarkan per-skenario lewat `drop`.
 *
 *  2. Nilai yang berubah-ubah antar-request. Contoh: `created_at`,
 *     `updated_at`, `signed_at`, `token`. Nilai seperti ini diberi placeholder
 *     sehingga perbandingan tetap ketat pada bagian yang penting.
 *
 * 3. Urutan kunci JSON. Disortir agar `{"a":1,"b":2}` dan `{"b":2,"a":1}`
 *     dianggap sama.
 */
class Normalizer
{
    /**
     * Kunci yang isinya boleh berbeda antar-waktu.
     */
    public const VOLATILE_KEYS = [
        'created_at',
        'updated_at',
        'deleted_at',
        'signed_at',
        'otp_verifikasi_at',
        'otp_terkirim_at',
        'otp_expired_at',
        'last_used_at',
        'expires_at',
        'created',
        'updated',
        'waktu',
    ];

    /**
     * Normalisasi struktur data.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $drop  Kunci yang boleh dihapus (mendukung "a.b" untuk nested)
     * @return array<string, mixed>
     */
    public static function normalize(array $data, array $drop = []): array
    {
        return self::walk($data, $drop, '');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $drop
     * @return array<string, mixed>
     */
    private static function walk(array $data, array $drop, string $prefix): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            // Kunci yang sengaja boleh berbeda.
            if (in_array((string) $key, $drop, true) || in_array($path, $drop, true)) {
                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::walk($value, $drop, $path);
                continue;
            }

            // Nilai volatil -> placeholder agar perbandingan tetap ketat
            // pada bagian yang benar-benar penting.
            if (in_array((string) $key, self::VOLATILE_KEYS, true)) {
                $out[$key] = '<volatile>';
                continue;
            }

            $out[$key] = $value;
        }

        // Urutan kunci dinormalkan.
        ksort($out);

        return $out;
    }

    /**
     * Bandingkan dua struktur data dan kembalikan daftar perbedaan.
     *
     * @param  array<string, mixed>  $expected  Data dari API legacy (golden master)
     * @param  array<string, mixed>  $actual    Data dari API baru
     * @return array<int, string>
     */
    public static function diff(array $expected, array $actual, string $prefix = ''): array
    {
        $diffs = [];

        $keys = array_unique(array_merge(array_keys($expected), array_keys($actual)));
        sort($keys);

        foreach ($keys as $key) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            $inExpected = array_key_exists($key, $expected);
            $inActual   = array_key_exists($key, $actual);

            if (! $inExpected) {
                $diffs[] = "{$path}: tidak ada di API legacy, ada di API baru (" . self::render($actual[$key]) . ')';
                continue;
            }

            if (! $inActual) {
                $diffs[] = "{$path}: ada di API legacy (" . self::render($expected[$key]) . '), tidak ada di API baru';
                continue;
            }

            $e = $expected[$key];
            $a = $actual[$key];

            if (is_array($e) && is_array($a)) {
                // Dua daftar (indexed) harus jumlah & urutan elemennya sama.
                if (array_is_list($e) !== array_is_list($a)) {
                    $diffs[] = "{$path}: struktur berbeda (list vs map)";
                    continue;
                }

                if (array_is_list($e)) {
                    if (count($e) !== count($a)) {
                        $diffs[] = "{$path}: jumlah elemen berbeda (" . count($e) . ' vs ' . count($a) . ')';
                        continue;
                    }
                    foreach ($e as $i => $ev) {
                        if (is_array($ev) && is_array($a[$i])) {
                            $diffs = array_merge($diffs, self::diff($ev, $a[$i], $path . '[' . $i . ']'));
                        } elseif ($ev !== $a[$i]) {
                            $diffs[] = "{$path}[{$i}]: " . self::render($ev) . ' vs ' . self::render($a[$i]);
                        }
                    }
                    continue;
                }

                $diffs = array_merge($diffs, self::diff($e, $a, $path));
                continue;
            }

            if ($e !== $a) {
                $diffs[] = "{$path}: " . self::render($e) . ' vs ' . self::render($a);
            }
        }

        return $diffs;
    }

    /**
     * Render nilai ringkas untuk pesan perbedaan.
     */
    private static function render(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        if ($v === null) {
            return 'null';
        }

        if (is_array($v)) {
            $json = json_encode($v, JSON_UNESCAPED_UNICODE);

            return strlen((string) $json) > 120
                ? substr((string) $json, 0, 117) . '...'
                : (string) $json;
        }

        $s = (string) $v;

        return strlen($s) > 120 ? substr($s, 0, 117) . '...' : $s;
    }
}
