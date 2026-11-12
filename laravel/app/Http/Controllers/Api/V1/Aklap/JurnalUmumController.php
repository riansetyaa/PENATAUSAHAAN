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
 * AKLAP — Jurnal Umum (double-entry manual) + saran jurnal dari dokumen.
 *
 * Diporting dari blok `action=jurnal_list`, `jurnal_simpan`, `jurnal_hapus`,
 * `jurnal_status`, `saran_dokumen`, dan `saran_jurnal` pada
 * peta.simtkd.com/api/akuntansi.php.
 *
 * Aturan penting (sama dengan legacy):
 *  - Jurnal wajib seimbang (Debet = Kredit) dan minimal 2 baris.
 *  - Bila memakai dokumen sumber (STS/SP2D), dokumen harus valid & belum
 *    pernah dijurnal; penandanya (`sudah_dijurnal`) diisi saat simpan dan
 *    dibebaskan saat jurnal dihapus/dipindah sumbernya.
 *  - Hanya jurnal `sudah_approve` yang masuk laporan (buku besar, LRA, dll).
 */
class JurnalUmumController extends Controller
{
    use CoaLra;
    use TanggalValid;

    /** Kode akun bawaan untuk template saran jurnal (chart of accounts ringkas). */
    private const KAS_PENERIMAAN  = '1.1.1.01';
    private const KAS_PENGELUARAN = '1.1.1.02';
    private const KAS_BUD         = '1.1.3.01';
    private const BLJ_GAJI        = '5.1.1.01';
    private const BLJ_BJ          = '5.1.2.01';
    private const BLJ_MODAL       = '5.1.3.01';
    private const UTANG_PAJAK     = '2.1.1.01';
    private const UTANG_POTONGAN  = '2.1.1.02';

    /**
     * GET /api/v1/aklap/jurnal-umum?dari=&akhir=&q=&status=
     */
    public function index(Request $request)
    {
        $skpd = Tenant::requireSkpd();

        $dari  = $this->tanggalValid((string) $request->query('dari', ''));
        $akhir = $this->tanggalValid((string) $request->query('akhir', ''));
        $q     = trim((string) $request->query('q', ''));
        $st    = (string) $request->query('status', '');

        $query = DB::table('jurnal_umum')->where('skpd', $skpd);

        if ($dari !== '') {
            $query->where('tanggal', '>=', $dari);
        }

        if ($akhir !== '') {
            $query->where('tanggal', '<=', $akhir);
        }

        if ($st !== '' && in_array($st, ['draft', 'sudah_approve', 'ditolak'], true)) {
            $query->where('status', $st);
        }

        if ($q !== '') {
            $query->where(function ($builder) use ($q): void {
                $builder->where('nomor_jurnal', 'like', '%' . $q . '%')
                    ->orWhere('uraian', 'like', '%' . $q . '%');
            });
        }

        $jurnal = $query->orderBy('tanggal')->orderBy('id')->limit(500)->get();

        $rows = [];

        foreach ($jurnal as $r) {
            $baris   = [];
            $totalD  = 0.0;
            $totalK  = 0.0;

            $detail = DB::table('jurnal_umum_detail')
                ->where('jurnal_id', (int) $r->id)
                ->orderByDesc('posisi')
                ->orderBy('id')
                ->get(['kode_akun', 'nama_akun', 'posisi', 'jumlah']);

            foreach ($detail as $d) {
                $baris[] = [
                    'kode_akun' => (string) $d->kode_akun,
                    'nama_akun' => (string) $d->nama_akun,
                    'posisi'    => (string) $d->posisi,
                    'jumlah'    => (float) $d->jumlah,
                ];

                if ((string) $d->posisi === 'debet') {
                    $totalD += (float) $d->jumlah;
                } else {
                    $totalK += (float) $d->jumlah;
                }
            }

            $rows[] = [
                'id'           => (int) $r->id,
                'nomor_jurnal' => (string) $r->nomor_jurnal,
                'tanggal'      => (string) ($r->tanggal ?? ''),
                'uraian'       => (string) $r->uraian,
                'sumber_tipe'  => (string) $r->sumber_tipe,
                'sumber_id'    => (int) ($r->sumber_id ?? 0),
                'status'       => (string) $r->status,
                'total_debet'  => round($totalD, 2),
                'total_kredit' => round($totalK, 2),
                'detail'       => $baris,
            ];
        }

        return $this->okData(['data' => $rows, 'total' => count($rows)]);
    }

