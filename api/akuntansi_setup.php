<?php
/**
 * SIM-TKD - API Pengaturan Akuntansi (Anggaran LRA & Neraca Awal)
 * ============================================
 * Endpoint untuk menyimpan setting akuntansi (Pengaturan Akuntansi).
 *
 *   GET  ?action=anggaran_list&tahun=2026 : daftar anggaran LRA per akun
 *   POST action=anggaran_save {tahun, rows:[{kode_akun,nama_akun,anggaran}]}
 *   GET  ?action=neraca_list&tahun=2026   : daftar saldo neraca awal per akun
 *   POST action=neraca_save {tahun, rows:[{kode_akun,nama_akun,saldo,jenis}]}
 *
 * Wajib login. Multi-dinas via $_SESSION['instansi']. Respons JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    jsonResponse(false, 'Tidak terautentikasi.', [], 401);
}

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$skpd = requireInstansi(); // fail-closed: tolak jika instansi kosong

function requestBody(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $_POST;
}

// ---------------- GET: daftar anggaran LRA ----------------
if ($method === 'GET' && ($_GET['action'] ?? '') === 'anggaran_list') {
    $tahun = (string) ($_GET['tahun'] ?? date('Y'));
    $sql = "SELECT * FROM anggaran_lra WHERE 1=1";
    $params = [];
    if ($skpd !== '') { $sql .= " AND skpd = ?"; $params[] = $skpd; }
    $sql .= " AND tahun = ?"; $params[] = $tahun;
    $sql .= " ORDER BY kode_akun ASC";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    jsonResponse(true, 'OK', ['data' => $stmt->fetchAll()]);
}

// ---------------- POST: simpan anggaran LRA / neraca awal ----------------
if ($method === 'POST') {
    $body = requestBody();
    if (($body['action'] ?? '') === 'anggaran_save') {
        $tahun = trim((string) ($body['tahun'] ?? date('Y')));
        $rows = isset($body['rows']) && is_array($body['rows']) ? $body['rows'] : [];
        if ($tahun === '') jsonResponse(false, 'Tahun wajib diisi.', [], 422);
        // Hapus data lama utk skpd+tahun lalu insert ulang (upsert sederhana)
        $del = $pdo->prepare("DELETE FROM anggaran_lra WHERE tahun = ? AND (? = '' OR skpd = ?)");
        $del->execute([$tahun, $skpd, $skpd]);
        $ins = $pdo->prepare("INSERT INTO anggaran_lra (skpd, tahun, kode_akun, nama_akun, anggaran) VALUES (?,?,?,?,?)");
        $cnt = 0;
        foreach ($rows as $r) {
            $kd = trim((string) ($r['kode_akun'] ?? ''));
            $nm = trim((string) ($r['nama_akun'] ?? ''));
            $nl = (float) ($r['anggaran'] ?? 0);
            if ($kd === '' && $nm === '') continue;
            $ins->execute([$skpd, $tahun, $kd, $nm, $nl]);
            $cnt++;
        }
        jsonResponse(true, 'Anggaran LRA tersimpan (' . $cnt . ' akun).');
    }
    if (($body['action'] ?? '') === 'neraca_save') {
        $tahun = trim((string) ($body['tahun'] ?? date('Y')));
        $rows = isset($body['rows']) && is_array($body['rows']) ? $body['rows'] : [];
        if ($tahun === '') jsonResponse(false, 'Tahun wajib diisi.', [], 422);
        $del = $pdo->prepare("DELETE FROM neraca_awal WHERE tahun = ? AND (? = '' OR skpd = ?)");
        $del->execute([$tahun, $skpd, $skpd]);
        $ins = $pdo->prepare("INSERT INTO neraca_awal (skpd, tahun, kode_akun, nama_akun, saldo, jenis) VALUES (?,?,?,?,?,?)");
        $cnt = 0;
        foreach ($rows as $r) {
            $kd = trim((string) ($r['kode_akun'] ?? ''));
            $nm = trim((string) ($r['nama_akun'] ?? ''));
            $nl = (float) ($r['saldo'] ?? 0);
            $js = in_array(($r['jenis'] ?? ''), ['aset', 'kewajiban', 'ekuitas'], true) ? $r['jenis'] : 'aset';
            if ($kd === '' && $nm === '') continue;
            $ins->execute([$skpd, $tahun, $kd, $nm, $nl, $js]);
            $cnt++;
        }
        jsonResponse(true, 'Neraca awal tersimpan (' . $cnt . ' akun).');
    }
    jsonResponse(false, 'Aksi tidak dikenal.', [], 404);
}

// ---------------- GET: daftar neraca awal ----------------
if ($method === 'GET' && ($_GET['action'] ?? '') === 'neraca_list') {
    $tahun = (string) ($_GET['tahun'] ?? date('Y'));
    $sql = "SELECT * FROM neraca_awal WHERE 1=1";
    $params = [];
    if ($skpd !== '') { $sql .= " AND skpd = ?"; $params[] = $skpd; }
    $sql .= " AND tahun = ?"; $params[] = $tahun;
    $sql .= " ORDER BY FIELD(jenis,'aset','kewajiban','ekuitas'), kode_akun ASC";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    jsonResponse(true, 'OK', ['data' => $stmt->fetchAll()]);
}

jsonResponse(false, 'Metode tidak didukung.', [], 405);
