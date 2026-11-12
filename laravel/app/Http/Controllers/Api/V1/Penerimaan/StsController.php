<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Penerimaan;

use App\Http\Controllers\Api\V1\Penerimaan\Concerns\FormatPenerimaan;
use App\Http\Controllers\Controller;
use App\Models\Sts;
use App\Support\Concerns\HasSkpdScope;
use App\Support\Tenant;
use App\Support\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * API STS (Surat Tanda Setoran).
 *
 * Diporting dari api/sts.php pada sistem legacy.
 *
 *   GET  /api/v1/penerimaan/sts                  : daftar + penghitung status
 *   GET  /api/v1/penerimaan/sts?id=N             : detail + rincian STBP
 *   GET  /api/v1/penerimaan/sts?lpj=1&dari=&akhir= : LPJ Bendahara Penerimaan
 *   GET  /api/v1/penerimaan/sts?register=1&dari=&akhir= : Register STS
 *   POST /api/v1/penerimaan/sts (action=create)  : buat STS dari STBP tervalidasi
 *
 * Catatan: query agregat memakai Query Builder dengan SQL yang sama seperti
 * legacy (termasuk syarat tenant berkualifikasi `s.skpd`), karena tabel
 * `stbp` di-JOIN dengan `stbp_pembayaran`/`skp_daerah` yang juga punya
 * kolom `skpd`.
 */
class StsController extends Controller
{
    use FormatPenerimaan;

    /** Status yang dikenali (identik dengan legacy). */
    private const STATUS_LIST = ['aktif', 'dihapus'];

    /**
     * GET /api/v1/penerimaan/sts
     */
    public function index(Request $request)
    {
        $skpd = Tenant::requireSkpd();

        if ((string) $request->input('lpj', '') === '1') {
            return $this->lpj($request, $skpd);
        }

        if ((string) $request->input('register', '') === '1') {
            return $this->register($request, $skpd);
        }

        $detailId = (int) $request->input('id', '0');

        if ($detailId > 0) {
            return $this->detail($skpd, $detailId);
        }

        $q      = trim((string) $request->input('q', ''));
        $status = (string) $request->input('status', 'aktif');

        if (! in_array($status, self::STATUS_LIST, true)) {
            $status = 'aktif';
        }

        $counts = [];

        foreach (self::STATUS_LIST as $st) {
            $counts[$st] = (int) Sts::query()->where('status', $st)->where('skpd', $skpd)->count();
        }

        $sql = Sts::query()
            ->withoutGlobalScope(HasSkpdScope::class)
            ->from('sts as s')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->select(['s.*', 'u.nama_lengkap as dibuat_oleh'])
            ->where('s.status', $status)
            ->where('s.skpd', $skpd);

        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql->where(function ($builder) use ($like): void {
                $builder->where('s.nomor_sts', 'like', $like)
                    ->orWhere('s.nama_penyetor', 'like', $like)
                    ->orWhere('s.nama_bank', 'like', $like)
                    ->orWhere('s.keterangan', 'like', $like)
                    ->orWhere('s.skpd', 'like', $like);
            });
        }

        $rows = $sql->orderByDesc('s.tanggal_sts')->orderByDesc('s.id')->get();

