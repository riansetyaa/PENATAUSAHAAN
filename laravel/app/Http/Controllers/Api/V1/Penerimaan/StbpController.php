<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Penerimaan;

use App\Http\Controllers\Api\V1\Penerimaan\Concerns\FormatPenerimaan;
use App\Http\Controllers\Controller;
use App\Models\SkpDaerah;
use App\Models\Stbp;
use App\Support\Concerns\HasSkpdScope;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * API STBP (Surat Tanda Bukti Penerimaan).
 *
 * Diporting dari api/stbp.php pada sistem legacy.
 *
 *   GET  /api/v1/penerimaan/stbp                 : daftar + penghitung status
 *   GET  /api/v1/penerimaan/stbp?id=N            : detail satu STBP
 *   POST /api/v1/penerimaan/stbp (action=create) : buat STBP dari SKP Daerah
 *   POST /api/v1/penerimaan/stbp (action=update_status) : majukan tahap status
 *
 * Catatan implementasi: query memakai Query Builder dengan alias kolom
 * yang sama seperti legacy (`stbp as s`, `stbp_pembayaran as sp`,
 * `skp_daerah as sk`). Hal ini penting karena `skp_daerah` juga punya kolom
 * `skpd`, sehingga syarat tenant WAJIB memakai kualifikasi `s.skpd`
 * (kalau tidak, MySQL menolak "column 'skpd' in where clause is ambiguous").
 */
class StbpController extends Controller
{
    use FormatPenerimaan;

    /** Status yang dikenali (identik dengan legacy). */
    private const STATUS_LIST = [
        'belum_diverifikasi',
        'sudah_diverifikasi',
        'sudah_diotorisasi',
        'sudah_divalidasi',
        'dihapus',
    ];

    /** Urutan tahap yang boleh dimajukan satu per satu. */
    private const STAGES = ['belum_diverifikasi', 'sudah_diverifikasi', 'sudah_diotorisasi', 'sudah_divalidasi'];

    /**
     * GET /api/v1/penerimaan/stbp
     */
    public function index(Request $request)
    {
        $skpd = Tenant::requireSkpd();

        $detailId = (int) $request->query('id', '0');

        if ($detailId > 0) {
            return $this->detail($skpd, $detailId);
        }

        $q      = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', 'belum_diverifikasi'));

        // Status khusus: STBP yang siap dimasukkan ke STS & jurnal, yaitu
        // sudah melalui tahap otorisasi/validasi DAN belum dipakai STS aktif.
        $siapSts = $status === 'siap_sts';

        if (! $siapSts && ! in_array($status, self::STATUS_LIST, true)) {
            $status = 'belum_diverifikasi';
        }

        // Penghitung per status (untuk tab).
        $counts = [];

        foreach (self::STATUS_LIST as $st) {
            $counts[$st] = (int) $this->basisStbp()
                ->where('s.status', $st)
                ->where('s.skpd', $skpd)
                ->count();
        }

        $sql = $this->basisStbp()
            ->leftJoin('stbp_pembayaran as sp', 'sp.stbp_id', '=', 's.id')
            ->leftJoin('skp_daerah as sk', 'sk.id', '=', 's.skp_daerah_id')
            ->select([
                's.*',
                'u.nama_lengkap as dibuat_oleh',
                'sp.metode_penyetoran as metode_penyetoran',
                'sp.nama_penyetor as nama_penyetor',
                'sp.nama_bank as nama_bank',
                'sp.nomor_rekening as nomor_rekening',
                'sk.nomor_skp as nomor_skp',
                'sk.jenis_pajak as jenis_pajak',
            ])
            ->where('s.skpd', $skpd);

        if ($siapSts) {
            $sql->whereIn('s.status', ['sudah_diotorisasi', 'sudah_divalidasi'])
                ->whereNotExists(function ($sub): void {
                    $sub->select(DB::raw('1'))
                        ->from('sts_detail as sd')
                        ->join('sts as st', 'st.id', '=', 'sd.sts_id')
                        ->whereColumn('sd.stbp_id', 's.id')
                        ->where('st.status', 'aktif');
                });
        } else {
            $sql->where('s.status', $status);
        }

        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql->where(function ($builder) use ($like): void {
                $builder->where('s.nomor_stbp', 'like', $like)
                    ->orWhere('s.akun_nama', 'like', $like)
                    ->orWhere('s.uraian', 'like', $like)
                    ->orWhere('s.skpd', 'like', $like);
            });
        }

