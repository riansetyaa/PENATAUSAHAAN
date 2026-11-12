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
 * AKLAP — Rekonsiliasi Bank (BUD, blanko Modul-3E-LK).
 *
 * Diporting dari peta.simtkd.com/api/akuntansi.php:
 *  - `rekonsiliasi_bank`         -> show()
 *  - `rekonsiliasi_bank_daftar`  -> index()
 *  - `rekonsiliasi_bank_simpan`  -> store()
 *  - `rekonsiliasi_bank_hapus`   -> destroy()
 *
 * Satu dokumen per BULAN per instansi. Rincian keterangan selisih dibagi
 * empat bagian (A/B/C/D); total buku = A + B, total bank = C + D.
 * Nilai isian memakai parser angka manual (`angka()`, "1.234,50" -> 1234.5).
 */
class RekonsiliasiBankController extends Controller
{
    use TanggalValid;

    private const MAX_BARIS_BAGIAN = 60;

    /**
     * GET /api/v1/aklap/rekonsiliasi-bank  (action=rekonsiliasi_bank)
     *
     * `tahun` harus 4 digit (selain itu -> tahun berjalan); `bulan` 1-12
     * (selain itu -> bulan berjalan).
     */
    public function show(Request $request)
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

        return $this->okData(array_merge($this->dokumen($skpd, (int) $tahun, $bulan), [
            'instansi'  => $skpd,
            'kota'      => (string) ($user?->kota ?? ''),
            'bendahara' => (string) ($user?->nama_lengkap ?? ''),
            'nip'       => trim((string) ($user?->nip ?? '')),
        ]));
    }

    /**
     * GET /api/v1/aklap/rekonsiliasi-bank/daftar  (action=rekonsiliasi_bank_daftar)
     */
    public function index()
    {
        $skpd = Tenant::requireSkpd();

        $rows = [];

        foreach (DB::table('rekonsiliasi_bank as k')
            ->where('k.skpd', $skpd)
            ->select(['k.id', 'k.tahun', 'k.bulan', 'k.periode', 'k.saldo_buku', 'k.saldo_bank', 'k.updated_at'])
            ->selectRaw('(SELECT COUNT(*) FROM rekonsiliasi_bank_item i WHERE i.rekonsiliasi_id = k.id) AS baris')
            ->orderByDesc('k.tahun')
            ->orderByDesc('k.bulan')
            ->limit(120)
            ->get() as $r) {
            $rows[] = [
                'id'         => (int) $r->id,
                'tahun'      => (int) $r->tahun,
                'bulan'      => (int) $r->bulan,
                'periode'    => (string) $r->periode,
                'baris'      => (int) $r->baris,
                'selisih'    => round((float) $r->saldo_buku - (float) $r->saldo_bank, 2),
                'updated_at' => (string) $r->updated_at,
            ];
        }

        return $this->okData(['data' => $rows, 'total' => count($rows)]);
    }

    /**
     * POST /api/v1/aklap/rekonsiliasi-bank  (action=rekonsiliasi_bank_simpan)
     *
     * `items` = JSON objek `{A: [...], B: [...], C: [...], D: [...]}` dengan
     * tiap baris `{uraian, nilai}`. Baris kosong (uraian kosong & nilai 0)
     * tidak disimpan.
     */
    public function store(Request $request)
    {
        $skpd = Tenant::requireSkpd();

        [$tahun, $bulan, $valid] = $this->periode($request);

        if (! $valid) {
            return $this->fail('Periode (tahun / bulan) tidak valid.', [], 422);
        }

        $periode = $this->potong($request->input('periode', ''), 20);

        if ($periode === '') {
            $periode = $this->bulanId($bulan);
        }

        $tglTtd = trim((string) $request->input('tgl_ttd', ''));

        if ($this->tanggalValid($tglTtd) === '') {
            $tglTtd = $this->akhirBulan($tahun, $bulan);
        }

        $catatan   = $this->potong($request->input('catatan', ''), 255);
        $saldoBuku = $this->angka($request->input('saldo_buku', 0));
        $saldoBank = $this->angka($request->input('saldo_bank', 0));

        $raw = $request->input('items', '{}');

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return $this->fail('Data rincian keterangan selisih tidak valid.', [], 422);
        }

        $items = [];

        foreach (['A', 'B', 'C', 'D'] as $b) {
            $daftar = (isset($raw[$b]) && is_array($raw[$b])) ? $raw[$b] : [];

            if (count($daftar) > self::MAX_BARIS_BAGIAN) {
                return $this->fail('Baris bagian ' . $b . ' melebihi batas ' . self::MAX_BARIS_BAGIAN . ' baris.', [], 422);
            }

            $urut = 0;

            foreach (array_values($daftar) as $i => $r) {
                if (! is_array($r)) {
                    continue;
                }

                $uraian = $this->potong($r['uraian'] ?? '', 255);
                $nilai  = $this->angka($r['nilai'] ?? 0);

                if ($nilai < 0) {
                    return $this->fail('Nilai tidak boleh negatif (bagian ' . $b . ' baris ' . ($i + 1) . ').', [], 422);
                }

                if ($uraian === '' && $nilai === 0.0) {
                    continue;   // baris kosong tidak disimpan
                }

                $urut++;
                $items[] = ['bagian' => $b, 'urut' => $urut, 'uraian' => $uraian, 'nilai' => $nilai];
            }
        }

        $userId = (int) ($request->user()?->id ?? 0);

        DB::beginTransaction();

        try {
            $headId = (int) (DB::table('rekonsiliasi_bank')
                ->where('skpd', $skpd)
                ->where('tahun', $tahun)
                ->where('bulan', $bulan)
                ->value('id') ?: 0);

            if ($headId > 0) {
                DB::table('rekonsiliasi_bank')
                    ->where('id', $headId)
                    ->where('skpd', $skpd)
                    ->update([
                        'periode'    => $periode,
                        'tgl_ttd'    => $tglTtd,
                        'saldo_buku' => $saldoBuku,
                        'saldo_bank' => $saldoBank,
                        'catatan'    => $catatan,
                        'user_id'    => $userId,
                    ]);

                DB::table('rekonsiliasi_bank_item')->where('rekonsiliasi_id', $headId)->where('skpd', $skpd)->delete();
            } else {
                $headId = (int) DB::table('rekonsiliasi_bank')->insertGetId([
                    'skpd'       => $skpd,
                    'user_id'    => $userId,
                    'tahun'      => $tahun,
                    'bulan'      => $bulan,
                    'periode'    => $periode,
                    'tgl_ttd'    => $tglTtd,
                    'saldo_buku' => $saldoBuku,
                    'saldo_bank' => $saldoBank,
                    'catatan'    => $catatan,
                ]);
            }

            if ($items !== []) {
                $baris = [];

                foreach ($items as $it) {
                    $baris[] = [
                        'rekonsiliasi_id' => $headId,
                        'skpd'            => $skpd,
                        'bagian'          => $it['bagian'],
                        'urut'            => $it['urut'],
                        'uraian'          => $it['uraian'],
                        'nilai'           => $it['nilai'],
                    ];
                }

                DB::table('rekonsiliasi_bank_item')->insert($baris);
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $this->okData(array_merge([
            'message'    => 'Rekonsiliasi Bank tersimpan (' . count($items) . ' baris rincian).',
            'laporan_id' => $headId,
        ], $this->dokumen($skpd, $tahun, $bulan), [
            'instansi'  => $skpd,
            'kota'      => (string) ($request->user()?->kota ?? ''),
            'bendahara' => (string) ($request->user()?->nama_lengkap ?? ''),
            'nip'       => trim((string) ($request->user()?->nip ?? '')),
        ]));
    }


    /**
     * POST /api/v1/aklap/rekonsiliasi-bank/hapus  (action=rekonsiliasi_bank_hapus)
     */
    public function destroy(Request $request)
    {
        $skpd = Tenant::requireSkpd();

        [$tahun, $bulan, $valid] = $this->periode($request);

        if (! $valid) {
            return $this->fail('Periode (tahun / bulan) tidak valid.', [], 422);
        }

        $headId = (int) (DB::table('rekonsiliasi_bank')
            ->where('skpd', $skpd)
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->value('id') ?: 0);

        if ($headId <= 0) {
            return $this->okData(['message' => 'Rekonsiliasi periode tersebut belum ada.']);
        }

        DB::beginTransaction();

        try {
            DB::table('rekonsiliasi_bank_item')->where('rekonsiliasi_id', $headId)->where('skpd', $skpd)->delete();
            DB::table('rekonsiliasi_bank')->where('id', $headId)->where('skpd', $skpd)->delete();

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $this->okData([
            'message' => 'Rekonsiliasi Bank periode ' . $this->bulanId($bulan) . ' ' . $tahun . ' dihapus.',
        ]);
    }

    /**
     * Bangun dokumen rekonsiliasi (kepala + rincian A/B/C/D + subtotal) untuk
     * satu periode. `saran_saldo_buku` = saldo kumulatif Buku Kas s.d. akhir
     * bulan terpilih (saran, bukan nilai tersimpan).
     *
     * @return array<string, mixed>
     */
    private function dokumen(string $skpd, int $tahun, int $bulan): array
    {
        $head = DB::table('rekonsiliasi_bank')
            ->where('skpd', $skpd)
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->first();

        $bagian = ['A' => [], 'B' => [], 'C' => [], 'D' => []];
        $nilai  = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0, 'D' => 0.0];

        if ($head !== null) {
            foreach (DB::table('rekonsiliasi_bank_item')
                ->where('rekonsiliasi_id', (int) $head->id)
                ->select(['bagian', 'uraian', 'nilai'])
                ->orderBy('bagian')
                ->orderBy('urut')
                ->orderBy('id')
                ->limit(400)
                ->get() as $r) {
                $b = strtoupper((string) $r->bagian);

                if (! isset($bagian[$b])) {
                    continue;
                }

                $n = (float) $r->nilai;

                $bagian[$b][] = ['uraian' => (string) $r->uraian, 'nilai' => round($n, 2)];
                $nilai[$b] += $n;
            }
        }

        // Saran "Saldo Kas umum daerah Menurut Buku" = saldo kumulatif Buku Kas
        // sampai akhir bulan terpilih.
        $akhir = $this->akhirBulan($tahun, $bulan);
        $saran = 0.0;

        if ($akhir !== '') {
            try {
                $netto = DB::table('buku_kas')
                    ->where('skpd', $skpd)
                    ->where('tanggal', '<=', $akhir)
                    ->selectRaw("COALESCE(SUM(CASE WHEN jenis = 'penerimaan' THEN jumlah ELSE -jumlah END), 0) AS netto")
                    ->first();

                $saran = (float) ($netto->netto ?? 0);
            } catch (Throwable) {
                $saran = 0.0;
            }
        }

        $saldoBuku = $head !== null ? (float) $head->saldo_buku : 0.0;
        $saldoBank = $head !== null ? (float) $head->saldo_bank : 0.0;

        return [
            'tahun'            => $tahun,
            'bulan'            => $bulan,
            'periode'          => $head !== null ? (string) $head->periode : $this->bulanId($bulan),
            'tgl_ttd'          => (string) ($head->tgl_ttd ?? $akhir),
            'saldo_buku'       => round($saldoBuku, 2),
            'saldo_bank'       => round($saldoBank, 2),
            'selisih'          => round($saldoBuku - $saldoBank, 2),
            'saran_saldo_buku' => round($saran, 2),
            'bagian'           => $bagian,
            'subtotal'         => [
                'A' => round($nilai['A'], 2),
                'B' => round($nilai['B'], 2),
                'C' => round($nilai['C'], 2),
                'D' => round($nilai['D'], 2),
            ],
            'total_buku'       => round($nilai['A'] + $nilai['B'], 2),
            'total_bank'       => round($nilai['C'] + $nilai['D'], 2),
            'catatan'          => (string) ($head->catatan ?? ''),
            'tersimpan'        => $head !== null,
            'updated_at'       => (string) ($head->updated_at ?? ''),
        ];
    }


    /**
     * Baca & validasi periode dari request (tahun 2000-2100, bulan 1-12).
     *
     * @return array{0: int, 1: int, 2: bool}
     */
    private function periode(Request $request): array
    {
        $tahun = (int) $request->input('tahun', 0);
        $bulan = (int) $request->input('bulan', 0);

        return [$tahun, $bulan, $tahun >= 2000 && $tahun <= 2100 && $bulan >= 1 && $bulan <= 12];
    }

    /** Nama bulan Bahasa Indonesia (1-12). */
    private function bulanId(int $bulan): string
    {
        $nama = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
            'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        return $nama[$bulan] ?? '';
    }

    /** Tanggal terakhir sebuah bulan (YYYY-MM-DD) — default tanggal tanda tangan. */
    private function akhirBulan(int $tahun, int $bulan): string
    {
        $t = strtotime(sprintf('%04d-%02d-01', $tahun, $bulan));

        return $t === false ? '' : date('Y-m-t', $t);
    }

    /** Angka dari isian manual (menerima "1.234.500" maupun "1234500"). */
    private function angka(mixed $teks): float
    {
        $s = str_replace([' ', '.'], '', trim((string) $teks));

        if ($s === '' || $s === '-') {
            return 0.0;
        }

        $s = str_replace(',', '.', $s);

        return is_numeric($s) ? (float) $s : 0.0;
    }

    /** Batasi panjang teks isian manual agar aman terhadap kolom DB. */
    private function potong(mixed $teks, int $maks): string
    {
        $t = trim((string) $teks);

        return mb_strlen($t) > $maks ? mb_substr($t, 0, $maks) : $t;
    }
}
