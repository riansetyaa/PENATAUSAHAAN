<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Aklap;

use App\Http\Controllers\Api\V1\Aklap\Concerns\CoaLra;
use App\Http\Controllers\Controller;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AKLAP — Anggaran (pagu LRA berbasis Master Akun).
 *
 * Diporting dari blok `action=anggaran_list`, `anggaran_simpan`,
 * `anggaran_hapus`, dan `anggaran_sinkron_master` pada
 * peta.simtkd.com/api/akuntansi.php.
 *
 * Kode & nama akun bersumber dari akun_master, sedangkan penyimpanan pagu
 * tetap di tabel `anggaran_lra` agar laporan lama (LRA, rekap AKLAP, Buku
 * Pembantu) tetap kompatibel — sama seperti legacy.
 */
class AnggaranController extends Controller
{
    use CoaLra;

    /**
     * GET /api/v1/aklap/anggaran?tahun=&tipe=&q=
     *
     * `tipe`: 'lra' (pendapatan + belanja) atau salah satu tipe akun.
     */
    public function index(Request $request)
    {
        $skpd = Tenant::requireSkpd();

        $tahun = trim((string) $request->query('tahun', date('Y')));

        if (preg_match('/^\d{4}$/', $tahun) !== 1) {
            $tahun = date('Y');
        }

        $tipe = (string) $request->query('tipe', 'lra');
        $q    = trim((string) $request->query('q', ''));

        $this->seedDefault($skpd);
        $this->seedCoa($skpd);

        // Tahun yang sudah punya data (pemilih tahun di halaman).
        $daftarTahun = DB::table('anggaran_lra')
            ->where('skpd', $skpd)
            ->distinct()
            ->orderByDesc('tahun')
            ->pluck('tahun')
            ->map(fn ($t): string => (string) $t)
            ->all();

        // 1) Akun Master + pagu tahun terpilih.
        $query = DB::table('akun_master as m')
            ->leftJoin('anggaran_lra as a', function ($join) use ($tahun): void {
                $join->on('a.skpd', '=', 'm.skpd')
                    ->on('a.kode_akun', '=', 'm.kode_akun')
                    ->where('a.tahun', '=', $tahun);
            })
            ->select([
                'm.kode_akun',
                'm.nama_akun',
                'm.tipe',
                'm.saldo_normal',
                'a.id as id_anggaran',
                'a.nama_akun as nama_anggaran',
            ])
            ->selectRaw('COALESCE(a.anggaran, 0) AS pagu')
            ->where('m.skpd', $skpd);

        if (in_array($tipe, ['pendapatan', 'belanja', 'aset', 'liabilitas', 'ekuitas'], true)) {
            $query->where('m.tipe', $tipe);
        } elseif ($tipe === 'lra') {
            $query->whereIn('m.tipe', ['pendapatan', 'belanja']);
        }

        if ($q !== '') {
            $query->where(function ($builder) use ($q): void {
                $builder->where('m.kode_akun', 'like', '%' . $q . '%')
                    ->orWhere('m.nama_akun', 'like', '%' . $q . '%');
            });
        }

        $rows   = [];
        $terisi = 0;

        foreach ($query->orderBy('m.kode_akun')->limit(400)->get() as $r) {
            $adaBaris = $r->id_anggaran !== null;

            if ($adaBaris) {
                $terisi++;
            }

            $rows[] = [
                'kode_akun'     => (string) $r->kode_akun,
                'nama_akun'     => (string) $r->nama_akun,
                'nama_anggaran' => (string) ($r->nama_anggaran ?? ''),
                'tipe'          => (string) $r->tipe,
                'saldo_normal'  => (string) $r->saldo_normal,
                'level'         => substr_count((string) $r->kode_akun, '.') + 1,
                'pagu'          => (float) $r->pagu,
                'id_anggaran'   => $adaBaris ? (int) $r->id_anggaran : 0,
                'sumber'        => 'master',
            ];
        }

        $luar = $this->hitungLuarMaster($skpd, $tahun, $q);
        $totalLra = $this->hitungTotalLra($skpd, $tahun, $luar['luar_master']);

        return $this->okData([
            'tahun'             => $tahun,
            'tipe'              => $tipe,
            'daftar_tahun'      => $daftarTahun !== [] ? $daftarTahun : [date('Y')],
            'data'              => $rows,
            'luar_master'       => $luar['luar_master'],
            'total'             => count($rows) + count($luar['luar_master']),
            'jumlah_terisi'     => $terisi,
            'coa_jumlah'        => count($this->coaLra()),
            'total_pendapatan'  => $totalLra['pendapatan'],
            'total_belanja'     => $totalLra['belanja'],
            'total_luar_master' => $luar['total'],
        ]);
    }

