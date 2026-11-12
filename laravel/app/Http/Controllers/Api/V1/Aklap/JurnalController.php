<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Aklap;

use App\Http\Controllers\Controller;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AKLAP — Jurnal.
 *
 * Diporting dari blok `action=jurnal` dan `action=jurnal_biasa` (daftar)
 * pada peta.simtkd.com/api/aklap.php.
 *
 * Catatan: respons sukses modul AKLAP tidak memuat kunci `message`
 * (berbeda dari modul utama), sehingga memakai helper `okData()`.
 */
class JurnalController extends Controller
{
    /**
     * GET /api/v1/aklap/jurnal
     *
     * Gabungan dokumen sumber jurnal: STBP (penerimaan, sudah divalidasi),
     * STS (penyetoran, aktif), dan SP2D (pengeluaran, sudah dicairkan).
     */
    public function index()
    {
        $skpd = Tenant::requireSkpd();
        $rows = [];

        foreach (DB::table('stbp')
            ->where('status', 'sudah_divalidasi')
            ->where('skpd', $skpd)
            ->orderBy('tanggal')
            ->orderBy('id')
            ->get() as $r) {
            $jstat = (string) ($r->jurnal_status ?? 'belum_approve');

            $rows[] = [
                'no'            => 'JRN-' . ($r->nomor_stbp ?: 'STBP-' . $r->id),
                'dok'           => (string) ($r->nomor_stbp ?: ''),
                'tgl'           => (string) ($r->tanggal ?: ''),
                'tglAkhir'      => (string) ($r->tanggal ?: ''),
                'nilai'         => (float) ($r->jumlah ?? 0),
                'ket'           => (string) ($r->uraian ?: ''),
                'status'        => $this->labelStatus($jstat),
                'jurnal_status' => $jstat,
                'transaksi'     => 'Penerimaan',
                'sumber'        => 'stbp',
                'id'            => (int) $r->id,
                'akun_kode'     => (string) ($r->akun_kode ?: ''),
                'akun_nama'     => (string) ($r->akun_nama ?: ''),
                'skpd'          => (string) ($r->skpd ?: ''),
            ];
        }

        foreach (DB::table('sts')
            ->where('status', 'aktif')
            ->where('skpd', $skpd)
            ->orderBy('tanggal_sts')
            ->orderBy('id')
            ->get() as $r) {
            $jstat = (string) ($r->jurnal_status ?? 'belum_approve');

            $rows[] = [
                'no'            => 'JRN-' . ($r->nomor_sts ?: 'STS-' . $r->id),
                'dok'           => (string) ($r->nomor_sts ?: ''),
                'tgl'           => (string) ($r->tanggal_sts ?: ''),
                'tglAkhir'      => (string) ($r->tanggal_acuan_akhir ?: ($r->tanggal_sts ?: '')),
                'nilai'         => (float) ($r->total ?? 0),
                'ket'           => (string) ($r->keterangan ?: ''),
                'status'        => $this->labelStatus($jstat),
                'jurnal_status' => $jstat,
                'transaksi'     => 'Penyetoran',
                'sumber'        => 'sts',
                'id'            => (int) $r->id,
                'akun_kode'     => '',
                'akun_nama'     => '',
                'skpd'          => (string) ($r->skpd ?: ''),
            ];
        }

        foreach (DB::table('sp2d as s')
            ->leftJoin('spm as m', 'm.id', '=', 's.spm_id')
            ->where('s.status', 'sudah_dicairkan')
            ->where('s.skpd', $skpd)
            ->orderBy('s.tanggal')
            ->orderBy('s.id')
            ->get(['s.*', 'm.nomor_spm']) as $r) {
            $jstat = (string) ($r->jurnal_status ?? 'belum_approve');
            $nomorSpm = (string) ($r->nomor_spm ?? '');

            $rows[] = [
                'no'            => 'JRN-' . ($r->nomor_sp2d ?: 'SP2D-' . $r->id),
                'dok'           => (string) ($r->nomor_sp2d ?: ''),
                'tgl'           => (string) ($r->tanggal ?: ''),
                'tglAkhir'      => (string) ($r->tanggal ?: ''),
                'nilai'         => (float) ($r->jumlah ?? 0),
                'ket'           => 'Pencairan SP2D' . ($nomorSpm !== '' ? ' (' . $nomorSpm . ')' : ''),
                'status'        => $this->labelStatus($jstat),
                'jurnal_status' => $jstat,
                'transaksi'     => 'Pengeluaran',
                'sumber'        => 'sp2d',
                'id'            => (int) $r->id,
                'akun_kode'     => '',
                'akun_nama'     => '',
                'skpd'          => (string) ($r->skpd ?: ''),
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['tgl'], $b['tgl']) ?: ($a['id'] <=> $b['id']));

        return $this->okData(['data' => $rows, 'total' => count($rows)]);
    }

