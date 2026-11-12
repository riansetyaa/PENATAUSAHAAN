<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Penerimaan;

use App\Http\Controllers\Controller;
use App\Models\AkunPenerimaan;
use App\Models\SkpDaerah;
use App\Support\Tenant;
use App\Support\Validator;
use Illuminate\Http\Request;

/**
 * API SKP Daerah.
 *
 * Diporting dari api/skp.php pada sistem legacy.
 */
class SkpDaerahController extends Controller
{
    /**
     * Status yang dikenali (identik dengan legacy).
     */
    private const STATUS_LIST = ['aktif', 'terpakai', 'dihapus'];

    /**
     * GET /api/v1/penerimaan/skp
     *
     * Dengan ?id= -> detail satu SKP. Tanpa -> daftar + penghitung status.
     */
    public function index(Request $request)
    {
        $skpd     = Tenant::requireSkpd();
        $detailId = (int) $request->query('id', '0');

        if ($detailId > 0) {
            $row = SkpDaerah::query()->with('dibuatOleh:id,nama_lengkap')->find($detailId);

            if (! $row) {
                return $this->fail('SKP Daerah tidak ditemukan.', [], 404);
            }

            return $this->ok('OK', ['skp' => $this->mapSkp($row)]);
        }

        $q      = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'semua');

        // Penghitung per status
        $counts = [];

        foreach (self::STATUS_LIST as $st) {
            $counts[$st] = (int) SkpDaerah::query()->where('status', $st)->count();
        }

        $query = SkpDaerah::query()->with('dibuatOleh:id,nama_lengkap');

        if ($status !== 'semua') {
            if (! in_array($status, self::STATUS_LIST, true)) {
                $status = 'semua';
            } else {
                $query->where('status', $status);
            }
        } else {
            $query->where('status', '<>', 'dihapus');
        }

        if ($q !== '') {
            $like = '%' . $q . '%';
            $query->where(function ($builder) use ($like) {
                $builder->where('nomor_skp', 'like', $like)
                    ->orWhere('nama_penyetor', 'like', $like)
                    ->orWhere('jenis_pajak', 'like', $like)
                    ->orWhere('objek_pajak', 'like', $like)
                    ->orWhere('akun_kode', 'like', $like);
            });
        }

        $rows = $query->orderByDesc('tanggal')->orderByDesc('id')->get();

