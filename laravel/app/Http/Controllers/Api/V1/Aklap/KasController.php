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
 * AKLAP — Kas: Buku Kas (mutasi manual) & Laporan Posisi Kas Harian (BUD).
 *
 * Diporting dari peta.simtkd.com/api/akuntansi.php:
 *  - `buku_kas_simpan`  -> bukuKasStore()
 *  - `buku_kas_hapus`   -> bukuKasDestroy()
 *  - `kas_harian`       -> kasHarian()
 *  - `kas_harian_daftar`-> kasHarianDaftar()
 *  - `kas_harian_simpan`-> kasHarianSimpan()
 *  - `kas_harian_hapus` -> kasHarianHapus()
 *
 * Catatan kesetiaan:
 *  - `jumlah` pada buku kas dibaca apa adanya sebagai float (bukan parser
 *    angka manual seperti kas harian) — sama seperti legacy.
 *  - Total & posisi kas harian SELALU dihitung server (otoritatif), bukan
 *    dari kiriman klien; baris yang benar-benar kosong tidak disimpan.
 *  - Satu dokumen kas harian per tanggal per instansi (unik `skpd+tanggal`).
 */
class KasController extends Controller
{
    use TanggalValid;

    /**
     * POST /api/v1/aklap/buku-kas  (action=buku_kas_simpan)
     *
     * `id` > 0 -> perbarui; selain itu -> tambah baru.
     */
    public function bukuKasStore(Request $request)
    {
        $skpd = Tenant::requireSkpd();

        $id      = (int) $request->input('id', 0);
        $tanggal = trim((string) $request->input('tanggal', ''));
        $jenis   = (string) $request->input('jenis', '');
        $noBukti = trim((string) $request->input('no_bukti', ''));
        $kode    = trim((string) $request->input('kode_rekening', ''));
        $uraian  = trim((string) $request->input('uraian', ''));
        $jumlah  = (float) $request->input('jumlah', 0);

        if ($this->tanggalValid($tanggal) === '') {
            return $this->fail('Tanggal wajib diisi dengan benar.', [], 422);
        }

        if (! in_array($jenis, ['penerimaan', 'pengeluaran'], true)) {
            return $this->fail('Jenis transaksi wajib dipilih.', [], 422);
        }

        if ($uraian === '') {
            return $this->fail('Uraian wajib diisi.', [], 422);
        }

        if ($jumlah <= 0) {
            return $this->fail('Jumlah harus lebih besar dari nol.', [], 422);
        }

        if ($id > 0) {
            DB::table('buku_kas')
                ->where('id', $id)
                ->where('skpd', $skpd)
                ->update([
                    'tanggal'       => $tanggal,
                    'jenis'         => $jenis,
                    'no_bukti'      => $noBukti,
                    'kode_rekening' => $kode,
                    'uraian'        => $uraian,
                    'jumlah'        => $jumlah,
                ]);

            return $this->ok('Transaksi diperbarui.', ['id' => $id]);
        }

        $baru = (int) DB::table('buku_kas')->insertGetId([
            'skpd'          => $skpd,
            'user_id'       => (int) ($request->user()?->id ?? 0),
            'tanggal'       => $tanggal,
            'jenis'         => $jenis,
            'no_bukti'      => $noBukti,
            'kode_rekening' => $kode,
            'uraian'        => $uraian,
            'jumlah'        => $jumlah,
        ]);

        return $this->ok('Transaksi tersimpan.', ['id' => $baru]);
    }

    /**
     * POST /api/v1/aklap/buku-kas/hapus  (action=buku_kas_hapus)
     */
    public function bukuKasDestroy(Request $request)
    {
        $skpd = Tenant::requireSkpd();
        $id   = (int) $request->input('id', 0);

        if ($id <= 0) {
            return $this->fail('ID transaksi tidak valid.', [], 422);
        }

        $terhapus = DB::table('buku_kas')->where('id', $id)->where('skpd', $skpd)->delete();

        return $this->ok($terhapus > 0 ? 'Transaksi dihapus.' : 'Transaksi tidak ditemukan.');
    }

    // =====================================================================
    //  Laporan Posisi Kas Harian (BUD) — diisi manual, satu dokumen/tanggal
    // =====================================================================

