<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Aklap;

use App\Http\Controllers\Api\V1\Aklap\Concerns\JurnalKhususTemplate;
use App\Http\Controllers\Api\V1\Aklap\Concerns\TanggalValid;
use App\Http\Controllers\Controller;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AKLAP — Buku Jurnal Khusus (blanko Modul-3E-LK, diisi manual).
 *
 * Diporting dari peta.simtkd.com/api/akuntansi.php:
 *  - `jurnal_khusus_template` -> template()
 *  - `jurnal_khusus`          -> show()
 *  - `jurnal_khusus_daftar`   -> index()
 *  - `jurnal_khusus_simpan`   -> store()
 *  - `jurnal_khusus_hapus`    -> destroy()
 *
 * Satu dokumen per jenis per periode (tahun+bulan). Kolom uang tiap grup
 * maksimal 10 slot (JK_MAX_SLOT); kolom kosong di ujung grup boleh diisi kode
 * akun manual (`kode_extra`). Baris tidak wajib seimbang, tetapi baris yang
 * timpang dicatat pada pesan simpan.
 */
class JurnalKhususController extends Controller
{
    use JurnalKhususTemplate;
    use TanggalValid;

    /**
     * GET /api/v1/aklap/jurnal-khusus/template  (action=jurnal_khusus_template)
     */
    public function template()
    {
        Tenant::requireSkpd();

        return $this->okData([
            'maks_slot' => self::MAKS_SLOT,
            'data'      => $this->jkTemplatePublik(),
        ]);
    }

    /**
     * GET /api/v1/aklap/jurnal-khusus  (action=jurnal_khusus)
     *
     * Jenis tidak dikenal -> jenis pertama. `tahun` harus 4 digit, `bulan`
     * 1-12 (selain itu -> periode hari ini).
     */
    public function show(Request $request)
    {
        $skpd = Tenant::requireSkpd();
        $user = $request->user();

        [$jenis, $tahun, $bulan] = $this->periodeGet($request);

        return $this->okData(array_merge($this->dokumen($skpd, $jenis, $tahun, $bulan), [
            'instansi' => $skpd,
            'kota'     => (string) ($user?->kota ?? ''),
            'nama'     => (string) ($user?->nama_lengkap ?? ''),
            'nip'      => trim((string) ($user?->nip ?? '')),
        ]));
    }

    /**
     * GET /api/v1/aklap/jurnal-khusus/daftar  (action=jurnal_khusus_daftar)
     *
     * Riwayat periode tersimpan untuk satu jenis (maks. 120).
     */
    public function index(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $jenis = $this->jkJenisValid(trim((string) $request->query('jenis', '')));

        $rows = [];

        foreach (DB::table('jurnal_khusus as k')
            ->where('k.skpd', $skpd)
            ->where('k.jenis', $jenis)
            ->select(['k.tahun', 'k.bulan', 'k.updated_at'])
            ->selectRaw('(SELECT COUNT(*) FROM jurnal_khusus_baris b WHERE b.jurnal_id = k.id) AS baris')
            ->orderByDesc('k.tahun')
            ->orderByDesc('k.bulan')
            ->limit(120)
            ->get() as $r) {
            $rows[] = [
                'tahun'      => (int) $r->tahun,
                'bulan'      => (int) $r->bulan,
                'bulan_nama' => $this->bulanId((int) $r->bulan),
                'baris'      => (int) $r->baris,
                'updated_at' => (string) $r->updated_at,
            ];
        }

        return $this->okData(['jenis' => $jenis, 'data' => $rows, 'total' => count($rows)]);
    }