    /**
     * POST /api/v1/aklap/jurnal-umum  (action=jurnal_simpan)
     *
     * Simpan/ubah jurnal umum. `detail` dikirim sebagai JSON string
     * `[{kode_akun, posisi, jumlah}]` (boleh juga array bila klien
     * mengirim application/json).
     */
    public function store(Request $request)
    {
        $skpd = Tenant::requireSkpd();

        $id         = (int) $request->input('id', 0);
        $tanggal    = trim((string) $request->input('tanggal', ''));
        $uraian     = trim((string) $request->input('uraian', ''));
        $sumberTipe = (string) $request->input('sumber_tipe', 'manual');
        $sumberId   = (int) $request->input('sumber_id', 0);
        $detailMentah = $request->input('detail', '[]');

        if (is_string($detailMentah)) {
            $detailMentah = json_decode($detailMentah, true);
        }

        if (! in_array($sumberTipe, ['manual', 'sts', 'sp2d'], true)) {
            $sumberTipe = 'manual';
        }

        if ($this->tanggalValid($tanggal) === '') {
            return $this->fail('Tanggal jurnal wajib diisi (format YYYY-MM-DD).', [], 422);
        }

        if (! is_array($detailMentah) || count($detailMentah) < 2) {
            return $this->fail('Jurnal umum minimal memiliki 2 baris (Debet dan Kredit).', [], 422);
        }

        [$baris, $totalD, $totalK] = $this->normalisasiBaris($detailMentah, $skpd);

        if (count($baris) < 2) {
            return $this->fail('Setiap baris wajib memiliki kode akun, posisi D/K, dan jumlah > 0.', [], 422);
        }

        if (abs($totalD - $totalK) > 0.01) {
            return $this->fail(
                'Jurnal TIDAK seimbang: Total Debet Rp ' . number_format($totalD, 2, ',', '.')
                    . ' != Total Kredit Rp ' . number_format($totalK, 2, ',', '.') . '.',
                [],
                422
            );
        }

        // Verifikasi dokumen sumber (STS / SP2D) bila memakai template.
        if ($sumberTipe !== 'manual' && $sumberId > 0) {
            $tabel   = $sumberTipe === 'sts' ? 'sts' : 'sp2d';
            $kondisi = $sumberTipe === 'sts' ? 'aktif' : 'sudah_dicairkan';

            $tersedia = DB::table($tabel)
                ->where('id', $sumberId)
                ->where('status', $kondisi)
                ->where('skpd', $skpd)
                ->whereRaw('COALESCE(sudah_dijurnal, 0) = 0')
                ->exists();

            if (! $tersedia) {
                return $this->fail('Dokumen sumber tidak tersedia / sudah pernah dibuatkan jurnal.', [], 409);
            }
        } else {
            $sumberTipe = 'manual';
            $sumberId   = 0;
        }

        $hasil = DB::transaction(function () use ($request, $skpd, $id, $tanggal, $uraian, $sumberTipe, $sumberId, $baris): array {
            if ($id > 0) {
                $lama = DB::table('jurnal_umum')->where('id', $id)->where('skpd', $skpd)->first();

                if (! $lama) {
                    return ['ok' => false, 'id' => 0, 'pesan' => 'Jurnal tidak ditemukan.'];
                }

                DB::table('jurnal_umum_detail')->where('jurnal_id', $id)->delete();

                DB::table('jurnal_umum')->where('id', $id)->update([
                    'tanggal'     => $tanggal,
                    'uraian'      => $uraian,
                    'sumber_tipe' => $sumberTipe,
                    'sumber_id'   => $sumberId ?: null,
                ]);

                // Bebaskan penanda dokumen sumber lama bila sumbernya berubah.
                $tipeLama = (string) ($lama->sumber_tipe ?? '');
                $idLama   = (int) ($lama->sumber_id ?? 0);

                if ($tipeLama !== '' && $tipeLama !== 'manual' && $idLama !== $sumberId) {
                    DB::table($tipeLama)->where('id', $idLama)->update(['sudah_dijurnal' => 0]);
                }

                $jurnalId = $id;
                $pesan    = 'Jurnal diperbarui.';
            } else {
                $jurnalId = (int) DB::table('jurnal_umum')->insertGetId([
                    'skpd'        => $skpd,
                    'user_id'     => $request->user()?->id,
                    'tanggal'     => $tanggal,
                    'uraian'      => $uraian,
                    'sumber_tipe' => $sumberTipe,
                    'sumber_id'   => $sumberId ?: null,
                ]);

                $nomor = 'JU-' . date('Ym') . '-' . str_pad((string) $jurnalId, 4, '0', STR_PAD_LEFT);

                DB::table('jurnal_umum')->where('id', $jurnalId)->update(['nomor_jurnal' => $nomor]);

                $pesan = 'Jurnal ' . $nomor . ' tersimpan.';
            }

            foreach ($baris as $b) {
                DB::table('jurnal_umum_detail')->insert([
                    'jurnal_id' => $jurnalId,
                    'skpd'      => $skpd,
                    'kode_akun' => $b['kode_akun'],
                    'nama_akun' => $b['nama_akun'],
                    'posisi'    => $b['posisi'],
                    'jumlah'    => $b['jumlah'],
                ]);
            }

            if ($sumberTipe !== 'manual' && $sumberId > 0) {
                DB::table($sumberTipe)->where('id', $sumberId)->update(['sudah_dijurnal' => 1]);
            }

            return ['ok' => true, 'id' => $jurnalId, 'pesan' => $pesan];
        });

        if (! $hasil['ok']) {
            return $this->fail($hasil['pesan'], [], 404);
        }

        return $this->ok($hasil['pesan'], ['id' => $hasil['id']]);
    }

