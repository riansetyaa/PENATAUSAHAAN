<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Dokumen;

use App\Http\Controllers\Controller;
use App\Models\Dokumen;
use App\Models\DokumenTtd;
use App\Models\UserTtd;
use App\Support\Concerns\HasSkpdScope;
use App\Support\Waktu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * API Tanda Tangan Elektronik (sisi subdomain dokumen).
 *
 * Diporting dari doc.simtkd.com/api/dokumen.php pada sistem legacy.
 *
 * Endpoint publik:
 *   - verify : cek keaslian dokumen via kode TTD-XXXXXXXX (rate-limited)
 *
 * Endpoint terproteksi (token):
 *   - me, list, detail, konten, ttd_gambar_saya, ttd_gambar, ttd
 */
class TtdController extends Controller
{
    // ================================================================
    // PUBLIK — verifikasi keaslian dokumen
    // ================================================================

    /**
     * GET /api/v1/dokumen/verify?kode=TTD-XXXXXXXX
     *
     * Publik: siapa pun dapat memeriksa keaslian dokumen.
     * Bentuk respons dan pesan SAMA dengan
     * doc.simtkd.com/api/dokumen.php legacy (verify.html membaca `valid`,
     * `dokumen`, dan `penandatangan`).
     */
    public function verify(Request $request)
    {
        // Rate limit 60 permintaan / 5 menit per IP.
        if (RateLimiter::tooManyAttempts('dokumen_verify_' . ($request->ip() ?? '-'), 60)) {
            return $this->fail('Terlalu banyak permintaan. Coba lagi beberapa menit lagi.', [], 429);
        }
        RateLimiter::hit('dokumen_verify_' . ($request->ip() ?? '-'), 300);

        $kode = strtoupper(trim((string) $request->query('kode', '')));

        if (! preg_match('/^TTD-[A-F0-9]{8}$/', $kode)) {
            return $this->fail('Format kode verifikasi tidak valid (contoh: TTD-1A2B3C4D).', [], 422);
        }

        // Query Builder (bukan Eloquent) agar global scope tenant tidak perlu
        // dilepas dan kolom `user_id`/`status` di JOIN tidak ambigu.
        $d = DB::table('dokumen as d')
            ->leftJoin('dokumen_ttd as t', function ($join): void {
                $join->on('t.dokumen_id', '=', 'd.id')
                    ->where('t.urutan', '=', 1);
            })
            ->where('d.kode_verifikasi', $kode)
            ->first([
                'd.jenis',
                'd.nomor',
                'd.judul',
                'd.tanggal',
                'd.skpd',
                'd.status',
                'd.hash_signed',
                'd.konten_html_signed',
                'd.signed_at',
                't.nama as ttd_nama',
                't.jabatan as ttd_jabatan',
                't.nip as ttd_nip',
            ]);

        if (! $d) {
            return $this->fail('Dokumen tidak ditemukan untuk kode tersebut.', ['ditemukan' => false], 404);
        }

        // Integritas: hash dokumen bertanda tangan harus cocok.
        $valid = ($d->status === 'ditandatangani'
            && ! empty($d->hash_signed)
            && hash_equals((string) $d->hash_signed, hash('sha256', (string) $d->konten_html_signed)));

        return $this->ok($valid
            ? 'Dokumen VALID dan telah ditandatangani secara elektronik.'
            : 'Dokumen ditemukan namun belum ditandatangani / tanda tangan tidak valid.', [
            'ditemukan'     => true,
            'valid'         => $valid,
            'dokumen'       => [
                'jenis'   => $d->jenis,
                'nomor'   => $d->nomor,
                'judul'   => $d->judul,
                'tanggal' => $d->tanggal,
                'skpd'    => $d->skpd,
                'status'  => $d->status,
                'waktu'   => Waktu::indo($d->signed_at),
            ],
            'penandatangan' => [
                'nama'    => (string) ($d->ttd_nama ?? ''),
                'jabatan' => (string) ($d->ttd_jabatan ?? ''),
                'nip'     => trim((string) ($d->ttd_nip ?? '')),
            ],
        ]);
    }

