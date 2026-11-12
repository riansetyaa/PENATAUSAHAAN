<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Penerimaan;

use App\Http\Controllers\Controller;
use App\Models\AkunPenerimaan;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * API Akun Penerimaan (Pengaturan).
 *
 * Diporting dari api/akun_penerimaan.php pada sistem legacy.
 */
class AkunPenerimaanController extends Controller
{
    /**
     * Metode input yang valid (identik dengan legacy).
     */
    private const METODE_LIST = [
        'harian',
        'mingguan',
        'bulanan',
        'per_wajib_pajak',
        'per_wajib_retribusi',
    ];

    /**
     * GET /api/v1/penerimaan/akun
     */
    public function index(Request $request)
    {
        $q     = trim((string) $request->query('q', ''));
        $aktif = (string) $request->query('aktif', '');

        $query = AkunPenerimaan::query()->with('dibuatOleh:id,nama_lengkap');

        if ($aktif === '1') {
            $query->where('status_aktif', true);
        }

        if ($q !== '') {
            $like = '%' . $q . '%';
            $query->where(function ($builder) use ($like) {
                $builder->where('kode_akun', 'like', $like)
                    ->orWhere('nama_akun', 'like', $like)
                    ->orWhere('skpd', 'like', $like);
            });
        }

        $rows = $query->orderBy('kode_akun')->orderBy('id')->get();

        return $this->ok('OK', [
            'metode_list' => self::METODE_LIST,
            'data' => $rows->map(fn (AkunPenerimaan $a): array => [
                'id'           => (int) $a->id,
                'skpd'         => (string) $a->skpd,
                'kode_akun'    => (string) $a->kode_akun,
                'nama_akun'    => (string) $a->nama_akun,
                'metode_input' => (string) $a->metode_input,
                'status_aktif' => (bool) $a->status_aktif,
                'dibuat_oleh'  => (string) ($a->dibuatOleh?->nama_lengkap ?? ''),
                'created_at'   => (string) $a->created_at,
            ])->all(),
        ]);
    }

    /**
     * POST /api/v1/penerimaan/akun
     *
     * Body JSON: { action: create|bulk_create|save_selection|update_metode, ... }
     */
    public function store(Request $request)
    {
        $action = (string) $request->input('action', 'create');
        $skpd   = Tenant::requireSkpd();

        return match ($action) {
            'create'         => $this->create($request, $skpd),
            'bulk_create'    => $this->bulkCreate($request, $skpd),
            'save_selection' => $this->saveSelection($request, $skpd),
            'update_metode'  => $this->updateMetode($request, $skpd),
            default          => $this->fail('Aksi tidak dikenali.', [], 422),
        };
    }

    /** Simpan satu akun baru. */
    private function create(Request $request, string $skpd)
    {
        $kode   = trim((string) $request->input('kode_akun', ''));
        $nama   = trim((string) $request->input('nama_akun', ''));
        $metode = $this->normalizeMetode((string) $request->input('metode_input', 'harian'));

        if ($kode === '') {
            return $this->fail('Kode akun wajib diisi.', ['field' => 'kode_akun'], 422);
        }

        if ($nama === '') {
            return $this->fail('Nama akun wajib diisi.', ['field' => 'nama_akun'], 422);
        }

        $akun = AkunPenerimaan::create([
            'user_id'       => $request->user()?->id,
            'skpd'          => $skpd,
            'kode_akun'     => $kode,
            'nama_akun'     => $nama,
            'metode_input'  => $metode,
            'status_aktif'  => true,
        ]);

        return $this->ok('Akun penerimaan berhasil disimpan.', [
            'id' => (int) $akun->id,
        ], 201);
    }

