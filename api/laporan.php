<?php
/**
 * SIM-TKD - API Laporan
 * ============================================
 * Endpoint laporan (Penerimaan).
 *
 *   GET ?action=bku&dari=YYYY-MM-DD&sampai=YYYY-MM-DD
 *       : data Buku Kas Umum (BKU) Penerimaan Daerah (gabungan STBP + STS)
 *
 *   GET ?action=obyek_penerimaan&tahun=YYYY
 *       : daftar kode rekening rincian obyek penerimaan (utk dropdown Buku Pembantu)
 *
 *   GET ?action=buku_pembantu&kode_akun=4.1.1.01.03&bulan=MM&tahun=YYYY
 *       : Buku Pembantu per Rincian Obyek Penerimaan (Permendagri 55/2008) —
 *         baris STBP yang telah disetor via STS + rekap bulanan & kumulatif.
 *
 *   GET ?action=bku&dari=YYYY-MM-DD&sampai=YYYY-MM-DD
 *       : data Buku Kas Umum (BKU) Penerimaan Daerah (gabungan STBP + STS)
 *
 * Wajib login. Respons JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    jsonResponse(false, 'Tidak terautentikasi.', [], 401);
}

$pdo  = db();
$skpd = requireInstansi(); // pemisahan data multi-dinas (fail-closed)

// ---- Migrasi bertahap (cutover). Bawaan: MATI (perilaku legacy tidak berubah).
require_once __DIR__ . '/../includes/cutover.php';
cutover_maybe_proxy(__FILE__);

$action = input('action', 'bku');

if (!in_array($action, ['bku', 'rekap', 'obyek_penerimaan', 'buku_pembantu'], true)) {
    jsonResponse(false, 'Aksi tidak dikenali.', [], 422);
}

// ============================================================
// OBYEK PENERIMAAN — daftar kode rekening rincian obyek
//   GET ?action=obyek_penerimaan&tahun=YYYY
//   Sumber: anggaran_lra (pagu resmi) UNION objek yang pernah
//   dipakai di STBP. Untuk dropdown Buku Pembantu Penerimaan.
// ============================================================
if ($action === 'obyek_penerimaan') {
    $tahun = input('tahun', (string) date('Y'));
    if (!isValidTahun($tahun)) $tahun = (string) date('Y');

    $obyek = [];

    // 1) Master anggaran (LRA) — sumber nama rekening & pagu
    $sqlA = "SELECT a.kode_akun, MAX(a.nama_akun) AS nama_akun, MAX(a.anggaran) AS anggaran
             FROM anggaran_lra a
             WHERE a.tahun = ?" . ($skpd !== '' ? " AND a.skpd = ?" : "") . "
             GROUP BY a.kode_akun";
    $stmtA = $pdo->prepare($sqlA);
    $stmtA->execute($skpd !== '' ? [$tahun, $skpd] : [$tahun]);
    foreach ($stmtA->fetchAll() as $r) {
        $obyek[(string) $r['kode_akun']] = [
            'kode_akun' => (string) $r['kode_akun'],
            'nama_akun' => (string) $r['nama_akun'],
            'anggaran'  => round((float) $r['anggaran'], 2),
        ];
    }

    // 2) Objek yang pernah dipakai di STBP (belum tentu ada di anggaran)
    $sqlB = "SELECT s.akun_kode, MAX(s.akun_nama) AS nama_akun
             FROM stbp s
             WHERE s.status <> 'dihapus'" . ($skpd !== '' ? " AND s.skpd = ?" : "") . "
             GROUP BY s.akun_kode";
    $stmtB = $pdo->prepare($sqlB);
    $stmtB->execute($skpd !== '' ? [$skpd] : []);
    foreach ($stmtB->fetchAll() as $r) {
        $kode = trim((string) $r['akun_kode']);
        if ($kode === '' || isset($obyek[$kode])) continue;
        $obyek[$kode] = [
            'kode_akun' => $kode,
            'nama_akun' => (string) $r['nama_akun'],
            'anggaran'  => 0.0,
        ];
    }

    ksort($obyek);

    jsonResponse(true, 'OK', [
        'tahun' => (int) $tahun,
        'skpd'  => $skpd,
        'obyek' => array_values($obyek),
    ]);
}

// ============================================================
// BUKU PEMBANTU PER RINCIAN OBYEK PENERIMAAN
//   GET ?action=buku_pembantu&kode_akun=...&bulan=MM&tahun=YYYY
//   Baris = STBP yang telah disetor ke kas daerah via STS aktif:
//     No Urut | No. BPP | Tanggal Setor | No. STS & Bukti
//     Penerimaan Lainnya | Jumlah (Rp)
//   Rekap: Jumlah Bulan ini / s.d. Bulan Lalu / s.d. Bulan ini.
// ============================================================
if ($action === 'buku_pembantu') {
    $kode  = input('kode_akun', '');
    $bulan = (int) input('bulan', (string) (int) date('n'));
    $tahun = input('tahun', (string) date('Y'));

    if ($kode === '') {
        jsonResponse(false, 'Kode rekening rincian obyek wajib dipilih.', [], 422);
    }
    if ($bulan < 1 || $bulan > 12) $bulan = (int) date('n');
    if (!isValidTahun($tahun)) $tahun = (string) date('Y');
    $tahunI = (int) $tahun;

    $whereSkpd = $skpd !== '' ? " AND st.skpd = ?" : "";

    // --- Baris transaksi bulan terpilih ---
    $sql = "SELECT sd.nomor_stbp, sd.jumlah, st.nomor_sts, st.tanggal_sts
            FROM sts_detail sd
            INNER JOIN sts st ON st.id = sd.sts_id
            WHERE st.status = 'aktif' AND sd.akun_kode = ?
              AND YEAR(st.tanggal_sts) = ? AND MONTH(st.tanggal_sts) = ?" . $whereSkpd . "
            ORDER BY st.tanggal_sts ASC, st.id ASC, sd.id ASC";
    $params = [$kode, $tahunI, $bulan];
    if ($skpd !== '') $params[] = $skpd;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    $no = 0;
    $jumlahBulanIni = 0.0;
    foreach ($stmt->fetchAll() as $r) {
        $no++;
        $jml = (float) $r['jumlah'];
        $jumlahBulanIni += $jml;
        $rows[] = [
            'no'            => $no,
            'no_bpp'        => (string) $r['nomor_stbp'],
            'tanggal_setor' => (string) $r['tanggal_sts'],
            'no_sts'        => (string) $r['nomor_sts'],
            'jumlah'        => round($jml, 2),
        ];
    }

    // --- Kumulatif: s.d. bulan ini & s.d. bulan lalu (tahun anggaran sama) ---
    $sumSql = "SELECT COALESCE(SUM(sd.jumlah), 0)
               FROM sts_detail sd
               INNER JOIN sts st ON st.id = sd.sts_id
               WHERE st.status = 'aktif' AND sd.akun_kode = ?
                 AND YEAR(st.tanggal_sts) = ? AND MONTH(st.tanggal_sts) <= ?" . $whereSkpd;
    $run = function (int $bln) use ($pdo, $sumSql, $kode, $tahunI, $skpd): float {
        $p = [$kode, $tahunI, $bln];
        if ($skpd !== '') $p[] = $skpd;
        $st = $pdo->prepare($sumSql);
        $st->execute($p);
        return (float) $st->fetchColumn();
    };
    $jumlahSdBulanIni  = $run($bulan);
    $jumlahSdBulanLalu = $bulan > 1 ? $run($bulan - 1) : 0.0;

    // --- Identitas rekening + pagu anggaran ---
    $namaAkun = '';
    $anggaran = 0.0;
    $stA = $pdo->prepare("SELECT nama_akun, anggaran FROM anggaran_lra
                          WHERE tahun = ? AND kode_akun = ?" . ($skpd !== '' ? " AND skpd = ?" : "") . "
                          ORDER BY id DESC LIMIT 1");
    $stA->execute($skpd !== '' ? [$tahunI, $kode, $skpd] : [$tahunI, $kode]);
    if ($rA = $stA->fetch()) {
        $namaAkun = (string) $rA['nama_akun'];
        $anggaran = (float) $rA['anggaran'];
    }
    if ($namaAkun === '') {
        $stN = $pdo->prepare("SELECT MAX(s.akun_nama) FROM stbp s
                              WHERE s.akun_kode = ? AND s.status <> 'dihapus'" . ($skpd !== '' ? " AND s.skpd = ?" : ""));
        $stN->execute($skpd !== '' ? [$kode, $skpd] : [$kode]);
        $namaAkun = (string) $stN->fetchColumn();
    }

    jsonResponse(true, 'OK', [
        'kode_akun'            => $kode,
        'nama_akun'            => $namaAkun,
        'anggaran'             => round($anggaran, 2),
        'bulan'                => $bulan,
        'tahun'                => $tahunI,
        'skpd'                 => $skpd,
        'rows'                 => $rows,
        'jumlah_bulan_ini'     => round($jumlahBulanIni, 2),
        'jumlah_sd_bulan_lalu' => round($jumlahSdBulanLalu, 2),
        'jumlah_sd_bulan_ini'  => round($jumlahSdBulanIni, 2),
        'bendahara'            => (string) ($_SESSION['nama'] ?? ''),
    ]);
}

$dari   = input('dari', '');
$sampai = input('sampai', '');

if ($dari === '' || $sampai === '') {
    jsonResponse(false, 'Periode (tanggal awal dan akhir) wajib diisi.', [], 422);
}

// ============================================
// REKAPITULASI PENERIMAAN (Harian / Bulanan)
//   GET ?action=rekap&mode=harian|bulanan&dari=YYYY-MM-DD&sampai=YYYY-MM-DD
//   Baris = periode (tanggal/bulan); kolom = 3 kelompok PAD
//   (Pajak Daerah 4.1.1.x, Retribusi Daerah 4.1.2.x, Lain-Lain PAD 4.1.x lain).
//   Sumber: STBP (setoran penerimaan) — STS adalah penyetoran, bukan penerimaan.
// ============================================
if ($action === 'rekap') {
    $mode = input('mode', 'harian') === 'bulanan' ? 'bulanan' : 'harian';
    // Kunci periode: tanggal utk harian, tanggal 1 utk bulanan
    $keySql = $mode === 'bulanan' ? "DATE_FORMAT(t.tanggal, '%Y-%m-01')" : 'DATE(t.tanggal)';

    $sql = "SELECT {$keySql} AS kunci, t.akun_kode, t.akun_nama, SUM(t.jumlah) AS jumlah
            FROM stbp t
            WHERE t.status <> 'dihapus' AND t.tanggal BETWEEN ? AND ?" . ($skpd !== '' ? " AND t.skpd = ?" : "") . "
            GROUP BY kunci, t.akun_kode, t.akun_nama
            ORDER BY kunci ASC, t.akun_kode ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($skpd !== '' ? [$dari, $sampai, $skpd] : [$dari, $sampai]);

    $perKunci = []; // kunci periode => [kelompok => [[kode, nama, jumlah]], total]
    foreach ($stmt->fetchAll() as $r) {
        $kunci = (string) $r['kunci'];
        $kode  = (string) $r['akun_kode'];
        $jml   = (float) $r['jumlah'];

        if (preg_match('/^4\.1\.1\./', $kode))      $g = 0; // Pajak Daerah
        elseif (preg_match('/^4\.1\.2\./', $kode))  $g = 1; // Retribusi Daerah
        else                                        $g = 2; // Lain-Lain PAD

        if (!isset($perKunci[$kunci])) {
            $perKunci[$kunci] = ['periode' => $kunci, 'kelompok' => [[], [], []], 'total' => 0.0];
        }
        $perKunci[$kunci]['kelompok'][$g][] = [
            'kode'   => $kode,
            'nama'   => (string) $r['akun_nama'],
            'jumlah' => round($jml, 2),
        ];
        $perKunci[$kunci]['total'] += $jml;
    }
    ksort($perKunci);

    // Total per kelompok + grand total
    $totG = [0.0, 0.0, 0.0];
    $grand = 0.0;
    foreach ($perKunci as $k) {
        for ($i = 0; $i < 3; $i++) {
            foreach ($k['kelompok'][$i] as $b) $totG[$i] += $b['jumlah'];
        }
        $grand += $k['total'];
    }

    // Nama Kuasa/Pengguna Anggaran: dari STS terbaru dalam periode (blok identitas)
    $sqlK = "SELECT st.kuasa_pengguna_anggaran FROM sts st
             WHERE st.status = 'aktif' AND st.kuasa_pengguna_anggaran <> ''"
          . ($skpd !== '' ? " AND st.skpd = ?" : "")
          . " AND st.tanggal_sts BETWEEN ? AND ?
             ORDER BY st.tanggal_sts DESC, st.id DESC LIMIT 1";
    $stmtK = $pdo->prepare($sqlK);
    $stmtK->execute($skpd !== '' ? [$skpd, $dari, $sampai] : [$dari, $sampai]);
    $kuasa = (string) ($stmtK->fetchColumn() ?: '');

    jsonResponse(true, 'OK', [
        'periode'   => ['dari' => $dari, 'sampai' => $sampai],
        'mode'      => $mode,
        'skpd'      => $skpd,
        'rows'      => array_values($perKunci),
        'total_kelompok' => [round($totG[0], 2), round($totG[1], 2), round($totG[2], 2)],
        'total'     => round($grand, 2),
        'bendahara' => (string) ($_SESSION['nama'] ?? ''),
        'pengguna_anggaran' => $kuasa,
    ]);
}

$entries = [];

// --- Penerimaan dari STBP ---
$stmt = $pdo->prepare("
    SELECT s.id, s.nomor_stbp AS no_bukti, s.tanggal, s.akun_kode, s.akun_nama,
           s.jumlah, s.uraian
    FROM stbp s
    WHERE s.status <> 'dihapus' AND s.tanggal BETWEEN ? AND ?" . ($skpd !== '' ? " AND s.skpd = ?" : "") . "
    ORDER BY s.tanggal ASC, s.id ASC
");
$stmt->execute($skpd !== '' ? [$dari, $sampai, $skpd] : [$dari, $sampai]);
foreach ($stmt->fetchAll() as $r) {
    $entries[] = [
        'tanggal'       => (string) $r['tanggal'],
        'no_bukti'      => (string) $r['no_bukti'],
        'kode_rekening' => trim((string) $r['akun_kode'] . ' - ' . (string) $r['akun_nama'], ' -'),
        'uraian'        => (string) $r['uraian'],
        'penerimaan'    => (float) $r['jumlah'],
        'pengeluaran'   => 0.0,
        'jenis'         => 'STBP',
    ];
}

// --- Setoran ke Kas Daerah dari STS (keluar dari kas SKPD -> Pengeluaran) ---
$stmt = $pdo->prepare("
    SELECT t.id, t.nomor_sts AS no_bukti, t.tanggal_sts AS tanggal,
           (SELECT d.akun_kode FROM sts_detail d WHERE d.sts_id = t.id ORDER BY d.id ASC LIMIT 1) AS akun_kode,
           (SELECT d.akun_nama FROM sts_detail d WHERE d.sts_id = t.id ORDER BY d.id ASC LIMIT 1) AS akun_nama,
           t.total AS jumlah, t.keterangan AS uraian
    FROM sts t
    WHERE t.status = 'aktif' AND t.tanggal_sts BETWEEN ? AND ?" . ($skpd !== '' ? " AND t.skpd = ?" : "") . "
    ORDER BY t.tanggal_sts ASC, t.id ASC
");
$stmt->execute($skpd !== '' ? [$dari, $sampai, $skpd] : [$dari, $sampai]);
foreach ($stmt->fetchAll() as $r) {
    $entries[] = [
        'tanggal'       => (string) $r['tanggal'],
        'no_bukti'      => (string) $r['no_bukti'],
        'kode_rekening' => trim((string) $r['akun_kode'] . ' - ' . (string) $r['akun_nama'], ' -'),
        'uraian'        => (string) $r['uraian'],
        'penerimaan'    => 0.0,
        'pengeluaran'   => (float) $r['jumlah'],
        'jenis'         => 'STS',
    ];
}

// Urutkan berdasarkan tanggal, lalu hitung saldo berjalan
usort($entries, function ($a, $b) {
    return strcmp($a['tanggal'], $b['tanggal']);
});

$saldo = 0.0;
$totalPenerimaan = 0.0;
$totalPengeluaran = 0.0;
foreach ($entries as &$e) {
    $saldo += $e['penerimaan'] - $e['pengeluaran'];
    $totalPenerimaan += $e['penerimaan'];
    $totalPengeluaran += $e['pengeluaran'];
    $e['saldo'] = round($saldo, 2);
}
unset($e);

jsonResponse(true, 'OK', [
    'periode' => ['dari' => $dari, 'sampai' => $sampai],
    'entries' => array_values($entries),
    'total_penerimaan' => round($totalPenerimaan, 2),
    'total_pengeluaran' => round($totalPengeluaran, 2),
    'saldo_akhir' => round($saldo, 2),
]);