    // ================================================================
    // TERPROTEKSI — butuh token
    // ================================================================

    /**
     * GET /api/v1/dokumen/me
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return $this->ok('OK', [
            'user' => [
                'nama'     => (string) $user->nama_lengkap,
                'username' => (string) $user->username,
                'instansi' => (string) ($user->instansi ?? ''),
                'peran'    => (string) $user->peran,
            ],
        ]);
    }

    /**
     * GET /api/v1/dokumen/list
     */
    public function list(Request $request)
    {
        $user = $request->user();

        $rows = Dokumen::query()
            ->withoutGlobalScope(HasSkpdScope::class)
            // Kolom WAJIB dikualifikasi: dokumen_ttd juga punya kolom
            // `user_id` & `status`, sehingga tanpa kualifikasi MySQL
            // melaporkan "Column 'user_id' in where clause is ambiguous".
            ->where('dokumen.user_id', $user->id)
            ->leftJoin('dokumen_ttd as t', function ($join) {
                $join->on('t.dokumen_id', '=', 'dokumen.id')
                    ->where('t.urutan', '=', 1);
            })
            ->orderByRaw("(dokumen.status = 'menunggu_ttd') DESC")
            ->orderByDesc('dokumen.id')
            ->limit(300)
            ->get([
                'dokumen.id',
                'dokumen.jenis',
                'dokumen.nomor',
                'dokumen.judul',
                'dokumen.tanggal',
                'dokumen.status',
                'dokumen.kode_verifikasi',
                'dokumen.signed_at',
                'dokumen.created_at',
                't.nama as ttd_nama',
                't.jabatan as ttd_jabatan',
                't.status as ttd_status',
            ]);

        return $this->ok('OK', [
            'data' => $rows->map(fn ($r): array => [
                'id'               => (int) $r->id,
                'jenis'            => (string) $r->jenis,
                'nomor'            => (string) $r->nomor,
                'judul'            => (string) $r->judul,
                // `tanggal` dibiarkan apa adanya (bisa NULL) — sama legacy.
                'tanggal'          => $r->tanggal,
                'status'           => (string) $r->status,
                'kode_verifikasi'  => (string) $r->kode_verifikasi,
                'signed_at'        => $r->signed_at ? $r->signed_at->format('Y-m-d H:i:s') : null,
                // Dipakai index.html untuk baris kecil di bawah badge status.
                'signed_at_fmt'    => Waktu::indo($r->signed_at),
                'created_at'       => $r->created_at ? $r->created_at->format('Y-m-d H:i:s') : null,
                'ttd_nama'         => (string) ($r->ttd_nama ?? ''),
                'ttd_jabatan'      => (string) ($r->ttd_jabatan ?? ''),
                'ttd_status'       => (string) ($r->ttd_status ?? ''),
            ])->all(),
        ]);
    }