    /** Label status jurnal (identik legacy). */
    private function labelStatus(string $jurnalStatus): string
    {
        if ($jurnalStatus === 'sudah_approve') {
            return 'Sudah Approve';
        }

        return $jurnalStatus === 'ditolak' ? 'Ditolak' : 'Belum Approve';
    }

    /**
     * GET /api/v1/aklap/jurnal-biasa
     *
     * Daftar jurnal manual (input jurnal biasa) dengan filter periode,
     * kata kunci, dan jenis pencarian. Mutasi (simpan/hapus) menyusul pada
     * fase berikutnya.
     */
    public function jurnalBiasa(Request $request)
    {
        $skpd = Tenant::requireSkpd();

        // Tanggal tidak valid diabaikan (perilaku legacy).
        $dari  = $this->tanggalValid((string) $request->query('dari', ''));
        $akhir = $this->tanggalValid((string) $request->query('akhir', ''));
        $kw    = trim((string) $request->query('q', ''));
        $tipe  = trim((string) $request->query('tipe', ''));

        $query = DB::table('jurnal_manual')
            ->select(['id', 'nomor_jurnal', 'nomor_dokumen', 'kode_akun', 'nama_akun', 'tanggal', 'uraian', 'jumlah'])
            ->where('skpd', $skpd);

        if ($dari !== '') {
            $query->where('tanggal', '>=', $dari);
        }

        if ($akhir !== '') {
            $query->where('tanggal', '<=', $akhir);
        }

        if ($kw !== '') {
            $kolom = ['jurnal' => 'nomor_jurnal', 'dokumen' => 'nomor_dokumen', 'keterangan' => 'uraian'][$tipe] ?? 'uraian';

            if ($tipe === '' || isset(['jurnal' => 1, 'dokumen' => 1, 'keterangan' => 1][$tipe])) {
                $query->where($kolom, 'like', '%' . $kw . '%');
            } else {
                $query->where(function ($builder) use ($kw): void {
                    $builder->where('nomor_jurnal', 'like', '%' . $kw . '%')
                        ->orWhere('nomor_dokumen', 'like', '%' . $kw . '%')
                        ->orWhere('uraian', 'like', '%' . $kw . '%');
                });
            }
        }

        $rows = $query->orderByDesc('tanggal')->orderByDesc('id')->get()->map(fn ($r): array => [
            'id'            => (int) $r->id,
            'nomor_jurnal'  => (string) $r->nomor_jurnal,
            'nomor_dokumen' => (string) $r->nomor_dokumen,
            'kode_akun'     => (string) $r->kode_akun,
            'nama_akun'     => (string) $r->nama_akun,
            'tanggal'       => (string) ($r->tanggal ?? ''),
            'uraian'        => (string) $r->uraian,
            'jumlah'        => (float) $r->jumlah,
        ])->all();

        return $this->ok('OK', ['data' => $rows]);
    }

    /** Kembalikan tanggal bila formatnya Y-m-d, selain itu string kosong. */
    private function tanggalValid(string $tanggal): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) === 1 ? $tanggal : '';
    }
}