        return $this->ok('OK', [
            'status'      => $status,
            'status_list' => self::STATUS_LIST,
            'counts'      => $counts,
            'data'        => $rows->map(fn ($r): array => [
                'id'              => (int) $r->id,
                'skpd'            => (string) $r->skpd,
                'nomor_sts'       => (string) $r->nomor_sts,
                'nama_penyetor'   => (string) $r->nama_penyetor,
                'tanggal_sts'     => $this->tanggal($r->tanggal_sts),
                'nama_bank'       => (string) $r->nama_bank,
                'nomor_rekening'  => (string) $r->nomor_rekening,
                'nama_rekening'   => (string) $r->nama_rekening,
                'keterangan'      => (string) $r->keterangan,
                'total'           => (float) $r->total,
                'status'          => (string) $r->status,
                'dibuat_oleh'     => (string) ($r->dibuat_oleh ?? ''),
                'created_at'      => $this->waktu($r->created_at),
            ])->all(),
        ]);
    }

    /**
     * Periode laporan (dari/akhir) — tanggal tidak valid diabaikan,
     * sama seperti legacy.
     *
     * @return array{0: string, 1: string}
     */
    private function periode(Request $request): array
    {
        $dari  = trim((string) $request->input('dari', ''));
        $akhir = trim((string) $request->input('akhir', ''));

        if ($dari !== '' && ! Validator::tanggal($dari)) {
            $dari = '';
        }

        if ($akhir !== '' && ! Validator::tanggal($akhir)) {
            $akhir = '';
        }

        return [$dari, $akhir];
    }

    /**
     * LPJ Bendahara Penerimaan: penerimaan (STBP tervalidasi) vs penyetoran (STS aktif).
     */
    private function lpj(Request $request, string $skpd)
    {
        [$dari, $akhir] = $this->periode($request);

        $penerimaan = DB::table('stbp as s')
            ->leftJoin('stbp_pembayaran as sp', 'sp.stbp_id', '=', 's.id')
            ->where('s.status', 'sudah_divalidasi')
            ->where('s.skpd', $skpd)
            ->when($dari !== '', fn ($qb) => $qb->where('s.tanggal', '>=', $dari))
            ->when($akhir !== '', fn ($qb) => $qb->where('s.tanggal', '<=', $akhir))
            ->selectRaw(
                'COALESCE(SUM(s.jumlah),0) AS total, '
                . "COALESCE(SUM(CASE WHEN sp.metode_penyetoran = 'tunai' THEN s.jumlah ELSE 0 END),0) AS tunai, "
                . "COALESCE(SUM(CASE WHEN sp.metode_penyetoran <> 'tunai' THEN s.jumlah ELSE 0 END),0) AS non_tunai"
            )
            ->first();

        $penyetoran = (float) DB::table('sts as st')
            ->where('st.status', 'aktif')
            ->where('st.skpd', $skpd)
            ->when($dari !== '', fn ($qb) => $qb->where('st.tanggal_sts', '>=', $dari))
            ->when($akhir !== '', fn ($qb) => $qb->where('st.tanggal_sts', '<=', $akhir))
            ->sum('st.total');

        $total = (float) ($penerimaan->total ?? 0);

        return $this->ok('OK', [
            'periode'    => ['dari' => $dari, 'akhir' => $akhir],
            'skpd'       => $skpd,
            'penerimaan' => [
                'total'     => $total,
                'tunai'     => (float) ($penerimaan->tunai ?? 0),
                'non_tunai' => (float) ($penerimaan->non_tunai ?? 0),
            ],
            'penyetoran' => $penyetoran,
            'saldo'      => $total - $penyetoran,
            'bendahara'  => (string) ($request->user()?->nama_lengkap ?? ''),
            'kuasa_pengguna_anggaran' => $this->kuasaPenggunaAnggaran($skpd, $dari, $akhir),
        ]);
    }

    /**
     * Register STS: satu baris per rincian pendapatan (snapshot sts_detail).
     */
    private function register(Request $request, string $skpd)
    {
        [$dari, $akhir] = $this->periode($request);

        $rows = DB::table('sts as st')
            ->join('sts_detail as sd', 'sd.sts_id', '=', 'st.id')
            ->leftJoin('stbp as s', 's.id', '=', 'sd.stbp_id')
            ->leftJoin('stbp_pembayaran as sp', 'sp.stbp_id', '=', 's.id')
            ->where('st.status', 'aktif')
            ->where('st.skpd', $skpd)
            ->when($dari !== '', fn ($qb) => $qb->where('st.tanggal_sts', '>=', $dari))
            ->when($akhir !== '', fn ($qb) => $qb->where('st.tanggal_sts', '<=', $akhir))
            ->orderBy('st.tanggal_sts')
            ->orderBy('st.id')
            ->orderBy('sd.id')
            ->get([
                'st.nomor_sts',
                'st.tanggal_sts',
                'sd.akun_kode',
                'sd.akun_nama',
                'sd.jumlah',
                DB::raw("COALESCE(NULLIF(sp.nama_penyetor, ''), st.nama_penyetor) AS nama_penyetor"),
            ]);

        $total = 0.0;

        $data = $rows->map(function ($r) use (&$total): array {
            $jumlah = (float) $r->jumlah;
            $total += $jumlah;

            return [
                'nomor_sts'     => (string) $r->nomor_sts,
                'tanggal'       => $this->tanggal($r->tanggal_sts),
                'akun_kode'     => (string) $r->akun_kode,
                'akun_nama'     => (string) $r->akun_nama,
                'jumlah'        => $jumlah,
                'nama_penyetor' => (string) ($r->nama_penyetor ?? ''),
            ];
        })->all();

        return $this->ok('OK', [
            'periode'    => ['dari' => $dari, 'akhir' => $akhir],
            'skpd'       => $skpd,
            'rows'       => $data,
            'total'      => $total,
            'bendahara'  => (string) ($request->user()?->nama_lengkap ?? ''),
            'kuasa_pengguna_anggaran' => $this->kuasaPenggunaAnggaran($skpd, $dari, $akhir),
        ]);
    }

    /**
     * Kuasa Pengguna Anggaran dari STS terbaru dalam periode
     * (dipakai pada blok tanda tangan dokumen cetak).
     */
    private function kuasaPenggunaAnggaran(string $skpd, string $dari, string $akhir): string
    {
        $kuasa = DB::table('sts as st')
            ->where('st.status', 'aktif')
            ->where('st.skpd', $skpd)
            ->where('st.kuasa_pengguna_anggaran', '<>', '')
            ->when($dari !== '', fn ($qb) => $qb->where('st.tanggal_sts', '>=', $dari))
            ->when($akhir !== '', fn ($qb) => $qb->where('st.tanggal_sts', '<=', $akhir))
            ->orderByDesc('st.tanggal_sts')
            ->orderByDesc('st.id')
            ->value('st.kuasa_pengguna_anggaran');

        return (string) ($kuasa ?? '');
    }

    /**
     * Detail satu STS berikut rincian STBP-nya.
     */
    private function detail(string $skpd, int $id)
    {
        $row = Sts::query()
            ->withoutGlobalScope(HasSkpdScope::class)
            ->from('sts as s')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->select(['s.*', 'u.nama_lengkap as dibuat_oleh'])
            ->where('s.id', $id)
            ->where('s.skpd', $skpd)
            ->first();

        if (! $row) {
            return $this->fail('STS tidak ditemukan.', [], 404);
        }

        $details = DB::table('sts_detail')->where('sts_id', $id)->orderBy('id')->get();

        return $this->ok('OK', [
            'sts' => [
                'id'                  => (int) $row->id,
                'skpd'                => (string) $row->skpd,
                'nomor_sts'           => (string) $row->nomor_sts,
                'nama_penyetor'       => (string) $row->nama_penyetor,
                'tanggal_sts'         => $this->tanggal($row->tanggal_sts),
                'tanggal_acuan_dari'  => $this->tanggal($row->tanggal_acuan_dari),
                'tanggal_acuan_akhir' => $this->tanggal($row->tanggal_acuan_akhir),
                'mengetahui'          => (string) $row->mengetahui,
                'kuasa_pengguna_anggaran' => (string) ($row->kuasa_pengguna_anggaran ?? ''),
                'nama_bank'           => (string) $row->nama_bank,
                'nomor_rekening'      => (string) $row->nomor_rekening,
                'nama_rekening'       => (string) $row->nama_rekening,
                'keterangan'          => (string) $row->keterangan,
                'total'               => (float) $row->total,
                'status'              => (string) $row->status,
                'dibuat_oleh'         => (string) ($row->dibuat_oleh ?? ''),
                'created_at'          => $this->waktu($row->created_at),
            ],
            'detail' => $details->map(fn ($d): array => [
                'id'         => (int) $d->id,
                'stbp_id'    => (int) $d->stbp_id,
                'nomor_stbp' => (string) $d->nomor_stbp,
                'tanggal'    => $this->tanggal($d->tanggal),
                'akun_kode'  => (string) $d->akun_kode,
                'akun_nama'  => (string) $d->akun_nama,
                'jumlah'     => (float) $d->jumlah,
                'uraian'     => (string) $d->uraian,
            ])->all(),
        ]);
    }

    /**
     * POST /api/v1/penerimaan/sts  (action=create)
     *
     * Hanya STBP yang SUDAH divalidasi (tahap 3) dan belum pernah dipakai STS
     * aktif. Total dihitung dari data STBP tersimpan (bukan input klien).
     */
    public function store(Request $request)
    {
        $skpd = Tenant::requireSkpd();

        if ((string) $request->input('action', 'create') !== 'create') {
            return $this->fail('Aksi tidak dikenali.', [], 422);
        }

        $nomor        = trim((string) $request->input('nomor_sts', ''));
        $namaPenyetor = trim((string) $request->input('nama_penyetor', ''));
        $tanggalSts   = trim((string) $request->input('tanggal_sts', ''));
        $tanggalDari  = trim((string) $request->input('tanggal_acuan_dari', ''));
        $tanggalAkhir = trim((string) $request->input('tanggal_acuan_akhir', ''));
        $mengetahui   = trim((string) $request->input('mengetahui', ''));
        $kuasaKpa     = trim((string) $request->input('kuasa_pengguna_anggaran', ''));
        $namaBank     = trim((string) $request->input('nama_bank', ''));
        $nomorRek     = trim((string) $request->input('nomor_rekening', ''));
        $namaRek      = trim((string) $request->input('nama_rekening', ''));
        $keterangan   = trim((string) $request->input('keterangan', ''));
        $stbpIds      = is_array($request->input('stbp_ids'))
            ? array_map('intval', (array) $request->input('stbp_ids'))
            : [];

        if ($nomor === '') {
            $nomor = 'STS-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        }

        if ($tanggalSts === '') {
            return $this->fail('Tanggal STS wajib diisi.', [], 422);
        }

        if (count($stbpIds) === 0) {
            return $this->fail('Pilih minimal satu STBP.', [], 422);
        }

        $tervalidasi = DB::table('stbp')
            ->whereIn('id', $stbpIds)
            ->where('status', 'sudah_divalidasi')
            ->where('skpd', $skpd)
            ->count();

        if ($tervalidasi !== count($stbpIds)) {
            return $this->fail('Ada STBP yang belum divalidasi (tahap 3) sehingga tidak dapat dimasukkan ke STS.', [], 422);
        }

        $sudahDipakai = DB::table('sts_detail as sd')
            ->join('sts as st', 'st.id', '=', 'sd.sts_id')
            ->whereIn('sd.stbp_id', $stbpIds)
            ->where('st.status', 'aktif')
            ->count();

        if ($sudahDipakai > 0) {
            return $this->fail('Ada STBP yang sudah pernah dibuatkan STS. STBP tersebut tidak dapat dipilih lagi.', [], 422);
        }

        try {
            $stsId = $this->simpanSts($request, $skpd, $nomor, [
                'nama_penyetor'           => $namaPenyetor,
                'tanggal_sts'             => $tanggalSts,
                'tanggal_acuan_dari'      => $tanggalDari,
                'tanggal_acuan_akhir'     => $tanggalAkhir,
                'mengetahui'              => $mengetahui,
                'kuasa_pengguna_anggaran' => $kuasaKpa,
                'nama_bank'               => $namaBank,
                'nomor_rekening'          => $nomorRek,
                'nama_rekening'           => $namaRek,
                'keterangan'              => $keterangan,
            ], $stbpIds);
        } catch (Throwable $e) {
            report($e);

            return $this->fail('Gagal menyimpan STS.', [], 500);
        }

        return $this->ok('STS berhasil dibuat.', [
            'id'    => $stsId,
            'nomor' => $nomor,
        ], 201);
    }

    /**
     * Simpan STS + rincian STBP dalam satu transaksi, lalu hitung totalnya
     * dari data STBP tersimpan (bukan dari input klien).
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $stbpIds
     */
    private function simpanSts(Request $request, string $skpd, string $nomor, array $data, array $stbpIds): int
    {
        return DB::transaction(function () use ($request, $skpd, $nomor, $data, $stbpIds): int {
            $id = (int) Sts::create([
                'user_id'                 => $request->user()?->id,
                'skpd'                    => $skpd,
                'nomor_sts'               => $nomor,
                'nama_penyetor'           => (string) $data['nama_penyetor'],
                'tanggal_sts'             => (string) $data['tanggal_sts'],
                'tanggal_acuan_dari'      => $data['tanggal_acuan_dari'] !== '' ? $data['tanggal_acuan_dari'] : null,
                'tanggal_acuan_akhir'     => $data['tanggal_acuan_akhir'] !== '' ? $data['tanggal_acuan_akhir'] : null,
                'mengetahui'              => (string) $data['mengetahui'],
                'kuasa_pengguna_anggaran' => (string) $data['kuasa_pengguna_anggaran'],
                'nama_bank'               => (string) $data['nama_bank'],
                'nomor_rekening'          => (string) $data['nomor_rekening'],
                'nama_rekening'           => (string) $data['nama_rekening'],
                'keterangan'              => (string) $data['keterangan'],
                'total'                   => 0,
                'status'                  => 'aktif',
            ])->id;

            $total = 0.0;

            $daftar = DB::table('stbp')
                ->whereIn('id', $stbpIds)
                ->where('status', 'sudah_divalidasi')
                ->where('skpd', $skpd)
                ->get(['id', 'nomor_stbp', 'tanggal', 'akun_kode', 'akun_nama', 'jumlah', 'uraian']);

            foreach ($daftar as $sb) {
                DB::table('sts_detail')->insert([
                    'sts_id'     => $id,
                    'stbp_id'    => (int) $sb->id,
                    'nomor_stbp' => (string) $sb->nomor_stbp,
                    'tanggal'    => (string) $sb->tanggal,
                    'akun_kode'  => (string) $sb->akun_kode,
                    'akun_nama'  => (string) $sb->akun_nama,
                    'jumlah'     => (float) $sb->jumlah,
                    'uraian'     => (string) $sb->uraian,
                ]);

                $total += (float) $sb->jumlah;
            }

            Sts::query()->whereKey($id)->where('skpd', $skpd)->update(['total' => $total]);

            return $id;
        });
    }
}
