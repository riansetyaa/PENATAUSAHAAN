<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Aklap;

use App\Http\Controllers\Api\V1\Aklap\Concerns\TanggalValid;
use App\Http\Controllers\Controller;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AKLAP — Register SPP/SPM/SP2D & LPJ Bendahara Pengeluaran PPKD.
 *
 * Diporting dari peta.simtkd.com/api/akuntansi.php:
 *  - `register_ppkd_list`   -> registerList()
 *  - `register_ppkd_simpan` -> registerSimpan()
 *  - `lpj_ppkd`             -> lpj()
 *
 * Register = turunan Buku Pengeluaran PPKD (satu baris per transaksi
 * `buku_kas` jenis pengeluaran); pengguna hanya mengisi tanggal & nomor
 * SPP/SPM/SP2D + keterangan (upsert per `buku_kas_id`).
 *
 * LPJ = blanko Modul-3E-LK: rincian pengeluaran per kode rekening (pagu dari
 * `anggaran_lra`, dengan fallback kode induk), rekonsiliasi penerimaan &
 * pengeluaran per bucket (SP2D/SPJ, PPN, PPh 21/22/23, PFK), dan saldo kas.
 */
class RegisterPpkdController extends Controller
{
    use TanggalValid;

    /**
     * GET /api/v1/aklap/register-ppkd  (action=register_ppkd_list)
     */
    public function registerList(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $user  = $request->user();
        $dari  = $this->tanggalValid((string) $request->query('dari', ''));
        $akhir = $this->tanggalValid((string) $request->query('akhir', ''));

        $query = DB::table('buku_kas as bk')
            ->leftJoin('register_ppkd as r', function ($join): void {
                $join->on('r.buku_kas_id', '=', 'bk.id')->on('r.skpd', '=', 'bk.skpd');
            })
            ->where('bk.skpd', $skpd)
            ->where('bk.jenis', 'pengeluaran')
            ->select([
                'bk.id', 'bk.tanggal', 'bk.no_bukti', 'bk.kode_rekening', 'bk.uraian', 'bk.jumlah',
                'r.spp_tgl', 'r.spp_no', 'r.spm_tgl', 'r.spm_no', 'r.sp2d_tgl', 'r.sp2d_no', 'r.ket',
            ]);

        if ($dari !== '') {
            $query->where('bk.tanggal', '>=', $dari);
        }

        if ($akhir !== '') {
            $query->where('bk.tanggal', '<=', $akhir);
        }

        $rows    = [];
        $total   = 0.0;
        $lengkap = 0;

        foreach ($query->orderBy('bk.tanggal')->orderBy('bk.id')->get() as $r) {
            $jml = (float) $r->jumlah;
            $total += $jml;

            $sppTgl  = (string) ($r->spp_tgl ?? '');
            $spmTgl  = (string) ($r->spm_tgl ?? '');
            $sp2dTgl = (string) ($r->sp2d_tgl ?? '');
            $sppNo   = (string) ($r->spp_no ?? '');
            $spmNo   = (string) ($r->spm_no ?? '');
            $sp2dNo  = (string) ($r->sp2d_no ?? '');

            $penuh = $sppTgl !== '' && $sppNo !== '' && $spmTgl !== '' && $spmNo !== ''
                && $sp2dTgl !== '' && $sp2dNo !== '';

            if ($penuh) {
                $lengkap++;
            }

            $rows[] = [
                'id'            => (int) $r->id,
                'tanggal'       => (string) $r->tanggal,
                'no_bukti'      => (string) $r->no_bukti,
                'kode_rekening' => (string) $r->kode_rekening,
                'uraian'        => (string) $r->uraian,
                'jumlah'        => $jml,
                'spp_tgl'       => $sppTgl,
                'spp_no'        => $sppNo,
                'spm_tgl'       => $spmTgl,
                'spm_no'        => $spmNo,
                'sp2d_tgl'      => $sp2dTgl,
                'sp2d_no'       => $sp2dNo,
                'ket'           => (string) ($r->ket ?? ''),
                'lengkap'       => $penuh ? 1 : 0,
            ];
        }

        return $this->okData([
            'data'           => $rows,
            'total'          => count($rows),
            'total_jumlah'   => round($total, 2),
            'jumlah_lengkap' => $lengkap,
            'instansi'       => $skpd,
            'kota'           => (string) ($user?->kota ?? ''),
            'bendahara'      => (string) ($user?->nama_lengkap ?? ''),
            'nip'            => trim((string) ($user?->nip ?? '')),
            'periode'        => ['dari' => $dari, 'sampai' => $akhir],
        ]);
    }

