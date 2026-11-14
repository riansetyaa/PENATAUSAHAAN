<?php
/**
 * SIM-TKD (doc.simtkd.com) - API Dokumen & Tanda Tangan Elektronik
 * ============================================================
 * File MANDIRI (tidak memakai includes/ aplikasi utama) karena subdomain
 * memiliki document root sendiri saat produksi.
 *
 * Semua endpoint kecuali `verify` dilindungi token API per-user
 * (pola peta.simtkd.com/api/aklap.php): token dikirim via ?token= atau
 * header Authorization: Bearer, dicocokkan dengan HASH SHA-256 pada
 * users.api_token. Data dibatasi pemilik dokumen (multi-tenant).
 *
 *   GET  ?action=list             : daftar dokumen milik user login
 *   GET  ?action=me               : profil user pemilik token
 *   GET  ?action=detail&id=N      : metadata satu dokumen
 *   GET  ?action=konten&id=N      : HTML dokumen (untuk viewer iframe)
 *   GET  ?action=ttd_gambar_saya  : gambar tanda tangan tersimpan user
 *   POST ?action=ttd_gambar       : simpan gambar TTD (base64 PNG)
 *   POST ?action=ttd              : tanda tangani dokumen (id, qr opsional)
 *   POST ?action=hapus            : hapus dokumen milik user yang masih menunggu TTD
 *   GET  ?action=verify&kode=     : PUBLIK - cek keaslian dokumen
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

// ---- Migrasi bertahap (cutover), lihat laravel/docs/CUTOVER-TANPA-DOWNTIME.md
// File ini MANDIRI (bukan bagian aplikasi utama), jadi pustaka proksi dimuat
// dari folder includes/ aplikasi utama yang berada di atas document root
// subdomain ini. Bila tidak ada (mis. subdomain belum dideploy ulang),
// pengalihan dilewati -> legacy melayani seperti biasa.
$simtkdCutoverLib = __DIR__ . '/../../includes/cutover.php';
if (is_file($simtkdCutoverLib)) {
    require_once $simtkdCutoverLib;
    cutover_maybe_proxy(__FILE__);
}

/** Respons JSON + hentikan eksekusi. */
function jsonOut(bool $success, string $message, array $extra = [], int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * NIP pengguna dari profil (Pengaturan > Profil) untuk blok tanda tangan.
 * Kolom `nip` dibuat otomatis bila belum ada; kegagalan -> string kosong.
 */
function docNipUser(PDO $pdo, int $userId): string
{
    static $kolomAda = null;
    if ($userId <= 0) {
        return '';
    }
    try {
        if ($kolomAda === null) {
            $kolomAda = (bool) $pdo->query("SHOW COLUMNS FROM users LIKE 'nip'")->fetch();
            if (!$kolomAda) {
                $pdo->exec("ALTER TABLE users ADD COLUMN nip VARCHAR(30) DEFAULT NULL AFTER nama_lengkap");
                $kolomAda = true;
            }
        }
        $st = $pdo->prepare("SELECT nip FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        return trim((string) ($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

/** Rate limit sederhana berbasis file: maks $max permintaan per $windowDetik. */
function docRateLimit(string $key, int $max, int $windowDetik): bool
{
    $dir = sys_get_temp_dir() . '/simtkd_doc_ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . '/' . hash('sha256', $key) . '.json';
    $now  = time();
    $att  = [];
    if (is_file($file)) {
        $att = json_decode((string) @file_get_contents($file), true) ?: [];
        $att = array_values(array_filter($att, function ($t) use ($now, $windowDetik) {
            return ($now - (int) $t) < $windowDetik;
        }));
    }
    if (count($att) >= $max) {
        return false;
    }
    $att[] = $now;
    @file_put_contents($file, json_encode($att), LOCK_EX);
    return true;
}

/** Format "29 Agustus 2026 09:15" dari datetime SQL / waktu server. */
function docWaktu(?string $dt): string
{
    if ($dt === null || $dt === '' || $dt === '-') {
        return '-';
    }
    $b = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
          'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $t = strtotime($dt);
    if ($t === false) {
        return $dt;
    }
    return date('j', $t) . ' ' . $b[(int) date('n', $t)] . ' ' . date('Y H:i', $t);
}

// === LANJUTAN-VERIFY ===

/** Barcode (QR) verifikasi elektronik — satu per dokumen, disisipkan di akhir dokumen. */
function ttdVerifyBlock(string $kode, string $qr): string
{
    $e = function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    };
    if ($qr !== '') {
        return '<div data-ttd-qr="1" style="margin-top:24px;text-align:center;">'
            . '<img alt="Barcode verifikasi ' . $e($kode) . '" '
            . 'style="display:inline-block;width:90px;height:90px;" src="' . $qr . '">'
            . '</div>';
    }
    // Cadangan bila QR gagal dibuat: cukup kode verifikasi agar tetap dapat dicek
    return '<div data-ttd-qr="1" style="margin-top:24px;font-size:10px;color:#475569;font-family:Arial,Helvetica,sans-serif;">'
        . 'Kode verifikasi: ' . $e($kode) . ' — cek keaslian di doc.simtkd.com/verify.html</div>';
}

/**
 * Sisipkan gambar TTD ke HTML dokumen.
 * - $posX null  -> otomatis pada slot (.ttd-slot) milik $urutan (data-urut),
 *                  fallback ke slot kosong pertama di template.
 * - $posX float -> penempatan MANUAL: absolute di dalam #docArea
 *   (posisi dipilih user: x = % lebar dokArea, y = px dari atas dokArea,
 *   w = lebar gambar dalam px).
 */
function ttdSisipkan(string $html, string $gambar, $posX, int $posY, int $posW, int $urutan = 1): string
{
    $imgSlot   = '<img class="ttd-img" alt="Tanda tangan elektronik" src="' . $gambar . '">';
    $imgManual = '<img class="ttd-img" style="width:100%;display:block;" alt="Tanda tangan elektronik" src="' . $gambar . '">';
    if ($posX !== null) {
        $xp = rtrim(rtrim(sprintf('%.2f', (float) $posX), '0'), '.');
        if ($xp === '' || $xp === '-') {
            $xp = '0';
        }
        $manual = '<div style="position:absolute;left:' . $xp . '%;top:' . $posY . 'px;width:' . $posW . 'px;z-index:50;">' . $imgManual . '</div>';
        if (strpos($html, '<div id="docArea">') !== false) {
            return preg_replace('/<div id="docArea">/', '<div id="docArea" style="position:relative;">' . $manual, $html, 1);
        }
        return $html . $manual;
    }
    // 1) Slot dengan data-urut tertentu (masih kosong) -> tanda tangan masuk ke kolom yang tepat
    $slotPattern = '/<div class="ttd-slot" data-urut="' . (int) $urutan . '"><\/div>/';
    if (preg_match($slotPattern, $html)) {
        return preg_replace($slotPattern, '<div class="ttd-slot" data-urut="' . (int) $urutan . '">' . $imgSlot . '</div>', $html, 1);
    }
    // 2) Fallback: slot kosong pertama (template lama tanpa data-urut)
    if (strpos($html, '<div class="ttd-slot"></div>') !== false) {
        return preg_replace('/<div class="ttd-slot"><\/div>/', '<div class="ttd-slot">' . $imgSlot . '</div>', $html, 1);
    }
    return $html;
}


header('Content-Type: application/json; charset=utf-8');
$pdo    = db();
$action = (string) ($_GET['action'] ?? ($_POST['action'] ?? ''));
$ip     = $_SERVER['REMOTE_ADDR'] ?? '-';

// ============================================================
// PUBLIK: verifikasi keaslian dokumen (tanpa token, rate-limited)
// ============================================================
if ($action === 'verify') {
    if (!docRateLimit('verify:' . $ip, 60, 300)) {
        jsonOut(false, 'Terlalu banyak permintaan. Coba lagi beberapa menit lagi.', [], 429);
    }
    $kode = strtoupper(trim((string) ($_GET['kode'] ?? '')));
    if (!preg_match('/^TTD-[A-F0-9]{8}$/', $kode)) {
        jsonOut(false, 'Format kode verifikasi tidak valid (contoh: TTD-1A2B3C4D).', [], 422);
    }
    $stmt = $pdo->prepare(
        "SELECT d.jenis, d.nomor, d.judul, d.tanggal, d.skpd, d.status, d.hash_signed,
                d.konten_html_signed, d.signed_at,
                t.nama AS ttd_nama, t.jabatan AS ttd_jabatan, t.nip AS ttd_nip
         FROM dokumen d
         LEFT JOIN dokumen_ttd t ON t.dokumen_id = d.id AND t.urutan = 1
         WHERE d.kode_verifikasi = ? LIMIT 1"
    );
    $stmt->execute([$kode]);
    $d = $stmt->fetch();
    if (!$d) {
        jsonOut(false, 'Dokumen tidak ditemukan untuk kode tersebut.', ['ditemukan' => false], 404);
    }
    $valid = ($d['status'] === 'ditandatangani'
        && !empty($d['hash_signed'])
        && hash_equals((string) $d['hash_signed'], hash('sha256', (string) $d['konten_html_signed'])));
    jsonOut(true, $valid
        ? 'Dokumen VALID dan telah ditandatangani secara elektronik.'
        : 'Dokumen ditemukan namun belum ditandatangani / tanda tangan tidak valid.', [
        'ditemukan'     => true,
        'valid'         => $valid,
        'dokumen'       => [
            'jenis'   => $d['jenis'],
            'nomor'   => $d['nomor'],
            'judul'   => $d['judul'],
            'tanggal' => $d['tanggal'],
            'skpd'    => $d['skpd'],
            'status'  => $d['status'],
            'waktu'   => docWaktu((string) ($d['signed_at'] ?? '')),
        ],
        'penandatangan' => [
            'nama'    => (string) ($d['ttd_nama'] ?? ''),
            'jabatan' => (string) ($d['ttd_jabatan'] ?? ''),
            'nip'     => trim((string) ($d['ttd_nip'] ?? '')),
        ],
    ]);
}

// ============================================================
// Verifikasi token API (per-user / multi-tenant)
// ============================================================
$token = (string) ($_GET['token'] ?? ($_POST['token'] ?? ''));
if ($token === '') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        $token = trim($m[1]);
    }
}
if ($token === '') {
    jsonOut(false, 'Token API tidak ditemukan. Buka laman ini melalui menu "Tanda Tangan Dokumen" di SIM-TKD.', [], 401);
}

$tokenHash = hash('sha256', $token);
$stmtUser  = $pdo->prepare("SELECT id, nama_lengkap, username, email, instansi, peran FROM users WHERE api_token = ? LIMIT 1");
$stmtUser->execute([$tokenHash]);
$user = $stmtUser->fetch();
if (!$user && preg_match('/^[a-f0-9]{32}$/', $token)) {
    // MIGRASI TOKEN LEGACY (plaintext 32 hex -> hash SHA-256)
    // -------------------------------------------------------
    // NONAKTIF secara bawaan. Jalur ini pernah membiarkan token admin
    // hardcoded (yang bocor ke riwayat git) tetap berlaku sebagai kredensial.
    //
    // Token dirotasi (dan di-hash) pada setiap login di app utama
    // (api/login.php), sehingga setiap pengguna yang login kembali otomatis
    // mendapat token hash yang valid. Tidak ada kebutuhan membuka jalur ini
    // secara permanen.
    //
    // Untuk memindahkan user yang belum login sejak pergantian format, aktifkan
    // SEMENTARA di environment server:
    //
    //     SIMTKD_ALLOW_LEGACY_TOKEN=1
    //
    // lalu NONAKTIFKAN kembali setelah semua user bermigrasi.
    if (getenv('SIMTKD_ALLOW_LEGACY_TOKEN') === '1') {
        try {
            $st = $pdo->prepare("SELECT id FROM users WHERE api_token = ? LIMIT 1");
            $st->execute([$token]);
            $legacyId = $st->fetchColumn();
            if ($legacyId) {
                $pdo->prepare("UPDATE users SET api_token = ? WHERE id = ?")->execute([$tokenHash, (int) $legacyId]);
                $stmtUser->execute([$tokenHash]);
                $user = $stmtUser->fetch();
            }
        } catch (Throwable $e) {
            error_log('[DOC] migrasi token legacy gagal: ' . $e->getMessage());
        }
    }
}
if (!$user) {
    jsonOut(false, 'Token API tidak valid. Masuk kembali melalui menu "Tanda Tangan Dokumen" di SIM-TKD.', [], 401);
}
$userId = (int) $user['id'];

// === LANJUTAN-AKSI ===

// ---------- Profil user (validasi token + identitas) ----------
if ($action === 'me') {
    jsonOut(true, 'OK', ['user' => [
        'nama'     => (string) $user['nama_lengkap'],
        'username' => (string) $user['username'],
        'instansi' => (string) $user['instansi'],
        'peran'    => (string) $user['peran'],
    ]]);
}

// ---------- Daftar dokumen milik user ----------
if ($action === 'list') {
    $stmt = $pdo->prepare(
        "SELECT d.id, d.jenis, d.nomor, d.judul, d.tanggal, d.status, d.kode_verifikasi,
                d.signed_at, d.created_at,
                t.nama AS ttd_nama, t.jabatan AS ttd_jabatan, t.status AS ttd_status
         FROM dokumen d
         LEFT JOIN dokumen_ttd t ON t.dokumen_id = d.id AND t.urutan = 1
         WHERE d.user_id = ?
         ORDER BY (d.status = 'menunggu_ttd') DESC, d.id DESC
         LIMIT 300"
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['signed_at_fmt'] = docWaktu((string) ($r['signed_at'] ?? ''));
    }
    unset($r);
    jsonOut(true, 'OK', ['data' => $rows]);
}

// ---------- Metadata satu dokumen ----------
if ($action === 'detail') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        jsonOut(false, 'ID dokumen tidak valid.', [], 422);
    }
    $stmt = $pdo->prepare(
        "SELECT d.id, d.jenis, d.nomor, d.judul, d.tanggal, d.status, d.kode_verifikasi,
                d.hash_original, d.hash_signed, d.signed_at, d.created_at,
                t.nama AS ttd_nama, t.jabatan AS ttd_jabatan, t.nip AS ttd_nip, t.status AS ttd_status,
                t.signed_at AS ttd_signed_at
         FROM dokumen d
         LEFT JOIN dokumen_ttd t ON t.dokumen_id = d.id AND t.urutan = 1
         WHERE d.id = ? AND d.user_id = ? LIMIT 1"
    );
    $stmt->execute([$id, $userId]);
    $d = $stmt->fetch();
    if (!$d) {
        jsonOut(false, 'Dokumen tidak ditemukan.', [], 404);
    }
    $d['signed_at_fmt'] = docWaktu((string) ($d['signed_at'] ?? ''));
    $d['waktu_ttd']     = docWaktu((string) ($d['ttd_signed_at'] ?? ''));
    // Info slot penandatangan milik user saat ini
    $pt = $pdo->prepare("SELECT urutan, status, nama, jabatan FROM dokumen_ttd WHERE dokumen_id = ? AND user_id = ? LIMIT 1");
    $pt->execute([$id, $userId]);
    $my = $pt->fetch();
    $d['ttd_slot_urutan'] = (int) ($my['urutan'] ?? 0);
    $d['ttd_slot_status'] = (string) ($my['status'] ?? '');
    $sisa = $pdo->prepare("SELECT COUNT(*) FROM dokumen_ttd WHERE dokumen_id = ? AND status = 'menunggu' AND user_id IS NOT NULL");
    $sisa->execute([$id]);
    $d['ttd_sisa'] = (int) $sisa->fetchColumn();
    jsonOut(true, 'OK', ['dokumen' => $d]);
}

