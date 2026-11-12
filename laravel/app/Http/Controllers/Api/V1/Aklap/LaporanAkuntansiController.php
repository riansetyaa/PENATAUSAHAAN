<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Aklap;

use App\Http\Controllers\Api\V1\Aklap\Concerns\CoaLra;
use App\Http\Controllers\Api\V1\Aklap\Concerns\TanggalValid;
use App\Http\Controllers\Controller;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AKLAP — Laporan akuntansi (baca-saja).
 *
 * Diporting dari peta.simtkd.com/api/akuntansi.php:
 *  - `buku_besar`        -> bukuBesar()
 *  - `neraca_saldo`      -> neracaSaldo()
 *  - `buku_kas_list`     -> bukuKas()
 *  - `buku_kas_umum`     -> bukuKasUmum()
 *  - `buku_pembantu_list`    -> bukuPembantu()
 *  - `buku_pembantu_obyek_list` -> bukuPembantuObyek()
 *
 * Semua laporan hanya membaca jurnal berstatus `sudah_approve` (kecuali buku
 * kas & buku pembantu yang bersumber dari entri manual `buku_kas`), memakai
 * batas `LIMIT 1000` yang sama dengan legacy, dan mempertahankan bentuk
 * respons (tanpa kunci `message`).
 */
class LaporanAkuntansiController extends Controller
{
    use CoaLra;
    use TanggalValid;

    /**
     * GET /api/v1/aklap/buku-besar  (action=buku_besar)
     *
     * Posting per akun: saldo awal dari neraca_awal + mutasi jurnal
     * sudah_approve, dengan saldo berjalan mengikuti saldo normal akun.
     */
    public function bukuBesar(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $kode  = trim((string) $request->query('kode_akun', ''));
        $dari  = $this->tanggalValid((string) $request->query('dari', ''));
        $akhir = $this->tanggalValid((string) $request->query('akhir', ''));

        if ($kode === '') {
            return $this->fail('Pilih kode akun terlebih dahulu.', [], 422);
        }

        $this->seedDefault($skpd);

        $nama   = $this->akunNamaOf($skpd, $kode);
        $normal = 'debet';

        try {
            $n = (string) (DB::table('akun_master')
                ->where('skpd', $skpd)
                ->where('kode_akun', $kode)
                ->value('saldo_normal') ?? '');

            if ($n !== '') {
                $normal = $n;
            }
        } catch (Throwable) {
            // akun_master belum ada -> pakai saldo normal bawaan (debet)
        }

        // Saldo awal dari neraca_awal (bila tabel ada) — tahun dari periode.
        $saldoAwal = 0.0;

        try {
            $tahun = $dari !== '' ? (int) substr($dari, 0, 4) : (int) date('Y');

            $saldoAwal = (float) DB::table('neraca_awal')
                ->where('skpd', $skpd)
                ->where('kode_akun', $kode)
                ->where('tahun', $tahun)
                ->sum('saldo');
        } catch (Throwable) {
            $saldoAwal = 0.0;
        }

        $query = DB::table('jurnal_umum_detail as d')
            ->join('jurnal_umum as j', 'j.id', '=', 'd.jurnal_id')
            ->where('d.skpd', $skpd)
            ->where('d.kode_akun', $kode)
            ->where('j.status', 'sudah_approve')
            ->select(['j.nomor_jurnal', 'j.tanggal', 'j.uraian', 'j.sumber_tipe', 'd.posisi', 'd.jumlah']);

        if ($dari !== '') {
            $query->where('j.tanggal', '>=', $dari);
        }

        if ($akhir !== '') {
            $query->where('j.tanggal', '<=', $akhir);
        }

        $rows  = [];
        $saldo = $saldoAwal;
        $totD  = 0.0;
        $totK  = 0.0;

        foreach ($query->orderBy('j.tanggal')->orderBy('j.id')->orderBy('d.id')->limit(1000)->get() as $r) {
            $d = ((string) $r->posisi === 'debet') ? (float) $r->jumlah : 0.0;
            $k = ((string) $r->posisi === 'kredit') ? (float) $r->jumlah : 0.0;

            $totD += $d;
            $totK += $k;
            $saldo += ($normal === 'debet') ? ($d - $k) : ($k - $d);

            $rows[] = [
                'nomor_jurnal' => (string) $r->nomor_jurnal,
                'tanggal'      => (string) ($r->tanggal ?? ''),
                'uraian'       => (string) $r->uraian,
                'ref'          => strtoupper((string) $r->sumber_tipe),
                'debet'        => $d,
                'kredit'       => $k,
                'saldo'        => round($saldo, 2),
                'saldo_posisi' => $normal,
            ];
        }

        return $this->okData([
            'kode_akun'    => $kode,
            'nama_akun'    => $nama,
            'saldo_normal' => $normal,
            'saldo_awal'   => $saldoAwal,
            'total_debet'  => round($totD, 2),
            'total_kredit' => round($totK, 2),
            'saldo_akhir'  => round($saldo, 2),
            'data'         => $rows,
            'total'        => count($rows),
        ]);
    }

