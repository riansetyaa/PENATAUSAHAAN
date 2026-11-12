<?php
/**
 * SIM-TKD - API Permohonan
 * ============================================
 * Endpoint untuk data permohonan rekening bank penerimaan.
 *   - GET  : daftar permohonan (mendukung pencarian ?q=)
 *   - POST : simpan permohonan baru
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

$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

// Pemisahan data multi-dinas (fail-closed).
//
// PENTING: variabel ini HARUS diinisialisasi SEBELUM percabangan metode.
// Sebelumnya hanya di-set di dalam blok GET, sehingga pada aksi POST
// (set_aktif & verifikasi) $skpd bernilai null. Akibatnya syarat
// "AND (? = '' OR skpd = ?)" tidak pernah bernilai benar sehingga UPDATE
// tidak pernah menyentuh baris mana pun -> aksi tersebut selalu membalas
// 404 "Data tidak ditemukan atau bukan milik instansi Anda."
$skpd = requireInstansi();

// ============================================
// GET - Daftar permohonan
// ============================================
if ($method === 'GET') {
    $q     = input('q');
    $aktif = input('aktif', ''); // '1' = hanya rekening yang disetujui & aktif (untuk STBP)

    $sql    = "SELECT p.*, u.nama_lengkap AS dibuat_oleh
               FROM permohonan p
               LEFT JOIN users u ON u.id = p.user_id";
    $where  = [];
    $params = [];

    if ($aktif === '1') {
        $where[] = "p.status_disetujui = 1 AND p.status_aktif = 1";
    }
    if ($q !== '') {
        $where[] = "(p.bank LIKE ? OR p.nama_rekening LIKE ? OR p.skpd LIKE ? OR p.nomor_rekening LIKE ?)";
        $like    = '%' . $q . '%';
        $params  = array_merge($params, [$like, $like, $like, $like]);
    }
    if ($skpd !== '') {
        $where[]  = "p.skpd = ?";
        $params[] = $skpd;
    }
    if (count($where) > 0) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }

    $sql .= " ORDER BY p.created_at DESC, p.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonResponse(true, 'OK', [
        'data' => array_map(function ($r) {
            return [
                'id'               => (int) $r['id'],
                'skpd'             => (string) $r['skpd'],
                'dibuat_oleh'      => (string) ($r['dibuat_oleh'] ?? ''),
                'bank'             => (string) $r['bank'],
                'nama_rekening'    => (string) $r['nama_rekening'],
                'nomor_rekening'   => (string) $r['nomor_rekening'],
                'status_terbit'    => (bool) $r['status_terbit'],
                'status_disetujui' => (bool) $r['status_disetujui'],
                'status_aktif'     => (bool) $r['status_aktif'],
                'created_at'       => (string) $r['created_at'],
            ];
        }, $rows),
    ]);
}

// ============================================
// POST - Simpan permohonan baru
// ============================================
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $action = (string) ($body['action'] ?? 'create');

    // Aksi: perbarui status aktif (ceklist AKTIF di daftar permohonan)
    if ($action === 'set_aktif') {
        $id     = (int) ($body['id'] ?? 0);
        $status = !empty($body['status_aktif']) ? 1 : 0;
        if ($id <= 0) {
            jsonResponse(false, 'ID tidak valid.', [], 422);
        }
        // Cek keberadaan baris lebih dulu. rowCount() pada UPDATE hanya
        // menghitung baris yang BERUBAH: mencentang ulang nilai yang sama
        // menghasilkan 0 dan dulu salah dilaporkan sebagai 404.
        $cek = $pdo->prepare(
            "SELECT status_terbit, status_disetujui, status_aktif
             FROM permohonan WHERE id = ? AND skpd = ? LIMIT 1"
        );
        $cek->execute([$id, $skpd]);
        $row = $cek->fetch();

        if (!$row) {
            jsonResponse(false, 'Data tidak ditemukan atau bukan milik instansi Anda.', [], 404);
        }

        if ((int) $row['status_aktif'] !== $status) {
            $stmt = $pdo->prepare("UPDATE permohonan SET status_aktif = ? WHERE id = ? AND skpd = ?");
            $stmt->execute([$status, $id, $skpd]);
        }

        // Rekening baru bisa dipakai STBP/STS bila sudah disetujui.
        if ($status === 1 && empty($row['status_disetujui'])) {
            jsonResponse(true, 'Rekening diaktifkan. Klik tombol Verifikasi agar rekening dapat dipakai pada STBP/STS.');
        }

        jsonResponse(true, $status ? 'Rekening diaktifkan.' : 'Rekening dinonaktifkan.');
    }

    // Aksi: verifikasi rekening (terbit + disetujui + aktif)
    if ($action === 'verifikasi') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(false, 'ID tidak valid.', [], 422);
        }
        $cek = $pdo->prepare(
            "SELECT status_terbit, status_disetujui, status_aktif
             FROM permohonan WHERE id = ? AND skpd = ? LIMIT 1"
        );
        $cek->execute([$id, $skpd]);
        $row = $cek->fetch();

        if (!$row) {
            jsonResponse(false, 'Data tidak ditemukan atau bukan milik instansi Anda.', [], 404);
        }

        // Idempoten: verifikasi ulang bukan error.
        if ((int) $row['status_terbit'] === 1
            && (int) $row['status_disetujui'] === 1
            && (int) $row['status_aktif'] === 1) {
            jsonResponse(true, 'Rekening sudah diverifikasi dan aktif.');
        }

        $stmt = $pdo->prepare(
            "UPDATE permohonan
             SET status_terbit = 1, status_disetujui = 1, status_aktif = 1
             WHERE id = ? AND skpd = ?"
        );
        $stmt->execute([$id, $skpd]);

        jsonResponse(true, 'Rekening berhasil diverifikasi dan aktif.');
    }

    $bank   = input('bank');
    $nama   = input('nama_rekening');
    $nomor  = input('nomor_rekening');
    // skpd selalu dari sesi (sudah di-set di atas, jangan percaya input klien)

    if ($bank === '') {
        jsonResponse(false, 'Silakan pilih bank.', ['field' => 'bank'], 422);
    }
    if ($nama === '') {
        jsonResponse(false, 'Nama pemilik rekening wajib diisi.', ['field' => 'nama_rekening'], 422);
    }
    if ($nomor === '') {
        jsonResponse(false, 'Nomor rekening wajib diisi.', ['field' => 'nomor_rekening'], 422);
    }

    $stmt = $pdo->prepare("
        INSERT INTO permohonan (user_id, skpd, bank, nama_rekening, nomor_rekening, status_terbit, status_disetujui, status_aktif)
        VALUES (?, ?, ?, ?, ?, 0, 0, 0)
    ");
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        $skpd,
        $bank,
        $nama,
        $nomor,
    ]);

    jsonResponse(true, 'Permohonan berhasil disimpan.', [
        'id' => (int) $pdo->lastInsertId(),
    ], 201);
}

// Metode lain tidak diizinkan
jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
