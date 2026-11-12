<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Belanja;

use App\Http\Controllers\Api\V1\Belanja\Concerns\FiltersBelanja;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SPM (Surat Perintah Membayar).
 *
 * Diporting dari blok `action=spm_list` dan `action=spm_ver_list` pada
 * api/belanja.php.
 */
class SpmController extends Controller
{
    use FiltersBelanja;

    /**
     * GET /api/v1/belanja/spm?status=
     *
     * Setiap baris dilengkapi potongan, pajak, dan rincian dari SPP terkait.
     */
    public function index(Request $request)
    {
        $skpd   = $this->skpd();
        $status = $this->status($request);

        $query = DB::table('spm as s')
            ->leftJoin('spp as p', 'p.id', '=', 's.spp_id')
            ->leftJoin('spd as d', 'd.id', '=', 'p.spd_id')
            ->select([
                's.*',
                'p.nomor_spp',
                'p.jenis_spp',
                'p.keperluan',
                'p.jumlah as spp_jumlah',
                'p.total_potongan',
                'p.total_pajak',
                'd.nomor_spd',
            ])
            ->where('s.skpd', $skpd);

        $this->filterStatus($query, 's.status', $status);

        $rows = $query->orderByDesc('s.tanggal')->orderByDesc('s.id')->get();

        foreach ($rows as $r) {
            $r->potongan = [];
            $r->pajak    = [];
            $r->details  = [];

            $sppId = (int) ($r->spp_id ?? 0);

            if ($sppId <= 0) {
                continue;
            }

            $potongan = DB::table('spp_potongan_pajak')
                ->where('spp_id', $sppId)
                ->orderBy('id')
                ->get(['jenis', 'nama', 'nilai']);

            foreach ($potongan as $p) {
                $kunci = ((string) $p->jenis === 'pajak') ? 'pajak' : 'potongan';

                $r->{$kunci}[] = [
                    'nama'  => (string) $p->nama,
                    'nilai' => (float) $p->nilai,
                ];
            }

            $rincian = DB::table('spp_detail')
                ->where('spp_id', $sppId)
                ->orderBy('id')
                ->get(['kode_rekening', 'uraian', 'jumlah']);

            foreach ($rincian as $d) {
                $r->details[] = [
                    'kode_rekening' => (string) $d->kode_rekening,
                    'uraian'        => (string) $d->uraian,
                    'jumlah'        => (float) $d->jumlah,
                ];
            }
        }

        return $this->ok('OK', ['data' => $rows]);
    }

    /**
     * GET /api/v1/belanja/spm-ver
     *
     * Referensi SPM yang sudah diverifikasi (untuk pembuatan SP2D).
     */
    public function daftarVerifikasi()
    {
        $skpd = $this->skpd();

        $rows = DB::table('spm as s')
            ->where('s.status', 'sudah_diverifikasi')
            ->where('s.skpd', $skpd)
            ->orderByDesc('s.tanggal')
            ->orderByDesc('s.id')
            ->get();

        return $this->ok('OK', ['data' => $rows]);
    }

    /**
     * POST /api/v1/belanja/spm/{id}/persetujuan  (action=spm_persetujuan)
     *
     * Persetujuan (SPTJM SPM) memindahkan SPM ke tahap verifikasi.
     */
    public function persetujuan(int $id)
    {
        if ($id <= 0) {
            return $this->fail('ID tidak valid.', [], 422);
        }

        if (! $this->ubahStatus('spm', $id, $this->skpd(), 'belum_disetujui', 'belum_diverifikasi')) {
            return $this->fail('SPM tidak ditemukan / bukan milik instansi Anda.', [], 404);
        }

        return $this->ok('SPM disetujui (SPTJM SPM dibuat).');
    }

    /**
     * POST /api/v1/belanja/spm/{id}/verifikasi  (action=spm_verifikasi)
     */
    public function verifikasi(Request $request, int $id)
    {
        $setuju = (bool) $request->input('setuju', false);

        if ($id <= 0) {
            return $this->fail('ID tidak valid.', [], 422);
        }

        $berubah = $this->ubahStatus(
            'spm',
            $id,
            $this->skpd(),
            'belum_diverifikasi',
            $setuju ? 'sudah_diverifikasi' : 'ditolak'
        );

        if (! $berubah) {
            return $this->fail('SPM tidak ditemukan / belum diverifikasi / bukan milik instansi Anda.', [], 404);
        }

        return $this->ok($setuju ? 'SPM berhasil diverifikasi.' : 'SPM ditolak.');
    }
}
