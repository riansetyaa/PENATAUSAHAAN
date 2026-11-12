<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Aklap;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AKLAP — daftar akun (COA) untuk saran input jurnal biasa.
 *
 * Diporting dari blok `action=akun_list` pada peta.simtkd.com/api/aklap.php.
 * Catatan: daftar akun pada legacy TIDAK difilter per instansi (tabel
 * `akun_penerimaan` bersifat referensi), sehingga tetap demikian di sini.
 */
class AkunController extends Controller
{
    /**
     * GET /api/v1/aklap/akun?q=
     */
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        try {
            $query = DB::table('akun_penerimaan')->select(['kode_akun', 'nama_akun']);

            if ($q !== '') {
                $query->where(function ($builder) use ($q): void {
                    $builder->where('kode_akun', 'like', '%' . $q . '%')
                        ->orWhere('nama_akun', 'like', '%' . $q . '%');
                });
            }

            $akun = $query->orderBy('kode_akun')->limit(100)->get()->map(fn ($r): array => [
                'kode' => (string) $r->kode_akun,
                'nama' => (string) $r->nama_akun,
            ])->all();
        } catch (Throwable) {
            $akun = []; // tabel belum ada -> lewati (perilaku legacy)
        }

        return $this->okData(['data' => $akun]);
    }
}
