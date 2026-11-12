<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Aklap;

use App\Http\Controllers\Api\V1\Aklap\Concerns\CoaLra;
use App\Http\Controllers\Controller;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AKLAP — Master Akun (COA per instansi).
 *
 * Diporting dari blok `action=akun_list`, `akun_simpan`, `akun_hapus`, dan
 * `akun_seed_coa` pada peta.simtkd.com/api/akuntansi.php.
 *
 * Catatan: daftar akun memakai `LIMIT 500` seperti legacy, dan COA standar
 * di-seed otomatis (COA ringkas + COA LRA dari config/coa_lra.json).
 */
class AkunMasterController extends Controller
{
    use CoaLra;

    /**
     * GET /api/v1/aklap/akun-master?q=
     */
    public function index(Request $request)
    {
        $skpd = Tenant::requireSkpd();
        $q    = trim((string) $request->query('q', ''));

        $this->seedDefault($skpd);
        $this->seedCoa($skpd);

        $query = DB::table('akun_master')
            ->select(['id', 'kode_akun', 'nama_akun', 'tipe', 'saldo_normal', 'aktif'])
            ->where('skpd', $skpd);

        if ($q !== '') {
            $query->where(function ($builder) use ($q): void {
                $builder->where('kode_akun', 'like', '%' . $q . '%')
                    ->orWhere('nama_akun', 'like', '%' . $q . '%');
            });
        }

        $rows = $query->orderBy('kode_akun')->limit(500)->get()->map(fn ($r): array => [
            'id'           => (int) $r->id,
            'kode_akun'    => (string) $r->kode_akun,
            'nama_akun'    => (string) $r->nama_akun,
            'tipe'         => (string) $r->tipe,
            'saldo_normal' => (string) $r->saldo_normal,
            'aktif'        => (int) $r->aktif,
        ])->all();

        return $this->okData(['data' => $rows, 'total' => count($rows)]);
    }

    /**
     * POST /api/v1/aklap/akun-master  (action=akun_simpan)
     *
     * `id` > 0 -> perbarui; selain itu -> tambah baru. Kode akun unik per
     * instansi (bentrok -> 409).
     */
    public function store(Request $request)
    {
        $skpd = Tenant::requireSkpd();

        $id     = (int) $request->input('id', 0);
        $kode   = trim((string) $request->input('kode_akun', ''));
        $nama   = trim((string) $request->input('nama_akun', ''));
        $tipe   = (string) $request->input('tipe', 'aset');
        $normal = (string) $request->input('saldo_normal', 'debet');

        if ($kode === '' || $nama === '') {
            return $this->fail('Kode akun dan nama akun wajib diisi.', [], 422);
        }

        if (! in_array($tipe, ['aset', 'liabilitas', 'ekuitas', 'pendapatan', 'belanja'], true)) {
            $tipe = 'aset';
        }

        if (! in_array($normal, ['debet', 'kredit'], true)) {
            $normal = 'debet';
        }

        if ($id > 0) {
            DB::table('akun_master')
                ->where('id', $id)
                ->where('skpd', $skpd)
                ->update([
                    'kode_akun'    => $kode,
                    'nama_akun'    => $nama,
                    'tipe'         => $tipe,
                    'saldo_normal' => $normal,
                ]);

            return $this->ok('Akun diperbarui.', ['id' => $id]);
        }

        try {
            $baru = (int) DB::table('akun_master')->insertGetId([
                'skpd'         => $skpd,
                'kode_akun'    => $kode,
                'nama_akun'    => $nama,
                'tipe'         => $tipe,
                'saldo_normal' => $normal,
            ]);
        } catch (Throwable) {
            return $this->fail('Kode akun sudah dipakai untuk instansi ini.', [], 409);
        }

        return $this->ok('Akun tersimpan.', ['id' => $baru]);
    }

    /**
     * POST /api/v1/aklap/akun-master/hapus  (action=akun_hapus)
     */
    public function destroy(Request $request)
    {
        $skpd = Tenant::requireSkpd();
        $id   = (int) $request->input('id', 0);

        if ($id <= 0) {
            return $this->fail('ID akun tidak valid.', [], 422);
        }

        $terhapus = DB::table('akun_master')->where('id', $id)->where('skpd', $skpd)->delete();

        return $this->ok($terhapus > 0 ? 'Akun dihapus.' : 'Akun tidak ditemukan.');
    }

    /**
     * POST /api/v1/aklap/akun-master/seed-coa  (action=akun_seed_coa)
     */
    public function seedCoaAction()
    {
        $skpd = Tenant::requireSkpd();
        $n    = $this->seedCoa($skpd);

        return $this->ok(
            $n > 0
                ? $n . ' akun COA LRA standar ditambahkan ke Master Akun.'
                : 'Seluruh COA LRA standar sudah tersedia.',
            ['ditambahkan' => $n, 'coa_jumlah' => count($this->coaLra())]
        );
    }
}