    /**
     * Normalisasi baris jurnal: buang baris tak lengkap, ambil nama akun,
     * bulatkan jumlah, lalu hitung total debet/kredit.
     *
     * @param  array<int, mixed>  $detail
     * @return array{0: array<int, array<string, mixed>>, 1: float, 2: float}
     */
    private function normalisasiBaris(array $detail, string $skpd): array
    {
        $baris  = [];
        $totalD = 0.0;
        $totalK = 0.0;

        foreach ($detail as $d) {
            if (! is_array($d)) {
                continue;
            }

            $kode   = trim((string) ($d['kode_akun'] ?? ''));
            $posisi = strtolower(trim((string) ($d['posisi'] ?? '')));
            $jumlah = (float) ($d['jumlah'] ?? 0);

            if ($kode === '' || $jumlah <= 0) {
                continue;
            }

            if (! in_array($posisi, ['debet', 'kredit'], true)) {
                continue;
            }

            $baris[] = [
                'kode_akun' => $kode,
                'nama_akun' => $this->akunNamaOf($skpd, $kode),
                'posisi'    => $posisi,
                'jumlah'    => round($jumlah, 2),
            ];

            if ($posisi === 'debet') {
                $totalD += $jumlah;
            } else {
                $totalK += $jumlah;
            }
        }

        return [$baris, $totalD, $totalK];
    }

