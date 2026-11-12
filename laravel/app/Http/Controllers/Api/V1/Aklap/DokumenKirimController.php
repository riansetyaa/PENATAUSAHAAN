<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Aklap;

use App\Http\Controllers\Api\V1\Aklap\Concerns\TanggalValid;
use App\Http\Controllers\Controller;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AKLAP — Kirim dokumen ke antrean tanda tangan (doc.simtkd.com).
 *
 * Diporting dari blok `action=dokumen_kirim` pada
 * peta.simtkd.com/api/akuntansi.php (token-based, dipanggil dari modul peta).
 *
 * Perilaku penting:
 *  - Bila sudah ada dokumen sejenis (jenis + judul + instansi) berstatus
 *    `menunggu_ttd`, isinya DIPERBARUI (tanpa membuat baris baru).
 *  - Kode verifikasi `TTD-XXXXXXXX` dibuat acak & dijamin unik (5 percobaan).
 *  - Penandatangan opsional lewat `penandatangan` (JSON); bila kosong,
 *    otomatis dibuatkan satu baris untuk pengirim sendiri (jabatan Bendahara).
 */
class DokumenKirimController extends Controller
{
    use TanggalValid;

    /**
     * POST /api/v1/aklap/dokumen-kirim  (action=dokumen_kirim)
     */
    public function store(Request $request)
    {
        $skpd  = Tenant::requireSkpd();
        $user  = $request->user();

        $jenis   = trim((string) $request->input('jenis', ''));
        $nomor   = trim((string) $request->input('nomor', ''));
        $judul   = trim((string) $request->input('judul', ''));
        $tanggal = trim((string) $request->input('tanggal', ''));
        $konten  = (string) $request->input('konten_html', '');

        if ($jenis === '') {
            $jenis = 'Dokumen';
        }

        if ($judul === '') {
            $judul = $jenis;
        }

        if (mb_strlen($konten) < 200) {
            return $this->fail('Konten dokumen kosong / tidak valid.', [], 422);
        }

        if (strlen($konten) > 4 * 1024 * 1024) {
            return $this->fail('Konten dokumen terlalu besar (maks ±4 MB).', [], 413);
        }

        if ($tanggal !== '' && $this->tanggalValid($tanggal) === '') {
            return $this->fail('Format tanggal tidak valid.', [], 422);
        }

        $hash = hash('sha256', $konten);

        // Kirim ulang bila sudah ada dokumen sejenis yang masih menunggu TTD.
        $lama = DB::table('dokumen')
            ->where('jenis', $jenis)
            ->where('judul', $judul)
            ->where('skpd', $skpd)
            ->where('status', 'menunggu_ttd')
            ->orderByDesc('id')
            ->select(['id', 'status'])
            ->first();

        if ($lama !== null) {
            DB::table('dokumen')
                ->where('id', (int) $lama->id)
                ->update([
                    'nomor'         => $nomor,
                    'tanggal'       => $tanggal !== '' ? $tanggal : null,
                    'konten_html'   => $konten,
                    'hash_original' => $hash,
                ]);

            return $this->okData([
                'message' => 'Dokumen diperbarui di antrean tanda tangan.',
                'id'      => (int) $lama->id,
            ]);
        }

        $kode = '';

        for ($i = 0; $i < 5; $i++) {
            $c = 'TTD-' . strtoupper(bin2hex(random_bytes(4)));

            if (! DB::table('dokumen')->where('kode_verifikasi', $c)->exists()) {
                $kode = $c;
                break;
            }
        }

        if ($kode === '') {
            return $this->fail('Gagal membuat kode verifikasi. Silakan coba lagi.', [], 500);
        }

        $dokId = (int) DB::table('dokumen')->insertGetId([
            'user_id'         => (int) ($user?->id ?? 0),
            'skpd'            => $skpd,
            'jenis'           => $jenis,
            'nomor'           => $nomor,
            'judul'           => $judul,
            'tanggal'         => $tanggal !== '' ? $tanggal : null,
            'konten_html'     => $konten,
            'hash_original'   => $hash,
            'kode_verifikasi' => $kode,
            'status'          => 'menunggu_ttd',
        ]);

        // Penandatangan (opsional, JSON): [{user:'self', urutan, jabatan, nama}, ...]
        $penandatangan = [];
        $rawPttd       = trim((string) $request->input('penandatangan', ''));

        if ($rawPttd !== '') {
            $decoded = json_decode($rawPttd, true);

            if (is_array($decoded)) {
                $penandatangan = $decoded;
            }
        }

        $nipDoc = trim((string) $request->input('nip', ''));

        if ($penandatangan === [] && (string) ($user?->nama_lengkap ?? '') !== '') {
            $penandatangan = [['user' => 'self', 'urutan' => 1, 'jabatan' => 'Bendahara', 'nama' => (string) $user?->nama_lengkap]];
        }

        $baris = [];

        foreach ($penandatangan as $pt) {
            if (! is_array($pt)) {
                continue;
            }

            $baris[] = [
                'dokumen_id' => $dokId,
                'user_id'    => ((string) ($pt['user'] ?? '') === 'self') ? (int) ($user?->id ?? 0) : null,
                'urutan'     => max(1, min(20, (int) ($pt['urutan'] ?? 1))),
                'jabatan'    => mb_substr(trim((string) ($pt['jabatan'] ?? 'Bendahara')), 0, 100),
                'nama'       => mb_substr(trim((string) ($pt['nama'] ?? '')), 0, 100),
                'nip'        => mb_substr($nipDoc, 0, 50),
                'status'     => 'menunggu',
            ];
        }

        if ($baris !== []) {
            DB::table('dokumen_ttd')->insert($baris);
        }

        return $this->okData([
            'message'         => 'Dokumen terkirim ke antrean tanda tangan (doc.simtkd.com).',
            'id'              => $dokId,
            'kode_verifikasi' => $kode,
        ]);
    }
}
