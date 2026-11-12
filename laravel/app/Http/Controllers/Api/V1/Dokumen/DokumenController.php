<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Dokumen;

use App\Http\Controllers\Controller;
use App\Models\Dokumen;
use App\Models\DokumenTtd;
use App\Support\Tenant;
use App\Support\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * API Dokumen — sisi aplikasi utama (simpan ke antrean tanda tangan).
 *
 * Diporting dari api/dokumen.php pada sistem legacy.
 *
 * Dokumen hasil cetakan (SPP/SPD/SPM/SP2D/LPJ/NPD/...) dikirim ke antrean
 * tanda tangan yang dikelola subdomain dokumen. Keduanya memakai basis data
 * yang sama.
 */
class DokumenController extends Controller
{
    /**
     * POST /api/v1/dokumen/kirim
     */
    public function kirim(Request $request)
    {
        $user   = $request->user();
        $skpd   = Tenant::requireSkpd();
        $userId = (int) $user->id;

        $jenis   = trim((string) $request->input('jenis', ''));
        $refId   = (int) $request->input('ref_id', 0);
        $nomor   = trim((string) $request->input('nomor', ''));
        $judul   = trim((string) $request->input('judul', ''));
        $tanggal = trim((string) $request->input('tanggal', ''));
        $konten  = (string) $request->input('konten_html', '');

        if ($jenis === '') {
            $jenis = 'Dokumen';
        }

        if ($judul === '') {
            $judul = $jenis . ($nomor !== '' ? ' Nomor ' . $nomor : '');
        }

        if (mb_strlen($konten) < 200) {
            return $this->fail('Konten dokumen kosong / tidak valid.', [], 422);
        }

        if (strlen($konten) > 4 * 1024 * 1024) {
            return $this->fail('Konten dokumen terlalu besar (maks ±4 MB).', [], 413);
        }

        if ($tanggal !== '' && ! Validator::tanggal($tanggal)) {
            return $this->fail('Format tanggal tidak valid.', [], 422);
        }

        // Penandatangan (opsional): array [{user:'self'|'', urutan, jabatan, nama}]
        $penandatangan = $request->input('penandatangan', []);

        if (is_string($penandatangan)) {
            $decoded = json_decode($penandatangan, true);
            $penandatangan = is_array($decoded) ? $decoded : [];
        }

        $penandatangan = $this->normalizePenandatangan(
            is_array($penandatangan) ? $penandatangan : [],
            $userId,
            (string) $user->peran,
            (string) $user->nama_lengkap
        );

        $hash = hash('sha256', $konten);

        try {
            // ---- Kirim ulang: perbarui dokumen yang masih menunggu TTD ----
            if ($refId > 0) {
                $lama = Dokumen::query()
                    ->where('user_id', $userId)
                    ->where('jenis', $jenis)
                    ->where('ref_id', $refId)
                    ->orderByDesc('id')
                    ->first();

                if ($lama) {
                    if ($lama->status === 'ditandatangani') {
                        return $this->ok('Dokumen ini sudah ditandatangani dan tidak dapat dikirim ulang.', [
                            'id'     => (int) $lama->id,
                            'status' => 'ditandatangani',
                        ]);
                    }

                    $lama->forceFill([
                        'nomor'        => $nomor,
                        'judul'        => $judul,
                        'tanggal'      => $tanggal !== '' ? $tanggal : null,
                        'konten_html'  => $konten,
                        'hash_original' => $hash,
                        'skpd'         => $skpd,
                    ])->save();

                    // Perbarui daftar penandatangan: hapus yang masih menunggu,
                    // sisipkan ulang.
                    DB::transaction(function () use ($lama, $penandatangan): void {
                        DokumenTtd::query()
                            ->where('dokumen_id', $lama->id)
                            ->where('status', 'menunggu')
                            ->delete();

                        foreach ($penandatangan as $pt) {
                            DokumenTtd::create([
                                'dokumen_id' => $lama->id,
                                'user_id'    => $pt['user_id'],
                                'urutan'     => $pt['urutan'],
                                'jabatan'    => $pt['jabatan'],
                                'nama'       => $pt['nama'],
                                'status'     => 'menunggu',
                            ]);
                        }
                    });

                    return $this->ok('Dokumen diperbarui di antrean tanda tangan.', [
                        'id'     => (int) $lama->id,
                        'status' => 'menunggu_ttd',
                    ]);
                }
            }

            // ---- Dokumen baru ----
            // Kode verifikasi unik (32 bit — dicoba beberapa kali bila
            // bertabrakan). Panjang kode akan ditingkatkan pada versi
            // berikutnya; lihat catatan keamanan pada README-MIGRASI.md.
            $kode = '';

            for ($i = 0; $i < 5; $i++) {
                $candidate = 'TTD-' . strtoupper(bin2hex(random_bytes(4)));

                if (! Dokumen::query()->where('kode_verifikasi', $candidate)->exists()) {
                    $kode = $candidate;
                    break;
                }
            }

            if ($kode === '') {
                return $this->fail('Gagal membuat kode verifikasi. Silakan coba lagi.', [], 500);
            }

            $dokumenId = null;

            DB::transaction(function () use (
                $userId, $skpd, $jenis, $refId, $nomor, $judul, $tanggal,
                $konten, $hash, $kode, $penandatangan, &$dokumenId
            ): void {
                $dokumen = Dokumen::create([
                    'user_id'         => $userId,
                    'skpd'            => $skpd,
                    'jenis'           => $jenis,
                    'ref_id'          => $refId > 0 ? $refId : null,
                    'nomor'           => $nomor,
                    'judul'           => $judul,
                    'tanggal'         => $tanggal !== '' ? $tanggal : null,
                    'konten_html'     => $konten,
                    'hash_original'   => $hash,
                    'kode_verifikasi' => $kode,
                    'status'          => 'menunggu_ttd',
                ]);

                $dokumenId = (int) $dokumen->id;

                foreach ($penandatangan as $pt) {
                    DokumenTtd::create([
                        'dokumen_id' => $dokumenId,
                        'user_id'    => $pt['user_id'],
                        'urutan'     => $pt['urutan'],
                        'jabatan'    => $pt['jabatan'],
                        'nama'       => $pt['nama'],
                        'status'     => 'menunggu',
                    ]);
                }
            });

            return $this->ok('Dokumen terkirim ke antrean tanda tangan (doc.simtkd.com).', [
                'id'              => $dokumenId,
                'kode_verifikasi' => $kode,
                'status'          => 'menunggu_ttd',
            ]);
        } catch (Throwable $e) {
            report($e);
            Log::error('[SIM-TKD] dokumen kirim gagal: ' . $e->getMessage());

            return $this->fail('Gagal menyimpan dokumen. Coba beberapa saat lagi.', [], 500);
        }
    }

