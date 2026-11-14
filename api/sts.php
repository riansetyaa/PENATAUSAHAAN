<?php
/**
 * SIM-TKD - API STS (Surat Tanda Setoran)
 * ============================================
 * Endpoint untuk pengelolaan STS (Penerimaan).
 *
 *   GET ?status=aktif&q=   : daftar STS + jumlah per status
 *   GET ?id=X              : detail STS (termasuk rincian STBP)
 *   POST action=create     : buat STS baru (berisi pilihan STBP)
 *   POST action=delete     : hapus STS (status dihapus, STBP bisa dipakai lagi)
 *
 * Wajib login. Respons JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// ---- Migrasi bertahap (cutover). Bawaan: MATI (perilaku legacy tidak berubah).
require_once __DIR__ . '/../includes/cutover.php';
cutover_maybe_proxy(__FILE__);

if (!isLoggedIn()) {
    jsonResponse(false, 'Tidak terautentikasi.', [], 401);
}

$pdo  = db();
$skpd = requireInstansi(); // pemisahan data multi-dinas (fail-closed)

$STATUS_LIST = ['aktif', 'dihapus'];

// ============================================
// GET - Daftar / Detail STS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // --- LPJ Bendahara Penerimaan (laporan pertanggungjawaban) ---
    if (input('lpj', '') === '1') {
        $dari  = input('dari', '');
        $akhir = input('akhir', '');
        if ($dari !== '' && !isValidTanggal($dari))   $dari = '';
        if ($akhir !== '' && !isValidTanggal($akhir)) $akhir = '';

        // A. Penerimaan: STBP tervalidasi dalam periode, dirinci per metode penyetoran
        $sql1 = "SELECT COALESCE(SUM(s.jumlah),0) AS total,
                        COALESCE(SUM(CASE WHEN sp.metode_penyetoran = 'tunai' THEN s.jumlah ELSE 0 END),0) AS tunai,
                        COALESCE(SUM(CASE WHEN sp.metode_penyetoran <> 'tunai' THEN s.jumlah ELSE 0 END),0) AS non_tunai
                 FROM stbp s
                 LEFT JOIN stbp_pembayaran sp ON sp.stbp_id = s.id
                 WHERE s.status = 'sudah_divalidasi'"
              . ($skpd !== '' ? " AND s.skpd = ?" : "")
              . ($dari !== '' ? " AND s.tanggal >= ?" : "")
              . ($akhir !== '' ? " AND s.tanggal <= ?" : "");
        $params = [];
        if ($skpd !== '') $params[] = $skpd;
        if ($dari !== '') $params[] = $dari;
        if ($akhir !== '') $params[] = $akhir;
        $stmt1 = $pdo->prepare($sql1);
        $stmt1->execute($params);
        $r1 = $stmt1->fetch();
        $penerimaan = [
            'total'     => (float) $r1['total'],
            'tunai'     => (float) $r1['tunai'],
            'non_tunai' => (float) $r1['non_tunai'],
        ];

        // C. Jumlah penyetoran: total STS aktif dalam periode
        $sql2 = "SELECT COALESCE(SUM(st.total),0) FROM sts st WHERE st.status = 'aktif'"
              . ($skpd !== '' ? " AND st.skpd = ?" : "")
              . ($dari !== '' ? " AND st.tanggal_sts >= ?" : "")
              . ($akhir !== '' ? " AND st.tanggal_sts <= ?" : "");
        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute($params);
        $penyetoran = (float) $stmt2->fetchColumn();

        // Kuasa Pengguna Anggaran: dari STS terbaru dalam periode (blok tanda tangan kiri)
        $sqlK = "SELECT st.kuasa_pengguna_anggaran FROM sts st
                 WHERE st.status = 'aktif' AND st.kuasa_pengguna_anggaran <> ''"
              . ($skpd !== '' ? " AND st.skpd = ?" : "")
              . ($dari !== '' ? " AND st.tanggal_sts >= ?" : "")
              . ($akhir !== '' ? " AND st.tanggal_sts <= ?" : "")
              . " ORDER BY st.tanggal_sts DESC, st.id DESC LIMIT 1";
        $stmtK = $pdo->prepare($sqlK);
        $stmtK->execute($params);
        $kuasa = (string) ($stmtK->fetchColumn() ?: '');

        jsonResponse(true, 'OK', [
            'periode' => ['dari' => $dari, 'akhir' => $akhir],
            'skpd'    => $skpd,
            'penerimaan' => $penerimaan,
            'penyetoran' => $penyetoran,
            'saldo'   => $penerimaan['total'] - $penyetoran,
            'bendahara' => (string) ($_SESSION['nama'] ?? ''),
            'kuasa_pengguna_anggaran' => $kuasa,
        ]);
    }

    // --- Register STS (laporan rekap) ---
    if (input('register', '') === '1') {
        $dari  = input('dari', '');
        $akhir = input('akhir', '');
        if ($dari !== '' && !isValidTanggal($dari))   $dari = '';
        if ($akhir !== '' && !isValidTanggal($akhir)) $akhir = '';

        $where  = "st.status = 'aktif'";
        $params = [];
        if ($skpd !== '') { $where .= " AND st.skpd = ?"; $params[] = $skpd; }
        if ($dari !== '') { $where .= " AND st.tanggal_sts >= ?"; $params[] = $dari; }
        if ($akhir !== '') { $where .= " AND st.tanggal_sts <= ?"; $params[] = $akhir; }

        // Satu baris = satu baris pendapatan (snapshot sts_detail);
        // penyetor diambil dari STBP terkait, fallback ke penyetor STS.
        $sql = "SELECT st.nomor_sts, st.tanggal_sts,
                       sd.akun_kode, sd.akun_nama, sd.jumlah,
                       COALESCE(NULLIF(sp.nama_penyetor, ''), st.nama_penyetor) AS nama_penyetor
                FROM sts st
                JOIN sts_detail sd ON sd.sts_id = st.id
                LEFT JOIN stbp s ON s.id = sd.stbp_id
                LEFT JOIN stbp_pembayaran sp ON sp.stbp_id = s.id
                WHERE $where
                ORDER BY st.tanggal_sts ASC, st.id ASC, sd.id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $total = 0.0;
        $data = array_map(function ($r) use (&$total) {
            $jumlah = (float) $r['jumlah'];
            $total += $jumlah;
            return [
                'nomor_sts'     => (string) $r['nomor_sts'],
                'tanggal'       => (string) $r['tanggal_sts'],
                'akun_kode'     => (string) $r['akun_kode'],
                'akun_nama'     => (string) $r['akun_nama'],
                'jumlah'        => $jumlah,
                'nama_penyetor' => (string) ($r['nama_penyetor'] ?? ''),
            ];
        }, $rows);

        // Kuasa Pengguna Anggaran: dari STS terbaru dalam periode (utk blok tanda tangan kiri)
        $sqlK = "SELECT st.kuasa_pengguna_anggaran FROM sts st
                 WHERE st.status = 'aktif' AND st.kuasa_pengguna_anggaran <> ''"
              . ($skpd !== '' ? " AND st.skpd = ?" : "")
              . ($dari !== '' ? " AND st.tanggal_sts >= ?" : "")
              . ($akhir !== '' ? " AND st.tanggal_sts <= ?" : "")
              . " ORDER BY st.tanggal_sts DESC, st.id DESC LIMIT 1";
        $stmtK = $pdo->prepare($sqlK);
        $stmtK->execute($params);
        $kuasa = (string) ($stmtK->fetchColumn() ?: '');

        jsonResponse(true, 'OK', [
            'periode' => ['dari' => $dari, 'akhir' => $akhir],
            'skpd'    => $skpd,
            'rows'    => $data,
            'total'   => $total,
            'bendahara' => (string) ($_SESSION['nama'] ?? ''),
            'kuasa_pengguna_anggaran' => $kuasa,
        ]);
    }

    // --- Detail satu STS ---
    $detailId = (int) input('id', '0');
    if ($detailId > 0) {
        $stmt = $pdo->prepare("SELECT s.*, u.nama_lengkap AS dibuat_oleh
                               FROM sts s
                               LEFT JOIN users u ON u.id = s.user_id
                               WHERE s.id = ?" . ($skpd !== '' ? " AND s.skpd = ?" : ""));
        $stmt->execute($skpd !== '' ? [$detailId, $skpd] : [$detailId]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonResponse(false, 'STS tidak ditemukan.', [], 404);
        }

        $stmtD = $pdo->prepare("SELECT * FROM sts_detail WHERE sts_id = ? ORDER BY id ASC");
        $stmtD->execute([$detailId]);
        $details = $stmtD->fetchAll();

        jsonResponse(true, 'OK', [
            'sts' => [
                'id'                 => (int) $row['id'],
                'skpd'               => (string) $row['skpd'],
                'nomor_sts'          => (string) $row['nomor_sts'],
                'nama_penyetor'      => (string) $row['nama_penyetor'],
                'tanggal_sts'        => (string) $row['tanggal_sts'],
                'tanggal_acuan_dari' => (string) $row['tanggal_acuan_dari'],
                'tanggal_acuan_akhir'=> (string) $row['tanggal_acuan_akhir'],
                'mengetahui'         => (string) $row['mengetahui'],
                'kuasa_pengguna_anggaran' => (string) ($row['kuasa_pengguna_anggaran'] ?? ''),
                'nama_bank'          => (string) $row['nama_bank'],
                'nomor_rekening'     => (string) $row['nomor_rekening'],
                'nama_rekening'      => (string) $row['nama_rekening'],
                'keterangan'         => (string) $row['keterangan'],
                'total'              => (float) $row['total'],
                'status'             => (string) $row['status'],
                'dibuat_oleh'        => (string) ($row['dibuat_oleh'] ?? ''),
                'created_at'         => (string) $row['created_at'],
            ],
            'detail' => array_map(function ($d) {
                return [
                    'id'         => (int) $d['id'],
                    'stbp_id'    => (int) $d['stbp_id'],
                    'nomor_stbp' => (string) $d['nomor_stbp'],
                    'tanggal'    => (string) $d['tanggal'],
                    'akun_kode'  => (string) $d['akun_kode'],
                    'akun_nama'  => (string) $d['akun_nama'],
                    'jumlah'     => (float) $d['jumlah'],
                    'uraian'     => (string) $d['uraian'],
                ];
            }, $details),
        ]);
    }

    // --- Daftar STS ---
    $q      = input('q');
    $status = input('status', 'aktif');

    if (!in_array($status, $STATUS_LIST, true)) {
        $status = 'aktif';
    }

    $counts = [];
    foreach ($STATUS_LIST as $st) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sts WHERE status = ?" . ($skpd !== '' ? " AND skpd = ?" : ""));
        $stmt->execute($skpd !== '' ? [$st, $skpd] : [$st]);
        $counts[$st] = (int) $stmt->fetchColumn();
    }

    $sql = "SELECT s.*, u.nama_lengkap AS dibuat_oleh
            FROM sts s
            LEFT JOIN users u ON u.id = s.user_id
            WHERE s.status = ?" . ($skpd !== '' ? " AND s.skpd = ?" : "");
    $params = $skpd !== '' ? [$status, $skpd] : [$status];

    if ($q !== '') {
        $sql   .= " AND (s.nomor_sts LIKE ? OR s.nama_penyetor LIKE ? OR s.nama_bank LIKE ? OR s.keterangan LIKE ? OR s.skpd LIKE ?)";
        $like   = '%' . $q . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
    }

    $sql .= " ORDER BY s.tanggal_sts DESC, s.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonResponse(true, 'OK', [
        'status'      => $status,
        'status_list' => $STATUS_LIST,
        'counts'      => $counts,
        'data' => array_map(function ($r) {
            return [
                'id'            => (int) $r['id'],
                'skpd'          => (string) $r['skpd'],
                'nomor_sts'     => (string) $r['nomor_sts'],
                'nama_penyetor' => (string) $r['nama_penyetor'],
                'tanggal_sts'   => (string) $r['tanggal_sts'],
                'nama_bank'     => (string) $r['nama_bank'],
                'nomor_rekening'=> (string) $r['nomor_rekening'],
                'nama_rekening' => (string) $r['nama_rekening'],
                'keterangan'    => (string) $r['keterangan'],
                'total'         => (float) $r['total'],
                'status'        => (string) $r['status'],
                'dibuat_oleh'   => (string) ($r['dibuat_oleh'] ?? ''),
                'created_at'    => (string) $r['created_at'],
            ];
        }, $rows),
    ]);
}

// ============================================
// POST - Buat STS baru
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $action = $body['action'] ?? 'create';

    if ($action === 'delete') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(false, 'ID tidak valid.', [], 422);
        }

        $stmtCur = $pdo->prepare("SELECT id, status, nomor_sts FROM sts WHERE id = ?" . ($skpd !== '' ? " AND skpd = ?" : ""));
        $stmtCur->execute($skpd !== '' ? [$id, $skpd] : [$id]);
        $cur = $stmtCur->fetch();
        if (!$cur) {
            jsonResponse(false, 'STS tidak ditemukan atau bukan milik instansi Anda.', [], 404);
        }
        if ((string) $cur['status'] === 'dihapus') {
            jsonResponse(false, 'STS ini sudah dihapus.', [], 422);
        }

        $upd = $pdo->prepare("UPDATE sts SET status = 'dihapus' WHERE id = ?" . ($skpd !== '' ? " AND skpd = ?" : ""));
        $params = [$id];
        if ($skpd !== '') {
            $params[] = $skpd;
        }
        $upd->execute($params);

        jsonResponse(true, 'STS ' . (string) $cur['nomor_sts'] . ' berhasil dihapus. STBP di dalamnya bisa dipilih lagi.', [
            'id'     => $id,
            'status' => 'dihapus',
        ]);
    }

    if ($action !== 'create') {
        jsonResponse(false, 'Aksi tidak dikenali.', [], 422);
    }

    $nomor        = trim((string) ($body['nomor_sts'] ?? ''));
    $nama_penyetor = trim((string) ($body['nama_penyetor'] ?? ''));
    $tanggal_sts  = trim((string) ($body['tanggal_sts'] ?? ''));
    $tanggal_dari = trim((string) ($body['tanggal_acuan_dari'] ?? ''));
    $tanggal_akhir= trim((string) ($body['tanggal_acuan_akhir'] ?? ''));
    $mengetahui   = trim((string) ($body['mengetahui'] ?? ''));
    $kuasaKpa    = trim((string) ($body['kuasa_pengguna_anggaran'] ?? ''));
    $nama_bank    = trim((string) ($body['nama_bank'] ?? ''));
    $nomor_rek    = trim((string) ($body['nomor_rekening'] ?? ''));
    $nama_rek     = trim((string) ($body['nama_rekening'] ?? ''));
    $keterangan   = trim((string) ($body['keterangan'] ?? ''));
    // skpd selalu dari sesi (jangan percaya input klien)
    $skpd         = requireInstansi();
    $stbp_ids     = is_array($body['stbp_ids'] ?? null) ? array_map('intval', $body['stbp_ids']) : [];

    // Auto-generate nomor STS jika tidak diisi
    if ($nomor === '') {
        $nomor = 'STS-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }

    if ($tanggal_sts === '') {
        jsonResponse(false, 'Tanggal STS wajib diisi.', [], 422);
    }
    if (count($stbp_ids) === 0) {
        jsonResponse(false, 'Pilih minimal satu STBP.', [], 422);
    }

    // Validasi (server-side): STBP yang dipilih harus SUDAH divalidasi (tahap 3)
    $in = implode(',', array_fill(0, count($stbp_ids), '?'));
    $chkParams = $stbp_ids;
    if ($skpd !== '') $chkParams[] = $skpd;
    $chk = $pdo->prepare("SELECT COUNT(*) FROM stbp WHERE id IN ($in) AND status = 'sudah_divalidasi'" . ($skpd !== '' ? " AND skpd = ?" : ""));
    $chk->execute($chkParams);
    if ((int) $chk->fetchColumn() !== count($stbp_ids)) {
        jsonResponse(false, 'Ada STBP yang belum divalidasi (tahap 3) sehingga tidak dapat dimasukkan ke STS.', [], 422);
    }

    // Validasi: STBP yang sudah pernah dibuatkan STS tidak boleh dipilih lagi
    $in2 = implode(',', array_fill(0, count($stbp_ids), '?'));
    $chk2 = $pdo->prepare("SELECT COUNT(*) FROM sts_detail sd
                           INNER JOIN sts st ON st.id = sd.sts_id
                           WHERE sd.stbp_id IN ($in2) AND st.status = 'aktif'");
    $chk2->execute($stbp_ids);
    if ((int) $chk2->fetchColumn() > 0) {
        jsonResponse(false, 'Ada STBP yang sudah pernah dibuatkan STS. STBP tersebut tidak dapat dipilih lagi.', [], 422);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sts (user_id, skpd, nomor_sts, nama_penyetor, tanggal_sts,
                             tanggal_acuan_dari, tanggal_acuan_akhir, mengetahui, kuasa_pengguna_anggaran,
                             nama_bank, nomor_rekening, nama_rekening, keterangan, total, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'aktif')
        ");
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $skpd,
            $nomor,
            $nama_penyetor,
            $tanggal_sts,
            $tanggal_dari !== '' ? $tanggal_dari : null,
            $tanggal_akhir !== '' ? $tanggal_akhir : null,
            $mengetahui,
            $kuasaKpa,
            $nama_bank,
            $nomor_rek,
            $nama_rek,
            $keterangan,
        ]);
        $stsId = $pdo->lastInsertId();

        // Simpan rincian STBP
        $stmtD = $pdo->prepare("
            INSERT INTO sts_detail (sts_id, stbp_id, nomor_stbp, tanggal, akun_kode, akun_nama, jumlah, uraian)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        // Hanya pakai STBP yang SUDAH divalidasi (tahap 3), milik instansi yang sama
        $stmtS = $pdo->prepare("SELECT id, nomor_stbp, tanggal, akun_kode, akun_nama, jumlah, uraian FROM stbp WHERE id = ? AND status = 'sudah_divalidasi' AND (? = '' OR skpd = ?)");
        $total = 0.0;
        foreach ($stbp_ids as $sid) {
            $stmtS->execute([$sid, $skpd, $skpd]);
            $sb = $stmtS->fetch();
            if (!$sb) continue;
            $stmtD->execute([
                $stsId,
                (int) $sb['id'],
                (string) $sb['nomor_stbp'],
                (string) $sb['tanggal'],
                (string) $sb['akun_kode'],
                (string) $sb['akun_nama'],
                (float) $sb['jumlah'],
                (string) $sb['uraian'],
            ]);
            $total += (float) $sb['jumlah'];
        }

        $pdo->prepare("UPDATE sts SET total = ? WHERE id = ?")->execute([$total, $stsId]);

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        jsonResponse(false, 'Gagal menyimpan STS.', [], 500);
    }

    jsonResponse(true, 'STS berhasil dibuat.', [
        'id'    => $stsId,
        'nomor' => $nomor,
    ], 201);
}

jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
