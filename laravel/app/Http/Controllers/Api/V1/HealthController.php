<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\CutoverTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Health check aplikasi (readiness probe).
 *
 * Dipakai sebagai SYARAT sebelum sebuah endpoint dialihkan ke Laravel
 * (cutover): bila endpoint ini tidak 200, proksi legacy tidak boleh
 * mengaktifkan pengalihan.
 *
 * Bersifat publik (tanpa autentikasi) tetapi TIDAK membocorkan rahasia:
 * hanya status, nama tabel yang hilang, dan versi PHP/Laravel.
 */
class HealthController extends Controller
{
    /**
     * GET /api/v1/health
     */
    public function index()
    {
        $masalah = [];

        // ---- Koneksi database ----
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            report($e);
            $masalah[] = 'Koneksi database gagal.';
        }

        // ---- Tabel wajib (bukti skema legacy sudah tersedia) ----
        $tabelWajib = (array) config('simtkd.tabel_wajib', []);
        $tabelHilang = [];

        if ($masalah === []) {
            foreach ($tabelWajib as $tabel) {
                try {
                    if (! Schema::hasTable((string) $tabel)) {
                        $tabelHilang[] = (string) $tabel;
                    }
                } catch (Throwable $e) {
                    $tabelHilang[] = (string) $tabel;
                }
            }

            if ($tabelHilang !== []) {
                $masalah[] = 'Tabel belum ada: ' . implode(', ', $tabelHilang) . '.';
            }
        }

        // ---- APP_KEY (tanpa ini session/enkripsi tidak jalan) ----
        if ((string) config('app.key') === '') {
            $masalah[] = 'APP_KEY belum diisi.';
        }

        // ---- Direktori storage harus bisa ditulis ----
        if (! is_writable(storage_path())) {
            $masalah[] = 'Direktori storage tidak dapat ditulis.';
        }

        $sehat = $masalah === [];

        $payload = [
            'status'      => $sehat ? 'ok' : 'gagal',
            'aplikasi'    => 'laravel',
            'waktu'       => now()->toIso8601String(),
            'php'         => PHP_VERSION,
            'laravel'     => app()->version(),
            'database'    => $sehat ? 'ok' : 'gagal',
            'tabel_hilang' => $tabelHilang,
            'cutover'     => [
                'aktif'    => CutoverTicket::aktif(),
                'ttl'      => CutoverTicket::ttl(),
            ],
            'masalah'     => $masalah,
        ];

        return $this->ok($sehat ? 'Aplikasi sehat.' : 'Aplikasi belum siap menerima lalu lintas cutover.', $payload, $sehat ? 200 : 503);
    }
}