    /**
     * GET /api/v1/aklap/neraca-saldo  (action=neraca_saldo)
     *
     * Mutasi + saldo awal + saldo akhir per akun. Akun yang ber-mutasi tetapi
     * belum terdaftar di Master Akun (mis. pendapatan dari akun_penerimaan)
     * ditambahkan di akhir lalu seluruh baris diurutkan ulang menurut kode.
     */
    public function neracaSaldo(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $dari  = $this->tanggalValid((string) $request->query('dari', ''));
        $akhir = $this->tanggalValid((string) $request->query('akhir', ''));

        $this->seedDefault($skpd);

        // Mutasi dari jurnal yang sudah di-approve.
        $query = DB::table('jurnal_umum_detail as d')
            ->join('jurnal_umum as j', 'j.id', '=', 'd.jurnal_id')
            ->where('d.skpd', $skpd)
            ->where('j.status', 'sudah_approve');

        if ($dari !== '') {
            $query->where('j.tanggal', '>=', $dari);
        }

        if ($akhir !== '') {
            $query->where('j.tanggal', '<=', $akhir);
        }

        $mutasi = [];

        foreach ($query
            ->selectRaw("d.kode_akun,
                SUM(CASE WHEN d.posisi = 'debet'  THEN d.jumlah ELSE 0 END) AS mutasi_debet,
                SUM(CASE WHEN d.posisi = 'kredit' THEN d.jumlah ELSE 0 END) AS mutasi_kredit")
            ->groupBy('d.kode_akun')
            ->get() as $r) {
            $mutasi[(string) $r->kode_akun] = [
                'debet'  => (float) $r->mutasi_debet,
                'kredit' => (float) $r->mutasi_kredit,
            ];
        }

        // Saldo awal dari neraca_awal (bila tabel ada).
        $awal = [];

        try {
            $tahun = $dari !== '' ? (int) substr($dari, 0, 4) : (int) date('Y');

            foreach (DB::table('neraca_awal')
                ->where('skpd', $skpd)
                ->where('tahun', $tahun)
                ->selectRaw('kode_akun, COALESCE(SUM(saldo), 0) AS saldo')
                ->groupBy('kode_akun')
                ->get() as $r) {
                $awal[(string) $r->kode_akun] = (float) $r->saldo;
            }
        } catch (Throwable) {
            $awal = [];
        }

        $rows = [];
        $ada  = [];

        foreach (DB::table('akun_master')
            ->where('skpd', $skpd)
            ->where('aktif', 1)
            ->select(['kode_akun', 'nama_akun', 'tipe', 'saldo_normal'])
            ->orderBy('kode_akun')
            ->get() as $a) {
            $kode = (string) $a->kode_akun;
            $m    = $mutasi[$kode] ?? ['debet' => 0.0, 'kredit' => 0.0];
            $sa   = $awal[$kode] ?? 0.0;

            $akhirSaldo = ((string) $a->saldo_normal === 'debet')
                ? ($sa + $m['debet'] - $m['kredit'])
                : ($sa + $m['kredit'] - $m['debet']);

            $rows[] = [
                'kode_akun'     => $kode,
                'nama_akun'     => (string) $a->nama_akun,
                'tipe'          => (string) $a->tipe,
                'saldo_normal'  => (string) $a->saldo_normal,
                'saldo_awal'    => $sa,
                'mutasi_debet'  => round($m['debet'], 2),
                'mutasi_kredit' => round($m['kredit'], 2),
                'saldo_akhir'   => round($akhirSaldo, 2),
            ];

            $ada[$kode] = true;
        }

        // Akun ber-mutasi tapi belum terdaftar di Master Akun.
        foreach ($mutasi as $kode => $m) {
            if (isset($ada[$kode])) {
                continue;
            }

            $rows[] = [
                'kode_akun'     => (string) $kode,
                'nama_akun'     => $this->akunNamaOf($skpd, (string) $kode),
                'tipe'          => 'pendapatan',
                'saldo_normal'  => ($m['kredit'] >= $m['debet'] ? 'kredit' : 'debet'),
                'saldo_awal'    => 0.0,
                'mutasi_debet'  => round($m['debet'], 2),
                'mutasi_kredit' => round($m['kredit'], 2),
                'saldo_akhir'   => round(abs($m['debet'] - $m['kredit']), 2),
            ];
        }

        usort($rows, static fn (array $x, array $z): int => strcmp((string) $x['kode_akun'], (string) $z['kode_akun']));

        return $this->okData(['data' => $rows, 'total' => count($rows)]);
    }


    /**
     * GET /api/v1/aklap/buku-kas  (action=buku_kas_list)
     *
     * Buku kas manual (penerimaan/pengeluaran) dengan saldo berjalan.
     */
    public function bukuKas(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $dari  = $this->tanggalValid((string) $request->query('dari', ''));
        $akhir = $this->tanggalValid((string) $request->query('akhir', ''));
        $jenis = (string) $request->query('jenis', '');

        $query = DB::table('buku_kas')
            ->where('skpd', $skpd)
            ->select(['id', 'tanggal', 'jenis', 'no_bukti', 'kode_rekening', 'uraian', 'jumlah']);

        if ($dari !== '') {
            $query->where('tanggal', '>=', $dari);
        }

        if ($akhir !== '') {
            $query->where('tanggal', '<=', $akhir);
        }

        if (in_array($jenis, ['penerimaan', 'pengeluaran'], true)) {
            $query->where('jenis', $jenis);
        }

        $rows  = [];
        $saldo = 0.0;
        $tp    = 0.0;
        $tk    = 0.0;

        foreach ($query->orderBy('tanggal')->orderBy('id')->get() as $r) {
            $jml         = (float) $r->jumlah;
            $jenisR      = (string) $r->jenis;
            $penerimaan  = $jenisR === 'penerimaan' ? $jml : 0.0;
            $pengeluaran = $jenisR === 'pengeluaran' ? $jml : 0.0;

            $saldo += $penerimaan - $pengeluaran;

            if ($jenisR === 'penerimaan') {
                $tp += $jml;
            } else {
                $tk += $jml;
            }

            $rows[] = [
                'id'            => (int) $r->id,
                'tanggal'       => (string) $r->tanggal,
                'jenis'         => $jenisR,
                'no_bukti'      => (string) $r->no_bukti,
                'kode_rekening' => (string) $r->kode_rekening,
                'uraian'        => (string) $r->uraian,
                'jumlah'        => $jml,
                'penerimaan'    => $penerimaan,
                'pengeluaran'   => $pengeluaran,
                'saldo'         => round($saldo, 2),
            ];
        }

        return $this->okData([
            'data'              => $rows,
            'total'             => count($rows),
            'saldo_akhir'       => round($saldo, 2),
            'total_penerimaan'  => round($tp, 2),
            'total_pengeluaran' => round($tk, 2),
        ]);
    }

    /**
     * GET /api/v1/aklap/buku-kas-umum  (action=buku_kas_umum)
     *
     * Buku Kas Umum BUD: gabungan penerimaan + pengeluaran dengan saldo
     * berjalan. Saldo awal = posisi kas sebelum `dari` (bila periode dipilih),
     * dapat ditimpa manual lewat `saldo_awal`.
     */
    public function bukuKasUmum(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $user  = $request->user();
        $dari  = $this->tanggalValid((string) $request->query('dari', ''));
        $akhir = $this->tanggalValid((string) $request->query('akhir', ''));

        $saldoAwal = 0.0;

        if ($dari !== '') {
            $netto = DB::table('buku_kas')
                ->where('skpd', $skpd)
                ->where('tanggal', '<', $dari)
                ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'penerimaan' THEN jumlah ELSE -jumlah END), 0) AS netto")
                ->first();

            $saldoAwal = (float) ($netto->netto ?? 0);
        }