    /**
     * POST /api/v1/aklap/jurnal-khusus/hapus  (action=jurnal_khusus_hapus)
     */
    public function destroy(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $jenis = trim((string) $request->input('jenis', ''));
        $tahun = (int) $request->input('tahun', 0);
        $bulan = (int) $request->input('bulan', 0);

        if ($jenis === '' || ! isset($this->jkTemplate()[$jenis]) || $tahun < 2000 || $tahun > 2100 || $bulan < 1 || $bulan > 12) {
            return $this->fail('Jenis / periode jurnal tidak valid.', [], 422);
        }

        $headId = (int) (DB::table('jurnal_khusus')
            ->where('skpd', $skpd)
            ->where('jenis', $jenis)
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->value('id') ?: 0);

        if ($headId <= 0) {
            return $this->okData(['message' => 'Jurnal periode tersebut belum ada.']);
        }

        DB::beginTransaction();

        try {
            DB::table('jurnal_khusus_baris')->where('jurnal_id', $headId)->where('skpd', $skpd)->delete();
            DB::table('jurnal_khusus')->where('id', $headId)->where('skpd', $skpd)->delete();

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $this->okData([
            'message' => (string) ($this->jkTemplate()[$jenis]['judul'] ?? '')
                . ' ' . $this->bulanId($bulan) . ' ' . $tahun . ' dihapus.',
        ]);
    }

    /**
     * POST /api/v1/aklap/jurnal-khusus  (action=jurnal_khusus_simpan)
     *
     * `baris` = JSON `[{tanggal, nomor_dok, uraian, debet:[...], kredit:[...]}]`
     * (nilai kolom boleh berformat "1.000,50"). `kode_extra` = JSON
     * `{debet: {slot: kode}, kredit: {slot: kode}}` untuk kolom bebas, dan
     * `skp` (kolom SKPKD blanko Konsolidator) hanya untuk template tersebut.
     */
    public function store(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $tpl   = $this->jkTemplate();
        $jenis = trim((string) $request->input('jenis', ''));
        $tahun = (int) $request->input('tahun', 0);
        $bulan = (int) $request->input('bulan', 0);

        if (! isset($tpl[$jenis])) {
            return $this->fail('Jenis jurnal tidak dikenal.', [], 422);
        }

        if ($tahun < 2000 || $tahun > 2100) {
            return $this->fail('Tahun tidak valid (2000 - 2100).', [], 422);
        }

        if ($bulan < 1 || $bulan > 12) {
            return $this->fail('Bulan tidak valid (1 - 12).', [], 422);
        }

        $slotD = count((array) $tpl[$jenis]['debet']['kode']);
        $slotK = count((array) $tpl[$jenis]['kredit']['kode']);

        $data = $request->input('baris', '[]');

        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (! is_array($data)) {
            return $this->fail('Data baris tidak valid.', [], 422);
        }

        if (count($data) > 300) {
            return $this->fail('Maksimal 300 baris per dokumen.', [], 422);
        }

        // Kode akun pada kolom bebas: slot valid saja, panjang sewajarnya.
        $kodeExtra = ['debet' => [], 'kredit' => []];

        $decKode = $request->input('kode_extra', '{}');

        if (is_string($decKode)) {
            $decKode = json_decode($decKode, true);
        }

        if (is_array($decKode)) {
            foreach (['debet', 'kredit'] as $grup) {
                if (! isset($decKode[$grup]) || ! is_array($decKode[$grup])) {
                    continue;
                }

                $maks = $grup === 'debet' ? $slotD : $slotK;

                foreach ($decKode[$grup] as $slot => $kode) {
                    $s  = (int) $slot;
                    $kc = trim((string) $kode);

                    if ($s < 1 || $s > $maks || $kc === '') {
                        continue;
                    }

                    $kodeExtra[$grup][(string) $s] = mb_substr($kc, 0, 50);
                }
            }
        }

        // SKPKD (blanko Konsolidator) diisi manual; bila tidak dikirim, nilai
        // lama dipertahankan.
        $skp = null;

        if ($request->has('skp')) {
            $skp = trim((string) $request->input('skp', ''));

            if (mb_strlen($skp) > 80) {
                return $this->fail('SKPKD maksimal 80 karakter.', [], 422);
            }
        }

        $bersih     = [];
        $timpang    = [];
        $pesanBaris = '';


        foreach (array_values($data) as $i => $r) {
            if (! is_array($r)) {
                continue;
            }

            $no      = $i + 1;
            $tanggal = trim((string) ($r['tanggal'] ?? ''));

            if ($tanggal !== '' && $this->tanggalValid($tanggal) === '') {
                $pesanBaris = "Baris ke-{$no}: tanggal tidak valid (format YYYY-MM-DD).";
                break;
            }

            $nomor = trim((string) ($r['nomor_dok'] ?? ''));

            if (mb_strlen($nomor) > 60) {
                $pesanBaris = "Baris ke-{$no}: nomor dokumen maksimal 60 karakter.";
                break;
            }

            $uraian = trim((string) ($r['uraian'] ?? ''));

            if (mb_strlen($uraian) > 255) {
                $pesanBaris = "Baris ke-{$no}: uraian maksimal 255 karakter.";
                break;
            }

            $batal = false;
            $nilai = [];

            foreach (['debet', 'kredit'] as $grup) {
                $maks = $grup === 'debet' ? $slotD : $slotK;
                $arr  = (isset($r[$grup]) && is_array($r[$grup])) ? array_values($r[$grup]) : [];
                $out  = [];

                for ($s = 0; $s < self::MAKS_SLOT; $s++) {
                    if ($s >= $maks) {
                        $out[] = 0.0;
                        continue;
                    }

                    $mentah = $arr[$s] ?? 0;

                    if ($mentah === null || $mentah === '') {
                        $out[] = 0.0;
                        continue;
                    }

                    if (is_string($mentah)) {
                        $mentah = str_replace(['.', ',', ' '], '', trim($mentah));
                    }

                    if (! is_numeric($mentah)) {
                        $pesanBaris = 'Baris ke-' . $no . ': nilai kolom ' . ucfirst($grup) . ' ke-' . ($s + 1) . ' bukan angka.';
                        $batal = true;
                        break;
                    }

                    $v = (float) $mentah;

                    if ($v < 0) {
                        $pesanBaris = 'Baris ke-' . $no . ': nilai kolom ' . ucfirst($grup) . ' ke-' . ($s + 1) . ' tidak boleh negatif.';
                        $batal = true;
                        break;
                    }

                    $out[] = round($v, 2);
                }

                if ($batal) {
                    break;
                }

                $nilai[$grup] = $out;
            }

            if ($batal) {
                break;
            }

            $td = array_sum($nilai['debet']);
            $tk = array_sum($nilai['kredit']);

            // Baris kosong tidak disimpan.
            if ($tanggal === '' && $nomor === '' && $uraian === '' && round($td, 2) === 0.0 && round($tk, 2) === 0.0) {
                continue;
            }

            if (round($td, 2) !== round($tk, 2)) {
                $timpang[] = $no;
            }

            $bersih[] = [
                'urut'      => count($bersih) + 1,
                'tanggal'   => $tanggal,
                'nomor_dok' => $nomor,
                'uraian'    => $uraian,
                'debet'     => $nilai['debet'],
                'kredit'    => $nilai['kredit'],
            ];
        }

        if ($pesanBaris !== '') {
            return $this->fail($pesanBaris, [], 422);
        }

        if ($bersih === []) {
            return $this->fail('Jurnal belum berisi data (minimal satu baris berisi angka atau uraian).', [], 422);
        }


        DB::beginTransaction();

        try {
            $headId = (int) (DB::table('jurnal_khusus')
                ->where('skpd', $skpd)
                ->where('jenis', $jenis)
                ->where('tahun', $tahun)
                ->where('bulan', $bulan)
                ->value('id') ?: 0);

            $jsonExtra = json_encode($kodeExtra, JSON_UNESCAPED_UNICODE);
            $userId    = (int) ($request->user()?->id ?? 0);

            if ($headId > 0) {
                DB::table('jurnal_khusus')
                    ->where('id', $headId)
                    ->where('skpd', $skpd)
                    ->update([
                        'user_id'    => $userId,
                        'kode_extra' => $jsonExtra,
                        'skp_label'  => $skp ?? DB::raw('skp_label'),
                    ]);

                DB::table('jurnal_khusus_baris')->where('jurnal_id', $headId)->where('skpd', $skpd)->delete();
            } else {
                $headId = (int) DB::table('jurnal_khusus')->insertGetId([
                    'skpd'       => $skpd,
                    'user_id'    => $userId,
                    'jenis'      => $jenis,
                    'tahun'      => $tahun,
                    'bulan'      => $bulan,
                    'kode_extra' => $jsonExtra,
                    'skp_label'  => (string) $skp,
                ]);
            }

            $kolom = ['jurnal_id', 'skpd', 'urut', 'tanggal', 'nomor_dok', 'uraian'];

            foreach ($this->jkKolomUang() as $k) {
                $kolom[] = $k;
            }

            $barisDb = [];

            foreach ($bersih as $b) {
                $row = [
                    'jurnal_id' => $headId,
                    'skpd'      => $skpd,
                    'urut'      => (int) $b['urut'],
                    'tanggal'   => $b['tanggal'] !== '' ? $b['tanggal'] : null,
                    'nomor_dok' => $b['nomor_dok'],
                    'uraian'    => $b['uraian'],
                ];

                for ($i = 0; $i < self::MAKS_SLOT; $i++) {
                    $row['debet_' . ($i + 1)]  = $b['debet'][$i] ?? 0;
                    $row['kredit_' . ($i + 1)] = $b['kredit'][$i] ?? 0;
                }

                $barisDb[] = $row;
            }

            DB::table('jurnal_khusus_baris')->insert($barisDb);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $pesan = (string) $tpl[$jenis]['judul'] . ' ' . $this->bulanId($bulan) . ' ' . $tahun
            . ' tersimpan (' . count($bersih) . ' baris).';

        if ($timpang !== []) {
            $pesan .= ' Catatan: baris ke-' . implode(', ', $timpang)
                . ' belum seimbang (Jumlah Debet <> Jumlah Kredit).';
        }

        return $this->okData([
            'message'              => $pesan,
            'id'                   => $headId,
            'baris'                => count($bersih),
            'baris_tidak_seimbang' => count($timpang),
        ]);
    }

    /**
     * Baca jenis + periode dari query string: jenis tidak dikenal -> jenis
     * pertama; `tahun` bukan 4 digit -> tahun berjalan; `bulan` di luar 1-12
     * -> bulan berjalan.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function periodeGet(Request $request): array
    {
        $jenis = $this->jkJenisValid(trim((string) $request->query('jenis', '')));
        $tahun = trim((string) $request->query('tahun', date('Y')));

        if (preg_match('/^\d{4}$/', $tahun) !== 1) {
            $tahun = date('Y');
        }

        $bulan = (int) $request->query('bulan', (int) date('n'));

        if ($bulan < 1 || $bulan > 12) {
            $bulan = (int) date('n');
        }

        return [$jenis, (int) $tahun, $bulan];
    }

    /**
     * Bangun dokumen jurnal khusus satu periode (kepala + baris + total kolom).
     *
     * @return array<string, mixed>
     */
    private function dokumen(string $skpd, string $jenis, int $tahun, int $bulan): array
    {
        $tpl   = $this->jkTemplate();
        $jenis = $this->jkJenisValid($jenis);
        $t     = $tpl[$jenis];
        $slotD = count((array) $t['debet']['kode']);
        $slotK = count((array) $t['kredit']['kode']);

        $head = DB::table('jurnal_khusus')
            ->where('skpd', $skpd)
            ->where('jenis', $jenis)
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->first();

        $baris   = [];
        $totD    = array_fill(0, $slotD, 0.0);
        $totK    = array_fill(0, $slotK, 0.0);
        $timpang = 0;

        if ($head !== null) {
            foreach (DB::table('jurnal_khusus_baris')
                ->where('jurnal_id', (int) $head->id)
                ->where('skpd', $skpd)
                ->select(array_merge(['tanggal', 'nomor_dok', 'uraian'], $this->jkKolomUang()))
                ->orderBy('urut')
                ->orderBy('id')
                ->limit(400)
                ->get() as $r) {
                $d  = [];
                $k  = [];
                $td = 0.0;
                $tk = 0.0;

                for ($i = 1; $i <= self::MAKS_SLOT; $i++) {
                    if ($i <= $slotD) {
                        $v = (float) $r->{'debet_' . $i};
                        $d[] = round($v, 2);
                        $totD[$i - 1] += $v;
                        $td += $v;
                    }

                    if ($i <= $slotK) {
                        $v = (float) $r->{'kredit_' . $i};
                        $k[] = round($v, 2);
                        $totK[$i - 1] += $v;
                        $tk += $v;
                    }
                }

                if (round($td, 2) !== round($tk, 2)) {
                    $timpang++;
                }

                $baris[] = [
                    'tanggal'      => (string) ($r->tanggal ?? ''),
                    'nomor_dok'    => (string) $r->nomor_dok,
                    'uraian'       => (string) $r->uraian,
                    'debet'        => $d,
                    'kredit'       => $k,
                    'total_debet'  => round($td, 2),
                    'total_kredit' => round($tk, 2),
                ];
            }
        }

        // Kode akun pada kolom bebas (diisi manual pengguna) - hanya slot valid.
        $kodeExtra = ['debet' => [], 'kredit' => []];

        if ($head !== null && ! empty($head->kode_extra)) {
            $dec = json_decode((string) $head->kode_extra, true);

            if (is_array($dec)) {
                foreach (['debet', 'kredit'] as $grup) {
                    if (! isset($dec[$grup]) || ! is_array($dec[$grup])) {
                        continue;
                    }

                    foreach ($dec[$grup] as $slot => $kode) {
                        $s  = (int) $slot;
                        $kc = trim((string) $kode);

                        if ($s < 1 || $s > self::MAKS_SLOT || $kc === '') {
                            continue;
                        }

                        $kodeExtra[$grup][(string) $s] = $kc;
                    }
                }
            }
        }

        $totalD = array_sum($totD);
        $totalK = array_sum($totK);

        return [
            'jenis'                => $jenis,
            'judul'                => (string) $t['judul'],
            'tahun'                => $tahun,
            'bulan'                => $bulan,
            'bulan_nama'           => $this->bulanId($bulan),
            'tgl_ttd'              => $this->akhirBulan($tahun, $bulan),
            'tersimpan'            => $head !== null,
            'updated_at'           => (string) ($head->updated_at ?? ''),
            'kode_extra'           => $kodeExtra,
            'skp'                  => (string) ($head->skp_label ?? ''),
            'baris'                => $baris,
            'jumlah_baris'         => count($baris),
            'baris_tidak_seimbang' => $timpang,
            'total_kolom'          => ['debet' => $totD, 'kredit' => $totK],
            'total_debet'          => round($totalD, 2),
            'total_kredit'         => round($totalK, 2),
            'selisih'              => round($totalD - $totalK, 2),
        ];
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
}
