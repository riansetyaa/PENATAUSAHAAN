<?php
/**
 * SIM-TKD - API Dokumen Mahasiswa (Monitoring Pengguna)
 * ============================================
 * Dipakai halaman Pengaturan -> Monitoring Pengguna agar dosen/admin dapat
 * melihat hasil dokumen yang dikerjakan oleh tiap mahasiswa.
 *
 * Endpoint ini melengkapi api/monitoring.php (yang hanya menampilkan
 * hitungan progres). Data di sini adalah baris dokumen asli yang DIBUAT
 * oleh akun yang bersangkutan (kolom user_id), sehingga beberapa akun
 * dengan instansi sama tetap terpisah.
 *
 * Metode: GET
 *   GET ?user_id=N
 *       -> daftar entri per modul + daftar dokumen yang sudah dikirim
 *          ke Tanda Tangan Dokumen.
 *   GET ?user_id=N&dokumen_id=M
 *       -> isi HTML satu dokumen untuk pratinjau (read-only).
 *
 * Otorisasi: Admin Dinas / Kepala Dinas / Dosen (atau username "admin"),
 * sama persis dengan api/monitoring.php. Sengaja TIDAK memakai
 * requireInstansi() karena dosen perlu melihat lintas instansi.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    jsonResponse(false, 'Tidak terautentikasi.', [], 401);
}

$peran    = (string) ($_SESSION['peran'] ?? '');
$username = (string) ($_SESSION['username'] ?? '');
$boleh    = in_array($peran, ['Admin Dinas', 'Kepala Dinas', 'Dosen'], true)
            || $username === 'admin';

if (!$boleh) {
    jsonResponse(
        false,
        'Akses ditolak. Dokumen mahasiswa hanya dapat dilihat oleh admin/dosen.',
        [],
        403
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
}

$pdo = db();

$targetId = (int) ($_GET['user_id'] ?? 0);
if ($targetId <= 0) {
    jsonResponse(false, 'Pengguna yang akan dilihat tidak valid.', [], 422);
}

try {
    $stmt = $pdo->prepare("
        SELECT id, nama_lengkap, username, email, instansi, peran, status
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$targetId]);
    $pemilik = $stmt->fetch();
} catch (Throwable $e) {
    error_log('[SIM-TKD monitoring_dokumen] gagal membaca pengguna: ' . $e->getMessage());
    jsonResponse(false, 'Gagal membaca data pengguna.', [], 500);
}

if (!$pemilik) {
    jsonResponse(false, 'Pengguna tidak ditemukan.', [], 404);
}

// ------------------------------------------------------------------
// Pratinjau isi satu dokumen.
// ------------------------------------------------------------------
$dokumenId = (int) ($_GET['dokumen_id'] ?? 0);
if ($dokumenId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, user_id, jenis, ref_id, nomor, judul, tanggal, status,
                   signed_at, kode_verifikasi, konten_html, konten_html_signed
            FROM dokumen
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$dokumenId]);
        $doc = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('[SIM-TKD monitoring_dokumen] gagal membaca dokumen: ' . $e->getMessage());
        jsonResponse(false, 'Data dokumen tanda tangan belum tersedia di server.', [], 503);
    }

    // Penting: dokumen hanya boleh dibuka bila benar milik pengguna yang
    // sedang dilihat. Tanpa cek ini dosen (atau siapa pun yang punya akses
    // monitoring) bisa membaca dokumen akun lain lewat ID berurutan.
    if (!$doc || (int) $doc['user_id'] !== $targetId) {
        jsonResponse(false, 'Dokumen tidak ditemukan untuk pengguna ini.', [], 404);
    }

    $konten = (string) ($doc['konten_html_signed'] ?? '');
    $tertandatangani = $doc['status'] === 'ditandatangani' && $konten !== '';
    if ($konten === '') {
        $konten = (string) ($doc['konten_html'] ?? '');
    }

    jsonResponse(true, 'OK', [
        'dokumen' => [
            'id'              => (int) $doc['id'],
            'jenis'           => (string) $doc['jenis'],
            'nomor'           => (string) ($doc['nomor'] ?? ''),
            'judul'           => (string) ($doc['judul'] ?? ''),
            'tanggal'         => (string) ($doc['tanggal'] ?? ''),
            'status'          => (string) $doc['status'],
            'signed_at'       => $doc['signed_at'] ?? null,
            'kode_verifikasi' => (string) ($doc['kode_verifikasi'] ?? ''),
            'versi_ttd'       => $tertandatangani,
            'konten_html'     => $konten,
        ],
    ]);
}

// ------------------------------------------------------------------
// Sumber entri per modul. Hanya tabel yang punya kolom user_id supaya
// hasil kerja bisa diatribusikan ke akun yang benar.
//   ttd_jenis : nilai dokumen.jenis yang dipakai saat kirim ke TTD
//               (null bila modul ini tidak punya alur tanda tangan).
// ------------------------------------------------------------------
$sumber = [
    // ---------------- Penerimaan ----------------
    [
        'key' => 'permohonan', 'modul' => 'penerimaan', 'jenis' => 'Permohonan',
        'table' => 'permohonan', 'where' => '1=1', 'ttd_jenis' => null,
        'select' => "id,
                     NULLIF(`nomor_rekening`, '')                       AS nomor,
                     NULLIF(`nama_rekening`, '')                        AS judul,
                     `created_at`                                       AS tanggal,
                     CASE
                         WHEN `status_aktif` = 1     THEN 'aktif'
                         WHEN `status_disetujui` = 1 THEN 'disetujui'
                         WHEN `status_terbit` = 1    THEN 'terbit'
                         ELSE 'diajukan'
                     END                                                AS status,
                     NULL                                               AS nilai",
    ],
    [
        'key' => 'skp', 'modul' => 'penerimaan', 'jenis' => 'SKP Daerah',
        'table' => 'skp_daerah', 'where' => "status <> 'dihapus'", 'ttd_jenis' => 'SKP Daerah',
        'select' => "id, `nomor_skp` AS nomor, `jenis_pajak` AS judul, `tanggal`,
                     `status`, `nilai_keputusan` AS nilai",
    ],
    [
        'key' => 'stbp', 'modul' => 'penerimaan', 'jenis' => 'STBP',
        'table' => 'stbp', 'where' => "status <> 'dihapus'", 'ttd_jenis' => 'STBP',
        'select' => "id, `nomor_stbp` AS nomor, `uraian` AS judul, `tanggal`,
                     `status`, `jumlah` AS nilai",
    ],
    [
        'key' => 'sts', 'modul' => 'penerimaan', 'jenis' => 'STS',
        'table' => 'sts', 'where' => "status <> 'dihapus'", 'ttd_jenis' => 'STS',
        'select' => "id, `nomor_sts` AS nomor, `keterangan` AS judul, `tanggal_sts` AS tanggal,
                     `status`, `total` AS nilai",
    ],

    // ---------------- Belanja ----------------
    [
        'key' => 'rekanan', 'modul' => 'belanja', 'jenis' => 'Rekanan',
        'table' => 'rekanan', 'where' => '1=1', 'ttd_jenis' => null,
        'select' => "id, NULLIF(`npwp`, '') AS nomor, `nama_rekanan` AS judul,
                     `created_at` AS tanggal, NULL AS status, NULL AS nilai",
    ],
    [
        'key' => 'rekening_skpd', 'modul' => 'belanja', 'jenis' => 'Rekening Bank SKPD',
        'table' => 'rekening_skpd', 'where' => '1=1', 'ttd_jenis' => null,
        'select' => "id, NULLIF(`nomor_rekening`, '') AS nomor, `nama_pemilik` AS judul,
                     `created_at` AS tanggal, `status`, NULL AS nilai",
    ],
    [
        'key' => 'spd', 'modul' => 'belanja', 'jenis' => 'SPD',
        'table' => 'spd', 'where' => '1=1', 'ttd_jenis' => 'SPD',
        'select' => "id, `nomor_spd` AS nomor, `jenis` AS judul, `tanggal`,
                     `status`, `jumlah` AS nilai",
    ],
    [
        'key' => 'spp', 'modul' => 'belanja', 'jenis' => 'SPP',
        'table' => 'spp', 'where' => "status <> 'ditolak'", 'ttd_jenis' => 'SPP',
        'select' => "id, `nomor_spp` AS nomor, `keperluan` AS judul, `tanggal`,
                     `status`, `jumlah` AS nilai",
    ],
    [
        'key' => 'spm', 'modul' => 'belanja', 'jenis' => 'SPM',
        'table' => 'spm', 'where' => "status <> 'ditolak'", 'ttd_jenis' => 'SPM',
        'select' => "id, `nomor_spm` AS nomor, NULL AS judul, `tanggal`,
                     `status`, `jumlah` AS nilai",
    ],
    [
        'key' => 'sp2d', 'modul' => 'belanja', 'jenis' => 'SP2D',
        'table' => 'sp2d', 'where' => "status <> 'ditolak'", 'ttd_jenis' => 'SP2D',
        'select' => "id, `nomor_sp2d` AS nomor, `rekening` AS judul, `tanggal`,
                     `status`, `jumlah` AS nilai",
    ],
    [
        'key' => 'npd', 'modul' => 'belanja', 'jenis' => 'NPD',
        'table' => 'npd', 'where' => "status <> 'ditolak'", 'ttd_jenis' => 'NPD',
        'select' => "id, `nomor_npd` AS nomor, `keterangan` AS judul, `tanggal`,
                     `status`, `jumlah` AS nilai",
    ],

    // ---------------- Akuntansi ----------------
    [
        'key' => 'jurnal_umum', 'modul' => 'akuntansi', 'jenis' => 'Jurnal Umum',
        'table' => 'jurnal_umum', 'where' => "status <> 'ditolak'", 'ttd_jenis' => null,
        'select' => "id, `nomor_jurnal` AS nomor, `uraian` AS judul, `tanggal`,
                     `status`, NULL AS nilai",
    ],
    [
        'key' => 'jurnal_khusus', 'modul' => 'akuntansi', 'jenis' => 'Jurnal Khusus',
        'table' => 'jurnal_khusus', 'where' => '1=1', 'ttd_jenis' => null,
        'select' => "id,
                     CONCAT(COALESCE(`jenis`, 'Jurnal'), ' ', `bulan`, '/', `tahun`) AS nomor,
                     `skp_label` AS judul, `created_at` AS tanggal,
                     NULL AS status, NULL AS nilai",
    ],
    [
        'key' => 'buku_kas', 'modul' => 'akuntansi', 'jenis' => 'Buku Kas',
        'table' => 'buku_kas', 'where' => '1=1', 'ttd_jenis' => null,
        'select' => "id, `no_bukti` AS nomor, `uraian` AS judul, `tanggal`,
                     `jenis` AS status, `jumlah` AS nilai",
    ],
    [
        'key' => 'kas_harian', 'modul' => 'akuntansi', 'jenis' => 'Buku Kas Harian',
        'table' => 'kas_harian', 'where' => '1=1', 'ttd_jenis' => null,
        'select' => "id, `periode` AS nomor, NULL AS judul, `tanggal`,
                     `hari` AS status, `posisi_h` AS nilai",
    ],
    [
        'key' => 'rekonsiliasi', 'modul' => 'akuntansi', 'jenis' => 'Rekonsiliasi Bank',
        'table' => 'rekonsiliasi_bank', 'where' => '1=1', 'ttd_jenis' => null,
        'select' => "id, `periode` AS nomor, `catatan` AS judul, `tgl_ttd` AS tanggal,
                     NULL AS status, `saldo_buku` AS nilai",
    ],
    [
        'key' => 'jurnal_manual', 'modul' => 'akuntansi', 'jenis' => 'Jurnal Manual',
        'table' => 'jurnal_manual', 'where' => '1=1', 'ttd_jenis' => null,
        'select' => "id, `nomor_jurnal` AS nomor, `uraian` AS judul, `tanggal`,
                     NULL AS status, `jumlah` AS nilai",
    ],
    [
        'key' => 'akun_penerimaan', 'modul' => 'akuntansi', 'jenis' => 'Akun Penerimaan',
        'table' => 'akun_penerimaan', 'where' => '1=1', 'ttd_jenis' => null,
        'select' => "id, `kode_akun` AS nomor, `nama_akun` AS judul, `created_at` AS tanggal,
                     CASE WHEN `status_aktif` = 1 THEN 'aktif' ELSE 'nonaktif' END AS status,
                     NULL AS nilai",
    ],
];

// ------------------------------------------------------------------
// Dokumen yang sudah dikirim ke Tanda Tangan Dokumen (tabel dokumen).
// Dibaca lebih dulu agar setiap entri modul bisa diberi status TTD.
// ------------------------------------------------------------------
$dokumenList    = [];
$dokumenPerRef  = [];
$dokumenTersedia = true;

try {
    $stmt = $pdo->prepare("
        SELECT id, jenis, ref_id, nomor, judul, tanggal, status, signed_at,
               kode_verifikasi, created_at
        FROM dokumen
        WHERE user_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 500
    ");
    $stmt->execute([$targetId]);

    foreach ($stmt->fetchAll() as $d) {
        $item = [
            'id'              => (int) $d['id'],
            'jenis'           => (string) $d['jenis'],
            'ref_id'          => (int) $d['ref_id'],
            'nomor'           => (string) ($d['nomor'] ?? ''),
            'judul'           => (string) ($d['judul'] ?? ''),
            'tanggal'         => (string) ($d['tanggal'] ?? ''),
            'status'          => (string) $d['status'],
            'signed_at'       => $d['signed_at'] ?? null,
            'kode_verifikasi' => (string) ($d['kode_verifikasi'] ?? ''),
            'dikirim_pada'    => (string) ($d['created_at'] ?? ''),
        ];
        $dokumenList[] = $item;

        if ((int) $d['ref_id'] > 0) {
            $dokumenPerRef[$d['jenis'] . '|' . (int) $d['ref_id']] = [
                'dokumen_id'      => (int) $d['id'],
                'status'          => (string) $d['status'],
                'signed_at'       => $d['signed_at'] ?? null,
                'kode_verifikasi' => (string) ($d['kode_verifikasi'] ?? ''),
            ];
        }
    }
} catch (Throwable $e) {
    // Instalasi lama mungkin belum punya tabel dokumen.
    $dokumenTersedia = false;
    error_log('[SIM-TKD monitoring_dokumen] tabel dokumen: ' . $e->getMessage());
}

// ------------------------------------------------------------------
// Entri per modul.
// ------------------------------------------------------------------
const BATAS_PER_SUMBER = 100;

$entri          = [];
$perJenis       = [];
$perModul       = [
    'penerimaan' => 0,
    'belanja'    => 0,
    'akuntansi'  => 0,
];
$sumberKosong   = [];
$dibatasi       = false;
$totalEntri     = 0;

foreach ($sumber as $s) {
    $sql = "SELECT {$s['select']}
            FROM `{$s['table']}`
            WHERE `user_id` = ? AND ({$s['where']})
            ORDER BY tanggal DESC, id DESC
            LIMIT " . (BATAS_PER_SUMBER + 1);

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$targetId]);
        $baris = $stmt->fetchAll();
    } catch (Throwable $e) {
        // Tabel belum ada di instalasi ini -> lewati, jangan gagalkan semua.
        $sumberKosong[] = $s['key'];
        error_log('[SIM-TKD monitoring_dokumen] ' . $s['key'] . ': ' . $e->getMessage());
        continue;
    }

    if (count($baris) > BATAS_PER_SUMBER) {
        $dibatasi = true;
        $baris = array_slice($baris, 0, BATAS_PER_SUMBER);
    }

    $jumlahSumber = 0;
    foreach ($baris as $r) {
        $ttd = null;
        if ($s['ttd_jenis'] !== null) {
            $kunci = $s['ttd_jenis'] . '|' . (int) $r['id'];
            $ttd = $dokumenPerRef[$kunci] ?? null;
        }

        $entri[] = [
            'sumber'  => $s['key'],
            'modul'   => $s['modul'],
            'jenis'   => $s['jenis'],
            'id'      => (int) $r['id'],
            'nomor'   => (string) ($r['nomor'] ?? ''),
            'judul'   => (string) ($r['judul'] ?? ''),
            'tanggal' => (string) ($r['tanggal'] ?? ''),
            'status'  => (string) ($r['status'] ?? ''),
            'nilai'   => $r['nilai'] === null ? null : (float) $r['nilai'],
            'alur_ttd'=> $s['ttd_jenis'] !== null,
            'ttd'     => $ttd,
        ];

        $jumlahSumber++;
        $perModul[$s['modul']]++;
        $perJenis[$s['jenis']] = ($perJenis[$s['jenis']] ?? 0) + 1;
        $totalEntri++;
    }
}

// Urutkan gabungan berdasarkan tanggal (terbaru dulu). Nilai tanggal bisa
// DATE atau DATETIME, jadi bandingkan sebagai string setelah dinormalisasi.
usort($entri, function (array $a, array $b): int {
    $ta = substr(str_replace(['T', ' '], '-', $a['tanggal']), 0, 10);
    $tb = substr(str_replace(['T', ' '], '-', $b['tanggal']), 0, 10);
    if ($ta === $tb) {
        return $b['id'] <=> $a['id'];
    }
    return strcmp($tb, $ta);
});

$sudahTtd = 0;
$menunggu = 0;
foreach ($dokumenList as $d) {
    if ($d['status'] === 'ditandatangani') {
        $sudahTtd++;
    } else {
        $menunggu++;
    }
}

$perJenisOut = [];
foreach ($perJenis as $jenis => $jumlah) {
    $perJenisOut[] = ['jenis' => (string) $jenis, 'jumlah' => (int) $jumlah];
}
usort($perJenisOut, function (array $a, array $b): int {
    return $b['jumlah'] <=> $a['jumlah'];
});

jsonResponse(true, 'OK', [
    'user' => [
        'id'       => (int) $pemilik['id'],
        'nama'     => (string) $pemilik['nama_lengkap'],
        'username' => (string) $pemilik['username'],
        'email'    => (string) $pemilik['email'],
        'instansi' => (string) ($pemilik['instansi'] ?? ''),
        'peran'    => (string) ($pemilik['peran'] ?? ''),
        'status'   => (string) ($pemilik['status'] ?? ''),
    ],
    'ringkasan' => [
        'total_entri'   => $totalEntri,
        'per_modul'     => $perModul,
        'dokumen_kirim' => count($dokumenList),
        'ditandatangani'=> $sudahTtd,
        'menunggu_ttd'  => $menunggu,
        'dibatasi'      => $dibatasi,
    ],
    'per_jenis'     => $perJenisOut,
    'entri'         => $entri,
    'dokumen'       => $dokumenList,
    'tersedia'      => $dokumenTersedia,
    'sumber_kosong' => $sumberKosong,
]);