    /**
     * GET /api/v1/dokumen/detail?id=N
     */
    public function detail(Request $request)
    {
        $user = $request->user();
        $id   = (int) $request->query('id', 0);

        if ($id <= 0) {
            return $this->fail('ID dokumen tidak valid.', [], 422);
        }

        $d = Dokumen::query()
            ->withoutGlobalScope(HasSkpdScope::class)
            ->where('user_id', $user->id)
            ->find($id);

        if (! $d) {
            return $this->fail('Dokumen tidak ditemukan.', [], 404);
        }

        // Slot penandatangan urutan 1 (sumber ttd_nama/ttd_jabatan/ttd_nip).
        $slot = DokumenTtd::query()
            ->where('dokumen_id', $d->id)
            ->where('urutan', 1)
            ->first();

        // Slot milik user yang sedang login (ttd_slot_urutan/ttd_slot_status).
        $slotSaya = DokumenTtd::query()
            ->where('dokumen_id', $d->id)
            ->where('user_id', $user->id)
            ->first();

        // Sisa tanda tangan: slot pihak eksternal (user_id NULL) tidak
        // menahan penyelesaian dokumen — sama dengan sistem legacy.
        $sisa = DokumenTtd::query()
            ->where('dokumen_id', $d->id)
            ->where('status', 'menunggu')
            ->whereNotNull('user_id')
            ->count();

        $signedAt    = $d->signed_at ? $d->signed_at->format('Y-m-d H:i:s') : null;
        $ttdSignedAt = ($slot && $slot->signed_at) ? $slot->signed_at->format('Y-m-d H:i:s') : null;

        // Bentuk respons dipertahankan identik dengan legacy: SELURUH field
        // berada di dalam `dokumen` (dokumen.html membacanya sebagai datar,
        // mis. d.ttd_slot_status, d.signed_at_fmt, d.waktu_ttd).
        return $this->ok('OK', [
            'dokumen' => [
                'id'              => (int) $d->id,
                'jenis'           => $d->jenis,
                'nomor'           => $d->nomor,
                'judul'           => $d->judul,
                'tanggal'         => $d->tanggal,
                'status'          => $d->status,
                'kode_verifikasi' => $d->kode_verifikasi,
                'hash_original'   => $d->hash_original,
                'hash_signed'     => $d->hash_signed,
                'signed_at'       => $signedAt,
                'created_at'      => $d->created_at ? $d->created_at->format('Y-m-d H:i:s') : null,
                'ttd_nama'        => (string) ($slot->nama ?? ''),
                'ttd_jabatan'     => (string) ($slot->jabatan ?? ''),
                'ttd_nip'         => trim((string) ($slot->nip ?? '')),
                'ttd_status'      => (string) ($slot->status ?? ''),
                'ttd_signed_at'   => $ttdSignedAt,
                'signed_at_fmt'   => Waktu::indo($signedAt),
                'waktu_ttd'       => Waktu::indo($ttdSignedAt),
                'ttd_slot_urutan' => $slotSaya ? (int) $slotSaya->urutan : 0,
                'ttd_slot_status' => (string) ($slotSaya->status ?? ''),
                'ttd_sisa'        => $sisa,
            ],
        ]);
    }

    /**
     * GET /api/v1/dokumen/konten?id=N
     *
     * Mengembalikan HTML dokumen (versi bertanda tangan bila sudah ada).
     *
     * Respons memakai CSP ketat: HTML dokumen berasal dari cetakan halaman
     * aplikasi sehingga memuat nilai dari database. Tanpa CSP, script yang
     * tersisip bisa berjalan di iframe SAMEORIGIN dan mencuri token API
     * milik pengguna (bug Stored XSS yang ditemukan pada audit).
     */
    public function konten(Request $request)
    {
        $user = $request->user();
        $id   = (int) $request->query('id', 0);

        if ($id <= 0) {
            return $this->fail('ID dokumen tidak valid.', [], 422);
        }

        $d = Dokumen::query()
            ->withoutGlobalScope(HasSkpdScope::class)
            ->where('user_id', $user->id)
            ->find($id);

        if (! $d) {
            return $this->fail('Dokumen tidak ditemukan.', [], 404);
        }

        $html = ($d->status === 'ditandatangani' && ! empty($d->konten_html_signed))
            ? (string) $d->konten_html_signed
            : (string) $d->konten_html;

        return response($html, 200, [
            'Content-Type'           => 'text/html; charset=utf-8',
            'X-Frame-Options'        => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-store, private',
            'Content-Security-Policy' => implode('; ', [
                "default-src 'none'",
                "img-src 'self' data: blob:",
                "style-src 'unsafe-inline'",
                "font-src 'self' data:",
                "script-src 'none'",
                "object-src 'none'",
                "base-uri 'none'",
                "form-action 'none'",
                "frame-ancestors 'self'",
            ]),
        ]);
    }