    /**
     * Baris pagu lama yang kodenya belum ada di Master Akun.
     *
     * @return array{luar_master: array<int, array<string, mixed>>, total: float}
     */
    private function hitungLuarMaster(string $skpd, string $tahun, string $q): array
    {
        $luar = DB::table('anggaran_lra as a')
            ->where('a.skpd', $skpd)
            ->where('a.tahun', $tahun)
            ->whereNotExists(function ($sub): void {
                $sub->select(DB::raw('1'))
                    ->from('akun_master as m')
                    ->whereColumn('m.skpd', 'a.skpd')
                    ->whereColumn('m.kode_akun', 'a.kode_akun');
            })
            ->when($q !== '', function ($builder) use ($q): void {
                $builder->where(function ($w) use ($q): void {
                    $w->where('a.kode_akun', 'like', '%' . $q . '%')
                        ->orWhere('a.nama_akun', 'like', '%' . $q . '%');
                });
            })
            ->orderBy('a.kode_akun')
            ->limit(200)
            ->get(['a.kode_akun', 'a.nama_akun', 'a.anggaran', 'a.id']);

        $luarMaster = [];
        $total      = 0.0;

        foreach ($luar as $r) {
            $pagu = (float) $r->anggaran;
            [$tipeKode] = $this->tipeDariKode((string) $r->kode_akun);
            $total += $pagu;

            $luarMaster[] = [
                'kode_akun'   => (string) $r->kode_akun,
                'nama_akun'   => (string) $r->nama_akun,
                'tipe'        => $tipeKode,
                'level'       => substr_count((string) $r->kode_akun, '.') + 1,
                'pagu'        => $pagu,
                'id_anggaran' => (int) $r->id,
                'sumber'      => 'luar_master',
            ];
        }

        return ['luar_master' => $luarMaster, 'total' => $total];
    }

    /**
     * Total pagu LRA (pendapatan & belanja) tanpa dipengaruhi filter
     * pencarian/tipe, termasuk pagu dari luar Master Akun.
     *
     * @param  array<int, array<string, mixed>>  $luarMaster
     * @return array{pendapatan: float, belanja: float}
     */
    private function hitungTotalLra(string $skpd, string $tahun, array $luarMaster): array
    {
        $rows = DB::table('akun_master as m')
            ->leftJoin('anggaran_lra as a', function ($join) use ($tahun): void {
                $join->on('a.skpd', '=', 'm.skpd')
                    ->on('a.kode_akun', '=', 'm.kode_akun')
                    ->where('a.tahun', '=', $tahun);
            })
            ->where('m.skpd', $skpd)
            ->whereIn('m.tipe', ['pendapatan', 'belanja'])
            ->groupBy('m.tipe')
            ->get(['m.tipe', DB::raw('COALESCE(SUM(a.anggaran), 0) AS total')]);

        $pendapatan = 0.0;
        $belanja    = 0.0;

        foreach ($rows as $r) {
            if ((string) $r->tipe === 'pendapatan') {
                $pendapatan = (float) $r->total;
            } else {
                $belanja = (float) $r->total;
            }
        }

        foreach ($luarMaster as $lm) {
            if ($lm['tipe'] === 'pendapatan') {
                $pendapatan += $lm['pagu'];
            } else {
                $belanja += $lm['pagu'];
            }
        }

        return ['pendapatan' => round($pendapatan, 2), 'belanja' => round($belanja, 2)];
    }

