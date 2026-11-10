<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Penerimaan;

use App\Http\Controllers\Controller;
use App\Models\AnggaranLra;
use App\Models\Stbp;
use App\Models\Sts;
use App\Models\StsDetail;
use App\Support\Tenant;
use App\Support\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * API Laporan Penerimaan.
 *
 * Diporting dari api/laporan.php pada sistem legacy. Empat laporan:
 *
 *  - obyek_penerimaan : daftar kode rekening rincian obyek (dropdown BPP)
 *  - buku_pembantu     : Buku Pembantu per Rincian Obyek Penerimaan
 *  - rekap             : Rekapitulasi penerimaan harian/bulanan
 *  - bku               : Buku Kas Umum penerimaan (gabungan STBP + STS)
 *
 * Perhitungan memakai fungsi agregat SQL yang sama dengan legacy agar
 * angka yang dihasilkan identik (diverifikasi oleh differential test).
 */
class LaporanPenerimaanController extends Controller
{
    /**
     * GET /api/v1/penerimaan/laporan/obyek
     *
     * Master anggaran (LRA) UNION objek yang pernah dipakai di STBP.
     */
    public function obyek(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $tahun = (string) $request->query('tahun', (string) date('Y'));

        if (! Validator::tahun($tahun)) {
            $tahun = (string) date('Y');
        }

        $obyek = [];

        // 1) Master anggaran (LRA) — sumber nama rekening & pagu
        $rows = AnggaranLra::query()
            ->where('tahun', $tahun)
            ->groupBy('kode_akun')
            ->selectRaw('kode_akun, MAX(nama_akun) AS nama_akun, MAX(anggaran) AS anggaran')
            ->get();

        foreach ($rows as $r) {
            $obyek[(string) $r->kode_akun] = [
                'kode_akun' => (string) $r->kode_akun,
                'nama_akun' => (string) $r->nama_akun,
                'anggaran'  => round((float) $r->anggaran, 2),
            ];
        }

        // 2) Objek yang pernah dipakai di STBP (belum tentu ada di anggaran)
        $stbpRows = Stbp::query()
            ->where('status', '<>', 'dihapus')
            ->groupBy('akun_kode')
            ->selectRaw('akun_kode, MAX(akun_nama) AS nama_akun')
            ->get();

        foreach ($stbpRows as $r) {
            $kode = trim((string) $r->akun_kode);

            if ($kode === '' || isset($obyek[$kode])) {
                continue;
            }

            $obyek[$kode] = [
                'kode_akun' => $kode,
                'nama_akun' => (string) $r->nama_akun,
                'anggaran'  => 0.0,
            ];
        }

        ksort($obyek);

        return $this->ok('OK', [
            'tahun' => (int) $tahun,
            'skpd'  => $skpd,
            'obyek' => array_values($obyek),
        ]);
    }

    /**
     * GET /api/v1/penerimaan/laporan/buku-pembantu
     *
     * Buku Pembantu per Rincian Obyek Penerimaan (Permendagri 55/2008):
     * baris STBP yang telah disetor via STS + rekap bulanan & kumulatif.
     */
    public function bukuPembantu(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $kode  = trim((string) $request->query('kode_akun', ''));
        $bulan = (int) $request->query('bulan', (string) (int) date('n'));
        $tahun = (string) $request->query('tahun', (string) date('Y'));

        if ($kode === '') {
            return $this->fail('Kode rekening rincian obyek wajib dipilih.', [], 422);
        }

        if ($bulan < 1 || $bulan > 12) {
            $bulan = (int) date('n');
        }

        if (! Validator::tahun($tahun)) {
            $tahun = (string) date('Y');
        }

        $tahunI = (int) $tahun;

        // --- Baris transaksi bulan terpilih ---
        // CATATAN PENTING: `DB::table()` MEMBYPASS global scope, sehingga
        // filter SKPD harus ditulis eksplisit di sini. Tanpa itu, data
        // instansi lain ikut terbaca (celah tenant isolation).
        // sts_detail tidak punya kolom skpd, tetapi `sts` yang di-join
        // memilikinya — filter di `st.skpd` menutup keduanya.
        $transaksi = DB::table('sts_detail as sd')
            ->join('sts as st', 'st.id', '=', 'sd.sts_id')
            ->where('st.status', 'aktif')
            ->where('st.skpd', $skpd)
            ->where('sd.akun_kode', $kode)
            ->whereYear('st.tanggal_sts', $tahunI)
            ->whereMonth('st.tanggal_sts', $bulan)
            ->orderBy('st.tanggal_sts')
            ->orderBy('st.id')
            ->orderBy('sd.id')
            ->select([
                'sd.nomor_stbp',
                'sd.jumlah',
                'st.nomor_sts',
                'st.tanggal_sts',
            ])
            ->get();

        $rows = [];
        $no = 0;
        $jumlahBulanIni = 0.0;

        foreach ($transaksi as $r) {
            $no++;
            $jml = (float) $r->jumlah;
            $jumlahBulanIni += $jml;

            $rows[] = [
                'no'            => $no,
                'no_bpp'        => (string) $r->nomor_stbp,
                'tanggal_setor' => (string) $r->tanggal_sts,
                'no_sts'        => (string) $r->nomor_sts,
                'jumlah'        => round($jml, 2),
            ];
        }

        // --- Kumulatif: s.d. bulan ini & s.d. bulan lalu ---
        $jumlahSdBulanIni  = $this->sumTransaksi($kode, $tahunI, $bulan, $skpd);
        $jumlahSdBulanLalu = $bulan > 1 ? $this->sumTransaksi($kode, $tahunI, $bulan - 1, $skpd) : 0.0;

        // --- Identitas rekening + pagu anggaran ---
        $anggaranRow = AnggaranLra::query()
            ->where('tahun', $tahunI)
            ->where('kode_akun', $kode)
            ->orderByDesc('id')
            ->first();

        $namaAkun = $anggaranRow ? (string) $anggaranRow->nama_akun : '';
        $anggaran = $anggaranRow ? (float) $anggaranRow->anggaran : 0.0;

        if ($namaAkun === '') {
            $namaAkun = (string) (Stbp::query()
                ->where('akun_kode', $kode)
                ->where('status', '<>', 'dihapus')
                ->max('akun_nama') ?? '');
        }

        return $this->ok('OK', [
            'kode_akun'            => $kode,
            'nama_akun'            => $namaAkun,
            'anggaran'             => round($anggaran, 2),
            'bulan'                => $bulan,
            'tahun'                => $tahunI,
            'skpd'                 => $skpd,
            'rows'                 => $rows,
            'jumlah_bulan_ini'     => round($jumlahBulanIni, 2),
            'jumlah_sd_bulan_lalu' => round($jumlahSdBulanLalu, 2),
            'jumlah_sd_bulan_ini'  => round($jumlahSdBulanIni, 2),
            'bendahara'            => (string) ($request->user()?->nama_lengkap ?? ''),
        ]);
    }