        // Bisa ditimpa manual (mis. saldo awal kas dari Neraca) lewat ?saldo_awal=
        $manual = str_replace([' ', '.'], ['', ''], trim((string) $request->query('saldo_awal', '')));
        $manual = str_replace(',', '.', $manual);

        if ($manual !== '' && is_numeric($manual)) {
            $saldoAwal = (float) $manual;
        }

        $query = DB::table('buku_kas')
            ->where('skpd', $skpd)
            ->select(['id', 'tanggal', 'jenis', 'no_bukti', 'kode_rekening', 'uraian', 'jumlah']);

        if ($dari !== '') {
            $query->where('tanggal', '>=', $dari);
        }

        if ($akhir !== '') {
            $query->where('tanggal', '<=', $akhir);
        }

        $rows  = [];
        $saldo = $saldoAwal;
        $tp    = 0.0;
        $tk    = 0.0;

        foreach ($query->orderBy('tanggal')->orderBy('id')->limit(1000)->get() as $r) {
            $jml         = (float) $r->jumlah;
            $penerimaan  = (string) $r->jenis === 'penerimaan' ? $jml : 0.0;
            $pengeluaran = (string) $r->jenis === 'pengeluaran' ? $jml : 0.0;

            $saldo += $penerimaan - $pengeluaran;
            $tp += $penerimaan;
            $tk += $pengeluaran;

            $rows[] = [
                'id'            => (int) $r->id,
                'tanggal'       => (string) $r->tanggal,
                'jenis'         => (string) $r->jenis,
                'no_bukti'      => (string) $r->no_bukti,
                'kode_rekening' => (string) $r->kode_rekening,
                'uraian'        => (string) $r->uraian,
                'penerimaan'    => $penerimaan,
                'pengeluaran'   => $pengeluaran,
                'saldo'         => round($saldo, 2),
            ];
        }

