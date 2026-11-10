<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Penerimaan;

use App\Http\Controllers\Controller;
use App\Models\Permohonan;
use App\Support\Tenant;
use Illuminate\Http\Request;

/**
 * API Permohonan rekening penerimaan.
 *
 * Diporting dari api/permohonan.php pada sistem legacy.
 *
 * Bentuk respons, pesan error, dan status HTTP sengaja dipertahankan sama
 * agar frontend vanilla yang sudah ada tidak perlu diubah.
 */
class PermohonanController extends Controller
{
    /**
     * GET /api/v1/penerimaan/permohonan
     *
     * Daftar permohonan rekening. Mendukung pencarian (?q=) dan filter
     * rekening yang sudah disetujui & aktif (?aktif=1) untuk keperluan STBP.
     */
    public function index(Request $request)
    {
        $q     = trim((string) $request->query('q', ''));
        $aktif = (string) $request->query('aktif', '');

        $query = Permohonan::query()->with('dibuatOleh:id,nama_lengkap');

        if ($aktif === '1') {
            $query->where('status_disetujui', true)->where('status_aktif', true);
        }

        if ($q !== '') {
            $like = '%' . $q . '%';
            $query->where(function ($builder) use ($like) {
                $builder->where('bank', 'like', $like)
                    ->orWhere('nama_rekening', 'like', $like)
                    ->orWhere('skpd', 'like', $like)
                    ->orWhere('nomor_rekening', 'like', $like);
            });
        }

        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->get();

        return $this->ok('OK', [
            'data' => $rows->map(fn (Permohonan $p): array => [
                'id'               => (int) $p->id,
                'skpd'             => (string) $p->skpd,
                'dibuat_oleh'      => (string) ($p->dibuatOleh?->nama_lengkap ?? ''),
                'bank'             => (string) $p->bank,
                'nama_rekening'    => (string) $p->nama_rekening,
                'nomor_rekening'   => (string) $p->nomor_rekening,
                'status_terbit'    => (bool) $p->status_terbit,
                'status_disetujui' => (bool) $p->status_disetujui,
                'status_aktif'     => (bool) $p->status_aktif,
                'created_at'       => (string) $p->created_at,
            ])->all(),
        ]);
    }

    /**
     * POST /api/v1/penerimaan/permohonan
     *
     * Simpan permohonan baru.
     */
    public function store(Request $request)
    {
        $bank  = trim((string) $request->input('bank', ''));
        $nama  = trim((string) $request->input('nama_rekening', ''));
        $nomor = trim((string) $request->input('nomor_rekening', ''));

        // SKPD selalu dari user yang login (jangan percaya input klien).
        $skpd = Tenant::requireSkpd();

        if ($bank === '') {
            return $this->fail('Silakan pilih bank.', ['field' => 'bank'], 422);
        }

        if ($nama === '') {
            return $this->fail('Nama pemilik rekening wajib diisi.', ['field' => 'nama_rekening'], 422);
        }

        if ($nomor === '') {
            return $this->fail('Nomor rekening wajib diisi.', ['field' => 'nomor_rekening'], 422);
        }

        $permohonan = Permohonan::create([
            'user_id'          => $request->user()?->id,
            'skpd'             => $skpd,
            'bank'             => $bank,
            'nama_rekening'    => $nama,
            'nomor_rekening'   => $nomor,
            'status_terbit'    => false,
            'status_disetujui' => false,
            'status_aktif'     => false,
        ]);

        return $this->ok('Permohonan berhasil disimpan.', [
            'id' => (int) $permohonan->id,
        ], 201);
    }

    /**
     * POST /api/v1/penerimaan/permohonan/{id}/set-aktif
     *
     * Mengaktifkan / menonaktifkan rekening (checklist AKTIF).
     */
    public function setAktif(Request $request, int $id)
    {
        if ($id <= 0) {
            return $this->fail('ID tidak valid.', [], 422);
        }

        $status = $request->boolean('status_aktif') ? 1 : 0;

        // Global scope otomatis membatasi ke SKPD user, jadi record milik
        // instansi lain tidak akan pernah ditemukan.
        $terbarui = Permohonan::query()->whereKey($id)->update(['status_aktif' => $status]);

        if ($terbarui === 0) {
            return $this->fail('Data tidak ditemukan atau bukan milik instansi Anda.', [], 404);
        }

        return $this->ok($status ? 'Rekening diaktifkan.' : 'Rekening dinonaktifkan.');
    }

    /**
     * POST /api/v1/penerimaan/permohonan/{id}/verifikasi
     *
     * Verifikasi rekening: terbit + disetujui + aktif.
     */
    public function verifikasi(Request $request, int $id)
    {
        if ($id <= 0) {
            return $this->fail('ID tidak valid.', [], 422);
        }

        $terbarui = Permohonan::query()->whereKey($id)->update([
            'status_terbit'    => true,
            'status_disetujui' => true,
            'status_aktif'     => true,
        ]);

        if ($terbarui === 0) {
            return $this->fail('Data tidak ditemukan atau bukan milik instansi Anda.', [], 404);
        }

        return $this->ok('Rekening berhasil diverifikasi dan aktif.');
    }
}