    /**
     * POST /api/v1/aklap/register-ppkd  (action=register_ppkd_simpan)
     *
     * Fail-closed: hanya transaksi `buku_kas` jenis pengeluaran milik instansi
     * ini yang boleh diisi (selain itu 403).
     */
    public function registerSimpan(Request $request)
    {
        $skpd = Tenant::requireSkpd();
        $bkId = (int) $request->input('buku_kas_id', 0);

        if ($bkId <= 0) {
            return $this->fail('Transaksi Buku Pengeluaran PPKD tidak valid.', [], 422);
        }

        $adaTransaksi = DB::table('buku_kas')
            ->where('id', $bkId)
            ->where('skpd', $skpd)
            ->where('jenis', 'pengeluaran')
            ->exists();

        if (! $adaTransaksi) {
            return $this->fail('Transaksi tidak ditemukan pada Buku Pengeluaran PPKD Anda.', [], 403);
        }

        foreach (['spp', 'spm', 'sp2d'] as $kode) {
            $tgl = trim((string) $request->input($kode . '_tgl', ''));

            if ($tgl !== '' && $this->tanggalValid($tgl) === '') {
                return $this->fail('Format tanggal ' . strtoupper($kode) . ' tidak valid.', [], 422);
            }

            if (mb_strlen(trim((string) $request->input($kode . '_no', ''))) > 64) {
                return $this->fail('Nomor ' . strtoupper($kode) . ' maksimal 64 karakter.', [], 422);
            }
        }

        $ket = trim((string) $request->input('ket', ''));

        if (mb_strlen($ket) > 120) {
            return $this->fail('Keterangan maksimal 120 karakter.', [], 422);
        }

        $tglAtauNull = function (string $kunci) use ($request) {
            $t = trim((string) $request->input($kunci, ''));

            return $t !== '' ? $t : null;
        };

        $noBersih = fn (string $kunci): string => trim((string) $request->input($kunci, ''));

        $nilai = [
            'spp_tgl'  => $tglAtauNull('spp_tgl'),
            'spp_no'   => $noBersih('spp_no'),
            'spm_tgl'  => $tglAtauNull('spm_tgl'),
            'spm_no'   => $noBersih('spm_no'),
            'sp2d_tgl' => $tglAtauNull('sp2d_tgl'),
            'sp2d_no'  => $noBersih('sp2d_no'),
            'ket'      => $ket,
        ];

        $userId = (int) ($request->user()?->id ?? 0);

        // Upsert portabel: cek dulu -> UPDATE, bila belum ada -> INSERT.
        $idLama = DB::table('register_ppkd')->where('skpd', $skpd)->where('buku_kas_id', $bkId)->value('id');

        if ($idLama !== null) {
            DB::table('register_ppkd')
                ->where('id', (int) $idLama)
                ->where('skpd', $skpd)
                ->update(array_merge($nilai, ['user_id' => $userId]));
        } else {
            DB::table('register_ppkd')->insert(array_merge($nilai, [
                'skpd'        => $skpd,
                'buku_kas_id' => $bkId,
                'user_id'     => $userId,
            ]));
        }

        return $this->okData([
            'message'     => 'Register SPP/SPM/SP2D tersimpan.',
            'buku_kas_id' => $bkId,
        ]);
    }


