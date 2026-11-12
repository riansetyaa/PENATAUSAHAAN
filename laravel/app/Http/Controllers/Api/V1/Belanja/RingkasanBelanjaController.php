<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Belanja;

use App\Http\Controllers\Api\V1\Belanja\Concerns\FiltersBelanja;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ringkasan modul Belanja (penghitung & total per tahap).
 *
 * Diporting dari blok `action=summary` pada api/belanja.php.
 *
 * Catatan kesetiaan: legacy membungkus setiap query dengan try/catch dan
 * mengembalikan 0 bila tabel belum ada — perilaku itu dipertahankan agar
 * respons tetap sama pada basis data yang belum lengkap.
 */
class RingkasanBelanjaController extends Controller
{
    use FiltersBelanja;

    /**
     * GET /api/v1/belanja/summary
     */
    public function summary()
    {
        $skpd = $this->skpd();

        $hitung = function (string $tabel, string $status = '') use ($skpd): int {
            try {
                $query = DB::table($tabel . ' as s')->where('s.skpd', $skpd);

                if ($status !== '') {
                    $query->where('s.status', $status);
                }

                return (int) $query->count();
            } catch (Throwable) {
                return 0;
            }
        };

        $totalJumlah = function (string $tabel) use ($skpd): float {
            try {
                return (float) DB::table($tabel . ' as s')->where('s.skpd', $skpd)->sum('s.jumlah');
            } catch (Throwable) {
                return 0.0;
            }
        };

        return $this->ok('OK', [
            'rekanan'        => $hitung('rekanan'),
            'spd'            => $hitung('spd'),
            'spd_otor'       => $hitung('spd', 'sudah_otorisasi'),
            'spp'            => $hitung('spp'),
            'spp_ver'        => $hitung('spp', 'sudah_diverifikasi'),
            'spm'            => $hitung('spm'),
            'sp2d'           => $hitung('sp2d'),
            'sp2d_cair'      => $hitung('sp2d', 'sudah_dicairkan'),
            'total_spd'      => $totalJumlah('spd'),
            'total_spp'      => $totalJumlah('spp'),
            'lpj'            => $hitung('lpj'),
            'pengajuan_tu'   => $hitung('pengajuan_tu'),
            'rekening'       => $hitung('rekening_skpd'),
            'rekening_aktif' => $hitung('rekening_skpd', 'aktif'),
            'kebijakan_spd'  => $hitung('kebijakan_spd'),
            'npd'            => $hitung('npd'),
            'npd_siap'       => $hitung('npd', 'divalidasi_bp'),
        ]);
    }
}