    /**
     * POST /api/v1/aklap/jurnal-umum/hapus  (action=jurnal_hapus)
     *
     * Menghapus jurnal beserta rinciannya dan membebaskan penanda dokumen
     * sumber agar dapat dijurnal ulang.
     */
    public function destroy(Request $request)
    {
        $skpd = Tenant::requireSkpd();
        $id   = (int) $request->input('id', 0);

        if ($id <= 0) {
            return $this->fail('ID jurnal tidak valid.', [], 422);
        }

        $row = DB::table('jurnal_umum')->where('id', $id)->where('skpd', $skpd)->first();

        if (! $row) {
            return $this->fail('Jurnal tidak ditemukan.', [], 404);
        }

        DB::transaction(function () use ($id, $row): void {
            DB::table('jurnal_umum_detail')->where('jurnal_id', $id)->delete();
            DB::table('jurnal_umum')->where('id', $id)->delete();

            $tipe = (string) ($row->sumber_tipe ?? '');
            $sid  = (int) ($row->sumber_id ?? 0);

            if ($tipe !== '' && $tipe !== 'manual' && $sid > 0) {
                DB::table($tipe)->where('id', $sid)->update(['sudah_dijurnal' => 0]);
            }
        });

        return $this->ok('Jurnal dihapus.');
    }

    /**
     * POST /api/v1/aklap/jurnal-umum/status  (action=jurnal_status)
     *
     * Approve / tolak / kembalikan ke draft. Hanya jurnal `sudah_approve`
     * yang masuk laporan (buku besar, neraca saldo, LRA).
     */
    public function status(Request $request)
    {
        $skpd   = Tenant::requireSkpd();
        $id     = (int) $request->input('id', 0);
        $status = (string) $request->input('status', '');

        if ($id <= 0 || ! in_array($status, ['sudah_approve', 'ditolak', 'draft'], true)) {
            return $this->fail('Parameter status tidak valid.', [], 422);
        }

        $berubah = DB::table('jurnal_umum')
            ->where('id', $id)
            ->where('skpd', $skpd)
            ->update(['status' => $status]);

        $label = [
            'sudah_approve' => 'di-approve',
            'ditolak'       => 'ditolak',
            'draft'         => 'dikembalikan ke draft',
        ][$status];

        return $this->ok($berubah > 0 ? 'Jurnal ' . $label . '.' : 'Jurnal tidak ditemukan.');
    }

    /**
     * GET /api/v1/aklap/saran-dokumen
     *
     * STS & SP2D yang siap dibuatkan jurnal (status valid, belum pernah
     * dijurnal). Kegagalan satu sumber tidak mematikan sumber lainnya.
     */
    public function saranDokumen()
    {
        $skpd = Tenant::requireSkpd();

        $sts  = [];
        $sp2d = [];

        try {
            foreach (DB::table('sts')
                ->where('skpd', $skpd)
                ->where('status', 'aktif')
                ->whereRaw('COALESCE(sudah_dijurnal, 0) = 0')
                ->orderByDesc('tanggal_sts')
                ->orderByDesc('id')
                ->limit(100)
                ->get(['id', 'nomor_sts', 'tanggal_sts', 'total']) as $r) {
                $sts[] = [
                    'id'      => (int) $r->id,
                    'nomor'   => (string) $r->nomor_sts,
                    'tanggal' => (string) $r->tanggal_sts,
                    'jumlah'  => (float) $r->total,
                ];
            }
        } catch (Throwable) {
            // sumber STS tidak tersedia -> lewati (perilaku legacy)
        }

        try {
            foreach (DB::table('sp2d')
                ->leftJoin('spm', 'spm.id', '=', 'sp2d.spm_id')
                ->leftJoin('spp', 'spp.id', '=', 'spm.spp_id')
                ->where('sp2d.skpd', $skpd)
                ->where('sp2d.status', 'sudah_dicairkan')
                ->whereRaw('COALESCE(sp2d.sudah_dijurnal, 0) = 0')
                ->orderByDesc('sp2d.tanggal')
                ->orderByDesc('sp2d.id')
                ->limit(100)
                ->get([
                    'sp2d.id as id',
                    'sp2d.nomor_sp2d as nomor_sp2d',
                    'sp2d.tanggal as tanggal',
                    'sp2d.jumlah as jumlah',
                    'spp.jenis_spp as jenis_spp',
                ]) as $r) {
                $sp2d[] = [
                    'id'        => (int) $r->id,
                    'nomor'     => (string) $r->nomor_sp2d,
                    'tanggal'   => (string) ($r->tanggal ?? ''),
                    'jumlah'    => (float) $r->jumlah,
                    'jenis_spp' => (string) ($r->jenis_spp ?? ''),
                ];
            }
        } catch (Throwable) {
            // sumber SP2D tidak tersedia -> lewati (perilaku legacy)
        }

        return $this->okData(['sts' => $sts, 'sp2d' => $sp2d]);
    }

