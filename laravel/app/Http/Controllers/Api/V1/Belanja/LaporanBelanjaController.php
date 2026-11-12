<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Belanja;

use App\Http\Controllers\Api\V1\Belanja\Concerns\FiltersBelanja;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Laporan Belanja — Buku Kas Umum (BKU) belanja.
 *
 * Diporting dari blok `action=bku_belanja` pada api/belanja.php.
 * Sumber data: SP2D yang sudah dicairkan, dibatasi periode dari/sampai.
 */
class LaporanBelanjaController extends Controller
{
    use FiltersBelanja;

    /**
     * GET /api/v1/belanja/bku?dari=&sampai=
     */
    public function bku(Request $request)
    {
        $skpd   = $this->skpd();
        $dari   = trim((string) $request->query('dari', ''));
        $sampai = trim((string) $request->query('sampai', ''));

        $query = DB::table('sp2d as s')
            ->leftJoin('spm as m', 'm.id', '=', 's.spm_id')
            ->select(['s.*', 'm.nomor_spm'])
            ->where('s.status', 'sudah_dicairkan')
            ->where('s.skpd', $skpd);

        if ($dari !== '') {
            $query->where('s.tanggal', '>=', $dari);
        }

        if ($sampai !== '') {
            $query->where('s.tanggal', '<=', $sampai);
        }

        $rows = $query->orderBy('s.tanggal')->orderBy('s.id')->get();

        $total = 0.0;

        foreach ($rows as $r) {
            $total += (float) $r->jumlah;
        }

        return $this->ok('OK', ['data' => $rows, 'total' => $total]);
    }
}
