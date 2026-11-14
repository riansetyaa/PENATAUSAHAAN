<?php
/**
 * SIM-TKD - API Akun Penerimaan
 * ============================================
 * Endpoint untuk setup akun penerimaan (Pengaturan).
 *
 *   GET                       : daftar akun penerimaan (?q= untuk pencarian)
 *   POST action=create        : simpan satu akun baru
 *   POST action=bulk_create   : simpan banyak akun sekaligus (array items)
 *   POST action=update_metode : perbarui metode input banyak akun (array items)
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

// ---- Migrasi bertahap (cutover). Bawaan: MATI (perilaku legacy tidak berubah).
require_once __DIR__ . '/../includes/cutover.php';
cutover_maybe_proxy(__FILE__);

$pdo = db();

// Daftar metode input yang valid
$METODE_LIST = ['harian', 'mingguan', 'bulanan', 'per_wajib_pajak', 'per_wajib_retribusi'];

function normalizeMetode(string $metode, array $list): string
{
    $m = strtolower(trim($metode));
    return in_array($m, $list, true) ? $m : 'harian';
}

/**
 * Ambil body request (JSON atau form).
 */
function requestBody(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST;
}

// ============================================
// GET - Daftar akun penerimaan
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q     = input('q');
    $aktif = input('aktif', ''); // '1' = hanya akun yang dicentang (aktif)
    $skpd  = requireInstansi(); // pemisahan data multi-dinas (fail-closed)

    $sql    = "SELECT a.*, u.nama_lengkap AS dibuat_oleh
               FROM akun_penerimaan a
               LEFT JOIN users u ON u.id = a.user_id";
    $where  = [];
    $params = [];

    if ($aktif === '1') {
        $where[] = "a.status_aktif = 1";
    }
    if ($q !== '') {
        $where[] = "(a.kode_akun LIKE ? OR a.nama_akun LIKE ? OR a.skpd LIKE ?)";
        $like    = '%' . $q . '%';
        $params  = array_merge($params, [$like, $like, $like]);
    }
    if ($skpd !== '') {
        $where[]  = "a.skpd = ?";
        $params[] = $skpd;
    }
    if (count($where) > 0) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }

    $sql .= " ORDER BY a.kode_akun ASC, a.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonResponse(true, 'OK', [
        'metode_list' => $METODE_LIST,
        'data' => array_map(function ($r) {
            return [
                'id'            => (int) $r['id'],
                'skpd'          => (string) $r['skpd'],
                'kode_akun'     => (string) $r['kode_akun'],
                'nama_akun'     => (string) $r['nama_akun'],
                'metode_input'  => (string) $r['metode_input'],
                'status_aktif'  => (bool) $r['status_aktif'],
                'dibuat_oleh'   => (string) ($r['dibuat_oleh'] ?? ''),
                'created_at'    => (string) $r['created_at'],
            ];
        }, $rows),
    ]);
}

