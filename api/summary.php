<?php
/**
 * SIM-TKD - API Summary Dashboard
 * ============================================
 * Mengembalikan data ringkasan APBD dari database untuk dashboard frontend.
 * Wajib login (GET, respons JSON).
 *
 * Data yang dikembalikan:
 *   - user          : info pengguna dari sesi
 *   - tahun         : tahun anggaran aktif
 *   - summary       : total pagu, realisasi, persen, jumlah kegiatan
 *   - chart         : pagu & realisasi per tahun
 *   - kegiatan      : daftar kegiatan tahun berjalan
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// ---- Migrasi bertahap (cutover), lihat laravel/docs/CUTOVER-TANPA-DOWNTIME.md
// Bila endpoint ini aktif di config/cutover.php, request diteruskan ke
// aplikasi Laravel (beserta tiket SSO dari sesi). Bawaan: MATI -> tidak ada
// perubahan perilaku sama sekali.
require_once __DIR__ . '/../includes/cutover.php';
cutover_maybe_proxy(__FILE__);

if (!isLoggedIn()) {
    jsonResponse(false, 'Tidak terautentikasi.', [], 401);
}

$pdo    = db();
$tahun  = (int) date('Y');
// Pemisahan data multi-dinas (kegiatan kini per instansi, fail-closed)
$skpd   = requireInstansi();

// Ringkasan tahun berjalan
$stmt = $pdo->prepare("SELECT SUM(pagu) AS pagu, SUM(realisasi) AS realisasi, COUNT(*) AS jumlah FROM kegiatan WHERE tahun = ? AND (? = '' OR skpd = ?)");
$stmt->execute([$tahun, $skpd, $skpd]);
$row = $stmt->fetch();

$totalPagu      = (float) ($row['pagu'] ?? 0);
$totalRealisasi = (float) ($row['realisasi'] ?? 0);
$jumlahKegiatan = (int) ($row['jumlah'] ?? 0);
$persen         = $totalPagu > 0 ? round(($totalRealisasi / $totalPagu) * 100, 2) : 0;

// Tahun tersedia untuk grafik
$stmtYears = $pdo->prepare("SELECT DISTINCT tahun FROM kegiatan WHERE (? = '' OR skpd = ?) ORDER BY tahun DESC");
$stmtYears->execute([$skpd, $skpd]);
$years = $stmtYears->fetchAll(PDO::FETCH_COLUMN);
if (empty($years)) {
    $years = [$tahun];
}

$chart = [];
foreach ($years as $y) {
    $stmt = $pdo->prepare("SELECT SUM(pagu) AS pagu, SUM(realisasi) AS realisasi FROM kegiatan WHERE tahun = ? AND (? = '' OR skpd = ?)");
    $stmt->execute([$y, $skpd, $skpd]);
    $r = $stmt->fetch();
    $chart[$y] = [
        'pagu'      => (float) ($r['pagu'] ?? 0),
        'realisasi' => (float) ($r['realisasi'] ?? 0),
    ];
}

// Daftar kegiatan tahun berjalan
$stmt = $pdo->prepare("SELECT id, nama_kegiatan, tahun, pagu, realisasi, status FROM kegiatan WHERE tahun = ? AND (? = '' OR skpd = ?) ORDER BY pagu DESC LIMIT 10");
$stmt->execute([$tahun, $skpd, $skpd]);
$kegiatan = $stmt->fetchAll();

// ===== Ringkasan lintas modul (Penerimaan + Belanja) =====
// Dibungkus try/catch: jika tabel modul belum ada (migrasi belum jalan),
// dashboard tetap tampil dengan nilai 0 — bukan error 500.
$modul = [];
$runMod = function (string $key, string $sql, bool $asInt = false) use ($pdo, $skpd, &$modul) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$skpd, $skpd]); // pola (? = '' OR skpd = ?)
        $modul[$key] = $asInt ? (int) $stmt->fetchColumn() : (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[SIM-TKD summary] modul ' . $key . ': ' . $e->getMessage());
        $modul[$key] = 0;
    }
};
$runMod('stbp',          "SELECT COALESCE(SUM(jumlah),0) FROM stbp WHERE status='sudah_divalidasi' AND (?='' OR skpd=?)");
$runMod('sts',           "SELECT COALESCE(SUM(total),0) FROM sts WHERE status='aktif' AND (?='' OR skpd=?)");
$runMod('permohonan',    "SELECT COUNT(*) FROM permohonan WHERE (?='' OR skpd=?)", true);
$runMod('spd',           "SELECT COALESCE(SUM(jumlah),0) FROM spd WHERE (?='' OR skpd=?)");
$runMod('spp',           "SELECT COALESCE(SUM(jumlah),0) FROM spp WHERE (?='' OR skpd=?)");
$runMod('sp2d_dicairkan',"SELECT COALESCE(SUM(jumlah),0) FROM sp2d WHERE status='sudah_dicairkan' AND (?='' OR skpd=?)");
$runMod('rekanan',       "SELECT COUNT(*) FROM rekanan WHERE (?='' OR skpd=?)", true);

jsonResponse(true, 'OK', [
    'user' => [
        'nama'     => $_SESSION['nama'] ?? '',
        'username' => $_SESSION['username'] ?? '',
        'instansi' => $_SESSION['instansi'] ?? '',
        'peran'    => $_SESSION['peran'] ?? '',
    ],
    'tahun' => $tahun,
    'summary' => [
        'total_pagu'      => $totalPagu,
        'total_realisasi' => $totalRealisasi,
        'persen'          => $persen,
        'jumlah_kegiatan' => $jumlahKegiatan,
    ],
    'modul' => [
        'penerimaan' => [
            'stbp'       => $modul['stbp'] ?? 0,
            'sts'        => $modul['sts'] ?? 0,
            'permohonan' => $modul['permohonan'] ?? 0,
        ],
        'belanja' => [
            'spd'            => $modul['spd'] ?? 0,
            'spp'            => $modul['spp'] ?? 0,
            'sp2d_dicairkan' => $modul['sp2d_dicairkan'] ?? 0,
            'rekanan'        => $modul['rekanan'] ?? 0,
        ],
    ],
    'chart' => $chart,
    'kegiatan' => array_map(function ($k) {
        return [
            'id'          => (int) $k['id'],
            'nama_kegiatan'=> $k['nama_kegiatan'],
            'tahun'       => (int) $k['tahun'],
            'pagu'        => (float) $k['pagu'],
            'realisasi'   => (float) $k['realisasi'],
            'status'      => $k['status'],
        ];
    }, $kegiatan),
]);
