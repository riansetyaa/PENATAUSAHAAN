<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Belanja;

use App\Http\Controllers\Api\V1\Belanja\Concerns\FiltersBelanja;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SPP (Surat Permintaan Pembayaran) — daftar + rincian.
 *
 * Diporting dari blok `action=spp_list` pada api/belanja.php.
 *
 * Catatan: daftar SPP memakai LEFT JOIN ke beberapa tabel sehingga syarat
 * tenant WAJIB berkualifikasi `s.skpd` (kolom `skpd` juga ada di SPD).
 */
class SppController extends Controller
{
    use FiltersBelanja;

    /**
     * GET /api/v1/belanja/spp?status=
     */
    public function index(Request $request)
    {
        $skpd   = $this->skpd();
        $status = $this->status($request);

        $sisaSpp = "COALESCE((SELECT SUM(x.jumlah) FROM spp x WHERE x.spd_id = s.spd_id AND x.status <> 'ditolak'), 0)";

        $query = DB::table('spp as s')
            ->leftJoin('spd as d', 'd.id', '=', 's.spd_id')
            ->leftJoin('rekanan as r', 'r.id', '=', 's.rekanan_id')
            ->leftJoin('lpj as l', 'l.id', '=', 's.lpj_id')
            ->leftJoin('pengajuan_tu as t', 't.id', '=', 's.pengajuan_tu_id')
            ->select([
                's.*',
                'd.nomor_spd',
                'd.tanggal as spd_tanggal',
                'd.jumlah as spd_jumlah',
            ])
            ->selectRaw("(d.jumlah - {$sisaSpp}) AS spd_sisa")
            ->addSelect([
                'r.nama_rekanan',
                'l.nomor_lpj',
                't.nomor_pengajuan',
            ])
            ->where('s.skpd', $skpd);

        $this->filterStatus($query, 's.status', $status);

        $rows = $query->orderByDesc('s.tanggal')->orderByDesc('s.id')->get();

        // Lampirkan rincian (spp_detail) per SPP.
        $ids = $rows->map(fn ($r): int => (int) $r->id)->all();

        $details = [];

        if ($ids !== []) {
            foreach (DB::table('spp_detail')->whereIn('spp_id', $ids)->orderBy('id')->get() as $d) {
                $details[(int) $d->spp_id][] = [
                    'kode_rekening' => (string) $d->kode_rekening,
                    'uraian'        => (string) $d->uraian,
                    'jumlah'        => (float) $d->jumlah,
                ];
            }
        }

        foreach ($rows as $r) {
            $r->details = $details[(int) $r->id] ?? [];
        }

        return $this->ok('OK', ['data' => $rows]);
    }

