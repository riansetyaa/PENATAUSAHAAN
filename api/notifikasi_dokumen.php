<?php
/**
 * SIM-TKD - Notifikasi Dokumen Belum Dikirim ke Tanda Tangan
 * ==========================================================
 * Menampilkan dokumen sumber (SPD, SPP, SPM, SP2D, LPJ, NPD,
 * Pengajuan TU, SKP Daerah, STBP, STS) milik instansi pengguna yang
 * BELUM memiliki baris di tabel `dokumen` — artinya belum pernah
 * dikirim ke antrean Tanda Tangan Dokumen (doc.simtkd.com).
 *
 * Dokumen yang sudah dikirim (baik masih menunggu TTD maupun sudah
 * ditandatangani) tidak ditampilkan lagi, karena sudah "masuk" ke
 * antrean tanda tangan.
 *
 * Metode: GET. Respons JSON.
 *   {
 *     success, message,
 *     total, data: [{id, jenis, nomor, tanggal, judul, url}],
 *     per_jenis: [{jenis, jumlah}],
 *     tersedia, dibatasi
 *   }
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
}

if (!isLoggedIn()) {
    jsonResponse(false, 'Sesi berakhir. Silakan login kembali.', [], 401);
}

$pdo  = db();
$skpd = requireInstansi();

/**
 * Cek keberadaan tabel tanpa membuat error bila skema lama belum
 * memiliki tabel tersebut (mis. instalasi yang belum menjalankan
 * database/dokumen_ttd.sql).
 */
function notifTabelAda(PDO $pdo, string $tabel): bool
{
    static $cache = [];
    if (array_key_exists($tabel, $cache)) {
        return $cache[$tabel];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$tabel]);
        return $cache[$tabel] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[SIM-TKD] notifikasi_dokumen cek tabel ' . $tabel . ': ' . $e->getMessage());
        return $cache[$tabel] = false;
    }
}

if (!notifTabelAda($pdo, 'dokumen')) {
    jsonResponse(true, 'Tabel dokumen tanda tangan belum tersedia.', [
        'total'     => 0,
        'data'      => [],
        'per_jenis' => [],
        'tersedia'  => false,
        'dibatasi'  => false,
    ]);
}

/**
 * Definisi dokumen sumber yang dapat dikirim ke Tanda Tangan.
 * `nomor` dan `tanggal` adalah nama kolom pada tabel sumber.
 * `cetak` diisi true bila halaman cetak mendukung ?id=... sehingga
 * notifikasi bisa langsung membuka dokumen yang dimaksud.
 */
$sumber = [
    [
        'jenis'   => 'SPD',
        'tabel'   => 'spd',
        'nomor'   => 'nomor_spd',
        'tanggal' => 'tanggal',
        'url'     => 'belanja/spd.html',
        'cetak'   => false,
        'filter'  => '',
    ],
    [
        'jenis'   => 'SPP',
        'tabel'   => 'spp',
        'nomor'   => 'nomor_spp',
        'tanggal' => 'tanggal',
        'url'     => 'belanja/spp.html',
        'cetak'   => false,
        'filter'  => '',
    ],
    [
        'jenis'   => 'SPM',
        'tabel'   => 'spm',
        'nomor'   => 'nomor_spm',
        'tanggal' => 'tanggal',
        'url'     => 'belanja/spm.html',
        'cetak'   => false,
        'filter'  => '',
    ],
    [
        'jenis'   => 'SP2D',
        'tabel'   => 'sp2d',
        'nomor'   => 'nomor_sp2d',
        'tanggal' => 'tanggal',
        'url'     => 'belanja/sp2d.html',
        'cetak'   => false,
        'filter'  => '',
    ],
    [
        'jenis'   => 'LPJ',
        'tabel'   => 'lpj',
        'nomor'   => 'nomor_lpj',
        'tanggal' => 'tanggal',
        'url'     => 'belanja/lpj.html',
        'cetak'   => false,
        'filter'  => '',
    ],
    [
        'jenis'   => 'NPD',
        'tabel'   => 'npd',
        'nomor'   => 'nomor_npd',
        'tanggal' => 'tanggal',
        'url'     => 'belanja/npd.html',
        'cetak'   => false,
        'filter'  => '',
    ],
    [
        'jenis'   => 'Pengajuan TU',
        'tabel'   => 'pengajuan_tu',
        'nomor'   => 'nomor_pengajuan',
        'tanggal' => 'tanggal',
        'url'     => 'belanja/pengajuan-tu.html',
        'cetak'   => false,
        'filter'  => '',
    ],
    [
        'jenis'   => 'SKP Daerah',
        'tabel'   => 'skp_daerah',
        'nomor'   => 'nomor_skp',
        'tanggal' => 'tanggal',
        'url'     => 'penerimaan/skp-daerah-cetak.html',
        'cetak'   => true,
        'filter'  => "AND s.status <> 'dihapus'",
    ],
    [
        'jenis'   => 'STBP',
        'tabel'   => 'stbp',
        'nomor'   => 'nomor_stbp',
        'tanggal' => 'tanggal',
        'url'     => 'penerimaan/stbp-cetak.html',
        'cetak'   => true,
        'filter'  => "AND s.status <> 'dihapus'",
    ],
    [
        'jenis'   => 'STS',
        'tabel'   => 'sts',
        'nomor'   => 'nomor_sts',
        'tanggal' => 'tanggal_sts',
        'url'     => 'penerimaan/sts-cetak.html',
        'cetak'   => true,
        'filter'  => "AND s.status = 'aktif'",
    ],
];