    /**
     * Bangun dokumen kas harian lengkap untuk satu tanggal.
     *
     * Posisi Kas (H-1) memakai nilai tersimpan bila laporan hari itu sudah ada;
     * bila belum, diambil dari Posisi Kas (H) laporan terdekat SEBELUMNYA
     * sebagai saran (masih bisa ditimpa manual oleh pengguna).
     *
     * @return array<string, mixed>
     */
    private function dokumen(string $skpd, string $tanggal): array
    {
        $head = DB::table('kas_harian')->where('skpd', $skpd)->where('tanggal', $tanggal)->first();

        $items  = [];
        $totalP = 0.0;
        $totalK = 0.0;

        if ($head !== null) {
            foreach (DB::table('kas_harian_item')
                ->where('kas_harian_id', (int) $head->id)
                ->select(['no_sp2d', 'no_sts', 'no_lain', 'uraian', 'penerimaan', 'pengeluaran'])
                ->orderBy('urut')
                ->orderBy('id')
                ->limit(300)
                ->get() as $r) {
                $p = (float) $r->penerimaan;
                $k = (float) $r->pengeluaran;

                $totalP += $p;
                $totalK += $k;

                $items[] = [
                    'no_sp2d'     => (string) $r->no_sp2d,
                    'no_sts'      => (string) $r->no_sts,
                    'no_lain'     => (string) $r->no_lain,
                    'uraian'      => (string) $r->uraian,
                    'penerimaan'  => round($p, 2),
                    'pengeluaran' => round($k, 2),
                ];
            }
        }

        $dari = '';

        if ($head !== null) {
            $posisiH1 = (float) $head->posisi_h1;
            $sumber   = 'tersimpan';
        } else {
            $prev = DB::table('kas_harian')
                ->where('skpd', $skpd)
                ->where('tanggal', '<', $tanggal)
                ->select(['tanggal', 'posisi_h'])
                ->orderByDesc('tanggal')
                ->first();

            if ($prev !== null) {
                $posisiH1 = (float) $prev->posisi_h;
                $sumber   = 'hari_sebelumnya';
                $dari     = (string) $prev->tanggal;
            } else {
                $posisiH1 = 0.0;
                $sumber   = 'nol';
            }
        }

        $perubahan = $totalP - $totalK;
        $b1        = $head !== null ? (float) $head->bank1_saldo : 0.0;
        $b2        = $head !== null ? (float) $head->bank2_saldo : 0.0;

        return [
            'tanggal'           => $tanggal,
            'hari'              => $head !== null ? (string) $head->hari : $this->hariId($tanggal),
            'periode'           => $head !== null ? (string) $head->periode : $this->periodeId($tanggal),
            'posisi_h1'         => round($posisiH1, 2),
            'sumber_posisi_h1'  => $sumber,
            'posisi_h1_dari'    => $dari,
            'items'             => $items,
            'total_penerimaan'  => round($totalP, 2),
            'total_pengeluaran' => round($totalK, 2),
            'perubahan'         => round($perubahan, 2),
            'posisi_h'          => round($posisiH1 + $perubahan, 2),
            'bank1_nama'        => (string) ($head->bank1_nama ?? 'Saldo di Bank 1'),
            'bank1_saldo'       => round($b1, 2),
            'bank2_nama'        => (string) ($head->bank2_nama ?? 'Saldo di Bank 2'),
            'bank2_saldo'       => round($b2, 2),
            'total_saldo'       => round($b1 + $b2, 2),
            'catatan'           => (string) ($head->catatan ?? ''),
            'tersimpan'         => $head !== null,
            'updated_at'        => (string) ($head->updated_at ?? ''),
        ];
    }

    /** Nama hari Bahasa Indonesia (kepala laporan kas harian). */
    private function hariId(string $tanggal): string
    {
        $nama = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $ts   = strtotime($tanggal);

        return $ts === false ? '' : $nama[(int) date('w', $ts)];
    }