    /**
     * POST /api/v1/belanja/spp  (action=spp_create)
     *
     * Per jenis SPP: LS Gaji, LS Barang & Jasa, UP, GU (wajib LPJ), TU (wajib
     * pengajuan TU). Bila ada baris rincian, jumlah SPP diambil dari total
     * rincian (bukan input klien).
     */
    public function store(Request $request)
    {
        $skpd      = $this->skpd();
        $tanggal   = trim((string) $request->input('tanggal', ''));
        $jenis     = trim((string) $request->input('jenis_spp', ''));
        $spdId     = (int) $request->input('spd_id', 0);
        $rekId     = (int) $request->input('rekanan_id', 0);
        $keperluan = trim((string) $request->input('keperluan', ''));
        $jumlah    = (float) $request->input('jumlah', 0);
        $lpjId     = (int) $request->input('lpj_id', 0);
        $ptuId     = (int) $request->input('pengajuan_tu_id', 0);

        // Rincian detail (kode rekening belanja + uraian + jumlah).
        $detailItems = [];
        $detailTotal = 0.0;

        foreach ((array) $request->input('detail', []) as $row) {
            $kode   = trim((string) ($row['kode_rekening'] ?? ''));
            $uraian = trim((string) ($row['uraian'] ?? ''));
            $nilai  = (float) ($row['jumlah'] ?? 0);

            if ($kode === '' && $uraian === '') {
                continue;
            }

            if ($nilai <= 0) {
                continue;
            }

            $detailItems[] = ['kode_rekening' => $kode, 'uraian' => $uraian, 'jumlah' => $nilai];
            $detailTotal += $nilai;
        }

        if ($detailItems !== []) {
            $jumlah = $detailTotal;
        }

        if ($tanggal === '') {
            return $this->fail('Tanggal SPP wajib diisi.', ['field' => 'tanggal'], 422);
        }

        if ($spdId <= 0) {
            return $this->fail('Pilih SPD.', ['field' => 'spd_id'], 422);
        }

        if ($jumlah <= 0) {
            return $this->fail('Jumlah harus lebih dari 0.', ['field' => 'jumlah'], 422);
        }

        if ($jenis === 'GU' && $lpjId <= 0) {
            return $this->fail('Pilih LPJ untuk SPP GU.', ['field' => 'lpj_id'], 422);
        }

        if ($jenis === 'TU' && $ptuId <= 0) {
            return $this->fail('Pilih Pengajuan TU untuk SPP TU.', ['field' => 'pengajuan_tu_id'], 422);
        }

        // SPD harus terotorisasi & milik instansi yang sama.
        $spdValid = DB::table('spd')
            ->where('id', $spdId)
            ->where('status', 'sudah_otorisasi')
            ->where('skpd', $skpd)
            ->exists();

        if (! $spdValid) {
            return $this->fail('SPD tidak valid / belum diotorisasi / bukan milik instansi Anda.', [], 422);
        }

        // Sisa SPD = jumlah SPD - total SPP non-ditolak.
        $sisa = (float) (DB::table('spd as s')
            ->where('s.id', $spdId)
            ->selectRaw(
                "s.jumlah - COALESCE((SELECT SUM(x.jumlah) FROM spp x WHERE x.spd_id = s.id AND x.status <> 'ditolak'), 0) AS sisa"
            )
            ->value('sisa') ?? 0);

        if ($jumlah > $sisa + 0.001) {
            return $this->fail(
                'Jumlah SPP melebihi sisa SPD (sisa: Rp ' . number_format($sisa, 0, ',', '.') . ').',
                ['field' => 'jumlah'],
                422
            );
        }

        $potongan = $this->hitungPotonganPajak((array) $request->input('potongan', []), 'potongan', $jumlah);
        $pajak    = $this->hitungPotonganPajak((array) $request->input('pajak', []), 'pajak', $jumlah);

        $totPotongan = 0.0;
        $totPajak    = 0.0;

        foreach ($potongan as $p) {
            $totPotongan += $p['nilai'];
        }

        foreach ($pajak as $p) {
            $totPajak += $p['nilai'];
        }

        $nomor = $this->nomorDokumen('SPP');

        $sppId = $this->simpanSpp($request, $skpd, $nomor, [
            'tanggal'   => $tanggal,
            'jenis'     => $jenis,
            'spd_id'    => $spdId,
            'rekanan_id' => $rekId,
            'lpj_id'    => $lpjId,
            'pengajuan_tu_id' => $ptuId,
            'keperluan' => $keperluan,
            'jumlah'    => $jumlah,
            'tot_potongan' => $totPotongan,
            'tot_pajak' => $totPajak,
            'potongan'  => $potongan,
            'pajak'     => $pajak,
            'detail'    => $detailItems,
        ]);

        return $this->ok('SPP berhasil dibuat.', ['id' => $sppId, 'nomor_spp' => $nomor], 201);
    }

    /**
     * POST /api/v1/belanja/spp/{id}/verifikasi  (action=spp_verifikasi)
     *
     * Setuju -> status menjadi sudah_diverifikasi DAN SPM otomatis dibuat
     * dengan nilai netto (jumlah - potongan - pajak).
     */
    public function verifikasi(Request $request, int $id)
    {
        $skpd   = $this->skpd();
        $setuju = (bool) $request->input('setuju', false);

        if ($id <= 0) {
            return $this->fail('ID tidak valid.', [], 422);
        }

        $hasil = DB::transaction(function () use ($request, $id, $skpd, $setuju): array {
            $berubah = $this->ubahStatus(
                'spp',
                $id,
                $skpd,
                'belum_diverifikasi',
                $setuju ? 'sudah_diverifikasi' : 'ditolak'
            );

            if (! $berubah) {
                return ['ok' => false, 'spm' => null];
            }

            if (! $setuju) {
                return ['ok' => true, 'spm' => null];
            }

            $spp   = DB::table('spp')->where('id', $id)->first();
            $netto = (float) $spp->jumlah - (float) $spp->total_potongan - (float) $spp->total_pajak;
            $nomor = $this->nomorDokumen('SPM');

            DB::table('spm')->insert([
                'user_id'   => $request->user()?->id,
                'skpd'      => (string) $spp->skpd,
                'nomor_spm' => $nomor,
                'tanggal'   => (string) $spp->tanggal,
                'spp_id'    => (int) $spp->id,
                'jumlah'    => $netto,
                'status'    => 'belum_disetujui',
            ]);

            return ['ok' => true, 'spm' => $nomor];
        });

        if (! $hasil['ok']) {
            return $this->fail('SPP tidak ditemukan / bukan milik instansi Anda.', [], 404);
        }

        if ($hasil['spm'] === null) {
            return $this->ok('SPP ditolak.');
        }

        return $this->ok('SPP diverifikasi & SPM berhasil dibuat.', [
            'id'        => $id,
            'nomor_spm' => $hasil['spm'],
        ]);
    }