    /** Simpan banyak akun sekaligus. */
    private function bulkCreate(Request $request, string $skpd)
    {
        $items = (array) $request->input('items', []);

        if ($items === []) {
            return $this->fail('Tidak ada data untuk disimpan.', [], 422);
        }

        $saved = 0;
        $skipped = 0;
        $userId = $request->user()?->id;

        try {
            DB::transaction(function () use ($items, $skpd, $userId, &$saved, &$skipped): void {
                foreach ($items as $item) {
                    $item = (array) $item;
                    $kode = trim((string) ($item['kode_akun'] ?? ''));
                    $nama = trim((string) ($item['nama_akun'] ?? ''));

                    if ($kode === '' || $nama === '') {
                        $skipped++;
                        continue;
                    }

                    AkunPenerimaan::create([
                        'user_id'      => $userId,
                        'skpd'         => $skpd,
                        'kode_akun'    => $kode,
                        'nama_akun'    => $nama,
                        'metode_input' => $this->normalizeMetode((string) ($item['metode_input'] ?? 'harian')),
                        'status_aktif' => true,
                    ]);

                    $saved++;
                }
            });
        } catch (Throwable $e) {
            report($e);

            return $this->fail('Gagal menyimpan data.', [], 500);
        }

        return $this->ok(
            "Berhasil menyimpan {$saved} akun." . ($skipped > 0 ? " ({$skipped} dilewati karena tidak lengkap)" : ''),
            ['saved' => $saved, 'skipped' => $skipped],
            201
        );
    }

    /**
     * Simpan pilihan akun untuk dinas ini (mengganti seluruh pilihan lama).
     *
     * Perhatikan: penghapusan memakai filter SKPD eksplisit karena
     * `delete()` pada Eloquent TIDAK otomatis memakai global scope.
     */
    private function saveSelection(Request $request, string $skpd)
    {
        $items = (array) $request->input('items', []);
        $userId = $request->user()?->id;
        $saved = 0;

        try {
            DB::transaction(function () use ($items, $skpd, $userId, &$saved): void {
                AkunPenerimaan::query()->where('skpd', $skpd)->delete();

                foreach ($items as $item) {
                    $item = (array) $item;
                    $kode = trim((string) ($item['kode_akun'] ?? ''));
                    $nama = trim((string) ($item['nama_akun'] ?? ''));

                    if ($kode === '' || $nama === '') {
                        continue;
                    }

                    AkunPenerimaan::create([
                        'user_id'      => $userId,
                        'skpd'         => $skpd,
                        'kode_akun'    => $kode,
                        'nama_akun'    => $nama,
                        'metode_input' => $this->normalizeMetode((string) ($item['metode_input'] ?? 'harian')),
                        'status_aktif' => true,
                    ]);

                    $saved++;
                }
            });
        } catch (Throwable $e) {
            report($e);

            return $this->fail('Gagal menyimpan pilihan akun.', [], 500);
        }

        return $this->ok("Berhasil menyimpan {$saved} akun penerimaan untuk dinas ini.", [
            'saved' => $saved,
        ]);
    }

    /** Perbarui metode input banyak akun. */
    private function updateMetode(Request $request, string $skpd)
    {
        $items = (array) $request->input('items', []);

        if ($items === []) {
            return $this->fail('Tidak ada data untuk disimpan.', [], 422);
        }

        $updated = 0;

        try {
            DB::transaction(function () use ($items, $skpd, &$updated): void {
                foreach ($items as $item) {
                    $item = (array) $item;
                    $id = (int) ($item['id'] ?? 0);

                    if ($id <= 0) {
                        continue;
                    }

                    $metode = $this->normalizeMetode((string) ($item['metode_input'] ?? ''));
                    $status = ! empty($item['status_aktif']) ? 1 : 0;

                    // Scope SKPD mencegah modifikasi akun instansi lain (IDOR).
                    $updated += AkunPenerimaan::query()
                        ->whereKey($id)
                        ->where('skpd', $skpd)
                        ->update([
                            'metode_input' => $metode,
                            'status_aktif' => $status,
                        ]);
                }
            });
        } catch (Throwable $e) {
            report($e);

            return $this->fail('Gagal menyimpan data.', [], 500);
        }

        return $this->ok("Berhasil menyimpan {$updated} akun.", [
            'updated' => $updated,
        ]);
    }

    /**
     * Normalisasi metode input (identik dengan legacy).
     */
    private function normalizeMetode(string $metode): string
    {
        $m = strtolower(trim($metode));

        return in_array($m, self::METODE_LIST, true) ? $m : 'harian';
    }
}