    /** Nama bulan Bahasa Indonesia (periode laporan kas harian). */
    private function periodeId(string $tanggal): string
    {
        $nama = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
            'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $ts   = strtotime($tanggal);

        return $ts === false ? '' : $nama[(int) date('n', $ts)];
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


    /**
     * GET /api/v1/aklap/kas-harian  (action=kas_harian)
     *
     * `tanggal` kosong -> hari ini. Tanggal tidak valid -> 422.
     */
    public function kasHarian(Request $request)
    {
        $skpd    = Tenant::requireSkpd();
        $user    = $request->user();
        $tanggal = trim((string) $request->query('tanggal', ''));

        if ($tanggal === '') {
            $tanggal = date('Y-m-d');
        }

        if ($this->tanggalValid($tanggal) === '') {
            return $this->fail('Tanggal laporan tidak valid (format YYYY-MM-DD).', [], 422);
        }

        return $this->okData(array_merge($this->dokumen($skpd, $tanggal), [
            'instansi'  => $skpd,
            'kota'      => (string) ($user?->kota ?? ''),
            'bendahara' => (string) ($user?->nama_lengkap ?? ''),
            'nip'       => trim((string) ($user?->nip ?? '')),
        ]));
    }

    /**
     * GET /api/v1/aklap/kas-harian/daftar  (action=kas_harian_daftar)
     *
     * Daftar dokumen kas harian terbaru (maks. 400 baris) + jumlah barisnya.
     */
    public function kasHarianDaftar(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $dari  = $this->tanggalValid((string) $request->query('dari', ''));
        $akhir = $this->tanggalValid((string) $request->query('akhir', ''));

        $query = DB::table('kas_harian as k')
            ->where('k.skpd', $skpd)
            ->select([
                'k.id', 'k.tanggal', 'k.hari', 'k.periode', 'k.posisi_h1', 'k.posisi_h',
                'k.total_penerimaan', 'k.total_pengeluaran', 'k.updated_at',
            ])
            ->selectRaw('(SELECT COUNT(*) FROM kas_harian_item i WHERE i.kas_harian_id = k.id) AS baris');

        if ($dari !== '') {
            $query->where('k.tanggal', '>=', $dari);
        }

        if ($akhir !== '') {
            $query->where('k.tanggal', '<=', $akhir);
        }

        $rows = [];

        foreach ($query->orderByDesc('k.tanggal')->limit(400)->get() as $r) {
            $rows[] = [
                'id'                => (int) $r->id,
                'tanggal'           => (string) $r->tanggal,
                'hari'              => (string) $r->hari,
                'periode'           => (string) $r->periode,
                'baris'             => (int) $r->baris,
                'posisi_h1'         => round((float) $r->posisi_h1, 2),
                'posisi_h'          => round((float) $r->posisi_h, 2),
                'total_penerimaan'  => round((float) $r->total_penerimaan, 2),
                'total_pengeluaran' => round((float) $r->total_pengeluaran, 2),
                'updated_at'        => (string) $r->updated_at,
            ];
        }

        return $this->okData(['data' => $rows, 'total' => count($rows)]);
    }

    /**
     * POST /api/v1/aklap/kas-harian/hapus  (action=kas_harian_hapus)
     *
     * Menghapus dokumen + seluruh barisnya untuk satu tanggal.
     */
    public function kasHarianHapus(Request $request)
    {
        $skpd    = Tenant::requireSkpd();
        $tanggal = trim((string) $request->input('tanggal', ''));

        if ($this->tanggalValid($tanggal) === '') {
            return $this->fail('Tanggal laporan tidak valid (format YYYY-MM-DD).', [], 422);
        }

        $headId = (int) (DB::table('kas_harian')
            ->where('skpd', $skpd)
            ->where('tanggal', $tanggal)
            ->value('id') ?: 0);

        if ($headId <= 0) {
            return $this->okData(['message' => 'Laporan tanggal tersebut belum ada.']);
        }

        DB::beginTransaction();

        try {
            DB::table('kas_harian_item')->where('kas_harian_id', $headId)->where('skpd', $skpd)->delete();
            DB::table('kas_harian')->where('id', $headId)->where('skpd', $skpd)->delete();

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $this->okData(['message' => 'Laporan tanggal ' . $tanggal . ' dihapus.']);
    }


    /**
     * POST /api/v1/aklap/kas-harian  (action=kas_harian_simpan)
     *
     * `items` dikirim sebagai JSON string `[{no_sp2d, no_sts, no_lain,
     * uraian, penerimaan, pengeluaran}]` (boleh juga array bila klien
     * mengirim application/json). Total & posisi dihitung SERVER.
     */
    public function kasHarianSimpan(Request $request)
    {
        $skpd    = Tenant::requireSkpd();
        $user    = $request->user();
        $tanggal = trim((string) $request->input('tanggal', ''));

        if ($this->tanggalValid($tanggal) === '') {
            return $this->fail('Tanggal laporan wajib diisi dengan benar (YYYY-MM-DD).', [], 422);
        }

        $hari = $this->potong($request->input('hari', ''), 12);

        if ($hari === '') {
            $hari = $this->hariId($tanggal);
        }

        $periode = $this->potong($request->input('periode', ''), 20);

        if ($periode === '') {
            $periode = $this->periodeId($tanggal);
        }

        $posisiH1  = $this->angka($request->input('posisi_h1', 0));
        $bank1Nama = $this->potong($request->input('bank1_nama', ''), 80);
        $bank2Nama = $this->potong($request->input('bank2_nama', ''), 80);

        if ($bank1Nama === '') {
            $bank1Nama = 'Saldo di Bank 1';
        }

        if ($bank2Nama === '') {
            $bank2Nama = 'Saldo di Bank 2';
        }

        $bank1Saldo = $this->angka($request->input('bank1_saldo', 0));
        $bank2Saldo = $this->angka($request->input('bank2_saldo', 0));
        $catatan    = $this->potong($request->input('catatan', ''), 255);

        $rawItems = $request->input('items', '[]');

        if (is_string($rawItems)) {
            $rawItems = json_decode($rawItems, true);
        }

        if (! is_array($rawItems)) {
            return $this->fail('Data baris transaksi tidak valid.', [], 422);
        }

        if (count($rawItems) > 300) {
            return $this->fail('Jumlah baris melebihi batas 300 baris.', [], 422);
        }

        $items  = [];
        $totalP = 0.0;
        $totalK = 0.0;

        foreach (array_values($rawItems) as $i => $r) {
            if (! is_array($r)) {
                continue;
            }

            $sp2d   = $this->potong($r['no_sp2d'] ?? '', 60);
            $sts    = $this->potong($r['no_sts'] ?? '', 60);
            $lain   = $this->potong($r['no_lain'] ?? '', 60);
            $uraian = $this->potong($r['uraian'] ?? '', 255);
            $p      = $this->angka($r['penerimaan'] ?? 0);
            $k      = $this->angka($r['pengeluaran'] ?? 0);

            if ($p < 0 || $k < 0) {
                return $this->fail(
                    'Nilai penerimaan/pengeluaran tidak boleh negatif (baris ' . ($i + 1) . ').',
                    [],
                    422
                );
            }

            // Baris yang benar-benar kosong tidak perlu disimpan.
            if ($sp2d === '' && $sts === '' && $lain === '' && $uraian === '' && $p === 0.0 && $k === 0.0) {
                continue;
            }

            $totalP += $p;
            $totalK += $k;

            $items[] = [
                'no_sp2d'     => $sp2d,
                'no_sts'      => $sts,
                'no_lain'     => $lain,
                'uraian'      => $uraian,
                'penerimaan'  => $p,
                'pengeluaran' => $k,
            ];
        }

        // Total & posisi kas dihitung SERVER (otoritatif), bukan dari kiriman klien.
        $posisiH = $posisiH1 + $totalP - $totalK;
        $userId  = (int) ($user?->id ?? 0);


        DB::beginTransaction();

        try {
            $headId = (int) (DB::table('kas_harian')
                ->where('skpd', $skpd)
                ->where('tanggal', $tanggal)
                ->value('id') ?: 0);

            if ($headId > 0) {
                DB::table('kas_harian')
                    ->where('id', $headId)
                    ->where('skpd', $skpd)
                    ->update([
                        'hari'              => $hari,
                        'periode'           => $periode,
                        'posisi_h1'         => $posisiH1,
                        'total_penerimaan'  => round($totalP, 2),
                        'total_pengeluaran' => round($totalK, 2),
                        'posisi_h'          => round($posisiH, 2),
                        'bank1_nama'        => $bank1Nama,
                        'bank1_saldo'       => $bank1Saldo,
                        'bank2_nama'        => $bank2Nama,
                        'bank2_saldo'       => $bank2Saldo,
                        'catatan'           => $catatan,
                        'user_id'           => $userId,
                    ]);

                DB::table('kas_harian_item')->where('kas_harian_id', $headId)->where('skpd', $skpd)->delete();
            } else {
                $headId = (int) DB::table('kas_harian')->insertGetId([
                    'skpd'              => $skpd,
                    'user_id'           => $userId,
                    'tanggal'           => $tanggal,
                    'hari'              => $hari,
                    'periode'           => $periode,
                    'posisi_h1'         => $posisiH1,
                    'total_penerimaan'  => round($totalP, 2),
                    'total_pengeluaran' => round($totalK, 2),
                    'posisi_h'          => round($posisiH, 2),
                    'bank1_nama'        => $bank1Nama,
                    'bank1_saldo'       => $bank1Saldo,
                    'bank2_nama'        => $bank2Nama,
                    'bank2_saldo'       => $bank2Saldo,
                    'catatan'           => $catatan,
                ]);
            }

            if ($items !== []) {
                $urut  = 0;
                $baris = [];

                foreach ($items as $it) {
                    $urut++;
                    $baris[] = [
                        'kas_harian_id' => $headId,
                        'skpd'          => $skpd,
                        'urut'          => $urut,
                        'no_sp2d'       => $it['no_sp2d'],
                        'no_sts'        => $it['no_sts'],
                        'no_lain'       => $it['no_lain'],
                        'uraian'        => $it['uraian'],
                        'penerimaan'    => $it['penerimaan'],
                        'pengeluaran'   => $it['pengeluaran'],
                    ];
                }

                DB::table('kas_harian_item')->insert($baris);
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $this->okData(array_merge([
            'message'    => 'Laporan Posisi Kas Harian tersimpan (' . count($items) . ' baris).',
            'laporan_id' => $headId,
        ], $this->dokumen($skpd, $tanggal), [
            'instansi'  => $skpd,
            'kota'      => (string) ($user?->kota ?? ''),
            'bendahara' => (string) ($user?->nama_lengkap ?? ''),
            'nip'       => trim((string) ($user?->nip ?? '')),
        ]));
    }
}
