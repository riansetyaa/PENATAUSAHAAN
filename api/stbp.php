<?php
/**
 * SIM-TKD - API STBP (Surat Tanda Bukti Penerimaan)
 * ============================================
 * Endpoint untuk pembuatan STBP (Penerimaan).
 *
 *   GET ?status=belum_diverifikasi&q=   : daftar STBP + jumlah per status
 *   POST action=create                  : buat STBP baru
 *   POST action=update                  : perbarui data STBP
 *   POST action=delete                  : hapus STBP (status dihapus)
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

$STATUS_LIST = ['belum_diverifikasi', 'sudah_diverifikasi', 'sudah_diotorisasi', 'sudah_divalidasi', 'dihapus'];

// ============================================
// GET - Daftar STBP
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // --- Detail satu STBP (untuk halaman cetak) ---
    $detailId = (int) input('id', '0');
    if ($detailId > 0) {
        $stmt = $pdo->prepare("SELECT s.*, u.nama_lengkap AS dibuat_oleh,
                                      sk.nomor_skp      AS nomor_skp,
                                      sk.akun_kode      AS skp_akun_kode,
                                      sk.jenis_pajak    AS jenis_pajak,
                                      sk.nama_penyetor  AS skp_nama_penyetor,
                                      sk.nilai_keputusan AS skp_nilai
                               FROM stbp s
                               LEFT JOIN users u ON u.id = s.user_id
                               LEFT JOIN skp_daerah sk ON sk.id = s.skp_daerah_id
                               WHERE s.id = ?" . ($skpd !== '' ? " AND s.skpd = ?" : ""));
        $stmt->execute($skpd !== '' ? [$detailId, $skpd] : [$detailId]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonResponse(false, 'STBP tidak ditemukan.', [], 404);
        }

        $stmtPay = $pdo->prepare("SELECT * FROM stbp_pembayaran WHERE stbp_id = ? ORDER BY id ASC LIMIT 1");
        $stmtPay->execute([$detailId]);
        $pay = $stmtPay->fetch() ?: [];

        $stmtPend = $pdo->prepare("SELECT * FROM stbp_pendapatan WHERE stbp_id = ? ORDER BY id ASC");
        $stmtPend->execute([$detailId]);
        $pends = $stmtPend->fetchAll();

        jsonResponse(true, 'OK', [
            'stbp' => [
                'id'          => (int) $row['id'],
                'skpd'        => (string) $row['skpd'],
                'nomor_stbp'  => (string) $row['nomor_stbp'],
                'tanggal'     => (string) $row['tanggal'],
                'akun_kode'   => (string) $row['akun_kode'],
                'akun_nama'   => (string) $row['akun_nama'],
                'jumlah'      => (float) $row['jumlah'],
                'uraian'      => (string) $row['uraian'],
                'status'      => (string) $row['status'],
                'dibuat_oleh' => (string) ($row['dibuat_oleh'] ?? ''),
                'created_at'  => (string) $row['created_at'],
                'skp_daerah_id' => (int) ($row['skp_daerah_id'] ?? 0),
                'nomor_skp'     => (string) ($row['nomor_skp'] ?? ''),
                'jenis_pajak'   => (string) ($row['jenis_pajak'] ?? ''),
                'skp_akun_kode' => (string) ($row['skp_akun_kode'] ?? ''),
                'skp_nama_penyetor' => (string) ($row['skp_nama_penyetor'] ?? ''),
                'skp_nilai'     => (float) ($row['skp_nilai'] ?? 0),
            ],
            'pembayaran' => [
                'metode'         => (string) ($pay['metode_penyetoran'] ?? ''),
                'nama_penyetor'  => (string) ($pay['nama_penyetor'] ?? ''),
                'bank'           => (string) ($pay['nama_bank'] ?? ''),
                'nomor_rekening' => (string) ($pay['nomor_rekening'] ?? ''),
            ],
            'pendapatan' => array_map(function ($p) {
                return [
                    'akun_kode'     => (string) $p['akun_kode'],
                    'akun_nama'     => (string) $p['akun_nama'],
                    'rekening_kode' => (string) $p['rekening_bank'],
                    'rekening_nama' => (string) $p['rekening_nama'],
                    'nominal'       => (float) $p['nominal'],
                ];
            }, $pends),
        ]);
    }

    $q      = input('q');
    $status = input('status', 'belum_diverifikasi');

    // Status khusus: STBP yang SIAP dimasukkan ke STS & jurnal (SUDAH melalui 3 tahap: verifikasi, otorisasi, validasi)
    $STS_ELIGIBLE = ['sudah_divalidasi'];
    $isStsReady = ($status === 'siap_sts');

    if (!$isStsReady && !in_array($status, $STATUS_LIST, true)) {
        $status = 'belum_diverifikasi';
    }

    // Jumlah per status (untuk tab)
    $counts = [];
    foreach ($STATUS_LIST as $st) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM stbp WHERE status = ?" . ($skpd !== '' ? " AND skpd = ?" : ""));
        $stmt->execute($skpd !== '' ? [$st, $skpd] : [$st]);
        $counts[$st] = (int) $stmt->fetchColumn();
    }

    $statusCond = $isStsReady
        ? "s.status IN ('sudah_diotorisasi','sudah_divalidasi')
           AND NOT EXISTS (
               SELECT 1 FROM sts_detail sd
               INNER JOIN sts st ON st.id = sd.sts_id
               WHERE sd.stbp_id = s.id AND st.status = 'aktif'
           )"
        : "s.status = ?";
    $sql = "SELECT s.*, u.nama_lengkap AS dibuat_oleh,
                   sp.metode_penyetoran AS metode_penyetoran,
                   sp.nama_penyetor     AS nama_penyetor,
                   sp.nama_bank         AS nama_bank,
                   sp.nomor_rekening    AS nomor_rekening,
                   sk.nomor_skp         AS nomor_skp,
                   sk.jenis_pajak       AS jenis_pajak
            FROM stbp s
            LEFT JOIN users u ON u.id = s.user_id
            LEFT JOIN stbp_pembayaran sp ON sp.stbp_id = s.id
            LEFT JOIN skp_daerah sk ON sk.id = s.skp_daerah_id
            WHERE $statusCond" . ($skpd !== '' ? " AND s.skpd = ?" : "");
    $params = [];
    if (!$isStsReady) $params[] = $status;
    if ($skpd !== '') $params[] = $skpd;

    if ($q !== '') {
        $sql   .= " AND (s.nomor_stbp LIKE ? OR s.akun_nama LIKE ? OR s.uraian LIKE ? OR s.skpd LIKE ?)";
        $like   = '%' . $q . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }

    $sql .= " ORDER BY s.tanggal DESC, s.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonResponse(true, 'OK', [
        'status'      => $status,
        'status_list' => $STATUS_LIST,
        'counts'      => $counts,
        'data' => array_map(function ($r) {
            return [
                'id'         => (int) $r['id'],
                'skpd'       => (string) $r['skpd'],
                'nomor_stbp' => (string) $r['nomor_stbp'],
                'tanggal'    => (string) $r['tanggal'],
                'akun_kode'  => (string) $r['akun_kode'],
                'akun_nama'  => (string) $r['akun_nama'],
                'jumlah'     => (float) $r['jumlah'],
                'uraian'     => (string) $r['uraian'],
                'status'     => (string) $r['status'],
                'metode_penyetoran' => (string) ($r['metode_penyetoran'] ?? ''),
                'nama_penyetor'     => (string) ($r['nama_penyetor'] ?? ''),
                'bank'              => (string) ($r['nama_bank'] ?? ''),
                'nomor_rekening'    => (string) ($r['nomor_rekening'] ?? ''),
                'nomor_skp'         => (string) ($r['nomor_skp'] ?? ''),
                'jenis_pajak'       => (string) ($r['jenis_pajak'] ?? ''),
                'skp_daerah_id'     => (int) ($r['skp_daerah_id'] ?? 0),
                'dibuat_oleh'=> (string) ($r['dibuat_oleh'] ?? ''),
                'created_at' => (string) $r['created_at'],
            ];
        }, $rows),
    ]);
}

// ============================================
// POST - Buat STBP baru
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $action = $body['action'] ?? 'create';

    // ---------- 0. Majukan tahap status STBP (verifikasi -> otorisasi -> validasi) ----------
    if ($action === 'update_status') {
        $id     = (int) ($body['id'] ?? 0);
        $target = (string) ($body['status'] ?? '');
        $skpdS  = $skpd; // fail-closed di atas

        if ($id <= 0) {
            jsonResponse(false, 'ID tidak valid.', [], 422);
        }

        $STAGES = ['belum_diverifikasi', 'sudah_diverifikasi', 'sudah_diotorisasi', 'sudah_divalidasi'];
        if (!in_array($target, $STAGES, true)) {
            jsonResponse(false, 'Status tahap tidak valid.', [], 422);
        }

        // Ambil status saat ini (hanya milik instansi yang sama)
        $sel = $pdo->prepare("SELECT status FROM stbp WHERE id = ?" . ($skpdS !== '' ? " AND skpd = ?" : ""));
        $sel->execute($skpdS !== '' ? [$id, $skpdS] : [$id]);
        $cur = $sel->fetchColumn();
        if ($cur === false) {
            jsonResponse(false, 'STBP tidak ditemukan atau bukan milik instansi Anda.', [], 404);
        }

        // Hanya boleh maju SATU tahap secara berurutan
        $curIdx = array_search((string) $cur, $STAGES, true);
        $tgtIdx = array_search($target, $STAGES, true);
        if ($tgtIdx !== $curIdx + 1) {
            jsonResponse(false, 'Tahap tidak boleh melompat. Majukan status secara berurutan.', [], 422);
        }

        $upd = $pdo->prepare("UPDATE stbp SET status = ? WHERE id = ?" . ($skpdS !== '' ? " AND skpd = ?" : ""));
        $upd->execute($skpdS !== '' ? [$target, $id, $skpdS] : [$target, $id]);
        jsonResponse(true, 'Status STBP berhasil dimajukan ke tahap berikutnya.', ['id' => $id, 'status' => $target]);
    }

    if ($action === 'update') {
        $id      = (int) ($body['id'] ?? 0);
        $tanggal = trim((string) ($body['tanggal'] ?? ''));
        $uraian  = trim((string) ($body['uraian'] ?? ''));
        $payments = is_array($body['data_pembayaran'] ?? null) ? $body['data_pembayaran'] : [];

        if ($id <= 0) {
            jsonResponse(false, 'ID tidak valid untuk pembaruan.', [], 422);
        }
        if ($tanggal === '') {
            jsonResponse(false, 'Tanggal wajib diisi.', ['field' => 'tanggal'], 422);
        }

        $stmtCur = $pdo->prepare("SELECT id, status, skp_daerah_id FROM stbp WHERE id = ?" . ($skpd !== '' ? " AND skpd = ?" : ""));
        $stmtCur->execute($skpd !== '' ? [$id, $skpd] : [$id]);
        $cur = $stmtCur->fetch();
        if (!$cur) {
            jsonResponse(false, 'STBP tidak ditemukan atau bukan milik instansi Anda.', [], 404);
        }
        if ((string) $cur['status'] === 'dihapus') {
            jsonResponse(false, 'STBP yang dihapus tidak dapat diperbarui.', [], 422);
        }
        if ((string) $cur['status'] === 'sudah_divalidasi') {
            jsonResponse(false, 'STBP yang sudah divalidasi tidak dapat diperbarui.', [], 422);
        }

        $stmtUsed = $pdo->prepare("SELECT COUNT(*) FROM sts_detail sd
                                   INNER JOIN sts st ON st.id = sd.sts_id
                                   WHERE sd.stbp_id = ? AND st.status = 'aktif'");
        $stmtUsed->execute([$id]);
        if ((int) $stmtUsed->fetchColumn() > 0) {
            jsonResponse(false, 'STBP sudah dipakai pada STS aktif sehingga tidak dapat diperbarui.', [], 422);
        }

        $namaPenyetor = '';
        $skpId = (int) ($cur['skp_daerah_id'] ?? 0);
        if ($skpId > 0) {
            $stmtSkp = $pdo->prepare("SELECT nama_penyetor FROM skp_daerah WHERE id = ?" . ($skpd !== '' ? " AND skpd = ?" : ""));
            $stmtSkp->execute($skpd !== '' ? [$skpId, $skpd] : [$skpId]);
            $namaPenyetor = (string) ($stmtSkp->fetchColumn() ?: '');
        }

        $pay = is_array($payments[0] ?? null) ? $payments[0] : [];
        $metode = trim((string) ($pay['metode'] ?? 'non_tunai'));
        $bank = trim((string) ($pay['bank'] ?? ''));
        $nomorRek = trim((string) ($pay['nomor_rekening'] ?? ''));
        $namaPenyetorInput = trim((string) ($pay['nama_penyetor'] ?? ''));
        if ($namaPenyetor === '') {
            $namaPenyetor = $namaPenyetorInput;
        }

        $pdo->beginTransaction();
        try {
            $stmtUpd = $pdo->prepare("UPDATE stbp SET tanggal = ?, uraian = ? WHERE id = ?" . ($skpd !== '' ? " AND skpd = ?" : ""));
            $paramsUpd = [$tanggal, $uraian, $id];
            if ($skpd !== '') $paramsUpd[] = $skpd;
            $stmtUpd->execute($paramsUpd);

            $stmtPayCur = $pdo->prepare("SELECT id FROM stbp_pembayaran WHERE stbp_id = ? ORDER BY id ASC LIMIT 1");
            $stmtPayCur->execute([$id]);
            $payId = (int) ($stmtPayCur->fetchColumn() ?: 0);

            if ($payId > 0) {
                $stmtPayUpd = $pdo->prepare("UPDATE stbp_pembayaran
                                             SET metode_penyetoran = ?, nama_penyetor = ?, nama_bank = ?, nomor_rekening = ?
                                             WHERE id = ?");
                $stmtPayUpd->execute([$metode, $namaPenyetor, $bank, $nomorRek, $payId]);
            } else {
                $stmtPayIns = $pdo->prepare("INSERT INTO stbp_pembayaran (stbp_id, metode_penyetoran, nama_penyetor, nama_bank, nomor_rekening)
                                             VALUES (?, ?, ?, ?, ?)");
                $stmtPayIns->execute([$id, $metode, $namaPenyetor, $bank, $nomorRek]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[SIM-TKD] stbp update gagal: ' . $e->getMessage());
            jsonResponse(false, 'Gagal memperbarui STBP.', [], 500);
        }

        jsonResponse(true, 'STBP berhasil diperbarui.', ['id' => $id], 200);
    }

    if ($action === 'delete') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(false, 'ID tidak valid.', [], 422);
        }

        $stmtCur = $pdo->prepare("SELECT id, status, skp_daerah_id, nomor_stbp FROM stbp WHERE id = ?" . ($skpd !== '' ? " AND skpd = ?" : ""));
        $stmtCur->execute($skpd !== '' ? [$id, $skpd] : [$id]);
        $cur = $stmtCur->fetch();
        if (!$cur) {
            jsonResponse(false, 'STBP tidak ditemukan atau bukan milik instansi Anda.', [], 404);
        }
        if ((string) $cur['status'] === 'dihapus') {
            jsonResponse(false, 'STBP ini sudah dihapus.', [], 422);
        }

        $stmtUsed = $pdo->prepare("SELECT COUNT(*) FROM sts_detail sd
                                   INNER JOIN sts st ON st.id = sd.sts_id
                                   WHERE sd.stbp_id = ? AND st.status = 'aktif'");
        $stmtUsed->execute([$id]);
        if ((int) $stmtUsed->fetchColumn() > 0) {
            jsonResponse(false, 'STBP sudah dipakai pada STS aktif sehingga tidak dapat dihapus. Hapus STS-nya terlebih dahulu.', [], 422);
        }

        $skpId = (int) ($cur['skp_daerah_id'] ?? 0);
        $pdo->beginTransaction();
        try {
            $stmtUpd = $pdo->prepare("UPDATE stbp SET status = 'dihapus' WHERE id = ?" . ($skpd !== '' ? " AND skpd = ?" : ""));
            $paramsUpd = [$id];
            if ($skpd !== '') {
                $paramsUpd[] = $skpd;
            }
            $stmtUpd->execute($paramsUpd);

            if ($skpId > 0) {
                $lain = $pdo->prepare("SELECT COUNT(*) FROM stbp WHERE skp_daerah_id = ? AND id <> ? AND status <> 'dihapus'");
                $lain->execute([$skpId, $id]);
                if ((int) $lain->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE skp_daerah SET status = 'aktif' WHERE id = ? AND status = 'terpakai'")->execute([$skpId]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[SIM-TKD] stbp hapus gagal: ' . $e->getMessage());
            jsonResponse(false, 'Gagal menghapus STBP.', [], 500);
        }

        jsonResponse(true, 'STBP ' . (string) $cur['nomor_stbp'] . ' berhasil dihapus.', [
            'id'     => $id,
            'status' => 'dihapus',
        ]);
    }

    if ($action !== 'create') {
        jsonResponse(false, 'Aksi tidak dikenali.', [], 422);
    }

    $nomor  = trim((string) ($body['nomor_stbp'] ?? ''));
    $tanggal = trim((string) ($body['tanggal'] ?? ''));
    $kode   = trim((string) ($body['akun_kode'] ?? ''));
    $nama   = trim((string) ($body['akun_nama'] ?? ''));
    $jumlah = (float) ($body['jumlah'] ?? 0);
    $uraian = trim((string) ($body['uraian'] ?? ''));
    // skpd selalu dari sesi (jangan percaya input klien)
    $skpd   = requireInstansi();
    $skpId  = (int) ($body['skp_daerah_id'] ?? 0);
    $payments  = is_array($body['data_pembayaran'] ?? null) ? $body['data_pembayaran'] : [];
    $pendapatan = is_array($body['data_pendapatan'] ?? null) ? $body['data_pendapatan'] : [];

    // SKP Daerah WAJIB dipilih, valid, milik instansi yang sama, dan masih aktif.
    // Data STBP harus sama dengan SKP Daerah -> akun, nominal, dan nama penyetor diambil dari SKP.
    if ($skpId <= 0) {
        jsonResponse(false, 'SKP Daerah wajib dipilih sebagai dasar pembuatan STBP.', ['field' => 'skp_daerah_id'], 422);
    }
    $stmtSkp = $pdo->prepare("SELECT id, nomor_skp, akun_kode, jenis_pajak, nama_penyetor, objek_pajak, nilai_keputusan FROM skp_daerah WHERE id = ? AND status = 'aktif'" . ($skpd !== '' ? " AND skpd = ?" : ""));
    $stmtSkp->execute($skpd !== '' ? [$skpId, $skpd] : [$skpId]);
    $skpRow = $stmtSkp->fetch();
    if (!$skpRow) {
        jsonResponse(false, 'SKP Daerah tidak valid / sudah terpakai. Pilih SKP Daerah yang masih aktif.', [], 422);
    }
    $skpNomorSkp     = (string) $skpRow['nomor_skp'];
    $skpNamaPenyetor = (string) $skpRow['nama_penyetor'];

    // --- Data STBP disamakan PENUH dengan SKP Daerah (server authority, abaikan input klien) ---
    $kode   = (string) $skpRow['akun_kode'];          // akun penerimaan terpilih di SKP
    $nama   = (string) $skpRow['jenis_pajak'];        // jenis pajak = nama akun penerimaan
    $jumlah = (float) $skpRow['nilai_keputusan'];     // nominal = nilai ketetapan SKP
    $uraian = trim((string) $skpRow['objek_pajak']);
    $uraian = ($uraian !== '' ? $uraian . ' | ' : '') . 'SKP Daerah ' . $skpNomorSkp;
    if ($jumlah <= 0) {
        jsonResponse(false, 'Nilai ketetapan SKP Daerah harus lebih dari 0.', ['field' => 'jumlah'], 422);
    }

    // Auto-generate nomor STBP jika tidak diisi
    if ($nomor === '') {
        $nomor = 'STBP-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }

    if ($tanggal === '') {
        jsonResponse(false, 'Tanggal wajib diisi.', ['field' => 'tanggal'], 422);
    }
    if ($jumlah <= 0) {
        jsonResponse(false, 'Jumlah penerimaan harus lebih dari 0.', ['field' => 'jumlah'], 422);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO stbp (user_id, skp_daerah_id, skpd, nomor_stbp, tanggal, akun_kode, akun_nama, jumlah, uraian, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'belum_diverifikasi')
        ");
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $skpId > 0 ? $skpId : null,
            $skpd,
            $nomor,
            $tanggal,
            $kode,
            $nama,
            $jumlah,
            $uraian,
        ]);
        $stbpId = (int) $pdo->lastInsertId();

        // Simpan data pembayaran (jika ada) — nama_penyetor mengikuti SKP Daerah (jika dipilih)
        if (count($payments) > 0) {
            $stmtPay = $pdo->prepare("
                INSERT INTO stbp_pembayaran (stbp_id, metode_penyetoran, nama_penyetor, nama_bank, nomor_rekening)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($payments as $p) {
                $stmtPay->execute([
                    $stbpId,
                    trim((string) ($p['metode'] ?? 'non_tunai')),
                    $skpNamaPenyetor !== '' ? $skpNamaPenyetor : trim((string) ($p['nama_penyetor'] ?? '')),
                    trim((string) ($p['bank'] ?? '')),
                    trim((string) ($p['nomor_rekening'] ?? '')),
                ]);
            }
        }

        // SKP Daerah yang dipakai tidak boleh dipakai lagi (menghindari data beda)
        if ($skpId > 0) {
            $pdo->prepare("UPDATE skp_daerah SET status = 'terpakai' WHERE id = ?")->execute([$skpId]);
        }

        // Simpan data pendapatan — OTOMATIS dari SKP Daerah (bukan input manual).
        // Satu baris: akun penerimaan terpilih di SKP, nominal = nilai ketetapan SKP.
        $stmtPend = $pdo->prepare("
            INSERT INTO stbp_pendapatan (stbp_id, akun_kode, akun_nama, rekening_bank, rekening_nama, rekening_nomor, nominal)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtPend->execute([
            $stbpId,
            $kode,
            $nama,
            '',
            '',
            '',
            $jumlah,
        ]);

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        jsonResponse(false, 'Gagal menyimpan STBP.', [], 500);
    }

    jsonResponse(true, 'STBP berhasil dibuat.', [
        'id'    => $stbpId,
        'nomor' => $nomor,
    ], 201);
}

jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