        return $this->okData([
            'dari'              => $dari,
            'akhir'             => $akhir,
            'saldo_awal'        => round($saldoAwal, 2),
            'data'              => $rows,
            'total'             => count($rows),
            'total_penerimaan'  => round($tp, 2),
            'total_pengeluaran' => round($tk, 2),
            'saldo_akhir'       => round($saldo, 2),
            'instansi'          => $skpd,
            'kota'              => (string) ($user?->kota ?? ''),
            'bendahara'         => (string) ($user?->nama_lengkap ?? ''),
            'nip'               => trim((string) ($user?->nip ?? '')),
        ]);
    }

    /**
     * GET /api/v1/aklap/buku-pembantu  (action=buku_pembantu_list)
     *
     * Buku pembantu per rincian objek (sumber: entri manual `buku_kas`),
     * lengkap dengan total bulan ini, s.d. bulan lalu, s.d. bulan ini,
     * pagu anggaran, dan gambar tanda tangan.
     */
    public function bukuPembantu(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $user  = $request->user();
        $jenis = (string) $request->query('jenis', '');
        $kode  = trim((string) $request->query('kode_akun', ''));
        $bulan = (int) $request->query('bulan', (int) date('n'));
        $tahun = (int) $request->query('tahun', (int) date('Y'));

        if ($bulan < 1 || $bulan > 12) {
            $bulan = (int) date('n');
        }

        if ($tahun < 2000 || $tahun > 2100) {
            $tahun = (int) date('Y');
        }

        if (! in_array($jenis, ['penerimaan', 'pengeluaran'], true)) {
            return $this->fail('Pilih jenis (penerimaan / pengeluaran).', [], 422);
        }

        if ($kode === '') {
            return $this->fail('Pilih kode rekening rincian objek.', [], 422);
        }

        $rows           = [];
        $jumlahBulanIni = 0.0;

        foreach (DB::table('buku_kas')
            ->where('skpd', $skpd)
            ->where('jenis', $jenis)
            ->where('kode_rekening', $kode)
            ->whereYear('tanggal', $tahun)
            ->whereMonth('tanggal', $bulan)
            ->select(['tanggal', 'no_bukti', 'kode_rekening', 'uraian', 'jumlah'])
            ->orderBy('tanggal')
            ->orderBy('id')
            ->get() as $r) {
            $jml = (float) $r->jumlah;
            $jumlahBulanIni += $jml;

            $rows[] = [
                'tanggal'       => (string) $r->tanggal,
                'no_bukti'      => (string) $r->no_bukti,
                'kode_rekening' => (string) $r->kode_rekening,
                'uraian'        => (string) $r->uraian,
                'jumlah'        => round($jml, 2),
            ];
        }

        $sum = function (int $bln) use ($skpd, $jenis, $kode, $tahun): float {
            return (float) DB::table('buku_kas')
                ->where('skpd', $skpd)
                ->where('jenis', $jenis)
                ->where('kode_rekening', $kode)
                ->whereYear('tanggal', $tahun)
                ->whereMonth('tanggal', '<=', $bln)
                ->sum('jumlah');
        };

        $jumlahSdBulanIni  = $sum($bulan);
        $jumlahSdBulanLalu = $bulan > 1 ? $sum($bulan - 1) : 0.0;

        $namaAkun = (string) (DB::table('akun_master')
            ->where('skpd', $skpd)
            ->where('kode_akun', $kode)
            ->value('nama_akun') ?? '');

        if ($namaAkun === '') {
            $namaAkun = (string) (DB::table('buku_kas')
                ->where('skpd', $skpd)
                ->where('kode_rekening', $kode)
                ->orderByDesc('id')
                ->value('uraian') ?? '');
        }

        // Jumlah anggaran (pagu) dari anggaran_lra bila tersedia.
        $anggaran = 0.0;

        try {
            $anggaran = (float) (DB::table('anggaran_lra')
                ->where('skpd', $skpd)
                ->where('tahun', (string) $tahun)
                ->where('kode_akun', $kode)
                ->orderByDesc('id')
                ->value('anggaran') ?: 0);
        } catch (Throwable) {
            $anggaran = 0.0;
        }

        // Gambar tanda tangan user (user_ttd).
        $ttdGambar = '';

        try {
            $ttdGambar = (string) (DB::table('user_ttd')
                ->where('user_id', (int) ($user?->id ?? 0))
                ->value('gambar') ?? '');
        } catch (Throwable) {
            $ttdGambar = '';
        }

        return $this->okData([
            'jenis'                => $jenis,
            'kode_akun'            => $kode,
            'nama_akun'            => $namaAkun,
            'bulan'                => $bulan,
            'tahun'                => $tahun,
            'skpd'                 => $skpd,
            'bendahara'            => (string) ($user?->nama_lengkap ?? ''),
            'anggaran'             => round($anggaran, 2),
            'nip'                  => trim((string) ($user?->nip ?? '')),
            'ttd_gambar'           => $ttdGambar,
            'rows'                 => $rows,
            'jumlah_bulan_ini'     => round($jumlahBulanIni, 2),
            'jumlah_sd_bulan_lalu' => round($jumlahSdBulanLalu, 2),
            'jumlah_sd_bulan_ini'  => round($jumlahSdBulanIni, 2),
        ]);
    }

    /**
     * GET /api/v1/aklap/buku-pembantu-obyek  (action=buku_pembantu_obyek_list)
     *
     * Daftar rincian objek yang punya entri buku kas (untuk pemilih objek).
     */
    public function bukuPembantuObyek(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $jenis = (string) $request->query('jenis', '');

        if (! in_array($jenis, ['penerimaan', 'pengeluaran'], true)) {
            return $this->fail('Pilih jenis (penerimaan / pengeluaran).', [], 422);
        }

        $obyek = [];

        foreach (DB::table('buku_kas')
            ->where('skpd', $skpd)
            ->where('jenis', $jenis)
            ->where('kode_rekening', '<>', '')
            ->selectRaw('kode_rekening, MAX(uraian) AS nama, SUM(jumlah) AS total')
            ->groupBy('kode_rekening')
            ->orderBy('kode_rekening')
            ->get() as $r) {
            $obyek[] = [
                'kode_akun' => (string) $r->kode_rekening,
                'nama_akun' => (string) $r->nama,
                'total'     => round((float) $r->total, 2),
            ];
        }

        return $this->okData(['obyek' => $obyek, 'total' => count($obyek)]);
    }
}