    /**
     * Total transaksi STS aktif untuk satu kode akun sampai bulan tertentu.
     *
     * Query mentah -> filter SKPD wajib eksplisit (global scope tidak berlaku).
     */
    private function sumTransaksi(string $kode, int $tahun, int $sampaiBulan, string $skpd): float
    {
        $total = DB::table('sts_detail as sd')
            ->join('sts as st', 'st.id', '=', 'sd.sts_id')
            ->where('st.status', 'aktif')
            ->where('st.skpd', $skpd)
            ->where('sd.akun_kode', $kode)
            ->whereYear('st.tanggal_sts', $tahun)
            ->whereMonth('st.tanggal_sts', '<=', $sampaiBulan)
            ->sum('sd.jumlah');

        return (float) $total;
    }

    /**
     * GET /api/v1/penerimaan/laporan/rekap
     *
     * Rekapitulasi penerimaan harian/bulanan. Baris = periode; kolom = 3
     * kelompok PAD (Pajak Daerah, Retribusi Daerah, Lain-Lain PAD).
     */
    public function rekap(Request $request)
    {
        $skpd   = Tenant::requireSkpd();
        $mode   = (string) $request->query('mode', 'harian') === 'bulanan' ? 'bulanan' : 'harian';
        $dari   = (string) $request->query('dari', '');
        $sampai = (string) $request->query('sampai', '');

        if ($dari === '' || $sampai === '') {
            return $this->fail('Periode (tanggal awal dan akhir) wajib diisi.', [], 422);
        }

        // Kunci periode: tanggal utk harian, tanggal 1 utk bulanan.
        $keySql = $mode === 'bulanan'
            ? "DATE_FORMAT(t.tanggal, '%Y-%m-01')"
            : 'DATE(t.tanggal)';

        $rows = DB::table('stbp as t')
            ->where('t.status', '<>', 'dihapus')
            ->where('t.skpd', $skpd)   // query mentah -> filter SKPD eksplisit
            ->whereBetween('t.tanggal', [$dari, $sampai])
            ->groupBy('kunci', 't.akun_kode', 't.akun_nama')
            ->orderBy('kunci')
            ->orderBy('t.akun_kode')
            ->selectRaw("{$keySql} AS kunci, t.akun_kode, t.akun_nama, SUM(t.jumlah) AS jumlah")
            ->get();

        $perKunci = [];

        foreach ($rows as $r) {
            $kunci = (string) $r->kunci;
            $kode  = (string) $r->akun_kode;
            $jml   = (float) $r->jumlah;

            if (preg_match('/^4\.1\.1\./', $kode)) {
                $g = 0; // Pajak Daerah
            } elseif (preg_match('/^4\.1\.2\./', $kode)) {
                $g = 1; // Retribusi Daerah
            } else {
                $g = 2; // Lain-Lain PAD
            }

            if (! isset($perKunci[$kunci])) {
                $perKunci[$kunci] = [
                    'periode' => $kunci,
                    'kelompok' => [[], [], []],
                    'total'   => 0.0,
                ];
            }

            $perKunci[$kunci]['kelompok'][$g][] = [
                'kode'   => $kode,
                'nama'   => (string) $r->akun_nama,
                'jumlah' => round($jml, 2),
            ];

            $perKunci[$kunci]['total'] += $jml;
        }

        ksort($perKunci);

        // Total per kelompok + grand total
        $totG = [0.0, 0.0, 0.0];
        $grand = 0.0;

        foreach ($perKunci as $k) {
            for ($i = 0; $i < 3; $i++) {
                foreach ($k['kelompok'][$i] as $b) {
                    $totG[$i] += $b['jumlah'];
                }
            }
            $grand += $k['total'];
        }

        // Nama Kuasa/Pengguna Anggaran: dari STS terbaru dalam periode.
        $kuasa = (string) (Sts::query()
            ->where('status', 'aktif')
            ->where('kuasa_pengguna_anggaran', '<>', '')
            ->whereBetween('tanggal_sts', [$dari, $sampai])
            ->orderByDesc('tanggal_sts')
            ->orderByDesc('id')
            ->value('kuasa_pengguna_anggaran') ?? '');

        return $this->ok('OK', [
            'periode'           => ['dari' => $dari, 'sampai' => $sampai],
            'mode'              => $mode,
            'skpd'              => $skpd,
            'rows'              => array_values($perKunci),
            'total_kelompok'    => [round($totG[0], 2), round($totG[1], 2), round($totG[2], 2)],
            'total'             => round($grand, 2),
            'bendahara'         => (string) ($request->user()?->nama_lengkap ?? ''),
            'pengguna_anggaran' => $kuasa,
        ]);
    }

