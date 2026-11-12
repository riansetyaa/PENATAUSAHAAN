<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Penerimaan\Concerns;

use DateTimeInterface;

/**
 * Helper format tanggal/waktu untuk modul Penerimaan.
 *
 * Legacy memakai `(string) $row['tanggal']` pada hasil PDO: DATE/DATETIME
 * dikembalikan apa adanya sebagai string, dan NULL menjadi string kosong.
 * Eloquent mengembalikan objek tanggal, jadi perlu diformat agar BENTUK
 * JSON tetap identik dengan sistem lama (frontend & differential test).
 */
trait FormatPenerimaan
{
    /** Tanggal (DATE) -> "YYYY-MM-DD"; NULL -> "". */
    protected function tanggal(mixed $nilai): string
    {
        if ($nilai === null || $nilai === '') {
            return '';
        }

        if ($nilai instanceof DateTimeInterface) {
            return $nilai->format('Y-m-d');
        }

        return (string) $nilai;
    }

    /** Waktu (DATETIME) -> "YYYY-MM-DD HH:MM:SS"; NULL -> "". */
    protected function waktu(mixed $nilai): string
    {
        if ($nilai === null || $nilai === '') {
            return '';
        }

        if ($nilai instanceof DateTimeInterface) {
            return $nilai->format('Y-m-d H:i:s');
        }

        return (string) $nilai;
    }
}