// ---------- HTML dokumen untuk viewer iframe ----------
if ($action === 'konten') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        jsonOut(false, 'ID dokumen tidak valid.', [], 422);
    }
    $stmt = $pdo->prepare("SELECT status, konten_html, konten_html_signed FROM dokumen WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$id, $userId]);
    $d = $stmt->fetch();
    if (!$d) {
        jsonOut(false, 'Dokumen tidak ditemukan.', [], 404);
    }
    $html = ($d['status'] === 'ditandatangani' && !empty($d['konten_html_signed']))
        ? (string) $d['konten_html_signed']
        : (string) $d['konten_html'];
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');
    // CSP ketat: HTML dokumen berasal dari cetakan halaman aplikasi sehingga
    // memuat nilai dari database. Tanpa CSP, script yang tersisip di dalam
    // dokumen akan berjalan di iframe SAMEORIGIN dan bisa membaca
    // sessionStorage (token API) milik pengguna -> pencurian token.
    // Dokumen TTD yang sah HANYA butuh: <style> inline, <img> (logo & tanda
    // tangan/QR berupa data-URI). Tidak boleh ada script sama sekali.
    header(
        "Content-Security-Policy: "
        . "default-src 'none'; "
        . "img-src 'self' data: blob:; "
        . "style-src 'unsafe-inline'; "
        . "font-src 'self' data:; "
        . "script-src 'none'; "
        . "object-src 'none'; "
        . "base-uri 'none'; "
        . "form-action 'none'; "
        . "frame-ancestors 'self'"
    );
    echo $html;
    exit;
}