    /**
     * POST /api/v1/aklap/anggaran  (action=anggaran_simpan)
     *
     * Menerima batch: `rows` berupa array (JSON) `[{kode_akun, anggaran}]`
     * atau satu baris `kode_akun` + `anggaran`. Nilai bertipe teks
     * dinormalkan ("1.000,50" -> 1000.50) persis seperti legacy.
     */
    public function store(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $tahun = trim((string) $request->input('tahun', ''));

        if (preg_match('/^\d{4}$/', $tahun) !== 1) {
            return $this->fail('Tahun anggaran tidak valid (format YYYY).', [], 422);
        }

        $rows = $request->input('rows');

        if (is_string($rows)) {
            $rows = json_decode($rows, true);
        }

        if (! is_array($rows) || $rows === []) {
            if ($request->filled('kode_akun')) {
                $rows = [[
                    'kode_akun' => (string) $request->input('kode_akun'),
                    'anggaran'  => $request->input('anggaran', 0),
                ]];
            }
        }

        if (! is_array($rows) || $rows === []) {
            return $this->fail('Tidak ada baris anggaran yang dikirim.', [], 422);
        }

        $namaMaster = DB::table('akun_master')
            ->where('skpd', $skpd)
            ->pluck('nama_akun', 'kode_akun')
            ->all();

        $bersih  = [];
        $invalid = [];

        foreach ($rows as $r) {
            $kode = trim((string) ($r['kode_akun'] ?? ''));

            if ($kode === '') {
                continue;
            }

            $pagu = $r['anggaran'] ?? 0;

            if (is_string($pagu)) {
                $pagu = str_replace([' ', '.'], '', $pagu);
                $pagu = str_replace(',', '.', $pagu);
            }

            if (! is_numeric($pagu)) {
                return $this->fail('Anggaran akun ' . $kode . ' harus berupa angka.', [], 422);
            }

            if ((float) $pagu < 0) {
                return $this->fail('Anggaran akun ' . $kode . ' tidak boleh negatif.', [], 422);
            }

            if (! isset($namaMaster[$kode])) {
                $invalid[] = $kode;
                continue;
            }

            $bersih[$kode] = (float) $pagu;
        }

        if ($invalid !== []) {
            return $this->fail(
                'Akun berikut belum ada di Master Akun: ' . implode(', ', array_slice($invalid, 0, 8))
                    . (count($invalid) > 8 ? ' ...' : '') . '. Tambahkan dulu di halaman Master Akun.',
                ['invalid' => $invalid],
                422
            );
        }

        if ($bersih === []) {
            return $this->fail('Tidak ada akun valid untuk disimpan.', [], 422);
        }

        DB::transaction(function () use ($bersih, $namaMaster, $skpd, $tahun): void {
            foreach ($bersih as $kode => $pagu) {
                $nama = (string) $namaMaster[$kode];

                $ada = DB::table('anggaran_lra')
                    ->where('skpd', $skpd)
                    ->where('tahun', $tahun)
                    ->where('kode_akun', $kode)
                    ->value('id');

                if ($ada) {
                    DB::table('anggaran_lra')
                        ->where('id', (int) $ada)
                        ->where('skpd', $skpd)
                        ->update(['nama_akun' => $nama, 'anggaran' => $pagu]);

                    continue;
                }

                DB::table('anggaran_lra')->insert([
                    'skpd'      => $skpd,
                    'tahun'     => $tahun,
                    'kode_akun' => $kode,
                    'nama_akun' => $nama,
                    'anggaran'  => $pagu,
                ]);
            }
        });

        return $this->ok('Anggaran tahun ' . $tahun . ' tersimpan (' . count($bersih) . ' akun).', [
            'tersimpan' => count($bersih),
            'tahun'     => $tahun,
        ]);
    }

    /**
     * POST /api/v1/aklap/anggaran/hapus  (action=anggaran_hapus)
     *
     * Hapus pagu satu akun pada satu tahun (menjadi 0).
     */
    public function destroy(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $tahun = trim((string) $request->input('tahun', ''));
        $kode  = trim((string) $request->input('kode_akun', ''));

        if (preg_match('/^\d{4}$/', $tahun) !== 1 || $kode === '') {
            return $this->fail('Tahun / kode akun tidak valid.', [], 422);
        }

        $terhapus = DB::table('anggaran_lra')
            ->where('skpd', $skpd)
            ->where('tahun', $tahun)
            ->where('kode_akun', $kode)
            ->delete();

        return $this->ok($terhapus > 0
            ? 'Pagu akun ' . $kode . ' dihapus (menjadi 0).'
            : 'Belum ada pagu untuk akun tersebut.');
    }

    /**
     * POST /api/v1/aklap/anggaran/sinkron-master  (action=anggaran_sinkron_master)
     *
     * Kode akun pada data anggaran lama (mis. hasil impor) yang belum ada di
     * Master Akun ditambahkan ke Master Akun.
     */
    public function sinkronMaster()
    {
        $skpd = Tenant::requireSkpd();

        $rows = DB::table('anggaran_lra as a')
            ->where('a.skpd', $skpd)
            ->where('a.kode_akun', '<>', '')
            ->whereNotExists(function ($sub): void {
                $sub->select(DB::raw('1'))
                    ->from('akun_master as m')
                    ->whereColumn('m.skpd', 'a.skpd')
                    ->whereColumn('m.kode_akun', 'a.kode_akun');
            })
            ->groupBy('a.kode_akun')
            ->orderBy('a.kode_akun')
            ->get(['a.kode_akun', DB::raw('MAX(a.nama_akun) AS nama_akun')]);

        $ditambah = [];

        foreach ($rows as $r) {
            $kode = (string) $r->kode_akun;
            $nama = trim((string) $r->nama_akun);

            if ($nama === '') {
                $nama = $kode;
            }

            [$tipe, $normal] = $this->tipeDariKode($kode);

            $masuk = DB::affectingStatement(
                'INSERT IGNORE INTO akun_master (skpd, kode_akun, nama_akun, tipe, saldo_normal) VALUES (?, ?, ?, ?, ?)',
                [$skpd, $kode, $nama, $tipe, $normal]
            );

            if ($masuk > 0) {
                $ditambah[] = $kode;
            }
        }

        return $this->ok(
            $ditambah !== []
                ? count($ditambah) . ' akun dari data anggaran ditambahkan ke Master Akun.'
                : 'Semua kode anggaran sudah ada di Master Akun.',
            ['ditambahkan' => count($ditambah), 'kode' => array_slice($ditambah, 0, 20)]
        );
    }
}
