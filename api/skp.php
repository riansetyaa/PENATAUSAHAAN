<?php
/**
 * SIM-TKD - API SKP Daerah (Surat Ketetapan Pajak Daerah)
 * ============================================
 * Endpoint alur Penerimaan SEBELUM STBP.
 *
 *   GET  ?status=aktif&q=  : daftar SKP Daerah + jumlah per status
 *   GET  ?id=X             : detail satu SKP Daerah
 *   POST action=create     : buat SKP Daerah baru
 *   POST action=update     : perbarui data SKP Daerah
 *   POST action=delete     : hapus (soft) SKP Daerah
 *
 * Wajib login. Respons JSON. Data dibatasi per instansi (multi-tenant).
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

$STATUS_LIST = ['aktif', 'terpakai', 'dihapus'];

// ============================================
// GET - Daftar / Detail SKP Daerah
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $detailId = (int) input('id', '0');
    if ($detailId > 0) {
        $stmt = $pdo->prepare(
            "SELECT s.*, u.nama_lengkap AS dibuat_oleh
             FROM skp_daerah s
             LEFT JOIN users u ON u.id = s.user_id
             WHERE s.id = ?" . ($skpd !== '' ? " AND s.skpd = ?" : "")
        );
        $stmt->execute($skpd !== '' ? [$detailId, $skpd] : [$detailId]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonResponse(false, 'SKP Daerah tidak ditemukan.', [], 404);
        }
        jsonResponse(true, 'OK', ['skp' => skpMap($row)]);
    }

    $q      = input('q');
    $status = input('status', 'semua');

    $counts = [];
    foreach ($STATUS_LIST as $st) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM skp_daerah WHERE status = ?" . ($skpd !== '' ? " AND skpd = ?" : ""));
        $stmt->execute($skpd !== '' ? [$st, $skpd] : [$st]);
        $counts[$st] = (int) $stmt->fetchColumn();
    }

    $where  = '';
    $params = [];
    if ($status !== 'semua') {
        if (!in_array($status, $STATUS_LIST, true)) {
            $status = 'semua';
        } else {
            $where .= "s.status = ?";
            $params[] = $status;
        }
    } else {
        $where .= "s.status <> 'dihapus'";
    }
    if ($skpd !== '') {
        $where .= ($where !== '' ? ' AND ' : '') . "s.skpd = ?";
        $params[] = $skpd;
    }
    if ($q !== '') {
        $where .= ($where !== '' ? ' AND ' : '') . "(s.nomor_skp LIKE ? OR s.nama_penyetor LIKE ? OR s.jenis_pajak LIKE ? OR s.objek_pajak LIKE ? OR s.akun_kode LIKE ?)";
        $like = '%' . $q . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
    }

    $sql = "SELECT s.*, u.nama_lengkap AS dibuat_oleh
            FROM skp_daerah s
            LEFT JOIN users u ON u.id = s.user_id"
        . ($where !== '' ? " WHERE $where" : "")
        . " ORDER BY s.tanggal DESC, s.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    jsonResponse(true, 'OK', [
        'status'      => $status,
        'status_list' => $STATUS_LIST,
        'counts'      => $counts,
        'data'        => array_map('skpMap', $stmt->fetchAll()),
    ]);
}

// ============================================
// POST - Aksi (create / update / delete)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $action = (string) ($body['action'] ?? 'create');

    if ($action === 'delete') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(false, 'ID tidak valid.', [], 422);
        }
        $upd = $pdo->prepare("UPDATE skp_daerah SET status = 'dihapus' WHERE id = ?" . ($skpd !== '' ? " AND skpd = ?" : ""));
        $upd->execute($skpd !== '' ? [$id, $skpd] : [$id]);
        if ($upd->rowCount() === 0) {
            jsonResponse(false, 'SKP Daerah tidak ditemukan atau bukan milik instansi Anda.', [], 404);
        }
        jsonResponse(true, 'SKP Daerah berhasil dihapus.');
    }

    if ($action !== 'create' && $action !== 'update') {
        jsonResponse(false, 'Aksi tidak dikenali.', [], 422);
    }

    $id          = (int) ($body['id'] ?? 0);
    $nomor       = trim((string) ($body['nomor_skp'] ?? ''));
    $tanggal     = trim((string) ($body['tanggal'] ?? ''));
    $jenisPajak  = trim((string) ($body['jenis_pajak'] ?? ''));
    $akunKode    = trim((string) ($body['akun_kode'] ?? ''));
    $namaPenyetor= trim((string) ($body['nama_penyetor'] ?? ''));
    $alamat      = trim((string) ($body['alamat'] ?? ''));
    $npwpd       = trim((string) ($body['npwpd'] ?? ''));
    $objek       = trim((string) ($body['objek_pajak'] ?? ''));
    $nilai       = (float) ($body['nilai_keputusan'] ?? 0);
    $masaDari    = trim((string) ($body['masa_pajak_dari'] ?? ''));
    $masaAkhir   = trim((string) ($body['masa_pajak_akhir'] ?? ''));
    $jatuhTempo  = trim((string) ($body['jatuh_tempo'] ?? ''));
    $keterangan  = trim((string) ($body['keterangan'] ?? ''));

    // Auto-generate nomor SKP jika kosong
    if ($nomor === '') {
        $nomor = 'SKP-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }

    if ($tanggal === '') {
        jsonResponse(false, 'Tanggal SKP wajib diisi.', ['field' => 'tanggal'], 422);
    }
    if ($namaPenyetor === '') {
        jsonResponse(false, 'Nama Penyetor (Wajib Pajak) wajib diisi.', ['field' => 'nama_penyetor'], 422);
    }
    if ($nilai <= 0) {
        jsonResponse(false, 'Nilai ketetapan harus lebih dari 0.', ['field' => 'nilai_keputusan'], 422);
    }
    foreach (['masa_pajak_dari' => $masaDari, 'masa_pajak_akhir' => $masaAkhir, 'jatuh_tempo' => $jatuhTempo] as $f => $v) {
        if ($v !== '' && !isValidTanggal($v)) {
            jsonResponse(false, 'Format tanggal tidak valid (' . $f . ').', ['field' => $f], 422);
        }
    }

    // Jenis pajak WAJIB terikat ke Akun Penerimaan yang aktif di Pengaturan (milik instansi yang sama).
    // Server yang menentukan kode & nama akun (input klien hanya sebagai pencari).
    $akunTerm = $akunKode !== '' ? $akunKode : $jenisPajak;
    if ($akunTerm === '') {
        jsonResponse(false, 'Jenis pajak wajib dipilih dari daftar Akun Penerimaan.', ['field' => 'jenis_pajak'], 422);
    }
    $akunStmt = $pdo->prepare(
        "SELECT kode_akun, nama_akun FROM akun_penerimaan
         WHERE status_aktif = 1 AND (kode_akun = ? OR nama_akun = ?)" . ($skpd !== '' ? " AND skpd = ?" : "") . " LIMIT 1"
    );
    $akunParams = [$akunTerm, $akunTerm];
    if ($skpd !== '') $akunParams[] = $skpd;
    $akunStmt->execute($akunParams);
    $akunRow = $akunStmt->fetch();
    if (!$akunRow) {
        jsonResponse(false, 'Jenis pajak wajib dipilih dari daftar Akun Penerimaan yang aktif di menu Pengaturan.', ['field' => 'jenis_pajak'], 422);
    }
    $akunKode   = (string) $akunRow['kode_akun'];
    $jenisPajak = (string) $akunRow['nama_akun'];

    try {
        if ($action === 'create') {
            $stmt = $pdo->prepare(
                "INSERT INTO skp_daerah (user_id, skpd, nomor_skp, tanggal, akun_kode, jenis_pajak, nama_penyetor, alamat, npwpd, objek_pajak, nilai_keputusan, masa_pajak_dari, masa_pajak_akhir, jatuh_tempo, keterangan, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aktif')"
            );
            $stmt->execute([
                (int) ($_SESSION['user_id'] ?? 0),
                $skpd, $nomor, $tanggal, $akunKode, $jenisPajak, $namaPenyetor, $alamat, $npwpd, $objek,
                $nilai,
                $masaDari !== '' ? $masaDari : null,
                $masaAkhir !== '' ? $masaAkhir : null,
                $jatuhTempo !== '' ? $jatuhTempo : null,
                $keterangan,
            ]);
            $id = (int) $pdo->lastInsertId();
        } else {
            if ($id <= 0) {
                jsonResponse(false, 'ID tidak valid untuk pembaruan.', [], 422);
            }
            $stmt = $pdo->prepare(
                "UPDATE skp_daerah
                 SET nomor_skp = ?, tanggal = ?, akun_kode = ?, jenis_pajak = ?, nama_penyetor = ?,
                     alamat = ?, npwpd = ?, objek_pajak = ?, nilai_keputusan = ?, masa_pajak_dari = ?,
                     masa_pajak_akhir = ?, jatuh_tempo = ?, keterangan = ?
                 WHERE id = ?" . ($skpd !== '' ? " AND skpd = ?" : "")
            );
            $params = [
                $nomor, $tanggal, $akunKode, $jenisPajak, $namaPenyetor, $alamat, $npwpd, $objek, $nilai,
                $masaDari !== '' ? $masaDari : null,
                $masaAkhir !== '' ? $masaAkhir : null,
                $jatuhTempo !== '' ? $jatuhTempo : null,
                $keterangan, $id,
            ];
            if ($skpd !== '') $params[] = $skpd;
            $stmt->execute($params);
            if ($stmt->rowCount() === 0) {
                // rowCount 0 bisa berarti tidak ada perubahan; pastikan data ada
                $cek = $pdo->prepare("SELECT id FROM skp_daerah WHERE id = ?" . ($skpd !== '' ? " AND skpd = ?" : ""));
                $cek->execute($skpd !== '' ? [$id, $skpd] : [$id]);
                if (!$cek->fetch()) {
                    jsonResponse(false, 'SKP Daerah tidak ditemukan atau bukan milik instansi Anda.', [], 404);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[SIM-TKD] skp simpan gagal: ' . $e->getMessage());
        jsonResponse(false, 'Gagal menyimpan SKP Daerah.', [], 500);
    }

    jsonResponse(true, $action === 'create' ? 'SKP Daerah berhasil dibuat.' : 'SKP Daerah berhasil diperbarui.', [
        'id'    => $id,
        'nomor' => $nomor,
    ], $action === 'create' ? 201 : 200);
}

jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);

/** Pemetaan baris skp_daerah -> JSON. */
function skpMap(array $r): array
{
    return [
        'id'              => (int) $r['id'],
        'skpd'            => (string) $r['skpd'],
        'nomor_skp'       => (string) $r['nomor_skp'],
        'tanggal'         => (string) $r['tanggal'],
        'akun_kode'       => (string) ($r['akun_kode'] ?? ''),
        'jenis_pajak'     => (string) $r['jenis_pajak'],
        'nama_penyetor'   => (string) $r['nama_penyetor'],
        'alamat'          => (string) ($r['alamat'] ?? ''),
        'npwpd'           => (string) ($r['npwpd'] ?? ''),
        'objek_pajak'     => (string) $r['objek_pajak'],
        'nilai_keputusan' => (float) $r['nilai_keputusan'],
        'masa_pajak_dari' => (string) ($r['masa_pajak_dari'] ?? ''),
        'masa_pajak_akhir'=> (string) ($r['masa_pajak_akhir'] ?? ''),
        'jatuh_tempo'     => (string) ($r['jatuh_tempo'] ?? ''),
        'keterangan'      => (string) $r['keterangan'],
        'status'          => (string) $r['status'],
        'dibuat_oleh'     => (string) ($r['dibuat_oleh'] ?? ''),
        'created_at'      => (string) $r['created_at'],
    ];
}