// ---------- Gambar tanda tangan tersimpan milik user ----------
if ($action === 'ttd_gambar_saya') {
    $st = $pdo->prepare("SELECT gambar FROM user_ttd WHERE user_id = ? LIMIT 1");
    $st->execute([$userId]);
    $gambar = (string) $st->fetchColumn();
    jsonOut(true, 'OK', ['gambar' => $gambar]);
}

// === LANJUTAN-TTD ===

// ---------- Simpan gambar tanda tangan (base64 PNG) ----------
if ($action === 'ttd_gambar') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonOut(false, 'Metode request tidak diizinkan.', [], 405);
    }
    $img = trim((string) ($_POST['gambar'] ?? ''));
    if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=\r\n]+$#', $img)) {
        jsonOut(false, 'Format gambar tanda tangan tidak valid (harus PNG base64).', [], 422);
    }
    if (strlen($img) > 600000) {
        jsonOut(false, 'Gambar tanda tangan terlalu besar.', [], 413);
    }
    $st = $pdo->prepare(
        "INSERT INTO user_ttd (user_id, gambar) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE gambar = VALUES(gambar), diperbarui_pada = NOW()"
    );
    $st->execute([$userId, $img]);
    jsonOut(true, 'Gambar tanda tangan tersimpan.');
}

