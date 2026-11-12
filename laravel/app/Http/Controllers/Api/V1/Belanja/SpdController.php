<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Belanja;

use App\Http\Controllers\Api\V1\Belanja\Concerns\FiltersBelanja;
use App\Http\Controllers\Controller;
use App\Support\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SPD (Surat Penyediaan Dana).
 *
 * Diporting dari blok `action=spd_list` dan `action=spd_otor_list` pada
 * api/belanja.php.
 */
class SpdController extends Controller
{
    use FiltersBelanja;

    /**
     * Sisa SPD = jumlah SPD dikurangi total SPP non-ditolak yang memakainya.
     */
    private const SISA_SPD = "COALESCE((SELECT SUM(x.jumlah) FROM spp x WHERE x.spd_id = %s AND x.status <> 'ditolak'), 0)";

    /**
     * GET /api/v1/belanja/spd?status=
     */
    public function index(Request $request)
    {
        $skpd   = $this->skpd();
        $status = $this->status($request);

        $query = DB::table('spd as s')->select('s.*')->where('s.skpd', $skpd);

        $this->filterStatus($query, 's.status', $status);

        $rows = $query->orderByDesc('s.tanggal')->orderByDesc('s.id')->get();

        return $this->ok('OK', ['data' => $rows]);
    }

    /**
     * GET /api/v1/belanja/spd-otor
     *
     * Hanya SPD terotorisasi yang MASIH punya sisa (belum habis dipakai SPP).
     */
    public function daftarOtorisasi()
    {
        $skpd = $this->skpd();
        $sisa = sprintf(self::SISA_SPD, 's.id');

        $rows = DB::table('spd as s')
            ->select('s.*')
            ->selectRaw("(s.jumlah - {$sisa}) AS sisa")
            ->where('s.status', 'sudah_otorisasi')
            ->where('s.skpd', $skpd)
            ->whereRaw("s.jumlah > {$sisa}")
            ->orderByDesc('s.tanggal')
            ->orderByDesc('s.id')
            ->get();

        return $this->ok('OK', ['data' => $rows]);
    }

    /**
     * POST /api/v1/belanja/spd  (action=spd_create)
     *
     * Bila ada Kebijakan SPD aktif, jenis periode SPD WAJIB mengikuti
     * kebijakan dan label periode diisi otomatis dari tanggal.
     */
    public function store(Request $request)
    {
        $skpd    = $this->skpd();
        $tanggal = trim((string) $request->input('tanggal', ''));
        $periode = trim((string) $request->input('periode', ''));
        $jumlah  = (float) $request->input('jumlah', 0);

        if ($tanggal === '') {
            return $this->fail('Tanggal SPD wajib diisi.', ['field' => 'tanggal'], 422);
        }

        if (! Validator::tanggal($tanggal)) {
            return $this->fail('Format tanggal tidak valid.', ['field' => 'tanggal'], 422);
        }

        if ($jumlah <= 0) {
            return $this->fail('Jumlah harus lebih dari 0.', ['field' => 'jumlah'], 422);
        }

        $kebijakan = $this->kebijakanAktif($skpd);

        if ($kebijakan !== null) {
            $jenisPeriodeKeb = trim((string) ($kebijakan->jenis_periode ?? ''));
            $penerbitanKeb   = trim((string) ($kebijakan->jenis_penerbitan ?? ''));

            // Kebijakan "Sekali Bayar" tidak membatasi periode.
            if ($penerbitanKeb !== '' && stripos($penerbitanKeb, 'Sekali Bayar') === false && $jenisPeriodeKeb !== '') {
                if ($periode !== '' && $periode !== $jenisPeriodeKeb) {
                    return $this->fail(
                        'Kebijakan SPD aktif menetapkan periode "' . $jenisPeriodeKeb . '". Ubah kebijakan terlebih dahulu bila ingin berbeda.',
                        ['field' => 'periode'],
                        422
                    );
                }

                $periode = $this->hitungPeriodeSpd($tanggal, $jenisPeriodeKeb);
            }
        }

        $nomor = $this->nomorDokumen('SPD');

        $id = (int) DB::table('spd')->insertGetId([
            'user_id'   => $request->user()?->id,
            'skpd'      => $skpd,
            'nomor_spd' => $nomor,
            'tanggal'   => $tanggal,
            'jenis'     => trim((string) $request->input('jenis', '')),
            'periode'   => $periode,
            'jumlah'    => $jumlah,
            'status'    => 'belum_otorisasi',
        ]);

        $pesan = 'SPD berhasil dibuat.';

        if ($kebijakan !== null) {
            $pesan .= ' Periode mengikuti Kebijakan SPD (' . trim((string) ($kebijakan->jenis_periode ?? '')) . ').';
        }

        return $this->ok($pesan, ['id' => $id, 'nomor_spd' => $nomor], 201);
    }

    /**
     * POST /api/v1/belanja/spd/{id}/otorisasi  (action=spd_otorisasi)
     */
    public function otorisasi(int $id)
    {
        if ($id <= 0) {
            return $this->fail('ID tidak valid.', [], 422);
        }

        if (! $this->ubahStatus('spd', $id, $this->skpd(), 'belum_otorisasi', 'sudah_otorisasi')) {
            return $this->fail('SPD tidak ditemukan / sudah diotorisasi / bukan milik instansi Anda.', [], 404);
        }

        return $this->ok('SPD berhasil diotorisasi.');
    }

    /** Kebijakan SPD aktif milik instansi (terbaru), null bila belum ada. */
    private function kebijakanAktif(string $skpd): ?object
    {
        try {
            return DB::table('kebijakan_spd')
                ->where('skpd', $skpd)
                ->where('status', 'aktif')
                ->orderByDesc('id')
                ->first();
        } catch (Throwable) {
            return null; // tabel belum ada -> lewati validasi (sama seperti legacy)
        }
    }

    /**
     * Label periode SPD dari tanggal + jenis periode (sesuai Kebijakan SPD).
     * Bulanan -> "Januari 2026"; Triwulanan -> "Triwulan I 2026"; Tahunan -> "2026".
     */
    private function hitungPeriodeSpd(string $tgl, string $jenisPeriode): string
    {
        $bulan = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];

        $tahun = (int) substr($tgl, 0, 4);
        $bln   = (int) substr($tgl, 5, 2);

        if ($tahun < 2000 || $bln < 1 || $bln > 12) {
            return '';
        }

        if ($jenisPeriode === 'Triwulanan') {
            $romawi = ['I', 'II', 'III', 'IV'][intdiv($bln - 1, 3)];

            return "Triwulan {$romawi} {$tahun}";
        }

        if ($jenisPeriode === 'Tahunan') {
            return (string) $tahun;
        }

        return $bulan[$bln] . ' ' . $tahun;
    }
}