    /**
     * GET /api/v1/dokumen/ttd-gambar-saya
     */
    public function ttdGambarSaya(Request $request)
    {
        $user = $request->user();

        $row = UserTtd::query()->where('user_id', $user->id)->first();

        return $this->ok('OK', [
            'gambar' => (string) ($row->gambar ?? ''),
        ]);
    }

    /**
     * POST /api/v1/dokumen/ttd-gambar
     */
    public function ttdGambar(Request $request)
    {
        $user = $request->user();
        $img  = trim((string) $request->input('gambar', ''));

        if (! preg_match('#^data:image/png;base64,[A-Za-z0-9+/=\r\n]+$#', $img)) {
            return $this->fail('Format gambar tanda tangan tidak valid (harus PNG base64).', [], 422);
        }

        if (strlen($img) > 600000) {
            return $this->fail('Gambar tanda tangan terlalu besar.', [], 413);
        }

        UserTtd::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['gambar' => $img, 'diperbarui_pada' => now()]
        );

        return $this->ok('Gambar tanda tangan tersimpan.');
    }

    /**
     * POST /api/v1/dokumen/ttd
     *
     * Menandatangani dokumen secara elektronik.
     */
    public function ttd(Request $request)
    {
        $user = $request->user();
        $id   = (int) $request->input('id', 0);
        $qr   = trim((string) $request->input('qr', ''));

        if ($id <= 0) {
            return $this->fail('ID dokumen tidak valid.', [], 422);
        }

        // QR bermasalah -> tandatangani tanpa QR, jangan gagalkan.
        if ($qr !== '' && (! preg_match('#^data:image/png;base64,[A-Za-z0-9+/=\r\n]+$#', $qr) || strlen($qr) > 300000)) {
            $qr = '';
        }

        $ua = substr((string) $request->userAgent(), 0, 255);

        try {
            return DB::transaction(function () use ($request, $user, $id, $qr, $ua) {
                // Kunci baris dokumen agar tidak terjadi tanda tangan ganda.
                $d = Dokumen::query()
                    ->withoutGlobalScope(HasSkpdScope::class)
                    ->where('id', $id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $d) {
                    return $this->fail('Dokumen tidak ditemukan.', [], 404);
                }

                if ($d->status !== 'menunggu_ttd') {
                    return $this->fail('Dokumen ini sudah ditandatangani sebelumnya.', [], 409);
                }

                // Integritas: dokumen harus persis seperti saat dikirim.
                if (! hash_equals((string) $d->hash_original, hash('sha256', (string) $d->konten_html))) {
                    return $this->fail(
                        'Integritas dokumen tidak terpenuhi. Kirim ulang dokumen dari laman cetak SIM-TKD.',
                        [],
                        409
                    );
                }

                // Gambar tanda tangan wajib sudah dibuat.
                $ttd = UserTtd::query()->where('user_id', $user->id)->first();
                $gambar = (string) ($ttd->gambar ?? '');

                if ($gambar === '') {
                    return $this->fail(
                        'Gambar tanda tangan belum dibuat. Gambar tanda tangan terlebih dahulu.',
                        [],
                        422
                    );
                }

                // Slot penandatangan milik user ini.
                $slot = DokumenTtd::query()
                    ->where('dokumen_id', $id)
                    ->where('user_id', $user->id)
                    ->first();

                $urutan = 1;

                if ($slot) {
                    if ($slot->status === 'ditandatangani') {
                        return $this->fail(
                            'Slot tanda tangan Anda pada dokumen ini sudah ditandatangani.',
                            [],
                            409
                        );
                    }

                    $urutan = max(1, (int) $slot->urutan);
                } else {
                    // Dokumen lama tanpa baris penandatangan -> pakai urutan 1.
                    $ada = DokumenTtd::query()
                        ->where('dokumen_id', $id)
                        ->where('urutan', 1)
                        ->first();

                    if (! $ada) {
                        DokumenTtd::create([
                            'dokumen_id' => $id,
                            'user_id'    => $user->id,
                            'urutan'     => 1,
                            'jabatan'    => (string) $user->peran,
                            'nama'       => (string) $user->nama_lengkap,
                            'status'     => 'menunggu',
                        ]);
                    }
                }

                // Sisipkan gambar TTD: posisi manual atau otomatis pada slot.
                $base = ! empty($d->konten_html_signed)
                    ? (string) $d->konten_html_signed
                    : (string) $d->konten_html;

                $posX = null;
                $posY = 0;
                $posW = 180;

                if ($request->has('pos_x') && $request->has('pos_y') && $request->has('pos_w')) {
                    $posX = (float) $request->input('pos_x');
                    $posY = (int) $request->input('pos_y');
                    $posW = (int) $request->input('pos_w');
                }

                $html = $this->sisipkanGambar($base, $gambar, $posX, $posY, $posW, $urutan);

                // Blok barcode (QR) verifikasi di akhir dokumen — CUKUP SATU per
                // dokumen meski dokumen ditandatangani beberapa penandatangan.
                if (! str_contains($html, 'data-ttd-qr="1"')) {
                    $html = $this->sisipkanVerifikasi($html, (string) $d->kode_verifikasi, $qr);
                }

                $hashSigned = hash('sha256', $html);
                $waktuSql   = now();

                // NIP diambil dari profil penandatangan (Pengaturan > Profil).
                $nipTtd = trim((string) ($user->nip ?? ''));

                // ---- Tandai slot penandatangan milik user ini ----
                // Identitas (nama/jabatan/nip) ikut diperbarui agar blok tanda
                // tangan pada dokumen memakai data profil terbaru.
                $identitas = [
                    'nama'       => (string) $user->nama_lengkap,
                    'jabatan'    => (string) $user->peran,
                    'nip'        => $nipTtd,
                    'status'     => 'ditandatangani',
                    'signed_at'  => $waktuSql,
                    'ip'         => $request->ip(),
                    'user_agent' => $ua,
                ];

                if ($slot) {
                    $slot->forceFill($identitas)->save();
                } else {
                    // Dokumen lama tanpa baris penandatangan untuk user ini:
                    // perbarui baris urutan 1, atau buat baru bila belum ada.
                    $urutanSatu = DokumenTtd::query()
                        ->where('dokumen_id', $id)
                        ->where('urutan', 1)
                        ->first();

                    if ($urutanSatu) {
                        $urutanSatu->forceFill($identitas)->save();
                    } else {
                        DokumenTtd::create([
                            'dokumen_id' => $id,
                            'user_id'    => $user->id,
                            'urutan'     => 1,
                        ] + $identitas);
                    }
                }

                // ---- Status dokumen ----
                // Dihitung SETELAH slot ditandai. Bila dihitung sebelumnya,
                // slot yang baru saja ditandatangani masih terhitung
                // 'menunggu' sehingga dokumen tidak pernah selesai.
                $statusDok = $this->statusSetelahTtd($id);

                $d->forceFill([
                    'konten_html_signed' => $html,
                    'hash_signed'        => $hashSigned,
                    'status'             => $statusDok,
                    'signed_at'          => $statusDok === 'ditandatangani' ? $waktuSql : null,
                ])->save();

                return $this->ok('Dokumen berhasil ditandatangani secara elektronik.', [
                    // Catatan: legacy mengembalikan null di sini (variabel tak
                    // terdefinisi pada action 'ttd'); API baru mengembalikan
                    // kode yang benar. Perbedaan disengaja (dokumentasi README).
                    'kode_verifikasi' => (string) $d->kode_verifikasi,
                    'hash_signed'     => $hashSigned,
                    'waktu'           => Waktu::indo($waktuSql),
                    'status'          => $statusDok,
                ]);
            });
        } catch (Throwable $e) {
            report($e);

            return $this->fail('Gagal memproses tanda tangan. Coba beberapa saat lagi.', [], 500);
        }
    }

    // ================================================================
    // Helper
    // ================================================================

    /**
     * Status dokumen setelah satu slot ditandatangani.
     *
     * Ditandatangani bila tidak ada slot milik akun (`user_id` TIDAK NULL)
     * yang masih 'menunggu'. Slot pihak eksternal (user_id NULL) tidak
     * menahan penyelesaian dokumen — sama dengan sistem legacy.
     */
    private function statusSetelahTtd(int $dokumenId): string
    {
        $sisa = DokumenTtd::query()
            ->where('dokumen_id', $dokumenId)
            ->where('status', 'menunggu')
            ->whereNotNull('user_id')
            ->count();

        return $sisa === 0 ? 'ditandatangani' : 'menunggu_ttd';
    }

    /**
     * Sisipkan gambar TTD ke HTML dokumen.
     *
     * - $posX null  -> otomatis pada slot (.ttd-slot) milik $urutan (data-urut),
     *                  fallback ke slot kosong pertama di template.
     * - $posX float -> penempatan MANUAL: absolute di dalam #docArea.
     *
     * Diporting dari ttdSisipkan() pada sistem legacy.
     */
    private function sisipkanGambar(
        string $html,
        string $gambar,
        ?float $posX,
        int $posY,
        int $posW,
        int $urutan = 1
    ): string {
        $imgSlot   = '<img class="ttd-img" alt="Tanda tangan elektronik" src="' . $gambar . '">';
        $imgManual = '<img class="ttd-img" style="width:100%;display:block;" alt="Tanda tangan elektronik" src="' . $gambar . '">';

        if ($posX !== null) {
            $xp = rtrim(rtrim(sprintf('%.2f', (float) $posX), '0'), '.');

            if ($xp === '' || $xp === '-') {
                $xp = '0';
            }

            $manual = '<div style="position:absolute;left:' . $xp . '%;top:' . $posY . 'px;width:' . $posW . 'px;z-index:50;">'
                . $imgManual . '</div>';

            if (str_contains($html, '<div id="docArea">')) {
                return preg_replace(
                    '/<div id="docArea">/',
                    '<div id="docArea" style="position:relative;">' . $manual,
                    $html,
                    1
                );
            }

            return $html . $manual;
        }

        // 1) Slot dengan data-urut tertentu (masih kosong).
        $slotPattern = '/<div class="ttd-slot" data-urut="' . (int) $urutan . '"><\/div>/';

        if (preg_match($slotPattern, $html)) {
            return preg_replace(
                $slotPattern,
                '<div class="ttd-slot" data-urut="' . (int) $urutan . '">' . $imgSlot . '</div>',
                $html,
                1
            );
        }

        // 2) Fallback: slot kosong pertama (template lama tanpa data-urut).
        if (str_contains($html, '<div class="ttd-slot"></div>')) {
            return preg_replace(
                '/<div class="ttd-slot"><\/div>/',
                '<div class="ttd-slot">' . $imgSlot . '</div>',
                $html,
                1
            );
        }

        return $html;
    }

    /**
     * Sisipkan barcode (QR) verifikasi di akhir dokumen.
     */
    private function sisipkanVerifikasi(string $html, string $kode, string $qr): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        if ($qr !== '') {
            $blok = '<div data-ttd-qr="1" style="margin-top:24px;text-align:center;">'
                . '<img alt="Barcode verifikasi ' . $e($kode) . '" '
                . 'style="display:inline-block;width:90px;height:90px;" src="' . $qr . '">'
                . '</div>';
        } else {
            // Cadangan bila QR gagal dibuat: kode verifikasi tetap dicantumkan.
            $blok = '<div data-ttd-qr="1" style="margin-top:24px;font-size:10px;color:#475569;font-family:Arial,Helvetica,sans-serif;">'
                . 'Kode verifikasi: ' . $e($kode) . ' — cek keaslian di doc.simtkd.com/verify.html</div>';
        }

        $pos = strrpos($html, '</div>');

        if ($pos === false) {
            return $html . $blok;
        }

        return substr($html, 0, $pos) . $blok . substr($html, $pos);
    }
}