    /**
     * Normalisasi daftar penandatangan (maks 20 slot, urutan unik).
     *
     * Identik dengan normalizePenandatangan() pada sistem legacy.
     *
     * @param  array<int, mixed>  $list
     * @return array<int, array<string, mixed>>
     */
    private function normalizePenandatangan(array $list, int $selfUserId, string $peran, string $nama): array
    {
        $out = [];
        $usedUrutan = [];

        foreach ($list as $i => $item) {
            if (! is_array($item)) {
                continue;
            }

            $urutan = (int) ($item['urutan'] ?? ($i + 1));

            if ($urutan < 1) {
                $urutan = $i + 1;
            }

            if ($urutan > 20) {
                $urutan = 20;
            }

            if (isset($usedUrutan[$urutan])) {
                continue;
            }

            $usedUrutan[$urutan] = true;

            $jabatan = mb_substr(trim((string) ($item['jabatan'] ?? '')), 0, 100);
            $namaSlot = mb_substr(trim((string) ($item['nama'] ?? '')), 0, 150);
            $uval    = trim((string) ($item['user'] ?? ''));
            $uid     = null;

            if ($uval === 'self' || $uval === (string) $selfUserId) {
                $uid = $selfUserId;
            }

            // Nilai user lain TIDAK diperkenankan: pembuat dokumen hanya
            // boleh menandatangani slotnya sendiri.
            $out[] = [
                'user_id' => $uid,
                'urutan'  => $urutan,
                'jabatan' => $jabatan,
                'nama'    => $namaSlot,
            ];
        }

        if ($out === []) {
            $out[] = [
                'user_id' => $selfUserId,
                'urutan'  => 1,
                'jabatan' => $peran,
                'nama'    => $nama,
            ];
        }

        return $out;
    }
}