        $rows = $sql->orderByDesc('s.tanggal')->orderByDesc('s.id')->get();

        return $this->ok('OK', [
            'status'      => $status,
            'status_list' => self::STATUS_LIST,
            'counts'      => $counts,
            'data'        => $rows->map(fn ($r): array => [
                'id'                => (int) $r->id,
                'skpd'              => (string) $r->skpd,
                'nomor_stbp'        => (string) $r->nomor_stbp,
                'tanggal'           => $this->tanggal($r->tanggal),
                'akun_kode'         => (string) $r->akun_kode,
                'akun_nama'         => (string) $r->akun_nama,
                'jumlah'            => (float) $r->jumlah,
                'uraian'            => (string) $r->uraian,
                'status'            => (string) $r->status,
                'metode_penyetoran' => (string) ($r->metode_penyetoran ?? ''),
                'nama_penyetor'     => (string) ($r->nama_penyetor ?? ''),
                'bank'              => (string) ($r->nama_bank ?? ''),
                'nomor_rekening'    => (string) ($r->nomor_rekening ?? ''),
                'nomor_skp'         => (string) ($r->nomor_skp ?? ''),
                'jenis_pajak'       => (string) ($r->jenis_pajak ?? ''),
                'skp_daerah_id'     => (int) ($r->skp_daerah_id ?? 0),
                'dibuat_oleh'       => (string) ($r->dibuat_oleh ?? ''),
                'created_at'        => $this->waktu($r->created_at),
            ])->all(),
        ]);
    }

    /**
     * Basis query STBP: alias `s` + join pembuat dokumen (`u`), dengan scope
     * tenant dilepas lalu diterapkan manual memakai kualifikasi `s.skpd`
     * (lihat catatan pada docblock kelas).
     */
    private function basisStbp()
    {
        return Stbp::query()
            ->withoutGlobalScope(HasSkpdScope::class)
            ->from('stbp as s')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id');
    }

    /**
     * Detail satu STBP (untuk halaman cetak).
     */
    private function detail(string $skpd, int $id)
    {
        $row = $this->basisStbp()
            ->leftJoin('skp_daerah as sk', 'sk.id', '=', 's.skp_daerah_id')
            ->select([
                's.*',
                'u.nama_lengkap as dibuat_oleh',
                'sk.nomor_skp as nomor_skp',
                'sk.akun_kode as skp_akun_kode',
                'sk.jenis_pajak as jenis_pajak',
                'sk.nama_penyetor as skp_nama_penyetor',
                'sk.nilai_keputusan as skp_nilai',
            ])
            ->where('s.id', $id)
            ->where('s.skpd', $skpd)
            ->first();

        if (! $row) {
            return $this->fail('STBP tidak ditemukan.', [], 404);
        }

        $pay = (array) (DB::table('stbp_pembayaran')
            ->where('stbp_id', $id)
            ->orderBy('id')
            ->first() ?? []);

        $pendapatan = DB::table('stbp_pendapatan')
            ->where('stbp_id', $id)
            ->orderBy('id')
            ->get();

        return $this->ok('OK', [
            'stbp' => [
                'id'                => (int) $row->id,
                'skpd'              => (string) $row->skpd,
                'nomor_stbp'        => (string) $row->nomor_stbp,
                'tanggal'           => $this->tanggal($row->tanggal),
                'akun_kode'         => (string) $row->akun_kode,
                'akun_nama'         => (string) $row->akun_nama,
                'jumlah'            => (float) $row->jumlah,
                'uraian'            => (string) $row->uraian,
                'status'            => (string) $row->status,
                'dibuat_oleh'       => (string) ($row->dibuat_oleh ?? ''),
                'created_at'        => $this->waktu($row->created_at),
                'skp_daerah_id'     => (int) ($row->skp_daerah_id ?? 0),
                'nomor_skp'         => (string) ($row->nomor_skp ?? ''),
                'jenis_pajak'       => (string) ($row->jenis_pajak ?? ''),
                'skp_akun_kode'     => (string) ($row->skp_akun_kode ?? ''),
                'skp_nama_penyetor' => (string) ($row->skp_nama_penyetor ?? ''),
                'skp_nilai'         => (float) ($row->skp_nilai ?? 0),
            ],
            'pembayaran' => [
                'metode'         => (string) ($pay['metode_penyetoran'] ?? ''),
                'nama_penyetor'  => (string) ($pay['nama_penyetor'] ?? ''),
                'bank'           => (string) ($pay['nama_bank'] ?? ''),
                'nomor_rekening' => (string) ($pay['nomor_rekening'] ?? ''),
            ],
            'pendapatan' => $pendapatan->map(fn ($p): array => [
                'akun_kode'     => (string) $p->akun_kode,
                'akun_nama'     => (string) $p->akun_nama,
                'rekening_kode' => (string) $p->rekening_bank,
                'rekening_nama' => (string) $p->rekening_nama,
                'nominal'       => (float) $p->nominal,
            ])->all(),
        ]);
    }

    /**
     * POST /api/v1/penerimaan/stbp
     *
     * Body JSON: { action: create|update_status, ... }
     */
    public function store(Request $request)
    {
        $skpd   = Tenant::requireSkpd();
        $action = (string) $request->input('action', 'create');

        if ($action === 'update_status') {
            return $this->majukanStatus($request, $skpd);
        }

        if ($action !== 'create') {
            return $this->fail('Aksi tidak dikenali.', [], 422);
        }

        $nomor    = trim((string) $request->input('nomor_stbp', ''));
        $tanggal  = trim((string) $request->input('tanggal', ''));
        $skpId    = (int) $request->input('skp_daerah_id', 0);
        $payments = is_array($request->input('data_pembayaran')) ? (array) $request->input('data_pembayaran') : [];

        // SKP Daerah WAJIB dipilih, masih aktif, dan milik instansi yang sama.
        // Data STBP disamakan PENUH dengan SKP (server authority).
        if ($skpId <= 0) {
            return $this->fail(
                'SKP Daerah wajib dipilih sebagai dasar pembuatan STBP.',
                ['field' => 'skp_daerah_id'],
                422
            );
        }

        $skp = SkpDaerah::query()
            ->whereKey($skpId)
            ->where('status', 'aktif')
            ->where('skpd', $skpd)
            ->first();

        if (! $skp) {
            return $this->fail('SKP Daerah tidak valid / sudah terpakai. Pilih SKP Daerah yang masih aktif.', [], 422);
        }

        $kode            = (string) $skp->akun_kode;
        $nama            = (string) $skp->jenis_pajak;
        $jumlah          = (float) $skp->nilai_keputusan;
        $skpNomor        = (string) $skp->nomor_skp;
        $skpNamaPenyetor = (string) $skp->nama_penyetor;

        $uraian = trim((string) $skp->objek_pajak);
        $uraian = ($uraian !== '' ? $uraian . ' | ' : '') . 'SKP Daerah ' . $skpNomor;

        if ($jumlah <= 0) {
            return $this->fail('Nilai ketetapan SKP Daerah harus lebih dari 0.', ['field' => 'jumlah'], 422);
        }

        // Auto-generate nomor STBP bila kosong.
        if ($nomor === '') {
            $nomor = 'STBP-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        }

        if ($tanggal === '') {
            return $this->fail('Tanggal wajib diisi.', ['field' => 'tanggal'], 422);
        }

        try {
            $stbpId = $this->simpanStbp($request, $skpd, [
                'skp_daerah_id' => $skpId,
                'nomor'         => $nomor,
                'tanggal'       => $tanggal,
                'akun_kode'     => $kode,
                'akun_nama'     => $nama,
                'jumlah'        => $jumlah,
                'uraian'        => $uraian,
                'nama_penyetor' => $skpNamaPenyetor,
            ], $payments);
        } catch (Throwable $e) {
            report($e);

            return $this->fail('Gagal menyimpan STBP.', [], 500);
        }

        return $this->ok('STBP berhasil dibuat.', [
            'id'    => $stbpId,
            'nomor' => $nomor,
        ], 201);
    }

    /**
     * Simpan STBP beserta data turunannya dalam satu transaksi.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $payments
     */
    private function simpanStbp(Request $request, string $skpd, array $data, array $payments): int
    {
        return DB::transaction(function () use ($request, $skpd, $data, $payments): int {
            $id = (int) Stbp::create([
                'user_id'       => $request->user()?->id,
                'skp_daerah_id' => (int) $data['skp_daerah_id'],
                'skpd'          => $skpd,
                'nomor_stbp'    => (string) $data['nomor'],
                'tanggal'       => (string) $data['tanggal'],
                'akun_kode'     => (string) $data['akun_kode'],
                'akun_nama'     => (string) $data['akun_nama'],
                'jumlah'        => (float) $data['jumlah'],
                'uraian'        => (string) $data['uraian'],
                'status'        => 'belum_diverifikasi',
            ])->id;

            // Data pembayaran — nama penyetor mengikuti SKP Daerah bila terisi.
            foreach ($payments as $p) {
                DB::table('stbp_pembayaran')->insert([
                    'stbp_id'           => $id,
                    'metode_penyetoran' => trim((string) ($p['metode'] ?? 'non_tunai')),
                    'nama_penyetor'     => (string) $data['nama_penyetor'] !== ''
                        ? (string) $data['nama_penyetor']
                        : trim((string) ($p['nama_penyetor'] ?? '')),
                    'nama_bank'         => trim((string) ($p['bank'] ?? '')),
                    'nomor_rekening'    => trim((string) ($p['nomor_rekening'] ?? '')),
                ]);
            }

            // SKP yang dipakai tidak boleh dipakai lagi (mencegah data ganda).
            SkpDaerah::query()
                ->whereKey((int) $data['skp_daerah_id'])
                ->where('skpd', $skpd)
                ->update(['status' => 'terpakai']);

            // Pendapatan OTOMATIS dari SKP Daerah (bukan input manual klien).
            DB::table('stbp_pendapatan')->insert([
                'stbp_id'        => $id,
                'akun_kode'      => (string) $data['akun_kode'],
                'akun_nama'      => (string) $data['akun_nama'],
                'rekening_bank'  => '',
                'rekening_nama'  => '',
                'rekening_nomor' => '',
                'nominal'        => (float) $data['jumlah'],
            ]);

            return $id;
        });
    }

    /**
     * Majukan tahap status STBP: verifikasi -> otorisasi -> validasi.
     * Hanya boleh MAJU SATU tahap secara berurutan (identik dengan legacy).
     */
    private function majukanStatus(Request $request, string $skpd)
    {
        $id     = (int) $request->input('id', 0);
        $target = (string) $request->input('status', '');

        if ($id <= 0) {
            return $this->fail('ID tidak valid.', [], 422);
        }

        if (! in_array($target, self::STAGES, true)) {
            return $this->fail('Status tahap tidak valid.', [], 422);
        }

        $sekarang = Stbp::query()->whereKey($id)->where('skpd', $skpd)->value('status');

        if ($sekarang === null) {
            return $this->fail('STBP tidak ditemukan atau bukan milik instansi Anda.', [], 404);
        }

        $curIdx = array_search((string) $sekarang, self::STAGES, true);
        $tgtIdx = array_search($target, self::STAGES, true);

        if ($tgtIdx !== $curIdx + 1) {
            return $this->fail('Tahap tidak boleh melompat. Majukan status secara berurutan.', [], 422);
        }

        Stbp::query()->whereKey($id)->where('skpd', $skpd)->update(['status' => $target]);

        return $this->ok('Status STBP berhasil dimajukan ke tahap berikutnya.', [
            'id'     => $id,
            'status' => $target,
        ]);
    }
}