    /**
     * GET /api/v1/aklap/lpj-ppkd  (action=lpj_ppkd)
     *
     * Blanko LPJ Bendahara Pengeluaran PPKD. Hanya kode rekening yang sudah
     * ada realisasinya (bulan ini atau sebelumnya) yang ditampilkan.
     */
    public function lpj(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $user  = $request->user();
        $tahun = trim((string) $request->query('tahun', date('Y')));

        if (preg_match('/^\d{4}$/', $tahun) !== 1) {
            $tahun = date('Y');
        }

        $bulan = (int) $request->query('bulan', (int) date('n'));

        if ($bulan < 1 || $bulan > 12) {
            $bulan = (int) date('n');
        }

        // ---------- 1) Rincian pengeluaran per kode rekening ----------
        $kelompok = DB::table('buku_kas')
            ->where('skpd', $skpd)
            ->where('jenis', 'pengeluaran')
            ->whereRaw('YEAR(tanggal) = ?', [$tahun])
            ->where('jumlah', '>', 0)
            ->where('kode_rekening', '<>', '')
            ->groupBy('kode_rekening')
            ->orderBy('kode_rekening')
            ->selectRaw(
                'kode_rekening,
                 SUM(CASE WHEN MONTH(tanggal) < ? THEN jumlah ELSE 0 END) AS sd_lalu,
                 SUM(CASE WHEN MONTH(tanggal) = ? THEN jumlah ELSE 0 END) AS bulan_ini,
                 MAX(uraian) AS uraian_terakhir',
                [$bulan, $bulan]
            )
            ->get();

        $paguAkun = [];

        foreach (DB::table('anggaran_lra')
            ->where('skpd', $skpd)
            ->where('tahun', $tahun)
            ->groupBy('kode_akun')
            ->selectRaw('kode_akun, MAX(anggaran) AS pagu, MAX(nama_akun) AS nama')
            ->get() as $r) {
            $paguAkun[(string) $r->kode_akun] = [
                'pagu' => (float) $r->pagu,
                'nama' => trim((string) $r->nama),
            ];
        }

        $namaAkun = [];

        foreach (DB::table('akun_master')->where('skpd', $skpd)->select(['kode_akun', 'nama_akun'])->get() as $r) {
            $namaAkun[(string) $r->kode_akun] = trim((string) $r->nama_akun);
        }

        $rincian     = [];
        $jmlAnggaran = 0.0;
        $jmlSdLalu   = 0.0;
        $jmlBulanIni = 0.0;
        $tanpaPagu   = 0;

        foreach ($kelompok as $r) {
            $kode     = (string) $r->kode_rekening;
            $sdLalu   = (float) $r->sd_lalu;
            $bulanIni = (float) $r->bulan_ini;
            $sdIni    = $sdLalu + $bulanIni;

            if ($sdIni <= 0) {
                continue;               // hanya yang sudah ada angkanya
            }

            $p        = $this->cariPagu($paguAkun, $kode);
            $anggaran = (float) $p['pagu'];
            $uraian   = $namaAkun[$kode] ?? '';

            if ($uraian === '') {
                $uraian = $p['nama'];
            }

            if ($uraian === '') {
                $uraian = trim((string) $r->uraian_terakhir);
            }

            if ($anggaran <= 0) {
                $tanpaPagu++;
            }

            $rincian[] = [
                'kode_rekening' => $kode,
                'uraian'        => $uraian,
                'anggaran'      => $anggaran,
                'sd_lalu'       => $sdLalu,
                'bulan_ini'     => $bulanIni,
                'sd_ini'        => $sdIni,
                'sisa'          => $anggaran - $sdIni,
            ];

            $jmlAnggaran += $anggaran;
            $jmlSdLalu += $sdLalu;
            $jmlBulanIni += $bulanIni;
        }


        // ---------- 2) Rekonsiliasi Penerimaan & Pengeluaran ----------
        $buckets = $this->bucketKosong();

        foreach (DB::table('buku_kas')
            ->where('skpd', $skpd)
            ->whereRaw('YEAR(tanggal) = ?', [$tahun])
            ->where('jumlah', '>', 0)
            ->orderBy('tanggal')
            ->orderBy('id')
            ->selectRaw('jenis, kode_rekening, uraian, jumlah, MONTH(tanggal) AS bln')
            ->get() as $r) {
            $jenis = (string) $r->jenis === 'penerimaan' ? 'penerimaan' : 'pengeluaran';
            $kunci = $this->bucketLpj((string) $r->uraian, (string) $r->kode_rekening, $jenis);
            $bln   = (int) $r->bln;
            $nilai = (float) $r->jumlah;

            if ($bln < $bulan) {
                $buckets[$jenis][$kunci][0] += $nilai;
            } elseif ($bln === $bulan) {
                $buckets[$jenis][$kunci][1] += $nilai;
            }
        }

        $penerimaan  = $this->ringkasBucket($buckets['penerimaan']);
        $pengeluaran = $this->ringkasBucket($buckets['pengeluaran']);

        // ---------- 3) Tanggal & identitas ----------
        $akhirBulan = (int) date('t', (int) mktime(0, 0, 0, $bulan, 1, (int) $tahun));
        $tglLaporan = $tahun . '-' . str_pad((string) $bulan, 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) $akhirBulan, 2, '0', STR_PAD_LEFT);

        if ($tglLaporan > date('Y-m-d')) {
            $tglLaporan = date('Y-m-d');   // bulan berjalan -> s.d. hari ini
        }

