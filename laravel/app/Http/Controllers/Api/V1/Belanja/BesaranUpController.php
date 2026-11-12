<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Belanja;

use App\Http\Controllers\Api\V1\Belanja\Concerns\FiltersBelanja;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Besaran UP / KKPD per tahun.
 *
 * Diporting dari blok `action=besaran_up_get` pada api/belanja.php.
 * Mengembalikan SATU baris (atau null bila belum ditetapkan).
 */
class BesaranUpController extends Controller
{
    use FiltersBelanja;

    /**
     * GET /api/v1/belanja/besaran-up?tahun=
     */
    public function show(Request $request)
    {
        $skpd  = $this->skpd();
        $tahun = (string) $request->query('tahun', date('Y'));

        $row = DB::table('besaran_up as s')
            ->where('s.tahun', $tahun)
            ->where('s.skpd', $skpd)
            ->first();

        return $this->ok('OK', ['data' => $row ?: null]);
    }

    /**
     * POST /api/v1/belanja/besaran-up  (action=besaran_up_set)
     *
     * Upsert per (skpd, tahun): perbarui bila sudah ada, buat bila belum.
     */
    public function simpan(Request $request)
    {
        $skpd   = $this->skpd();
        $tahun  = trim((string) $request->input('tahun', date('Y')));
        $up     = (float) $request->input('besaran_up', 0);
        $upKkpd = (float) $request->input('besaran_up_kkpd', 0);

        if ($up <= 0 && $upKkpd <= 0) {
            return $this->fail('Nilai besaran UP wajib diisi.', [], 422);
        }

        $nilai = ['besaran_up' => $up, 'besaran_up_kkpd' => $upKkpd];

        $ada = DB::table('besaran_up')->where('skpd', $skpd)->where('tahun', $tahun)->exists();

        if ($ada) {
            DB::table('besaran_up')->where('skpd', $skpd)->where('tahun', $tahun)->update($nilai);
        } else {
            DB::table('besaran_up')->insert(array_merge(['skpd' => $skpd, 'tahun' => $tahun], $nilai));
        }

        return $this->ok('Besaran UP berhasil disimpan.');
    }
}
