<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;

/**
 * Format waktu gaya Indonesia.
 *
 * Diporting dari `docWaktu()` pada doc.simtkd.com/api/dokumen.php agar API
 * baru mengembalikan string yang SAMA PERSIS dengan sistem legacy
 * (frontend lama menampilkan nilai ini apa adanya).
 *
 * Contoh: "29 Agustus 2026 09:15"
 */
final class Waktu
{
    /** @var array<int, string> */
    private const BULAN = [
        1  => 'Januari',
        2  => 'Februari',
        3  => 'Maret',
        4  => 'April',
        5  => 'Mei',
        6  => 'Juni',
        7  => 'Juli',
        8  => 'Agustus',
        9  => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    /**
     * Format "29 Agustus 2026 09:15" dari datetime SQL / waktu server.
     *
     * Nilai kosong mengembalikan "-" (persis perilaku legacy), sedangkan
     * string yang tidak dapat diurai dikembalikan apa adanya.
     */
    public static function indo(string|DateTimeInterface|null $dt): string
    {
        if ($dt === null) {
            return '-';
        }

        if ($dt instanceof DateTimeInterface) {
            $ts = $dt->getTimestamp();
        } else {
            $dt = trim($dt);

            if ($dt === '' || $dt === '-') {
                return '-';
            }

            $ts = strtotime($dt);

            if ($ts === false) {
                return $dt;
            }
        }

        return date('j', $ts) . ' ' . self::BULAN[(int) date('n', $ts)] . ' ' . date('Y H:i', $ts);
    }
}