    /**
     * GET /api/v1/aklap/saran-jurnal?sumber=sts|sp2d&id=
     *
     * Template baris Debet/Kredit dari dokumen (dapat diedit lalu dikirim ke
     * jurnal_simpan).
     */
    public function saranJurnal(Request $request)
    {
        $skpd   = Tenant::requireSkpd();
        $sumber = strtolower((string) $request->query('sumber', ''));
        $id     = (int) $request->query('id', 0);

        if ($id <= 0 || ! in_array($sumber, ['sts', 'sp2d'], true)) {
            return $this->fail('Sumber dan ID dokumen wajib dipilih.', [], 422);
        }

        return $sumber === 'sts' ? $this->saranDariSts($skpd, $id) : $this->saranDariSp2d($skpd, $id);
    }

    /**
     * Template jurnal dari STS: D Kas di Bendahara Penerimaan (total),
     * K pendapatan per rincian (fallback Pendapatan Lain-lain bila kosong).
     */
    private function saranDariSts(string $skpd, int $id)
    {
        $sts = DB::table('sts')
            ->where('id', $id)
            ->where('skpd', $skpd)
            ->where('status', 'aktif')
            ->first();

        if (! $sts) {
            return $this->fail('STS tidak ditemukan / tidak aktif.', [], 404);
        }

        $total = (float) $sts->total;
        $nomor = (string) $sts->nomor_sts;

        $rows = [[
            'kode_akun'  => self::KAS_PENERIMAAN,
            'posisi'     => 'debet',
            'jumlah'     => $total,
            'saran_nama' => $this->akunNamaOf($skpd, self::KAS_PENERIMAAN),
        ]];

        $sumK = 0.0;

        foreach (DB::table('sts_detail')
            ->where('sts_id', $id)
            ->where('jumlah', '>', 0)
            ->orderBy('id')
            ->get(['akun_kode', 'akun_nama', 'jumlah']) as $d) {
            $kode = trim((string) $d->akun_kode);

            if ($kode === '') {
                $kode = '4.1.4.01';
            }

            $nama = (string) $d->akun_nama;

            $rows[] = [
                'kode_akun'  => $kode,
                'posisi'     => 'kredit',
                'jumlah'     => (float) $d->jumlah,
                'saran_nama' => $nama !== '' ? $nama : $this->akunNamaOf($skpd, $kode),
            ];

            $sumK += (float) $d->jumlah;
        }

        if (count($rows) === 1) {
            // Tidak ada rincian: kredit pendapatan lain-lain senilai total.
            $rows[] = [
                'kode_akun'  => '4.1.4.01',
                'posisi'     => 'kredit',
                'jumlah'     => $total,
                'saran_nama' => $this->akunNamaOf($skpd, '4.1.4.01'),
            ];
        } elseif (abs($sumK - $total) > 0.01) {
            // Selisih rincian vs total -> diserap ke pendapatan lain-lain.
            $rows[] = [
                'kode_akun'  => '4.1.4.01',
                'posisi'     => 'kredit',
                'jumlah'     => round($total - $sumK, 2),
                'saran_nama' => $this->akunNamaOf($skpd, '4.1.4.01'),
            ];
        }

        return $this->okData([
            'sumber'    => 'sts',
            'sumber_id' => $id,
            'nomor'     => $nomor,
            'tanggal'   => (string) $sts->tanggal_sts,
            'uraian'    => 'Penerimaan disetor ke Kas Daerah - STS No. ' . $nomor,
            'detail'    => $rows,
        ]);
    }