$data     = [];
$perJenis = [];

foreach ($sumber as $def) {
    if (!notifTabelAda($pdo, $def['tabel'])) {
        continue;
    }

    // Nama tabel/kolom berasal dari daftar tetap di atas (bukan input
    // pengguna), sehingga aman disisipkan ke SQL. Nilai skpd & jenis
    // tetap dikirim lewat parameter.
    $sql = "SELECT s.id,
                   s.`" . $def['nomor'] . "` AS nomor,
                   s.`" . $def['tanggal'] . "` AS tanggal
            FROM `" . $def['tabel'] . "` s
            WHERE s.skpd = ?
              " . $def['filter'] . "
              AND NOT EXISTS (
                  SELECT 1 FROM `dokumen` d
                  WHERE d.jenis = ? AND d.ref_id = s.id
              )
            ORDER BY s.`" . $def['tanggal'] . "` DESC, s.id DESC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$skpd, $def['jenis']]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[SIM-TKD] notifikasi_dokumen ' . $def['jenis'] . ': ' . $e->getMessage());
        continue;
    }

    if (!$rows) {
        continue;
    }

    $perJenis[] = [
        'jenis'  => $def['jenis'],
        'jumlah' => count($rows),
    ];

    foreach ($rows as $r) {
        $id    = (int) $r['id'];
        $nomor = trim((string) ($r['nomor'] ?? ''));
        $url   = $def['url'] . ($def['cetak'] ? '?id=' . $id : '');

        $data[] = [
            'id'      => $id,
            'jenis'   => $def['jenis'],
            'nomor'   => $nomor,
            'tanggal' => $r['tanggal'] ? (string) $r['tanggal'] : null,
            'judul'   => $def['jenis'] . ($nomor !== '' ? ' ' . $nomor : ''),
            'url'     => $url,
        ];
    }
}

// Urutkan gabungan semua jenis: tanggal terbaru lebih dulu.
usort($data, function (array $a, array $b): int {
    $ta = (string) ($a['tanggal'] ?? '');
    $tb = (string) ($b['tanggal'] ?? '');
    if ($ta === $tb) {
        return $b['id'] <=> $a['id'];
    }
    return strcmp($tb, $ta);
});

$total     = count($data);
$dibatasi  = $total > 100;
if ($dibatasi) {
    $data = array_slice($data, 0, 100);
}

$pesan = $total > 0
    ? $total . ' dokumen belum dimasukkan ke Tanda Tangan Dokumen.'
    : 'Semua dokumen sudah dimasukkan ke Tanda Tangan Dokumen.';

jsonResponse(true, $pesan, [
    'total'     => $total,
    'data'      => $data,
    'per_jenis' => $perJenis,
    'tersedia'  => true,
    'dibatasi'  => $dibatasi,
]);