// ---------- Tanda tangani dokumen ----------
if ($action === 'ttd') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonOut(false, 'Metode request tidak diizinkan.', [], 405);
    }
    $id = (int) ($_POST['id'] ?? 0);
    $qr = trim((string) ($_POST['qr'] ?? ''));
    if ($id <= 0) {
        jsonOut(false, 'ID dokumen tidak valid.', [], 422);
    }
    if ($qr !== '' && (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=\r\n]+$#', $qr) || strlen($qr) > 300000)) {
        $qr = ''; // QR bermasalah -> tandatangani tanpa QR, jangan gagalkan
    }

    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $pdo->beginTransaction();
    try {
        // Kunci baris dokumen agar tidak ganda tanda tangan
        $stmt = $pdo->prepare("SELECT * FROM dokumen WHERE id = ? AND user_id = ? FOR UPDATE");
        $stmt->execute([$id, $userId]);
        $d = $stmt->fetch();
        if (!$d) {
            $pdo->rollBack();
            jsonOut(false, 'Dokumen tidak ditemukan.', [], 404);
        }
        if ($d['status'] !== 'menunggu_ttd') {
            $pdo->rollBack();
            jsonOut(false, 'Dokumen ini sudah ditandatangani sebelumnya.', [], 409);
        }
        // Integritas: dokumen harus persis seperti saat dikirim
        if (!hash_equals((string) $d['hash_original'], hash('sha256', (string) $d['konten_html']))) {
            $pdo->rollBack();
            jsonOut(false, 'Integritas dokumen tidak terpenuhi. Kirim ulang dokumen dari laman cetak SIM-TKD.', [], 409);
        }

        // Gambar tanda tangan wajib sudah dibuat
        $st = $pdo->prepare("SELECT gambar FROM user_ttd WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $gambar = (string) $st->fetchColumn();
        if ($gambar === '') {
            $pdo->rollBack();
            jsonOut(false, 'Gambar tanda tangan belum dibuat. Gambar tanda tangan terlebih dahulu.', [], 422);
        }

        // 0) Slot penandatangan milik user saat ini (multi-slot / data-urut)
        $ptRow = null;
        $ptSt = $pdo->prepare("SELECT id, urutan, status FROM dokumen_ttd WHERE dokumen_id = ? AND user_id = ? LIMIT 1");
        $ptSt->execute([$id, $userId]);
        $ptRow = $ptSt->fetch();
        $urutan = 1;
        if ($ptRow) {
            if ($ptRow['status'] === 'ditandatangani') {
                $pdo->rollBack();
                jsonOut(false, 'Slot tanda tangan Anda pada dokumen ini sudah ditandatangani.', [], 409);
            }
            $urutan = max(1, (int) $ptRow['urutan']);
        } else {
            // Dokumen lama yang belum punya baris penandatangan -> pakai urutan 1
            $ptIdRow = $pdo->prepare("SELECT id FROM dokumen_ttd WHERE dokumen_id = ? AND urutan = 1 LIMIT 1");
            $ptIdRow->execute([$id]);
            if (!$ptIdRow->fetch()) {
                // Keadaan luar biasa: sisipkan baris milik user yang menandatangani
                $inPt = $pdo->prepare(
                    "INSERT INTO dokumen_ttd (dokumen_id, user_id, urutan, jabatan, nama, status)
                     VALUES (?, ?, 1, ?, ?, 'menunggu')"
                );
                $inPt->execute([$id, $userId, (string) $user['peran'], (string) $user['nama_lengkap']]);
            }
        }

        // Posisi manual (opsional)
        $posX = null;
        if (isset($_POST['pos_x']) && $_POST['pos_x'] !== '') {
            $posX = (float) $_POST['pos_x'];
        }
        $posY = (int) ($_POST['pos_y'] ?? 0);
        $posW = (int) ($_POST['pos_w'] ?? 180);
        if ($posW < 60) {
            $posW = 60;
        }
        if ($posW > 400) {
            $posW = 400;
        }

        $html = ttdSisipkan((string) $d['konten_html'], $gambar, $posX, $posY, $posW, $urutan);

        // Kode verifikasi unik
        $kode = $d['kode_verifikasi'];
        if ($kode === null || $kode === '') {
            $kode = 'TTD-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }

        // Sisipkan QR / blok verifikasi di akhir dokumen (sekali saja)
        if (strpos($html, 'data-ttd-qr="1"') === false) {
            $html .= ttdVerifyBlock($kode, $qr);
        }

        $hashSigned = hash('sha256', $html);
        $waktuSql   = date('Y-m-d H:i:s');
        $nipTtd     = docNipUser($pdo, $userId);

        // Update / insert baris dokumen_ttd
        if ($ptRow) {
            $up2 = $pdo->prepare(
                "UPDATE dokumen_ttd
                 SET status = 'ditandatangani', nama = ?, jabatan = ?, nip = ?, signed_at = ?, ip = ?, user_agent = ?
                 WHERE id = ?"
            );
            $up2->execute([$user['nama_lengkap'], (string) $user['peran'], $nipTtd, $waktuSql, $ip, $ua, (int) $ptRow['id']]);
        } else {
            $up2 = $pdo->prepare(
                "UPDATE dokumen_ttd
                 SET status = 'ditandatangani', nama = ?, jabatan = ?, nip = ?, signed_at = ?, ip = ?, user_agent = ?
                 WHERE dokumen_id = ? AND urutan = 1"
            );
            $up2->execute([$user['nama_lengkap'], (string) $user['peran'], $nipTtd, $waktuSql, $ip, $ua, $id]);
            if ($up2->rowCount() === 0) {
                // Baris penandatangan belum ada (keadaan luar biasa) -> buat
                $in2 = $pdo->prepare(
                    "INSERT INTO dokumen_ttd (dokumen_id, user_id, urutan, jabatan, nama, nip, status, signed_at, ip, user_agent)
                     VALUES (?, ?, 1, ?, ?, ?, 'ditandatangani', ?, ?, ?)"
                );
                $in2->execute([$id, $userId, (string) $user['peran'], (string) $user['nama_lengkap'], $nipTtd, $waktuSql, $ip, $ua]);
            }
        }

        // Dokumen SELESAI bila semua slot milik akun (user_id) sudah ditandatangani;
        // slot pihak eksternal (user_id NULL) tidak menahan penyelesaian dokumen.
        $sisa = $pdo->prepare("SELECT COUNT(*) FROM dokumen_ttd WHERE dokumen_id = ? AND status = 'menunggu' AND user_id IS NOT NULL");
        $sisa->execute([$id]);
        $statusDok = ((int) $sisa->fetchColumn() === 0) ? 'ditandatangani' : 'menunggu_ttd';

        $up = $pdo->prepare(
            "UPDATE dokumen
             SET konten_html_signed = ?, hash_signed = ?, status = ?, signed_at = ?, kode_verifikasi = ?
             WHERE id = ?"
        );
        $up->execute([
            $html,
            $hashSigned,
            $statusDok,
            $statusDok === 'ditandatangani' ? $waktuSql : null,
            $kode,
            $id,
        ]);

        $pdo->commit();
        jsonOut(true, 'Dokumen berhasil ditandatangani secara elektronik.', [
            'kode_verifikasi' => $kode,
            'hash_signed'     => $hashSigned,
            'waktu'           => docWaktu($waktuSql),
            'status'          => $statusDok,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[DOC] ttd gagal: ' . $e->getMessage());
        jsonOut(false, 'Gagal memproses tanda tangan. Coba beberapa saat lagi.', [], 500);
    }
}

// ---------- Hapus dokumen milik user yang masih bisa ditandatangani ----------
if ($action === 'hapus' || $action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonOut(false, 'Metode request tidak diizinkan.', [], 405);
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        jsonOut(false, 'ID dokumen tidak valid.', [], 422);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id, status, judul, nomor FROM dokumen WHERE id = ? AND user_id = ? FOR UPDATE");
        $stmt->execute([$id, $userId]);
        $d = $stmt->fetch();
        if (!$d) {
            $pdo->rollBack();
            jsonOut(false, 'Dokumen tidak ditemukan atau bukan milik Anda.', [], 404);
        }
        if ((string) ($d['status'] ?? '') !== 'menunggu_ttd') {
            $pdo->rollBack();
            jsonOut(false, 'Hanya dokumen yang masih bisa ditandatangani yang dapat dihapus.', [], 409);
        }
        // Hapus catatan penandatangan terlebih dahulu
        $pdo->prepare("DELETE FROM dokumen_ttd WHERE dokumen_id = ?")->execute([$id]);
        // Hapus dokumen
        $pdo->prepare("DELETE FROM dokumen WHERE id = ? AND user_id = ?")->execute([$id, $userId]);

        $pdo->commit();
        jsonOut(true, 'Dokumen berhasil dihapus.', [
            'id'    => $id,
            'judul' => (string) ($d['judul'] ?? ''),
            'nomor' => (string) ($d['nomor'] ?? ''),
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[DOC] hapus gagal: ' . $e->getMessage());
        jsonOut(false, 'Gagal menghapus dokumen. Coba beberapa saat lagi.', [], 500);
    }
}

jsonOut(false, 'Aksi tidak dikenal.', [], 422);
