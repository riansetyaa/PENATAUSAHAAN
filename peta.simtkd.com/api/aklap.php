<?php
/**
 * SIM-TKD (peta.simtkd.com) - API Modul AKLAP
 * ============================================
 * Menyediakan data asli dari database untuk modul AKLAP.
 * Dilindungi token API (tanpa form login).
 *
 *   GET api/aklap.php?token=XXX&action=jurnal      : daftar jurnal (STBP + STS)
 *   GET api/aklap.php?token=XXX&action=lra_rekap   : realisasi per akun (untuk LRA)
 *   GET api/aklap.php?token=XXX&action=rekap       : ringkasan jumlah data
 *
 * Token dapat dikirim via query (?token=) atau header Authorization: Bearer.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
// Catatan: config/api.php (konstanta API_TOKEN legacy) telah DIHAPUS.
// Token API bersifat dinamis — hash SHA-256 dari token rotasi-per-login
// disimpan di users.api_token. Lihat api/login.php & includes/functions.php.

header('Content-Type: application/json; charset=utf-8');

// ---- Migrasi bertahap (cutover). Bawaan: MATI (perilaku legacy tidak berubah).
// Aksi baca modul AKLAP sudah diporting; mutasi (approve/reject, jurnal biasa)
// tetap dilayani di sini sampai fase berikutnya.
require_once __DIR__ . '/../../includes/cutover.php';
cutover_maybe_proxy(__FILE__);

$pdo    = db();
$action = (string) ($_GET['action'] ?? 'jurnal');

// ---------- Verifikasi token (per-user / multi-tenant) ----------
// Token = api_token milik pengguna di tabel users.
// DB menyimpan HASH SHA-256 dari token -> cocokkan hash token yang dikirim.
$token = (string) ($_GET['token'] ?? '');
if ($token === '') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        $token = trim($m[1]);
    }
}
$tokenHash = hash('sha256', $token);
$stmtUser = $pdo->prepare("SELECT id, nama_lengkap, username, email, instansi, kota, provinsi, peran FROM users WHERE api_token = ? LIMIT 1");
$stmtUser->execute([$tokenHash]);
$user = $stmtUser->fetch();
if (!$user && $token !== '' && preg_match('/^[a-f0-9]{32}$/', $token)) {
    // MIGRASI TOKEN LEGACY (plaintext 32 hex -> hash SHA-256)
    // -------------------------------------------------------
    // NONAKTIF secara bawaan. Jalur ini pernah membiarkan token admin
    // hardcoded (yang bocor ke riwayat git) tetap berlaku sebagai kredensial.
    // Token dirotasi di api/login.php setiap kali user login, sehingga user
    // yang login kembali otomatis mendapat token hash yang valid.
    //
    // Aktifkan SEMENTARA hanya untuk memindahkan user yang belum login:
    //     SIMTKD_ALLOW_LEGACY_TOKEN=1
    // lalu NONAKTIFKAN kembali setelah migrasi selesai.
    if (getenv('SIMTKD_ALLOW_LEGACY_TOKEN') === '1') {
        try {
            $stmtLegacy = $pdo->prepare("SELECT id FROM users WHERE api_token = ? LIMIT 1");
            $stmtLegacy->execute([$token]);
            $legacyId = $stmtLegacy->fetchColumn();
            if ($legacyId) {
                $pdo->prepare("UPDATE users SET api_token = ? WHERE id = ?")->execute([$tokenHash, (int) $legacyId]);
                $stmtUser->execute([$tokenHash]);
                $user = $stmtUser->fetch();
            }
        } catch (Throwable $e) {
            error_log('[AKLAP] migrasi token legacy gagal: ' . $e->getMessage());
        }
    }
}
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token API tidak valid.'], JSON_UNESCAPED_UNICODE);
    exit;
}
// SKPD/instansi pemilik token -> pemisahan data antar dinas (multi-tenant).
// Fail-closed: token tanpa instansi ditolak (bukan melihat semua data).
$skpdUser = trim((string) ($user['instansi'] ?? ''));
if ($skpdUser === '') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Instansi akun belum diatur. Hubungi administrator.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Seluruh aksi dibungkus try/catch: jika ada kolom/tabel yang belum ada di
// database (mis. jurnal_status, tabel sp2d/spm), API tetap mengembalikan JSON
// berisi pesan yang jelas — BUKAN error 500 / halaman kosong yang membuat
// approve jurnal & LRA tidak bisa digunakan sama sekali.
try {

// ============================================
// 1. Jurnal Approve (STBP = Penerimaan, STS = Penyetoran)
// ============================================
if ($action === 'jurnal') {
    $rows = [];

    // STBP -> Penerimaan (hanya yang sudah divalidasi / tahap 3)
    $stSql    = "SELECT * FROM stbp WHERE status = 'sudah_divalidasi'";
    $stParams = [];
    if ($skpdUser !== '') { $stSql .= " AND skpd = ?"; $stParams[] = $skpdUser; }
    $stSql .= " ORDER BY tanggal ASC, id ASC";
    $st = $pdo->prepare($stSql);
    $st->execute($stParams);
    $st = $st->fetchAll();
    foreach ($st as $r) {
        // Persetujuan jurnal tersimpan di DB (jurnal_status) - TIDAK client-side.
        $jstat = (string) ($r['jurnal_status'] ?? 'belum_approve');
        $status = ($jstat === 'sudah_approve') ? 'Sudah Approve' : (($jstat === 'ditolak') ? 'Ditolak' : 'Belum Approve');
        $rows[] = [
            'no'        => 'JRN-' . ($r['nomor_stbp'] ?: 'STBP-' . $r['id']),
            'dok'       => $r['nomor_stbp'] ?: '',
            'tgl'       => $r['tanggal'] ?: '',
            'tglAkhir'  => $r['tanggal'] ?: '',
            'nilai'     => (float) ($r['jumlah'] ?? 0),
            'ket'       => $r['uraian'] ?: '',
            'status'    => $status,
            'jurnal_status' => $jstat,
            'transaksi' => 'Penerimaan',
            'sumber'    => 'stbp',
            'id'        => (int) $r['id'],
            'akun_kode' => $r['akun_kode'] ?: '',
            'akun_nama' => $r['akun_nama'] ?: '',
            'skpd'      => $r['skpd'] ?: '',
        ];
    }

    // STS -> Penyetoran
    $st2Sql    = "SELECT * FROM sts WHERE status = 'aktif'";
    $st2Params = [];
    if ($skpdUser !== '') { $st2Sql .= " AND skpd = ?"; $st2Params[] = $skpdUser; }
    $st2Sql .= " ORDER BY tanggal_sts ASC, id ASC";
    $st2 = $pdo->prepare($st2Sql);
    $st2->execute($st2Params);
    $st2 = $st2->fetchAll();
    foreach ($st2 as $r) {
        $jstat = (string) ($r['jurnal_status'] ?? 'belum_approve');
        $status = ($jstat === 'sudah_approve') ? 'Sudah Approve' : (($jstat === 'ditolak') ? 'Ditolak' : 'Belum Approve');
        $rows[] = [
            'no'        => 'JRN-' . ($r['nomor_sts'] ?: 'STS-' . $r['id']),
            'dok'       => $r['nomor_sts'] ?: '',
            'tgl'       => $r['tanggal_sts'] ?: '',
            'tglAkhir'  => $r['tanggal_acuan_akhir'] ?: ($r['tanggal_sts'] ?: ''),
            'nilai'     => (float) ($r['total'] ?? 0),
            'ket'       => $r['keterangan'] ?: '',
            'status'    => $status,
            'jurnal_status' => $jstat,
            'transaksi' => 'Penyetoran',
            'sumber'    => 'sts',
            'id'        => (int) $r['id'],
            'akun_kode' => '',
            'akun_nama' => '',
            'skpd'      => $r['skpd'] ?: '',
        ];
    }

    // SP2D -> Pengeluaran/Belanja (hanya yang sudah dicairkan)
    $blSql    = "SELECT s.*, m.nomor_spm FROM sp2d s LEFT JOIN spm m ON m.id = s.spm_id WHERE s.status = 'sudah_dicairkan'";
    $blParams = [];
    if ($skpdUser !== '') { $blSql .= " AND s.skpd = ?"; $blParams[] = $skpdUser; }
    $blSql .= " ORDER BY s.tanggal ASC, s.id ASC";
    $bl = $pdo->prepare($blSql);
    $bl->execute($blParams);
    $bl = $bl->fetchAll();
    foreach ($bl as $r) {
        $jstat = (string) ($r['jurnal_status'] ?? 'belum_approve');
        $status = ($jstat === 'sudah_approve') ? 'Sudah Approve' : (($jstat === 'ditolak') ? 'Ditolak' : 'Belum Approve');
        $rows[] = [
            'no'        => 'JRN-' . ($r['nomor_sp2d'] ?: 'SP2D-' . $r['id']),
            'dok'       => $r['nomor_sp2d'] ?: '',
            'tgl'       => $r['tanggal'] ?: '',
            'tglAkhir'  => $r['tanggal'] ?: '',
            'nilai'     => (float) ($r['jumlah'] ?? 0),
            'ket'       => 'Pencairan SP2D' . (($r['nomor_spm'] ?? '') !== '' ? ' (' . $r['nomor_spm'] . ')' : ''),
            'status'    => $status,
            'jurnal_status' => $jstat,
            'transaksi' => 'Pengeluaran',
            'sumber'    => 'sp2d',
            'id'        => (int) $r['id'],
            'akun_kode' => '',
            'akun_nama' => '',
            'skpd'      => $r['skpd'] ?: '',
        ];
    }

    // Urutkan berdasarkan tanggal lalu id
    usort($rows, function ($a, $b) {
        return strcmp($a['tgl'], $b['tgl']) ?: ($a['id'] <=> $b['id']);
    });

    echo json_encode([
        'success' => true,
        'data'    => $rows,
        'total'   => count($rows),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// 1b. Approve / Reject jurnal (persisten, multi-tenant)
// ============================================
if ($action === 'approve' || $action === 'reject') {
    $newStatus = ($action === 'approve') ? 'sudah_approve' : 'ditolak';
    $items = $_GET['items'] ?? $_POST['items'] ?? '';
    $arr = [];
    if (is_array($items)) {
        $arr = $items;
    } elseif (is_string($items) && trim($items) !== '') {
        $arr = explode(',', (string) $items);
    }
    if (count($arr) === 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Tidak ada jurnal yang dipilih.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $updated = 0;
    foreach ($arr as $item) {
        $item = trim((string) $item);
        if ($item === '') continue;
        $parts = explode(':', $item, 2);
        $sumber = strtolower((string) ($parts[0] ?? ''));
        $id = (int) ($parts[1] ?? 0);
        if ($id <= 0) continue;
        if ($sumber === 'sts') {
            $table = 'sts';
            $statusCond = "status = 'aktif'";
        } elseif ($sumber === 'sp2d') {
            $table = 'sp2d';
            $statusCond = "status = 'sudah_dicairkan'";
        } else {
            $table = 'stbp';
            $statusCond = "status = 'sudah_divalidasi'";
        }
        $sql = "UPDATE {$table} SET jurnal_status = ? WHERE id = ? AND {$statusCond}";
        $params = [$newStatus, $id];
        if ($skpdUser !== '') { $sql .= " AND skpd = ?"; $params[] = $skpdUser; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $updated += $stmt->rowCount();
    }
    echo json_encode([
        'success' => true,
        'message' => ($newStatus === 'sudah_approve' ? $updated . ' jurnal di-approve.' : $updated . ' jurnal ditolak.'),
        'updated' => $updated,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// 1c. JURNAL BIASA - input manual jurnal (menggantikan alur approve).
//     Pengguna memasukkan nomor dokumen + nomor akun secara manual;
//     entri tersimpan persisten di tabel jurnal_manual (multi-tenant).
// ============================================
if ($action === 'jurnal_biasa') {
    // Pastikan tabel tersedia (tanpa perlu import SQL manual)
    $pdo->exec("CREATE TABLE IF NOT EXISTS jurnal_manual (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id       INT UNSIGNED      DEFAULT NULL,
        skpd          VARCHAR(150)      NOT NULL DEFAULT '',
        nomor_jurnal  VARCHAR(60)       NOT NULL DEFAULT '',
        nomor_dokumen VARCHAR(100)      NOT NULL DEFAULT '',
        kode_akun     VARCHAR(50)       NOT NULL DEFAULT '',
        nama_akun     VARCHAR(200)      NOT NULL DEFAULT '',
        tanggal       DATE              NULL,
        uraian        VARCHAR(255)      NOT NULL DEFAULT '',
        jumlah        DECIMAL(15,2)     NOT NULL DEFAULT 0,
        created_at    TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_skpd (skpd),
        INDEX idx_user (user_id),
        INDEX idx_tanggal (tanggal)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // --- Hapus entri (POST subaksi=hapus&id=X) ---
    if ($method === 'POST' && ($_POST['subaksi'] ?? '') === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'ID jurnal tidak valid.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $sql = "DELETE FROM jurnal_manual WHERE id = ?";
        $params = [$id];
        if ($skpdUser !== '') { $sql .= " AND skpd = ?"; $params[] = $skpdUser; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode([
            'success' => true,
            'message' => $stmt->rowCount() > 0 ? 'Jurnal dihapus.' : 'Jurnal tidak ditemukan.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Simpan entri baru (POST) ---
    if ($method === 'POST') {
        $nomorDok = trim((string) ($_POST['nomor_dokumen'] ?? ''));
        $kodeAkun = trim((string) ($_POST['kode_akun'] ?? ''));
        $tanggal  = trim((string) ($_POST['tanggal'] ?? ''));
        $uraian   = trim((string) ($_POST['uraian'] ?? ''));
        $jumlah   = (float) ($_POST['jumlah'] ?? 0);

        if ($nomorDok === '' || $kodeAkun === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Nomor dokumen dan nomor akun wajib diisi.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($tanggal !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Format tanggal tidak valid.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($jumlah <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Jumlah harus lebih besar dari nol.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Nama akun: dari input (auto-terisi frontend) atau lookup ke akun_penerimaan
        $namaAkun = trim((string) ($_POST['nama_akun'] ?? ''));
        if ($namaAkun === '') {
            try {
                $q = $pdo->prepare("SELECT nama_akun FROM akun_penerimaan WHERE kode_akun = ? ORDER BY id DESC LIMIT 1");
                $q->execute([$kodeAkun]);
                $namaAkun = (string) ($q->fetchColumn() ?: '');
            } catch (Throwable $e) { $namaAkun = ''; }
        }

        $stmt = $pdo->prepare("INSERT INTO jurnal_manual
            (user_id, skpd, nomor_dokumen, kode_akun, nama_akun, tanggal, uraian, jumlah)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            (int) ($user['id'] ?? 0), $skpdUser, $nomorDok, $kodeAkun,
            $namaAkun, $tanggal !== '' ? $tanggal : null, $uraian, $jumlah,
        ]);
        $newId = (int) $pdo->lastInsertId();
        $nomorJurnal = 'JB-' . str_pad((string) $newId, 4, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE jurnal_manual SET nomor_jurnal = ? WHERE id = ?")->execute([$nomorJurnal, $newId]);

        echo json_encode([
            'success' => true,
            'message' => 'Jurnal ' . $nomorJurnal . ' tersimpan.',
            'id'      => $newId,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Daftar entri (GET) dengan filter periode & kata kunci ---
    $dari  = $_GET['dari'] ?? '';
    $akhir = $_GET['akhir'] ?? '';
    if ($dari !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari))  $dari = '';
    if ($akhir !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $akhir)) $akhir = '';
    $kw    = trim((string) ($_GET['q'] ?? ''));
    $tipe  = trim((string) ($_GET['tipe'] ?? ''));

    $sql = "SELECT id, nomor_jurnal, nomor_dokumen, kode_akun, nama_akun, tanggal, uraian, jumlah
            FROM jurnal_manual WHERE 1=1";
    $params = [];
    if ($skpdUser !== '') { $sql .= " AND skpd = ?"; $params[] = $skpdUser; }
    if ($dari !== '')  { $sql .= " AND tanggal >= ?"; $params[] = $dari; }
    if ($akhir !== '') { $sql .= " AND tanggal <= ?"; $params[] = $akhir; }
    if ($kw !== '') {
        $col = ['jurnal' => 'nomor_jurnal', 'dokumen' => 'nomor_dokumen', 'keterangan' => 'uraian'][$tipe] ?? 'uraian';
        if ($tipe === '' || isset(['jurnal' => 1, 'dokumen' => 1, 'keterangan' => 1][$tipe])) {
            $sql .= " AND $col LIKE ?";
            $params[] = '%' . $kw . '%';
        } else {
            $sql .= " AND (nomor_jurnal LIKE ? OR nomor_dokumen LIKE ? OR uraian LIKE ?)";
            $params = array_merge($params, ['%' . $kw . '%', '%' . $kw . '%', '%' . $kw . '%']);
        }
    }
    $sql .= " ORDER BY tanggal DESC, id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = array_map(function ($r) {
        return [
            'id'            => (int) $r['id'],
            'nomor_jurnal'  => (string) $r['nomor_jurnal'],
            'nomor_dokumen' => (string) $r['nomor_dokumen'],
            'kode_akun'     => (string) $r['kode_akun'],
            'nama_akun'     => (string) $r['nama_akun'],
            'tanggal'       => (string) ($r['tanggal'] ?? ''),
            'uraian'        => (string) $r['uraian'],
            'jumlah'        => (float) $r['jumlah'],
        ];
    }, $stmt->fetchAll());

    echo json_encode([
        'success' => true,
        'message' => 'OK',
        'data'    => $rows,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// 1d. DAFTAR AKUN - saran nomor akun utk input jurnal biasa
// ============================================
if ($action === 'akun_list') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $sql  = "SELECT kode_akun, nama_akun FROM akun_penerimaan WHERE 1=1";
    $params = [];
    if ($q !== '') { $sql .= " AND (kode_akun LIKE ? OR nama_akun LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
    $sql .= " ORDER BY kode_akun ASC LIMIT 100";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $akun = array_map(function ($r) {
            return ['kode' => (string) $r['kode_akun'], 'nama' => (string) $r['nama_akun']];
        }, $stmt->fetchAll());
    } catch (Throwable $e) {
        $akun = [];
    }
    echo json_encode(['success' => true, 'data' => $akun], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// 1c. Daftar dokumen dari SIM-TKD (STBP + STS)
//     Untuk saran "Nomor Dokumen" pada Jurnal Biasa.
// ============================================
if ($action === 'dokumen_list') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $docs = [];

    // --- STBP (penerimaan) ---
    $stSql = "SELECT nomor_stbp, tanggal, jumlah, akun_kode, akun_nama, uraian
              FROM stbp
              WHERE status <> 'dihapus' AND nomor_stbp <> ''";
    $stParams = [];
    if ($skpdUser !== '') { $stSql .= " AND skpd = ?"; $stParams[] = $skpdUser; }
    if ($q !== '')       { $stSql .= " AND (nomor_stbp LIKE ? OR uraian LIKE ?)"; $stParams[] = "%{$q}%"; $stParams[] = "%{$q}%"; }
    $stSql .= " ORDER BY tanggal DESC, id DESC LIMIT 150";
    try {
        $st = $pdo->prepare($stSql);
        $st->execute($stParams);
        foreach ($st->fetchAll() as $r) {
            $docs[] = [
                'nomor'     => (string) $r['nomor_stbp'],
                'jenis'     => 'STBP',
                'tanggal'   => (string) $r['tanggal'],
                'jumlah'    => (float) $r['jumlah'],
                'akun_kode' => (string) $r['akun_kode'],
                'akun_nama' => (string) $r['akun_nama'],
                'uraian'    => (string) $r['uraian'],
            ];
        }
    } catch (Throwable $e) { /* tabel belum ada -> lewati */ }

    // --- STS (penyetoran) ---
    $tsSql = "SELECT t.nomor_sts, t.tanggal_sts AS tanggal, t.total AS jumlah, t.keterangan AS uraian,
                     (SELECT d.akun_kode FROM sts_detail d WHERE d.sts_id = t.id ORDER BY d.id ASC LIMIT 1) AS akun_kode,
                     (SELECT d.akun_nama FROM sts_detail d WHERE d.sts_id = t.id ORDER BY d.id ASC LIMIT 1) AS akun_nama
              FROM sts t
              WHERE t.status = 'aktif' AND t.nomor_sts <> ''";
    $tsParams = [];
    if ($skpdUser !== '') { $tsSql .= " AND t.skpd = ?"; $tsParams[] = $skpdUser; }
    if ($q !== '')        { $tsSql .= " AND (t.nomor_sts LIKE ? OR t.keterangan LIKE ?)"; $tsParams[] = "%{$q}%"; $tsParams[] = "%{$q}%"; }
    $tsSql .= " ORDER BY t.tanggal_sts DESC, t.id DESC LIMIT 150";
    try {
        $ts = $pdo->prepare($tsSql);
        $ts->execute($tsParams);
        foreach ($ts->fetchAll() as $r) {
            $docs[] = [
                'nomor'     => (string) $r['nomor_sts'],
                'jenis'     => 'STS',
                'tanggal'   => (string) $r['tanggal'],
                'jumlah'    => (float) $r['jumlah'],
                'akun_kode' => (string) ($r['akun_kode'] ?? ''),
                'akun_nama' => (string) ($r['akun_nama'] ?? ''),
                'uraian'    => (string) ($r['uraian'] ?? ''),
            ];
        }
    } catch (Throwable $e) { /* tabel belum ada -> lewati */ }

    echo json_encode(['success' => true, 'data' => $docs], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// 2. LRA - realisasi per akun (dari STBP) + pagu (dari kegiatan)
// ============================================
if ($action === 'lra_rekap') {
    $realisasiByAkun = [];
    // Laporan keuangan (LRA) hanya memakai jurnal yang SUDAH di-approve
    $stSql    = "SELECT akun_kode, SUM(jumlah) AS total FROM stbp WHERE status != 'dihapus' AND akun_kode <> '' AND jurnal_status = 'sudah_approve'";
    $stParams = [];
    if ($skpdUser !== '') { $stSql .= " AND skpd = ?"; $stParams[] = $skpdUser; }
    $stSql .= " GROUP BY akun_kode";
    $st = $pdo->prepare($stSql);
    $st->execute($stParams);
    $st = $st->fetchAll();
    foreach ($st as $r) {
        $realisasiByAkun[$r['akun_kode']] = (float) $r['total'];
    }

    $totalPagu          = (float) $pdo->query("SELECT COALESCE(SUM(pagu),0) FROM kegiatan WHERE " . (($skpdUser !== '') ? ("skpd = " . $pdo->quote($skpdUser)) : '1=1'))->fetchColumn();
    $totalRealisasi     = (float) $pdo->query("SELECT COALESCE(SUM(realisasi),0) FROM kegiatan WHERE " . (($skpdUser !== '') ? ("skpd = " . $pdo->quote($skpdUser)) : '1=1'))->fetchColumn();
    $jumlahKegiatan     = (int)  $pdo->query("SELECT COUNT(*) FROM kegiatan WHERE " . (($skpdUser !== '') ? ("skpd = " . $pdo->quote($skpdUser)) : '1=1'))->fetchColumn();

    // Realisasi BELANJA = SP2D yang sudah dicairkan & sudah di-approve jurnalnya
    $belanjaCond = "status = 'sudah_dicairkan' AND jurnal_status = 'sudah_approve'" . (($skpdUser !== '') ? " AND skpd = " . $pdo->quote($skpdUser) : '');
    $totalBelanja = (float) $pdo->query("SELECT COALESCE(SUM(jumlah),0) FROM sp2d WHERE {$belanjaCond}")->fetchColumn();

    // Anggaran LRA dari setting (pengaturan/anggaran-neraca.php) per akun
    $anggaranByAkun = [];
    $tahunLra = (string) ($_GET['tahun'] ?? date('Y'));
    $agSql = "SELECT kode_akun, SUM(anggaran) AS total FROM anggaran_lra WHERE 1=1";
    $agParams = [];
    if ($skpdUser !== '') { $agSql .= " AND skpd = ?"; $agParams[] = $skpdUser; }
    $agSql .= " AND tahun = ? GROUP BY kode_akun";
    $ag = $pdo->prepare($agSql);
    $ag->execute(array_merge($agParams, [$tahunLra]));
    foreach ($ag->fetchAll() as $r) {
        $anggaranByAkun[$r['kode_akun']] = (float) $r['total'];
    }

    echo json_encode([
        'success'           => true,
        'realisasi_by_akun' => $realisasiByAkun,
        'anggaran_by_akun'  => $anggaranByAkun,
        'total_pagu'        => $totalPagu,
        'total_realisasi'   => $totalRealisasi,
        'jumlah_kegiatan'   => $jumlahKegiatan,
        'total_penerimaan'  => array_sum($realisasiByAkun),
        'total_belanja'     => $totalBelanja,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// 2b. Neraca (saldo awal per akun dari pengaturan)
// ============================================
if ($action === 'neraca_rekap') {
    $tahunN = (string) ($_GET['tahun'] ?? date('Y'));
    $nSql = "SELECT kode_akun, nama_akun, SUM(saldo) AS saldo, jenis FROM neraca_awal WHERE 1=1";
    $nParams = [];
    if ($skpdUser !== '') { $nSql .= " AND skpd = ?"; $nParams[] = $skpdUser; }
    $nSql .= " AND tahun = ? GROUP BY kode_akun, nama_akun, jenis ORDER BY kode_akun ASC";
    $n = $pdo->prepare($nSql);
    $n->execute(array_merge($nParams, [$tahunN]));
    $rows = [];
    foreach ($n->fetchAll() as $r) {
        $rows[] = [
            'kode_akun' => (string) $r['kode_akun'],
            'nama_akun' => (string) $r['nama_akun'],
            'saldo'     => (float) $r['saldo'],
            'jenis'     => (string) $r['jenis'],
        ];
    }
    echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// 2c. Profil akun AKLAP (dari data pendaftaran)
// ============================================
if ($action === 'profil') {
    echo json_encode(['success' => true, 'user' => [
        'nama'      => (string) ($user['nama_lengkap'] ?? ''),
        'username'  => (string) ($user['username'] ?? ''),
        'instansi'  => (string) ($user['instansi'] ?? ''),
        'kota'      => (string) ($user['kota'] ?? ''),
        'provinsi'  => (string) ($user['provinsi'] ?? ''),
        'peran'     => (string) ($user['peran'] ?? ''),
    ]], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// 3. Rekap ringkasan (untuk dashboard/index)
// ============================================
if ($action === 'rekap') {
    $skpdCond = ($skpdUser !== '') ? ("skpd = " . $pdo->quote($skpdUser)) : '1=1';
    $count = function (string $table, string $where = '1=1') use ($pdo): int {
        try {
            return (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    };

    echo json_encode([
        'success' => true,
        'data'    => [
            'stbp'         => $count('stbp', "status != 'dihapus' AND {$skpdCond}"),
            'sts'          => $count('sts', "status = 'aktif' AND {$skpdCond}"),
            'permohonan'   => $count('permohonan', $skpdCond),
            'kegiatan'     => $count('kegiatan', $skpdCond),
            'akun'         => $count('akun_penerimaan', $skpdCond),
            'total_pagu'   => (float) $pdo->query("SELECT COALESCE(SUM(pagu),0) FROM kegiatan WHERE {$skpdCond}")->fetchColumn(),
            'total_penerimaan' => (float) $pdo->query("SELECT COALESCE(SUM(jumlah),0) FROM stbp WHERE status != 'dihapus' AND jurnal_status = 'sudah_approve' AND {$skpdCond}")->fetchColumn(),
            'sp2d_cair'    => $count('sp2d', "status = 'sudah_dicairkan' AND {$skpdCond}"),
            'total_belanja' => (float) $pdo->query("SELECT COALESCE(SUM(jumlah),0) FROM sp2d WHERE status = 'sudah_dicairkan' AND jurnal_status = 'sudah_approve' AND {$skpdCond}")->fetchColumn(),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// Fallback
// ============================================
http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.'], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Jangan biarkan error database mematikan seluruh API.
    // Detail error HANYA ke log server — tidak dikirim ke client (keamanan).
    error_log('[AKLAP] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Kesalahan pada server. Silakan coba beberapa saat lagi atau hubungi administrator.',
    ], JSON_UNESCAPED_UNICODE);
}