    /**
     * Normalisasi baris potongan/pajak: bila nilai_persen > 0, nilai dihitung
     * dari persen × jumlah SPP (dibulatkan 2 desimal).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function hitungPotonganPajak(array $rows, string $jenis, float $jumlah): array
    {
        $hasil = [];

        foreach ($rows as $row) {
            $nama = trim((string) ($row['nama'] ?? ''));

            if ($nama === '') {
                continue;
            }

            $persen = (float) ($row['nilai_persen'] ?? 0);
            $nilai  = $persen > 0 ? round($jumlah * $persen / 100, 2) : (float) ($row['nilai'] ?? 0);

            $hasil[] = [
                'jenis'       => $jenis,
                'nama'        => $nama,
                'persen'      => $persen,
                'nilai'       => $nilai,
                'id_billing'  => trim((string) ($row['id_billing'] ?? '')),
                'tgl_billing' => trim((string) ($row['tgl_billing'] ?? '')),
                'ntpn'        => trim((string) ($row['ntpn'] ?? '')),
                'tgl_ntpn'    => trim((string) ($row['tgl_ntpn'] ?? '')),
            ];
        }

        return $hasil;
    }

    /**
     * Simpan SPP beserta potongan/pajak dan rincian dalam satu transaksi.
     *
     * @param  array<string, mixed>  $data
     */
    private function simpanSpp(Request $request, string $skpd, string $nomor, array $data): int
    {
        return DB::transaction(function () use ($request, $skpd, $nomor, $data): int {
            $id = (int) DB::table('spp')->insertGetId([
                'user_id'         => $request->user()?->id,
                'skpd'            => $skpd,
                'nomor_spp'       => $nomor,
                'tanggal'         => (string) $data['tanggal'],
                'jenis_spp'       => (string) $data['jenis'],
                'spd_id'          => (int) $data['spd_id'],
                'rekanan_id'      => ((int) $data['rekanan_id']) ?: null,
                'lpj_id'          => ((int) $data['lpj_id']) ?: null,
                'pengajuan_tu_id' => ((int) $data['pengajuan_tu_id']) ?: null,
                'keperluan'       => (string) $data['keperluan'],
                'jumlah'          => (float) $data['jumlah'],
                'total_potongan'  => (float) $data['tot_potongan'],
                'total_pajak'     => (float) $data['tot_pajak'],
                'status'          => 'belum_diverifikasi',
            ]);

            foreach (array_merge($data['potongan'], $data['pajak']) as $r) {
                DB::table('spp_potongan_pajak')->insert([
                    'skpd'         => $skpd,
                    'spp_id'       => $id,
                    'jenis'        => $r['jenis'],
                    'nama'         => $r['nama'],
                    'nilai_persen' => $r['persen'],
                    'nilai'        => $r['nilai'],
                    'id_billing'   => $r['id_billing'],
                    'tgl_billing'  => $r['tgl_billing'] !== '' ? $r['tgl_billing'] : null,
                    'ntpn'         => $r['ntpn'],
                    'tgl_ntpn'     => $r['tgl_ntpn'] !== '' ? $r['tgl_ntpn'] : null,
                ]);
            }

            foreach ($data['detail'] as $d) {
                DB::table('spp_detail')->insert([
                    'skpd'          => $skpd,
                    'spp_id'        => $id,
                    'kode_rekening' => $d['kode_rekening'],
                    'uraian'        => $d['uraian'],
                    'jumlah'        => $d['jumlah'],
                ]);
            }

            return $id;
        });
    }
}