        return $this->ok('OK', [
            'status'      => $status,
            'status_list' => self::STATUS_LIST,
            'counts'      => $counts,
            'data'        => $rows->map(fn (SkpDaerah $s): array => $this->mapSkp($s))->all(),
        ]);
    }

    /**
     * POST /api/v1/penerimaan/skp
     *
     * Body JSON: { action: create|update|delete, ... }
     */
    public function store(Request $request)
    {
        $action = (string) $request->input('action', 'create');
        $skpd   = Tenant::requireSkpd();

        if ($action === 'delete') {
            $id = (int) $request->input('id', 0);

            if ($id <= 0) {
                return $this->fail('ID tidak valid.', [], 422);
            }

            $terbarui = SkpDaerah::query()
                ->whereKey($id)
                ->where('skpd', $skpd)
                ->update(['status' => 'dihapus']);

            if ($terbarui === 0) {
                return $this->fail('SKP Daerah tidak ditemukan atau bukan milik instansi Anda.', [], 404);
            }

            return $this->ok('SKP Daerah berhasil dihapus.');
        }

        if ($action !== 'create' && $action !== 'update') {
            return $this->fail('Aksi tidak dikenali.', [], 422);
        }

        $id           = (int) $request->input('id', 0);
        $nomor        = trim((string) $request->input('nomor_skp', ''));
        $tanggal      = trim((string) $request->input('tanggal', ''));
        $jenisPajak   = trim((string) $request->input('jenis_pajak', ''));
        $akunKode     = trim((string) $request->input('akun_akun', ''));
        $akunKode     = trim((string) $request->input('akun_kode', $akunKode));
        $namaPenyetor = trim((string) $request->input('nama_penyetor', ''));
        $alamat       = trim((string) $request->input('alamat', ''));
        $npwpd        = trim((string) $request->input('npwpd', ''));
        $objek        = trim((string) $request->input('objek_pajak', ''));
        $nilai        = (float) $request->input('nilai_keputusan', 0);
        $masaDari     = trim((string) $request->input('masa_pajak_dari', ''));
        $masaAkhir    = trim((string) $request->input('masa_pajak_akhir', ''));
        $jatuhTempo   = trim((string) $request->input('jatuh_tempo', ''));
        $keterangan   = trim((string) $request->input('keterangan', ''));

        // Auto-generate nomor SKP jika kosong
        if ($nomor === '') {
            $nomor = 'SKP-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        }

        if ($tanggal === '') {
            return $this->fail('Tanggal SKP wajib diisi.', ['field' => 'tanggal'], 422);
        }

        if ($namaPenyetor === '') {
            return $this->fail('Nama Penyetor (Wajib Pajak) wajib diisi.', ['field' => 'nama_penyetor'], 422);
        }

        if ($nilai <= 0) {
            return $this->fail('Nilai ketetapan harus lebih dari 0.', ['field' => 'nilai_keputusan'], 422);
        }

        foreach (['masa_pajak_dari' => $masaDari, 'masa_pajak_akhir' => $masaAkhir, 'jatuh_tempo' => $jatuhTempo] as $f => $v) {
            if ($v !== '' && ! Validator::tanggal($v)) {
                return $this->fail("Format tanggal tidak valid ({$f}).", ['field' => $f], 422);
            }
        }

        // Jenis pajak WAJIB terikat ke Akun Penerimaan yang aktif di Pengaturan
        // (milik instansi yang sama). Server yang menentukan kode & nama akun;
        // input klien hanya sebagai pencari.
        $akunTerm = $akunKode !== '' ? $akunKode : $jenisPajak;

        if ($akunTerm === '') {
            return $this->fail('Jenis pajak wajib dipilih dari daftar Akun Penerimaan.', ['field' => 'jenis_pajak'], 422);
        }

        $akun = AkunPenerimaan::query()
            ->where('status_aktif', true)
            ->where(function ($builder) use ($akunTerm) {
                $builder->where('kode_akun', $akunTerm)->orWhere('nama_akun', $akunTerm);
            })
            ->first();

        if (! $akun) {
            return $this->fail(
                'Jenis pajak wajib dipilih dari daftar Akun Penerimaan yang aktif di menu Pengaturan.',
                ['field' => 'jenis_pajak'],
                422
            );
        }

        $akunKode   = (string) $akun->kode_akun;
        $jenisPajak = (string) $akun->nama_akun;

        $atribut = [
            'nomor_skp'        => $nomor,
            'tanggal'         => $tanggal,
            'akun_kode'       => $akunKode,
            'jenis_pajak'     => $jenisPajak,
            'nama_penyetor'   => $namaPenyetor,
            'alamat'          => $alamat,
            'npwpd'           => $npwpd,
            'objek_pajak'     => $objek,
            'nilai_keputusan' => $nilai,
            'masa_pajak_dari' => $masaDari !== '' ? $masaDari : null,
            'masa_pajak_akhir' => $masaAkhir !== '' ? $masaAkhir : null,
            'jatuh_tempo'     => $jatuhTempo !== '' ? $jatuhTempo : null,
            'keterangan'      => $keterangan,
        ];

        if ($action === 'create') {
            $skp = SkpDaerah::create(array_merge($atribut, [
                'user_id' => $request->user()?->id,
                'skpd'    => $skpd,
                'status'  => 'aktif',
            ]));

            $id = (int) $skp->id;
        } else {
            if ($id <= 0) {
                return $this->fail('ID tidak valid untuk pembaruan.', [], 422);
            }

            $terbarui = SkpDaerah::query()
                ->whereKey($id)
                ->where('skpd', $skpd)
                ->update($atribut);

            if ($terbarui === 0) {
                // rowCount 0 bisa berarti tidak ada perubahan; pastikan data ada.
                $ada = SkpDaerah::query()->whereKey($id)->where('skpd', $skpd)->exists();

                if (! $ada) {
                    return $this->fail('SKP Daerah tidak ditemukan atau bukan milik instansi Anda.', [], 404);
                }
            }
        }

        return $this->ok('SKP Daerah berhasil disimpan.', [
            'id' => $id,
        ], $action === 'create' ? 201 : 200);
    }

    /**
     * Pemetaan baris SKP ke bentuk respons (identik dengan skpMap() legacy).
     */
    private function mapSkp(SkpDaerah $s): array
    {
        return [
            'id'              => (int) $s->id,
            'skpd'            => (string) $s->skpd,
            'nomor_skp'       => (string) $s->nomor_skp,
            'tanggal'         => (string) $s->tanggal,
            'akun_kode'       => (string) $s->akun_kode,
            'jenis_pajak'     => (string) $s->jenis_pajak,
            'nama_penyetor'   => (string) $s->nama_penyetor,
            'alamat'          => (string) $s->alamat,
            'npwpd'           => (string) $s->npwpd,
            'objek_pajak'     => (string) $s->objek_pajak,
            'nilai_keputusan' => (float) $s->nilai_keputusan,
            'masa_pajak_dari' => (string) ($s->masa_pajak_dari ?? ''),
            'masa_pajak_akhir' => (string) ($s->masa_pajak_akhir ?? ''),
            'jatuh_tempo'     => (string) ($s->jatuh_tempo ?? ''),
            'keterangan'      => (string) $s->keterangan,
            'status'          => (string) $s->status,
            'dibuat_oleh'     => (string) ($s->dibuatOleh?->nama_lengkap ?? ''),
            'created_at'      => (string) $s->created_at,
        ];
    }
}