    /**
     * Template jurnal dari SP2D.
     *
     * LS  : D Belanja (bruto) + K Utang Pajak/Potongan + K Kas di BUD (neto)
     * UP/GU/TU : D Kas di Bendahara Pengeluaran + K Kas di BUD
     */
    private function saranDariSp2d(string $skpd, int $id)
    {
        $sp2d = DB::table('sp2d')
            ->leftJoin('spm', 'spm.id', '=', 'sp2d.spm_id')
            ->leftJoin('spp', 'spp.id', '=', 'spm.spp_id')
            ->where('sp2d.id', $id)
            ->where('sp2d.skpd', $skpd)
            ->where('sp2d.status', 'sudah_dicairkan')
            ->first([
                'sp2d.nomor_sp2d as nomor_sp2d',
                'sp2d.tanggal as tanggal',
                'sp2d.jumlah as jumlah',
                'spp.jenis_spp as jenis_spp',
                'spp.id as spp_id',
            ]);

        if (! $sp2d) {
            return $this->fail('SP2D tidak ditemukan / belum dicairkan.', [], 404);
        }

        $jumlah = (float) $sp2d->jumlah;
        $jenis  = (string) ($sp2d->jenis_spp ?? '');
        $nomor  = (string) $sp2d->nomor_sp2d;
        $j      = strtolower($jenis);

        if (strpos($j, 'ls') !== false && strpos($j, 'gaji') !== false) {
            $kodeBelanja = self::BLJ_GAJI;
        } elseif (strpos($j, 'modal') !== false) {
            $kodeBelanja = self::BLJ_MODAL;
        } else {
            $kodeBelanja = self::BLJ_BJ;
        }

        $rows = [];

        if (strpos($j, 'ls') !== false) {
            $rows[] = [
                'kode_akun'  => $kodeBelanja,
                'posisi'     => 'debet',
                'jumlah'     => $jumlah,
                'saran_nama' => $this->akunNamaOf($skpd, $kodeBelanja),
            ];

            $neto = $jumlah;

            if ((int) ($sp2d->spp_id ?? 0) > 0) {
                foreach (DB::table('spp_potongan_pajak')
                    ->where('spp_id', (int) $sp2d->spp_id)
                    ->where('nilai', '>', 0)
                    ->orderBy('id')
                    ->get(['jenis', 'nama', 'nilai']) as $p) {
                    $kode = ((string) $p->jenis === 'pajak') ? self::UTANG_PAJAK : self::UTANG_POTONGAN;

                    $rows[] = [
                        'kode_akun'  => $kode,
                        'posisi'     => 'kredit',
                        'jumlah'     => (float) $p->nilai,
                        'saran_nama' => $this->akunNamaOf($skpd, $kode),
                    ];

                    $neto -= (float) $p->nilai;
                }
            }

            $rows[] = [
                'kode_akun'  => self::KAS_BUD,
                'posisi'     => 'kredit',
                'jumlah'     => round($neto, 2),
                'saran_nama' => $this->akunNamaOf($skpd, self::KAS_BUD),
            ];
        } else {
            $rows[] = [
                'kode_akun'  => self::KAS_PENGELUARAN,
                'posisi'     => 'debet',
                'jumlah'     => $jumlah,
                'saran_nama' => $this->akunNamaOf($skpd, self::KAS_PENGELUARAN),
            ];

            $rows[] = [
                'kode_akun'  => self::KAS_BUD,
                'posisi'     => 'kredit',
                'jumlah'     => $jumlah,
                'saran_nama' => $this->akunNamaOf($skpd, self::KAS_BUD),
            ];
        }

        return $this->okData([
            'sumber'    => 'sp2d',
            'sumber_id' => $id,
            'nomor'     => $nomor,
            'tanggal'   => (string) ($sp2d->tanggal ?? ''),
            'uraian'    => 'Pencairan ' . ($jenis !== '' ? $jenis : 'Belanja') . ' - SP2D No. ' . $nomor,
            'detail'    => $rows,
        ]);
    }
}