        $namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
            'Agustus', 'September', 'Oktober', 'November', 'Desember'][$bulan];

        return $this->okData([
            'tahun'       => $tahun,
            'bulan'       => $bulan,
            'bulan_nama'  => $namaBulan,
            'tgl_laporan' => $tglLaporan,
            'instansi'    => (string) ($user?->instansi ?? ''),
            'kota'        => (string) ($user?->kota ?? ''),
            'bendahara'   => (string) ($user?->nama_lengkap ?? ''),
            'nip'         => trim((string) ($user?->nip ?? '')),
            'rincian'     => $rincian,
            'jumlah_rincian' => [
                'anggaran'  => round($jmlAnggaran, 2),
                'sd_lalu'   => round($jmlSdLalu, 2),
                'bulan_ini' => round($jmlBulanIni, 2),
                'sd_ini'    => round($jmlSdLalu + $jmlBulanIni, 2),
                'sisa'      => round($jmlAnggaran - $jmlSdLalu - $jmlBulanIni, 2),
            ],
            'penerimaan'  => $penerimaan,
            'pengeluaran' => $pengeluaran,
            'saldo_kas'   => [
                'bulan_ini' => round($penerimaan['jumlah']['bulan_ini'] - $pengeluaran['jumlah']['bulan_ini'], 2),
                'sd_ini'    => round($penerimaan['jumlah']['sd_ini'] - $pengeluaran['jumlah']['sd_ini'], 2),
            ],
            'jumlah_baris' => count($rincian),
            'tanpa_pagu'   => $tanpaPagu,
        ]);
    }

    /**
     * Kelompok baris rekonsiliasi LPJ: potongan pajak / PFK / utama (SP2D-SPJ).
     * Disalin setia dari legacy `lpj_bucket_ppkd()`.
     */
    private function bucketLpj(string $uraian, string $kode, string $jenis): string
    {
        $t = mb_strtolower($uraian . ' ' . $kode);

        foreach (['ppn' => 'ppn', 'pph21' => 'pph-21', 'pph22' => 'pph-22', 'pph23' => 'pph-23'] as $kunci => $cari) {
            if (mb_strpos($t, $cari) !== false) {
                return $kunci;
            }
        }

        if (mb_strpos($t, 'pph 21') !== false || mb_strpos($t, 'pph-21') !== false) {
            return 'pph21';
        }

        if (mb_strpos($t, 'pph 22') !== false) {
            return 'pph22';
        }

        if (mb_strpos($t, 'pph 23') !== false) {
            return 'pph23';
        }

        if (mb_strpos($t, 'pfk') !== false || mb_strpos($t, 'pihak ketiga') !== false) {
            return 'pfk';
        }

        return $jenis === 'penerimaan' ? 'sp2d' : 'spj';
    }

    /**
     * Keranjang nilai awal per jenis transaksi: [sd_lalu, bulan_ini].
     *
     * @return array<string, array<string, array{0: float, 1: float}>>
     */
    private function bucketKosong(): array
    {
        return [
            'penerimaan'  => ['sp2d' => [0.0, 0.0], 'ppn' => [0.0, 0.0], 'pph21' => [0.0, 0.0], 'pph22' => [0.0, 0.0], 'pph23' => [0.0, 0.0], 'pfk' => [0.0, 0.0]],
            'pengeluaran' => ['spj' => [0.0, 0.0], 'ppn' => [0.0, 0.0], 'pph21' => [0.0, 0.0], 'pph22' => [0.0, 0.0], 'pph23' => [0.0, 0.0], 'pfk' => [0.0, 0.0]],
        ];
    }

    /**
     * Ringkas keranjang menjadi {kunci: {sd_lalu, bulan_ini, sd_ini}} + 'jumlah'.
     *
     * @param  array<string, array{0: float, 1: float}>  $b
     * @return array<string, array<string, float>>
     */
    private function ringkasBucket(array $b): array
    {
        $out           = [];
        $totalSdLalu   = 0.0;
        $totalBulanIni = 0.0;

        foreach ($b as $kunci => $v) {
            $sdLalu   = round($v[0], 2);
            $bulanIni = round($v[1], 2);

            $out[$kunci] = [
                'sd_lalu'   => $sdLalu,
                'bulan_ini' => $bulanIni,
                'sd_ini'    => round($sdLalu + $bulanIni, 2),
            ];

            $totalSdLalu += $sdLalu;
            $totalBulanIni += $bulanIni;
        }

        $out['jumlah'] = [
            'sd_lalu'   => round($totalSdLalu, 2),
            'bulan_ini' => round($totalBulanIni, 2),
            'sd_ini'    => round($totalSdLalu + $totalBulanIni, 2),
        ];

        return $out;
    }

    /**
     * Cari pagu akun: cocok persis; bila tidak ada, pakai kode induk
     * (5.1.2.01.04 -> 5.1.2.01 -> 5.1.2 -> ...).
     *
     * @param  array<string, array{pagu: float, nama: string}>  $paguAkun
     * @return array{pagu: float, nama: string}
     */
    private function cariPagu(array $paguAkun, string $kode): array
    {
        $k = $kode;

        while ($k !== '') {
            if (isset($paguAkun[$k])) {
                return $paguAkun[$k];
            }

            $pos = strrpos($k, '.');

            if ($pos === false) {
                break;
            }

            $k = substr($k, 0, $pos);
        }

        return ['pagu' => 0.0, 'nama' => ''];
    }
}