    /**
     * GET /api/v1/penerimaan/laporan/bku
     *
     * Buku Kas Umum (BKU) Penerimaan Daerah — gabungan STBP (penerimaan)
     * dan STS (setoran ke kas daerah = pengeluaran), dengan saldo berjalan.
     */
    public function bku(Request $request)
    {
        Tenant::requireSkpd();

        $dari   = (string) $request->query('dari', '');
        $sampai = (string) $request->query('sampai', '');

        if ($dari === '' || $sampai === '') {
            return $this->fail('Periode (tanggal awal dan akhir) wajib diisi.', [], 422);
        }

        $entries = [];

        // --- Penerimaan dari STBP ---
        $stbp = Stbp::query()
            ->where('status', '<>', 'dihapus')
            ->whereBetween('tanggal', [$dari, $sampai])
            ->orderBy('tanggal')
            ->orderBy('id')
            ->get(['id', 'nomor_stbp', 'tanggal', 'akun_kode', 'akun_nama', 'jumlah', 'uraian']);

        foreach ($stbp as $r) {
            $entries[] = [
                'tanggal'       => (string) $r->tanggal,
                'no_bukti'      => (string) $r->nomor_stbp,
                'kode_rekening' => trim((string) $r->akun_kode . ' - ' . (string) $r->akun_nama, ' -'),
                'uraian'        => (string) $r->uraian,
                'penerimaan'    => (float) $r->jumlah,
                'pengeluaran'   => 0.0,
                'jenis'         => 'STBP',
            ];
        }

        // --- Setoran ke Kas Daerah dari STS ---
        $sts = Sts::query()
            ->where('status', 'aktif')
            ->whereBetween('tanggal_sts', [$dari, $sampai])
            ->orderBy('tanggal_sts')
            ->orderBy('id')
            ->get(['id', 'nomor_sts', 'tanggal_sts', 'total', 'keterangan']);

        foreach ($sts as $r) {
            // Detail pertama STS (akun_kode & akun_nama) — sama dengan
            // subquery legacy (ORDER BY d.id ASC LIMIT 1).
            $detail = StsDetail::query()
                ->where('sts_id', $r->id)
                ->orderBy('id')
                ->first(['akun_kode', 'akun_nama']);

            $entries[] = [
                'tanggal'       => (string) $r->tanggal_sts,
                'no_bukti'      => (string) $r->nomor_sts,
                'kode_rekening' => trim(
                    (string) ($detail->akun_kode ?? '') . ' - ' . (string) ($detail->akun_nama ?? ''),
                    ' -'
                ),
                'uraian'        => (string) $r->keterangan,
                'penerimaan'    => 0.0,
                'pengeluaran'   => (float) $r->total,
                'jenis'         => 'STS',
            ];
        }

        // Urutkan berdasarkan tanggal, lalu hitung saldo berjalan.
        usort($entries, static fn (array $a, array $b): int => strcmp((string) $a['tanggal'], (string) $b['tanggal']));

        $saldo = 0.0;
        $totalPenerimaan = 0.0;
        $totalPengeluaran = 0.0;

        foreach ($entries as $i => $e) {
            $saldo += $e['penerimaan'] - $e['pengeluaran'];
            $totalPenerimaan += $e['penerimaan'];
            $totalPengeluaran += $e['pengeluaran'];
            $entries[$i]['saldo'] = round($saldo, 2);
        }

        return $this->ok('OK', [
            'periode'          => ['dari' => $dari, 'sampai' => $sampai],
            'entries'          => array_values($entries),
            'total_penerimaan' => round($totalPenerimaan, 2),
            'total_pengeluaran' => round($totalPengeluaran, 2),
            'saldo_akhir'      => round($saldo, 2),
        ]);
    }
}