// ============================================
// POST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = requestBody();
    $action = $body['action'] ?? 'create';
    // skpd selalu dari sesi (jangan percaya input klien)
    $skpd   = requireInstansi();

    // ---------- 1. Simpan satu akun baru ----------
    if ($action === 'create') {
        $kode   = trim((string) ($body['kode_akun'] ?? ''));
        $nama   = trim((string) ($body['nama_akun'] ?? ''));
        $metode = normalizeMetode((string) ($body['metode_input'] ?? 'harian'), $METODE_LIST);

        if ($kode === '') {
            jsonResponse(false, 'Kode akun wajib diisi.', ['field' => 'kode_akun'], 422);
        }
        if ($nama === '') {
            jsonResponse(false, 'Nama akun wajib diisi.', ['field' => 'nama_akun'], 422);
        }

        $stmt = $pdo->prepare("
            INSERT INTO akun_penerimaan (user_id, skpd, kode_akun, nama_akun, metode_input, status_aktif)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([$_SESSION['user_id'] ?? null, $skpd, $kode, $nama, $metode]);

        jsonResponse(true, 'Akun penerimaan berhasil disimpan.', [
            'id' => (int) $pdo->lastInsertId(),
        ], 201);
    }

    // ---------- 2. Simpan banyak akun sekaligus ----------
    if ($action === 'bulk_create') {
        $items = $body['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            jsonResponse(false, 'Tidak ada data untuk disimpan.', [], 422);
        }

        $stmt = $pdo->prepare("
            INSERT INTO akun_penerimaan (user_id, skpd, kode_akun, nama_akun, metode_input, status_aktif)
            VALUES (?, ?, ?, ?, ?, 1)
        ");

        $saved   = 0;
        $skipped = 0;
        $pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $kode = trim((string) ($item['kode_akun'] ?? ''));
                $nama = trim((string) ($item['nama_akun'] ?? ''));
                if ($kode === '' || $nama === '') {
                    $skipped++;
                    continue;
                }
                $metode = normalizeMetode((string) ($item['metode_input'] ?? 'harian'), $METODE_LIST);
                $stmt->execute([$_SESSION['user_id'] ?? null, $skpd, $kode, $nama, $metode]);
                $saved++;
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Gagal menyimpan data.', [], 500);
        }

        jsonResponse(true, "Berhasil menyimpan {$saved} akun." . ($skipped > 0 ? " ({$skipped} dilewati karena tidak lengkap)" : ''), [
            'saved'   => $saved,
            'skipped' => $skipped,
        ], 201);
    }

    // ---------- 3. Simpan pilihan akun untuk dinas ini (ganti seluruh pilihan) ----------
    if ($action === 'save_selection') {
        $items = $body['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }
        $skpdSel = requireInstansi();

        $pdo->beginTransaction();
        try {
            // Jangan hapus-lalu-insert seluruh baris dinas: cara lama membuat
            // pengguna lain dalam instansi yang sama kehilangan data (user_id,
            // created_at) hanya karena satu orang menekan "Simpan".
            // Nonaktifkan dulu, lalu upsert pilihan yang dikirim.
            $nonaktif = $pdo->prepare("UPDATE akun_penerimaan SET status_aktif = 0 WHERE skpd = ?");
            $nonaktif->execute([$skpdSel]);

            $cari = $pdo->prepare(
                "SELECT id FROM akun_penerimaan WHERE skpd = ? AND kode_akun = ? LIMIT 1"
            );
            $upd = $pdo->prepare(
                "UPDATE akun_penerimaan
                 SET nama_akun = ?, metode_input = ?, status_aktif = 1, user_id = ?
                 WHERE id = ?"
            );
            $ins = $pdo->prepare(
                "INSERT INTO akun_penerimaan (user_id, skpd, kode_akun, nama_akun, metode_input, status_aktif)
                 VALUES (?, ?, ?, ?, ?, 1)"
            );

            $saved = 0;
            foreach ($items as $item) {
                $kode = trim((string) ($item['kode_akun'] ?? ''));
                $nama = trim((string) ($item['nama_akun'] ?? ''));
                if ($kode === '' || $nama === '') {
                    continue;
                }

                $metode = normalizeMetode((string) ($item['metode_input'] ?? 'harian'), $METODE_LIST);
                $userId = $_SESSION['user_id'] ?? null;

                $cari->execute([$skpdSel, $kode]);
                $idAda = $cari->fetchColumn();

                if ($idAda !== false) {
                    $upd->execute([$nama, $metode, $userId, (int) $idAda]);
                } else {
                    $ins->execute([$userId, $skpdSel, $kode, $nama, $metode]);
                }
                $saved++;
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Gagal menyimpan pilihan akun.', [], 500);
        }

        jsonResponse(true, "Berhasil menyimpan {$saved} akun penerimaan untuk dinas ini.", [
            'saved' => $saved,
        ]);
    }

    // ---------- 4. Perbarui metode input ----------
    if ($action === 'update_metode') {
        $items = $body['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            jsonResponse(false, 'Tidak ada data untuk disimpan.', [], 422);
        }

        $stmt = $pdo->prepare("UPDATE akun_penerimaan SET metode_input = ?, status_aktif = ? WHERE id = ? AND skpd = ?");
        $updated = 0;

        $pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $metode = normalizeMetode((string) ($item['metode_input'] ?? ''), $METODE_LIST);
                $status = !empty($item['status_aktif']) ? 1 : 0;
                // Scope skpd: cegah modifikasi akun instansi lain (IDOR)
                $stmt->execute([$metode, $status, $id, $skpd]);
                $updated += $stmt->rowCount();
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Gagal menyimpan data.', [], 500);
        }

        jsonResponse(true, "Berhasil menyimpan {$updated} akun.", [
            'updated' => $updated,
        ]);
    }

    jsonResponse(false, 'Aksi tidak dikenali.', [], 422);
}

// Metode lain tidak diizinkan
jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
