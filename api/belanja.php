<?php
/**
 * SIM-TKD - API Modul Belanja (Penatausahaan Pengeluaran/Pembiayaan)
 * ============================================
 * Mengikuti manual book SIPD: Rekanan -> SPD -> SPP -> SPM -> SP2D -> BKU.
 * Multi-tenant: data dibatasi berdasarkan $_SESSION['instansi'].
 *
 *   GET  ?action=summary|rekanan_list|spd_list|spp_list|spm_list|sp2d_list
 *   POST {action, ...}  -> create / otorisasi / verifikasi / persetujuan / pencairan
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// ---- Migrasi bertahap (cutover). Bawaan: MATI (perilaku legacy tidak berubah).
// Pada fase 3b-baca hanya aksi GET yang dipetakan di config/cutover.php;
// mutasi (create/verifikasi/pencairan) tetap dilayani di sini.
require_once __DIR__ . '/../includes/cutover.php';
cutover_maybe_proxy(__FILE__);

if (!isLoggedIn()) {
    jsonResponse(false, 'Tidak terautentikasi.', [], 401);
}

$pdo  = db();
$skpd = requireInstansi(); // fail-closed: tolak jika instansi kosong
$method = $_SERVER['REQUEST_METHOD'];

// Helper nomor dokumen otomatis
function genNomor(string $prefix, PDO $pdo, string $table): string {
    $suffix = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    return $prefix . '-' . date('Ymd') . '-' . $suffix;
}

/**
 * Hitung label periode SPD dari tanggal + jenis periode (sesuai Kebijakan SPD).
 * Bulanan -> "Januari 2026" | Triwulanan -> "Triwulan I 2026" | Tahunan -> "2026".
 */
function hitungPeriodeSpd(string $tgl, string $jenisPeriode): string
{
    $bulanID = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $y = (int) substr($tgl, 0, 4);
    $m = (int) substr($tgl, 5, 2);
    if ($y < 2000 || $m < 1 || $m > 12) return '';
    if ($jenisPeriode === 'Triwulanan') {
        $rom = ['I', 'II', 'III', 'IV'][(intdiv($m - 1, 3))];
        return "Triwulan {$rom} {$y}";
    }
    if ($jenisPeriode === 'Tahunan') return (string) $y;
    return $bulanID[$m] . ' ' . $y; // Bulanan (default)
}

/**
 * Ambil Kebijakan SPD aktif milik instansi (terbaru).
 */
function kebijakanSpdAktif(PDO $pdo, string $skpd): ?array
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM kebijakan_spd WHERE skpd = ? AND status = 'aktif' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$skpd]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null; // tabel belum ada -> lewati validasi
    }
}
function skpdCond(string $alias, string $skpd): array {
    if ($skpd === '') return ['', []];
    return [" AND {$alias}.skpd = ?", [$skpd]];
}

// ============================================
// GET - daftar / ringkasan
// ============================================
if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? 'summary');

    if ($action === 'summary') {
        $q = function (string $sql, array $p = []) use ($pdo): int {
            try { $s = $pdo->prepare($sql); $s->execute($p); return (int) $s->fetchColumn(); }
            catch (Throwable $e) { return 0; }
        };
        $sum = function (string $sql, array $p = []) use ($pdo): float {
            try { $s = $pdo->prepare($sql); $s->execute($p); return (float) $s->fetchColumn(); }
            catch (Throwable $e) { return 0; }
        };
        $cr = skpdCond('r', $skpd); // rekanan
        $cs = skpdCond('s', $skpd); // spd/spp/spm/sp2d
        jsonResponse(true, 'OK', [
            'rekanan'      => $q("SELECT COUNT(*) FROM rekanan r WHERE 1=1{$cr[0]}", $cr[1]),
            'spd'          => $q("SELECT COUNT(*) FROM spd s WHERE 1=1{$cs[0]}", $cs[1]),
            'spd_otor'     => $q("SELECT COUNT(*) FROM spd s WHERE s.status='sudah_otorisasi'{$cs[0]}", $cs[1]),
            'spp'          => $q("SELECT COUNT(*) FROM spp s WHERE 1=1{$cs[0]}", $cs[1]),
            'spp_ver'      => $q("SELECT COUNT(*) FROM spp s WHERE s.status='sudah_diverifikasi'{$cs[0]}", $cs[1]),
            'spm'          => $q("SELECT COUNT(*) FROM spm s WHERE 1=1{$cs[0]}", $cs[1]),
            'sp2d'         => $q("SELECT COUNT(*) FROM sp2d s WHERE 1=1{$cs[0]}", $cs[1]),
            'sp2d_cair'    => $q("SELECT COUNT(*) FROM sp2d s WHERE s.status='sudah_dicairkan'{$cs[0]}", $cs[1]),
            'total_spd'    => $sum("SELECT COALESCE(SUM(s.jumlah),0) FROM spd s WHERE 1=1{$cs[0]}", $cs[1]),
            'total_spp'    => $sum("SELECT COALESCE(SUM(s.jumlah),0) FROM spp s WHERE 1=1{$cs[0]}", $cs[1]),
            'lpj'          => $q("SELECT COUNT(*) FROM lpj s WHERE 1=1{$cs[0]}", $cs[1]),
            'pengajuan_tu' => $q("SELECT COUNT(*) FROM pengajuan_tu s WHERE 1=1{$cs[0]}", $cs[1]),
            'rekening'     => $q("SELECT COUNT(*) FROM rekening_skpd s WHERE 1=1{$cs[0]}", $cs[1]),
            'rekening_aktif' => $q("SELECT COUNT(*) FROM rekening_skpd s WHERE s.status='aktif'{$cs[0]}", $cs[1]),
            'kebijakan_spd'=> $q("SELECT COUNT(*) FROM kebijakan_spd s WHERE 1=1{$cs[0]}", $cs[1]),
            'npd'          => $q("SELECT COUNT(*) FROM npd s WHERE 1=1{$cs[0]}", $cs[1]),
            'npd_siap'     => $q("SELECT COUNT(*) FROM npd s WHERE s.status='divalidasi_bp'{$cs[0]}", $cs[1]),
        ]);
    }

    if ($action === 'rekanan_list') {
        $c = skpdCond('r', $skpd);
        $stmt = $pdo->prepare("SELECT r.* FROM rekanan r WHERE 1=1{$c[0]} ORDER BY r.id DESC");
        $stmt->execute($c[1]);
        jsonResponse(true, 'OK', ['data' => $stmt->fetchAll()]);
    }

    if ($action === 'spd_list') {
        $status = (string) ($_GET['status'] ?? '');
        $c = skpdCond('s', $skpd);
        $sql = "SELECT s.* FROM spd s WHERE 1=1{$c[0]}";
        if ($status !== '' && $status !== 'semua') { $sql .= " AND s.status = ?"; $c[1][] = $status; }
        $sql .= " ORDER BY s.tanggal DESC, s.id DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($c[1]);
        jsonResponse(true, 'OK', ['data' => $stmt->fetchAll()]);
    }

    if ($action === 'spp_list') {
        $status = (string) ($_GET['status'] ?? '');
        $c = skpdCond('s', $skpd);
        $sql = "SELECT s.*, d.nomor_spd, d.tanggal AS spd_tanggal, d.jumlah AS spd_jumlah,
                       (d.jumlah - COALESCE((SELECT SUM(x.jumlah) FROM spp x WHERE x.spd_id = s.spd_id AND x.status <> 'ditolak'), 0)) AS spd_sisa,
                       r.nama_rekanan, l.nomor_lpj, t.nomor_pengajuan FROM spp s
                LEFT JOIN spd d ON d.id = s.spd_id
                LEFT JOIN rekanan r ON r.id = s.rekanan_id
                LEFT JOIN lpj l ON l.id = s.lpj_id
                LEFT JOIN pengajuan_tu t ON t.id = s.pengajuan_tu_id
                WHERE 1=1{$c[0]}";
        if ($status !== '' && $status !== 'semua') { $sql .= " AND s.status = ?"; $c[1][] = $status; }
        $sql .= " ORDER BY s.tanggal DESC, s.id DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($c[1]);
        $rows = $stmt->fetchAll();
        // Lampirkan rincian detail (spp_detail) per SPP
        $ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
        $details = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT * FROM spp_detail WHERE spp_id IN ($ph) ORDER BY id ASC");
            $st->execute($ids);
            foreach ($st->fetchAll() as $dd) {
                $details[(int) $dd['spp_id']][] = [
                    'kode_rekening' => (string) $dd['kode_rekening'],
                    'uraian'        => (string) $dd['uraian'],
                    'jumlah'        => (float) $dd['jumlah'],
                ];
            }
        }
        foreach ($rows as &$r) { $r['details'] = $details[(int) $r['id']] ?? []; }
        unset($r);
        jsonResponse(true, 'OK', ['data' => $rows]);
    }

    if ($action === 'spm_list') {
        $status = (string) ($_GET['status'] ?? '');
        $c = skpdCond('s', $skpd);
        $sql = "SELECT s.*, p.nomor_spp, p.jenis_spp, p.keperluan, p.jumlah AS spp_jumlah, p.total_potongan, p.total_pajak, d.nomor_spd FROM spm s
                LEFT JOIN spp p ON p.id = s.spp_id
                LEFT JOIN spd d ON d.id = p.spd_id
                WHERE 1=1{$c[0]}";
        if ($status !== '' && $status !== 'semua') { $sql .= " AND s.status = ?"; $c[1][] = $status; }
        $sql .= " ORDER BY s.tanggal DESC, s.id DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($c[1]);
        $rows = $stmt->fetchAll();
        // Lampirkan potongan, pajak (dari spp_potongan_pajak) & rincian detail (spp_detail) per SPM via spp_id
        foreach ($rows as &$r) {
            $r['potongan'] = [];
            $r['pajak']    = [];
            $r['details']  = [];
            if (!empty($r['spp_id'])) {
                $st = $pdo->prepare("SELECT jenis, nama, nilai FROM spp_potongan_pajak WHERE spp_id = ? ORDER BY id ASC");
                $st->execute([(int) $r['spp_id']]);
                foreach ($st->fetchAll() as $pp) {
                    $arr = ($pp['jenis'] === 'pajak') ? 'pajak' : 'potongan';
                    $r[$arr][] = ['nama' => (string) $pp['nama'], 'nilai' => (float) $pp['nilai']];
                }
                $st2 = $pdo->prepare("SELECT kode_rekening, uraian, jumlah FROM spp_detail WHERE spp_id = ? ORDER BY id ASC");
                $st2->execute([(int) $r['spp_id']]);
                foreach ($st2->fetchAll() as $dd) {
                    $r['details'][] = ['kode_rekening' => (string) $dd['kode_rekening'], 'uraian' => (string) $dd['uraian'], 'jumlah' => (float) $dd['jumlah']];
                }
            }
        }
        unset($r);
        jsonResponse(true, 'OK', ['data' => $rows]);
    }

    if ($action === 'sp2d_list') {
        $status = (string) ($_GET['status'] ?? '');
        $c = skpdCond('s', $skpd);
        $sql = "SELECT s.*, m.nomor_spm FROM sp2d s
                LEFT JOIN spm m ON m.id = s.spm_id
                WHERE 1=1{$c[0]}";
        if ($status !== '' && $status !== 'semua') { $sql .= " AND s.status = ?"; $c[1][] = $status; }
        $sql .= " ORDER BY s.tanggal DESC, s.id DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($c[1]);
        jsonResponse(true, 'OK', ['data' => $stmt->fetchAll()]);
    }

    // Referensi SPD terotorisasi utk SPP & SPM terverifikasi utk SP2D
    if ($action === 'spd_otor_list') {
        // Hanya SPD terotorisasi yang MASIH PUNYA SISA (belum habis dipakai SPP non-ditolak).
        // sisa = jumlah SPD - total jumlah SPP yang memakai SPD tersebut.
        $c = skpdCond('s', $skpd);
        $sql = "SELECT s.*,
                       (s.jumlah - COALESCE((SELECT SUM(x.jumlah) FROM spp x WHERE x.spd_id = s.id AND x.status <> 'ditolak'), 0)) AS sisa
                FROM spd s
                WHERE s.status='sudah_otorisasi'{$c[0]}
                  AND s.jumlah > COALESCE((SELECT SUM(x.jumlah) FROM spp x WHERE x.spd_id = s.id AND x.status <> 'ditolak'), 0)
                ORDER BY s.tanggal DESC, s.id DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($c[1]);
        jsonResponse(true, 'OK', ['data' => $stmt->fetchAll()]);
    }
    if ($action === 'spm_ver_list') {
        $c = skpdCond('s', $skpd);
        $stmt = $pdo->prepare("SELECT s.* FROM spm s WHERE s.status='sudah_diverifikasi'{$c[0]} ORDER BY s.tanggal DESC, s.id DESC");
        $stmt->execute($c[1]);
        jsonResponse(true, 'OK', ['data' => $stmt->fetchAll()]);
    }

    // ---------- BKU Belanja (SP2D yang sudah dicairkan, sesuai periode) ----------
    if ($action === 'bku_belanja') {
        $dari   = trim((string) ($_GET['dari'] ?? ''));
        $sampai = trim((string) ($_GET['sampai'] ?? ''));
        $c = skpdCond('s', $skpd);
        $sql = "SELECT s.*, m.nomor_spm FROM sp2d s
                LEFT JOIN spm m ON m.id = s.spm_id
                WHERE s.status='sudah_dicairkan'{$c[0]}";
        if ($dari !== '') { $sql .= " AND s.tanggal >= ?"; $c[1][] = $dari; }
        if ($sampai !== '') { $sql .= " AND s.tanggal <= ?"; $c[1][] = $sampai; }
        $sql .= " ORDER BY s.tanggal ASC, s.id ASC";
        $stmt = $pdo->prepare($sql); $stmt->execute($c[1]);
        $rows = $stmt->fetchAll();
        $total = 0.0;
        foreach ($rows as $r) $total += (float) $r['jumlah'];
        jsonResponse(true, 'OK', ['data' => $rows, 'total' => $total]);
    }

    // ---------- LPJ (referensi SPP GU) ----------
    if ($action === 'lpj_list') {
        $c = skpdCond('s', $skpd);
        $stmt = $pdo->prepare("SELECT s.* FROM lpj s WHERE 1=1{$c[0]} ORDER BY s.tanggal DESC, s.id DESC");
        $stmt->execute($c[1]);
        jsonResponse(true, 'OK', ['data' => $stmt->fetchAll()]);
    }

    // ---------- Pengajuan TU ----------
    if ($action === 'pengajuan_tu_list') {
        $status = (string) ($_GET['status'] ?? '');
        $c = skpdCond('s', $skpd);
        $sql = "SELECT s.* FROM pengajuan_tu s WHERE 1=1{$c[0]}";
        if ($status !== '' && $status !== 'semua') { $sql .= " AND s.status = ?"; $c[1][] = $status; }
        $sql .= " ORDER BY s.tanggal DESC, s.id DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($c[1]);
        jsonResponse(true, 'OK', ['data' => $stmt->fetchAll()]);
    }

    // ---------- Rekening Bank SKPD ----------
    if ($action === 'rekening_skpd_list') {
        $status = (string) ($_GET['status'] ?? '');
        $c = skpdCond('s', $skpd);
        $sql = "SELECT s.* FROM rekening_skpd s WHERE 1=1{$c[0]}";
        if ($status !== '' && $status !== 'semua') { $sql .= " AND s.status = ?"; $c[1][] = $status; }
        $sql .= " ORDER BY s.id DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($c[1]);
        jsonResponse(true, 'OK', ['data' => $stmt->fetchAll()]);
    }

    // ---------- Besaran UP ----------
    if ($action === 'besaran_up_get') {
        $tahun = (string) ($_GET['tahun'] ?? date('Y'));
        $c = skpdCond('s', $skpd);
        $stmt = $pdo->prepare("SELECT s.* FROM besaran_up s WHERE s.tahun=?{$c[0]} LIMIT 1");
        $stmt->execute(array_merge([$tahun], $c[1]));
        $row = $stmt->fetch();
        jsonResponse(true, 'OK', ['data' => $row ?: null]);
    }

    // ---------- Kebijakan SPD ----------
    if ($action === 'kebijakan_spd_list') {
        $c = skpdCond('s', $skpd);
        $stmt = $pdo->prepare("SELECT s.* FROM kebijakan_spd s WHERE 1=1{$c[0]} ORDER BY s.id DESC");
        $stmt->execute($c[1]);
        jsonResponse(true, 'OK', ['data' => $stmt->fetchAll()]);
    }

    // ---------- NPD (Nota Pencairan Dana) ----------
    if ($action === 'npd_list') {
        $status = (string) ($_GET['status'] ?? '');
        $c = skpdCond('s', $skpd);
        $sql = "SELECT s.* FROM npd s WHERE 1=1{$c[0]}";
        if ($status !== '' && $status !== 'semua') { $sql .= " AND s.status = ?"; $c[1][] = $status; }
        $sql .= " ORDER BY s.tanggal DESC, s.id DESC";
        $stmt = $pdo->prepare($sql); $stmt->execute($c[1]);
        jsonResponse(true, 'OK', ['data' => $stmt->fetchAll()]);
    }

    jsonResponse(false, 'Aksi tidak dikenali.', [], 422);
}

// ============================================
// POST - mutasi (create / workflow)
// ============================================
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) $body = $_POST;
    $action = (string) ($body['action'] ?? '');

    // ---------- Rekanan ----------
    if ($action === 'rekanan_create') {
        $nama   = trim((string) ($body['nama_rekanan'] ?? ''));
        $npwp   = trim((string) ($body['npwp'] ?? ''));
        $alamat = trim((string) ($body['alamat'] ?? ''));
        $bank   = trim((string) ($body['bank'] ?? ''));
        $noRek  = trim((string) ($body['nomor_rekening'] ?? ''));
        $nmRek  = trim((string) ($body['nama_rekening'] ?? ''));
        $jenis  = ($body['jenis'] === 'perseorangan') ? 'perseorangan' : 'perusahaan';
        if ($nama === '') jsonResponse(false, 'Nama rekanan wajib diisi.', ['field' => 'nama_rekanan'], 422);
        $stmt = $pdo->prepare("INSERT INTO rekanan (user_id, skpd, nama_rekanan, npwp, alamat, bank, nomor_rekening, nama_rekening, jenis) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$_SESSION['user_id'] ?? null, $skpd, $nama, $npwp, $alamat, $bank, $noRek, $nmRek, $jenis]);
        jsonResponse(true, 'Rekanan berhasil disimpan.', ['id' => (int) $pdo->lastInsertId()], 201);
    }

    // ---------- SPD ----------
    if ($action === 'spd_create') {
        $tanggal  = trim((string) ($body['tanggal'] ?? ''));
        $jenis    = trim((string) ($body['jenis'] ?? ''));
        $periode  = trim((string) ($body['periode'] ?? ''));
        $jumlah   = (float) ($body['jumlah'] ?? 0);
        if ($tanggal === '') jsonResponse(false, 'Tanggal SPD wajib diisi.', ['field' => 'tanggal'], 422);
        if (!isValidTanggal($tanggal)) jsonResponse(false, 'Format tanggal tidak valid.', ['field' => 'tanggal'], 422);
        if ($jumlah <= 0) jsonResponse(false, 'Jumlah harus lebih dari 0.', ['field' => 'jumlah'], 422);

        // ===== Validasi Kebijakan SPD (aturan penerbitan dari BUD) =====
        // Jika ada kebijakan aktif: jenis periode SPD wajib sesuai kebijakan,
        // dan label periode diisi otomatis dari tanggal + jenis periode.
        $keb = kebijakanSpdAktif($pdo, $skpd);
        if ($keb) {
            $jenisPeriodeKeb = trim((string) ($keb['jenis_periode'] ?? ''));
            $penerbitanKeb   = trim((string) ($keb['jenis_penerbitan'] ?? ''));
            // Kebijakan "Sekali Bayar" tidak membatasi periode.
            // Label periode (mis. "Triwulan IV 2026") dihitung dari tanggal,
            // bukan dibandingkan dengan nama jenis ("Triwulanan").
            if ($penerbitanKeb !== '' && stripos($penerbitanKeb, 'Sekali Bayar') === false && $jenisPeriodeKeb !== '') {
                $periode = hitungPeriodeSpd($tanggal, $jenisPeriodeKeb);
                if ($periode === '') {
                    jsonResponse(false, 'Periode SPD tidak dapat dihitung dari tanggal.', ['field' => 'tanggal'], 422);
                }
            }
        }

        $nomor = genNomor('SPD', $pdo, 'spd');
        $stmt = $pdo->prepare("INSERT INTO spd (user_id, skpd, nomor_spd, tanggal, jenis, periode, jumlah, status) VALUES (?,?,?,?,?,?,?, 'belum_otorisasi')");
        $stmt->execute([$_SESSION['user_id'] ?? null, $skpd, $nomor, $tanggal, $jenis, $periode, $jumlah]);
        jsonResponse(true, 'SPD berhasil dibuat.' . ($keb ? ' Periode mengikuti Kebijakan SPD (' . trim((string) ($keb['jenis_periode'] ?? '')) . ').' : ''), ['id' => (int) $pdo->lastInsertId(), 'nomor_spd' => $nomor], 201);
    }
    if ($action === 'spd_otorisasi') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) jsonResponse(false, 'ID tidak valid.', [], 422);
        $c = skpdCond('s', $skpd);
        $stmt = $pdo->prepare("UPDATE spd s SET s.status='sudah_otorisasi' WHERE s.id=? AND s.status='belum_otorisasi'{$c[0]}");
        $stmt->execute(array_merge([$id], $c[1]));
        if ($stmt->rowCount() === 0) jsonResponse(false, 'SPD tidak ditemukan / sudah diotorisasi / bukan milik instansi Anda.', [], 404);
        jsonResponse(true, 'SPD berhasil diotorisasi.');
    }

    // ---------- SPP (per jenis: LS Gaji, LS Barang & Jasa, UP, GU, TU) ----------
    if ($action === 'spp_create') {
        $tanggal  = trim((string) ($body['tanggal'] ?? ''));
        $jenis    = trim((string) ($body['jenis_spp'] ?? ''));
        $spdId    = (int) ($body['spd_id'] ?? 0);
        $rekId    = (int) ($body['rekanan_id'] ?? 0);
        $keperluan = trim((string) ($body['keperluan'] ?? ''));
        $jumlah   = (float) ($body['jumlah'] ?? 0);
        $lpjId    = (int) ($body['lpj_id'] ?? 0);
        $ptuId    = (int) ($body['pengajuan_tu_id'] ?? 0);
        // Rincian detail (kode rekening belanja + uraian + jumlah) - SPP LS Gaji multi-baris
        $detailItems = [];
        $detailTotal = 0.0;
        $detailRows  = isset($body['detail']) && is_array($body['detail']) ? $body['detail'] : [];
        foreach ($detailRows as $row) {
            $kd = trim((string) ($row['kode_rekening'] ?? ''));
            $ur = trim((string) ($row['uraian'] ?? ''));
            $nl = (float) ($row['jumlah'] ?? 0);
            if ($kd === '' && $ur === '') continue;
            if ($nl <= 0) continue;
            $detailItems[] = ['kode_rekening' => $kd, 'uraian' => $ur, 'jumlah' => $nl];
            $detailTotal += $nl;
        }
        if ($detailItems) $jumlah = $detailTotal;
        if ($tanggal === '') jsonResponse(false, 'Tanggal SPP wajib diisi.', ['field' => 'tanggal'], 422);
        if ($spdId <= 0) jsonResponse(false, 'Pilih SPD.', ['field' => 'spd_id'], 422);
        if ($jumlah <= 0) jsonResponse(false, 'Jumlah harus lebih dari 0.', ['field' => 'jumlah'], 422);
        if ($jenis === 'GU' && $lpjId <= 0) jsonResponse(false, 'Pilih LPJ untuk SPP GU.', ['field' => 'lpj_id'], 422);
        if ($jenis === 'TU' && $ptuId <= 0) jsonResponse(false, 'Pilih Pengajuan TU untuk SPP TU.', ['field' => 'pengajuan_tu_id'], 422);
        // Validasi SPD terotorisasi milik instansi
        $c = skpdCond('s', $skpd);
        $chk = $pdo->prepare("SELECT id FROM spd s WHERE s.id=? AND s.status='sudah_otorisasi'{$c[0]}");
        $chk->execute(array_merge([$spdId], $c[1]));
        if (!$chk->fetch()) jsonResponse(false, 'SPD tidak valid / belum diotorisasi / bukan milik instansi Anda.', [], 422);
        // Validasi sisa SPD: jumlah SPP tidak boleh melebihi sisa SPD
        $sisaQ = $pdo->prepare("SELECT s.jumlah - COALESCE((SELECT SUM(x.jumlah) FROM spp x WHERE x.spd_id = s.id AND x.status <> 'ditolak'), 0) AS sisa FROM spd s WHERE s.id = ?");
        $sisaQ->execute([$spdId]);
        $sisaRow = $sisaQ->fetch();
        $sisa = $sisaRow ? (float) $sisaRow['sisa'] : 0.0;
        if ($jumlah > $sisa + 0.001) {
            jsonResponse(false, 'Jumlah SPP melebihi sisa SPD (sisa: Rp ' . number_format($sisa, 0, ',', '.') . ').', ['field' => 'jumlah'], 422);
        }
        // Potongan & pajak (khusus LS Barang & Jasa)
        $potonganRows = isset($body['potongan']) && is_array($body['potongan']) ? $body['potongan'] : [];
        $pajakRows    = isset($body['pajak']) && is_array($body['pajak']) ? $body['pajak'] : [];
        $calc = function (array $rows, string $jenisRow) use ($jumlah): array {
            $out = [];
            foreach ($rows as $row) {
                $nama = trim((string) ($row['nama'] ?? ''));
                if ($nama === '') continue;
                $persen = (float) ($row['nilai_persen'] ?? 0);
                $nilai  = $persen > 0 ? round($jumlah * $persen / 100, 2) : (float) ($row['nilai'] ?? 0);
                $out[] = [
                    'jenis'       => $jenisRow,
                    'nama'        => $nama,
                    'persen'      => $persen,
                    'nilai'       => $nilai,
                    'id_billing'  => trim((string) ($row['id_billing'] ?? '')),
                    'tgl_billing' => trim((string) ($row['tgl_billing'] ?? '')),
                    'ntpn'        => trim((string) ($row['ntpn'] ?? '')),
                    'tgl_ntpn'    => trim((string) ($row['tgl_ntpn'] ?? '')),
                ];
            }
            return $out;
        };
        $potongan = $calc($potonganRows, 'potongan');
        $pajak    = $calc($pajakRows, 'pajak');
        $totPotongan = 0.0; $totPajak = 0.0;
        foreach ($potongan as $p) $totPotongan += $p['nilai'];
        foreach ($pajak as $p) $totPajak += $p['nilai'];
        $nomor = genNomor('SPP', $pdo, 'spp');
        $stmt = $pdo->prepare("INSERT INTO spp (user_id, skpd, nomor_spp, tanggal, jenis_spp, spd_id, rekanan_id, lpj_id, pengajuan_tu_id, keperluan, jumlah, total_potongan, total_pajak, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, 'belum_diverifikasi')");
        $stmt->execute([$_SESSION['user_id'] ?? null, $skpd, $nomor, $tanggal, $jenis, $spdId, $rekId ?: null, $lpjId ?: null, $ptuId ?: null, $keperluan, $jumlah, $totPotongan, $totPajak]);
        $sppId = (int) $pdo->lastInsertId();
        if ($potongan || $pajak) {
            $ins = $pdo->prepare("INSERT INTO spp_potongan_pajak (skpd, spp_id, jenis, nama, nilai_persen, nilai, id_billing, tgl_billing, ntpn, tgl_ntpn) VALUES (?,?,?,?,?,?,?,?,?,?)");
            foreach (array_merge($potongan, $pajak) as $r) {
                $ins->execute([$skpd, $sppId, $r['jenis'], $r['nama'], $r['persen'], $r['nilai'], $r['id_billing'], $r['tgl_billing'] ?: null, $r['ntpn'], $r['tgl_ntpn'] ?: null]);
            }
        }
        if ($detailItems) {
            $insD = $pdo->prepare("INSERT INTO spp_detail (skpd, spp_id, kode_rekening, uraian, jumlah) VALUES (?,?,?,?,?)");
            foreach ($detailItems as $d) {
                $insD->execute([$skpd, $sppId, $d['kode_rekening'], $d['uraian'], $d['jumlah']]);
            }
        }
        jsonResponse(true, 'SPP berhasil dibuat.', ['id' => $sppId, 'nomor_spp' => $nomor], 201);
    }
    if ($action === 'spp_verifikasi') {
        $id = (int) ($body['id'] ?? 0);
        $setuju = !empty($body['setuju']);
        if ($id <= 0) jsonResponse(false, 'ID tidak valid.', [], 422);
        $c = skpdCond('s', $skpd);
        $new = $setuju ? 'sudah_diverifikasi' : 'ditolak';
        // verifikasi setuju sekaligus membuat SPM
        $stmt = $pdo->prepare("UPDATE spp s SET s.status=? WHERE s.id=? AND s.status='belum_diverifikasi'{$c[0]}");
        $stmt->execute(array_merge([$new, $id], $c[1]));
        if ($stmt->rowCount() === 0) jsonResponse(false, 'SPP tidak ditemukan / bukan milik instansi Anda.', [], 404);
        if ($setuju) {
            $sp = $pdo->prepare("SELECT * FROM spp WHERE id=?"); $sp->execute([$id]); $spp = $sp->fetch();
            $netto = (float) $spp['jumlah'] - (float) $spp['total_potongan'] - (float) $spp['total_pajak'];
            $nomor = genNomor('SPM', $pdo, 'spm');
            $in = $pdo->prepare("INSERT INTO spm (user_id, skpd, nomor_spm, tanggal, spp_id, jumlah, status) VALUES (?,?,?,?,?,?, 'belum_disetujui')");
            $in->execute([$_SESSION['user_id'] ?? null, $spp['skpd'], $nomor, $spp['tanggal'], $spp['id'], $netto]);
            jsonResponse(true, 'SPP diverifikasi & SPM berhasil dibuat.', ['id' => $id, 'nomor_spm' => $nomor]);
        }
        jsonResponse(true, 'SPP ditolak.');
    }

    // ---------- SPM ----------
    if ($action === 'spm_persetujuan') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) jsonResponse(false, 'ID tidak valid.', [], 422);
        $c = skpdCond('s', $skpd);
        $stmt = $pdo->prepare("UPDATE spm s SET s.status='belum_diverifikasi' WHERE s.id=? AND s.status='belum_disetujui'{$c[0]}");
        $stmt->execute(array_merge([$id], $c[1]));
        if ($stmt->rowCount() === 0) jsonResponse(false, 'SPM tidak ditemukan / bukan milik instansi Anda.', [], 404);
        jsonResponse(true, 'SPM disetujui (SPTJM SPM dibuat).');
    }
    if ($action === 'spm_verifikasi') {
        $id = (int) ($body['id'] ?? 0);
        $setuju = !empty($body['setuju']);
        if ($id <= 0) jsonResponse(false, 'ID tidak valid.', [], 422);
        $c = skpdCond('s', $skpd);
        $new = $setuju ? 'sudah_diverifikasi' : 'ditolak';
        $stmt = $pdo->prepare("UPDATE spm s SET s.status=? WHERE s.id=? AND s.status='belum_diverifikasi'{$c[0]}");
        $stmt->execute(array_merge([$new, $id], $c[1]));
        if ($stmt->rowCount() === 0) jsonResponse(false, 'SPM tidak ditemukan / belum diverifikasi / bukan milik instansi Anda.', [], 404);
        jsonResponse(true, $setuju ? 'SPM berhasil diverifikasi.' : 'SPM ditolak.');
    }

    // ---------- SP2D ----------
    if ($action === 'sp2d_create') {
        $tanggal = trim((string) ($body['tanggal'] ?? ''));
        $spmId   = (int) ($body['spm_id'] ?? 0);
        $rekening = trim((string) ($body['rekening'] ?? ''));
        $jumlah  = (float) ($body['jumlah'] ?? 0);
        if ($tanggal === '') jsonResponse(false, 'Tanggal SP2D wajib diisi.', ['field' => 'tanggal'], 422);
        if ($spmId <= 0) jsonResponse(false, 'Pilih SPM.', ['field' => 'spm_id'], 422);
        if ($jumlah <= 0) jsonResponse(false, 'Jumlah harus lebih dari 0.', ['field' => 'jumlah'], 422);
        $c = skpdCond('s', $skpd);
        $chk = $pdo->prepare("SELECT id FROM spm s WHERE s.id=? AND s.status='sudah_diverifikasi'{$c[0]}");
        $chk->execute(array_merge([$spmId], $c[1]));
        if (!$chk->fetch()) jsonResponse(false, 'SPM tidak valid / belum diverifikasi / bukan milik instansi Anda.', [], 422);
        $nomor = genNomor('SP2D', $pdo, 'sp2d');
        $stmt = $pdo->prepare("INSERT INTO sp2d (user_id, skpd, nomor_sp2d, tanggal, spm_id, rekening, jumlah, status) VALUES (?,?,?,?,?,?,?, 'belum_diverifikasi')");
        $stmt->execute([$_SESSION['user_id'] ?? null, $skpd, $nomor, $tanggal, $spmId, $rekening, $jumlah]);
        jsonResponse(true, 'SP2D berhasil dibuat.', ['id' => (int) $pdo->lastInsertId(), 'nomor_sp2d' => $nomor], 201);
    }
    if ($action === 'sp2d_verifikasi') {
        $id = (int) ($body['id'] ?? 0);
        $setuju = !empty($body['setuju']);
        if ($id <= 0) jsonResponse(false, 'ID tidak valid.', [], 422);
        $c = skpdCond('s', $skpd);
        $new = $setuju ? 'sudah_diverifikasi' : 'ditolak';
        $stmt = $pdo->prepare("UPDATE sp2d s SET s.status=? WHERE s.id=? AND s.status='belum_diverifikasi'{$c[0]}");
        $stmt->execute(array_merge([$new, $id], $c[1]));
        if ($stmt->rowCount() === 0) jsonResponse(false, 'SP2D tidak ditemukan / bukan milik instansi Anda.', [], 404);
        jsonResponse(true, $setuju ? 'SP2D berhasil diverifikasi.' : 'SP2D ditolak.');
    }
    if ($action === 'sp2d_pencairan') {
        $id = (int) ($body['id'] ?? 0);
        $tanggal = trim((string) ($body['tanggal'] ?? ''));
        if ($id <= 0) jsonResponse(false, 'ID tidak valid.', [], 422);
        $c = skpdCond('s', $skpd);
        $stmt = $pdo->prepare("UPDATE sp2d s SET s.status='sudah_dicairkan', s.tanggal=COALESCE(?, s.tanggal) WHERE s.id=? AND s.status='sudah_diverifikasi'{$c[0]}");
        $stmt->execute(array_merge([$tanggal !== '' ? $tanggal : null, $id], $c[1]));
        if ($stmt->rowCount() === 0) jsonResponse(false, 'SP2D tidak ditemukan / belum diverifikasi / bukan milik instansi Anda.', [], 404);
        jsonResponse(true, 'Dana SP2D berhasil dicairkan (transfer).');
    }

    // ---------- LPJ (utk SPP GU) ----------
    if ($action === 'lpj_create') {
        $tanggal = trim((string) ($body['tanggal'] ?? ''));
        $uraian  = trim((string) ($body['uraian'] ?? ''));
        $jumlah  = (float) ($body['jumlah'] ?? 0);
        if ($tanggal === '') jsonResponse(false, 'Tanggal LPJ wajib diisi.', ['field' => 'tanggal'], 422);
        if ($jumlah <= 0) jsonResponse(false, 'Jumlah harus lebih dari 0.', ['field' => 'jumlah'], 422);
        $nomor = genNomor('LPJ', $pdo, 'lpj');
        $stmt = $pdo->prepare("INSERT INTO lpj (skpd, nomor_lpj, tanggal, uraian, jumlah) VALUES (?,?,?,?,?)");
        $stmt->execute([$skpd, $nomor, $tanggal, $uraian, $jumlah]);
        jsonResponse(true, 'LPJ berhasil dibuat.', ['id' => (int) $pdo->lastInsertId(), 'nomor_lpj' => $nomor], 201);
    }

    // ---------- Pengajuan TU ----------
    if ($action === 'pengajuan_tu_create') {
        $tanggal = trim((string) ($body['tanggal'] ?? ''));
        $keterangan = trim((string) ($body['keterangan'] ?? ''));
        $jumlah  = (float) ($body['jumlah'] ?? 0);
        if ($tanggal === '') jsonResponse(false, 'Tanggal pengajuan wajib diisi.', ['field' => 'tanggal'], 422);
        if ($jumlah <= 0) jsonResponse(false, 'Jumlah harus lebih dari 0.', ['field' => 'jumlah'], 422);
        $nomor = genNomor('TU', $pdo, 'pengajuan_tu');
        $stmt = $pdo->prepare("INSERT INTO pengajuan_tu (skpd, nomor_pengajuan, tanggal, keterangan, jumlah, status) VALUES (?,?,?,?,?, 'belum_otorisasi')");
        $stmt->execute([$skpd, $nomor, $tanggal, $keterangan, $jumlah]);
        jsonResponse(true, 'Pengajuan TU berhasil dibuat.', ['id' => (int) $pdo->lastInsertId(), 'nomor_pengajuan' => $nomor], 201);
    }
    if ($action === 'pengajuan_tu_otorisasi') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) jsonResponse(false, 'ID tidak valid.', [], 422);
        $c = skpdCond('s', $skpd);
        $stmt = $pdo->prepare("UPDATE pengajuan_tu s SET s.status='sudah_otorisasi' WHERE s.id=? AND s.status='belum_otorisasi'{$c[0]}");
        $stmt->execute(array_merge([$id], $c[1]));
        if ($stmt->rowCount() === 0) jsonResponse(false, 'Pengajuan TU tidak ditemukan / sudah diotorisasi / bukan milik instansi Anda.', [], 404);
        jsonResponse(true, 'Pengajuan TU berhasil diotorisasi (BUD).');
    }
    if ($action === 'pengajuan_tu_validasi') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) jsonResponse(false, 'ID tidak valid.', [], 422);
        $c = skpdCond('s', $skpd);
        $stmt = $pdo->prepare("UPDATE pengajuan_tu s SET s.status='sudah_divalidasi' WHERE s.id=? AND s.status='sudah_otorisasi'{$c[0]}");
        $stmt->execute(array_merge([$id], $c[1]));
        if ($stmt->rowCount() === 0) jsonResponse(false, 'Pengajuan TU tidak ditemukan / belum diotorisasi / bukan milik instansi Anda.', [], 404);
        jsonResponse(true, 'Pengajuan TU berhasil divalidasi (PA/KPA).');
    }

    // ---------- Rekening Bank SKPD ----------
    if ($action === 'rekening_skpd_create') {
        $bank = trim((string) ($body['bank'] ?? ''));
        $namaPemilik = trim((string) ($body['nama_pemilik'] ?? ''));
        if ($bank === '' || $namaPemilik === '') jsonResponse(false, 'Bank dan nama pemilik wajib diisi.', [], 422);
        $stmt = $pdo->prepare("INSERT INTO rekening_skpd (skpd, user_id, bank, nama_pemilik, status) VALUES (?,?,?,?, 'permohonan')");
        $stmt->execute([$skpd, $_SESSION['user_id'] ?? null, $bank, $namaPemilik]);
        jsonResponse(true, 'Permohonan rekening dibuat (BP).', ['id' => (int) $pdo->lastInsertId()], 201);
    }
    if ($action === 'rekening_skpd_ajukan') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) jsonResponse(false, 'ID tidak valid.', [], 422);
        $c = skpdCond('s', $skpd);
        $stmt = $pdo->prepare("UPDATE rekening_skpd s SET s.status='pengajuan' WHERE s.id=? AND s.status='permohonan'{$c[0]}");
        $stmt->execute(array_merge([$id], $c[1]));
        if ($stmt->rowCount() === 0) jsonResponse(false, 'Rekening tidak ditemukan / bukan milik instansi Anda.', [], 404);
        jsonResponse(true, 'Rekening diajukan (PA).');
    }
    if ($action === 'rekening_skpd_nomor') {
        $id = (int) ($body['id'] ?? 0);
        $noRek = trim((string) ($body['nomor_rekening'] ?? ''));
        if ($id <= 0 || $noRek === '') jsonResponse(false, 'ID dan nomor rekening wajib diisi.', [], 422);
        $c = skpdCond('s', $skpd);
        $stmt = $pdo->prepare("UPDATE rekening_skpd s SET s.nomor_rekening=?, s.status='pembuatan' WHERE s.id=? AND s.status='pengajuan'{$c[0]}");
        $stmt->execute(array_merge([$noRek, $id], $c[1]));
        if ($stmt->rowCount() === 0) jsonResponse(false, 'Rekening tidak ditemukan / belum diajukan / bukan milik instansi Anda.', [], 404);
        jsonResponse(true, 'Nomor rekening dibuat (BUD).');
    }
    if ($action === 'rekening_skpd_validasi') {
        $id = (int) ($body['id'] ?? 0);
        $setuju = !empty($body['setuju']);
        $noRek = trim((string) ($body['nomor_rekening'] ?? ''));
        if ($id <= 0) jsonResponse(false, 'ID tidak valid.', [], 422);
        if ($setuju && $noRek === '') jsonResponse(false, 'Nomor rekening wajib diisi saat validasi.', [], 422);
        $c = skpdCond('s', $skpd);
        $new = $setuju ? 'aktif' : 'ditolak';
        if ($setuju) {
            // Validasi sekaligus menetapkan nomor rekening
            $stmt = $pdo->prepare("UPDATE rekening_skpd s SET s.status=?, s.nomor_rekening=? WHERE s.id=? AND s.status='pembuatan'{$c[0]}");
            $stmt->execute(array_merge([$new, $noRek, $id], $c[1]));
        } else {
            $stmt = $pdo->prepare("UPDATE rekening_skpd s SET s.status=? WHERE s.id=? AND s.status='pembuatan'{$c[0]}");
            $stmt->execute(array_merge([$new, $id], $c[1]));
        }
        if ($stmt->rowCount() === 0) jsonResponse(false, 'Rekening tidak ditemukan / belum dibuat nomornya / bukan milik instansi Anda.', [], 404);
        jsonResponse(true, $setuju ? 'Rekening divalidasi & aktif.' : 'Rekening ditolak.');
    }

    // ---------- Besaran UP ----------
    if ($action === 'besaran_up_set') {
        $tahun = trim((string) ($body['tahun'] ?? date('Y')));
        $besaranUp = (float) ($body['besaran_up'] ?? 0);
        $besaranUpKkpd = (float) ($body['besaran_up_kkpd'] ?? 0);
        if ($besaranUp <= 0 && $besaranUpKkpd <= 0) jsonResponse(false, 'Nilai besaran UP wajib diisi.', [], 422);
        $stmt = $pdo->prepare("SELECT id FROM besaran_up WHERE skpd=? AND tahun=?");
        $stmt->execute([$skpd, $tahun]);
        if ($stmt->fetch()) {
            $up = $pdo->prepare("UPDATE besaran_up SET besaran_up=?, besaran_up_kkpd=? WHERE skpd=? AND tahun=?");
            $up->execute([$besaranUp, $besaranUpKkpd, $skpd, $tahun]);
        } else {
            $up = $pdo->prepare("INSERT INTO besaran_up (skpd, tahun, besaran_up, besaran_up_kkpd) VALUES (?,?,?,?)");
            $up->execute([$skpd, $tahun, $besaranUp, $besaranUpKkpd]);
        }
        jsonResponse(true, 'Besaran UP berhasil disimpan.');
    }

    // ---------- Kebijakan SPD ----------
    if ($action === 'kebijakan_spd_create') {
        $jenisPenerbitan = trim((string) ($body['jenis_penerbitan'] ?? ''));
        $jenisPeriode = trim((string) ($body['jenis_periode'] ?? ''));
        $tanggal = trim((string) ($body['tanggal'] ?? ''));
        if ($jenisPenerbitan === '' || $jenisPeriode === '') jsonResponse(false, 'Jenis penerbitan & periode wajib diisi.', [], 422);
        $stmt = $pdo->prepare("INSERT INTO kebijakan_spd (skpd, jenis_penerbitan, jenis_periode, tanggal) VALUES (?,?,?,?)");
        $stmt->execute([$skpd, $jenisPenerbitan, $jenisPeriode, $tanggal ?: null]);
        jsonResponse(true, 'Kebijakan SPD berhasil dibuat.', ['id' => (int) $pdo->lastInsertId()], 201);
    }

    // ---------- NPD (Nota Pencairan Dana) ----------
    if ($action === 'npd_create') {
        $tanggal = trim((string) ($body['tanggal'] ?? ''));
        $metode = ($body['metode'] === 'non_panjar') ? 'non_panjar' : 'panjar';
        $keterangan = trim((string) ($body['keterangan'] ?? ''));
        $kegiatan = trim((string) ($body['kegiatan'] ?? ''));
        $subKegiatan = trim((string) ($body['sub_kegiatan'] ?? ''));
        $jumlah = (float) ($body['jumlah'] ?? 0);
        if ($tanggal === '') jsonResponse(false, 'Tanggal NPD wajib diisi.', ['field' => 'tanggal'], 422);
        if ($jumlah <= 0) jsonResponse(false, 'Jumlah harus lebih dari 0.', ['field' => 'jumlah'], 422);
        $nomor = genNomor('NPD', $pdo, 'npd');
        $stmt = $pdo->prepare("INSERT INTO npd (skpd, user_id, nomor_npd, tanggal, metode, keterangan, kegiatan, sub_kegiatan, jumlah, status) VALUES (?,?,?,?,?,?,?,?,?, 'diajukan')");
        $stmt->execute([$skpd, $_SESSION['user_id'] ?? null, $nomor, $tanggal, $metode, $keterangan, $kegiatan, $subKegiatan, $jumlah]);
        jsonResponse(true, 'NPD berhasil diajukan.', ['id' => (int) $pdo->lastInsertId(), 'nomor_npd' => $nomor], 201);
    }
    if ($action === 'npd_validasi_pa') {
        $id = (int) ($body['id'] ?? 0);
        $setuju = !empty($body['setuju']);
        if ($id <= 0) jsonResponse(false, 'ID tidak valid.', [], 422);
        $c = skpdCond('s', $skpd);
        $new = $setuju ? 'divalidasi_pa' : 'ditolak';
        $stmt = $pdo->prepare("UPDATE npd s SET s.status=? WHERE s.id=? AND s.status='diajukan'{$c[0]}");
        $stmt->execute(array_merge([$new, $id], $c[1]));
        if ($stmt->rowCount() === 0) jsonResponse(false, 'NPD tidak ditemukan / bukan milik instansi Anda.', [], 404);
        jsonResponse(true, $setuju ? 'NPD divalidasi (PA/KPA).' : 'NPD ditolak.');
    }
    if ($action === 'npd_validasi_bp') {
        $id = (int) ($body['id'] ?? 0);
        $setuju = !empty($body['setuju']);
        if ($id <= 0) jsonResponse(false, 'ID tidak valid.', [], 422);
        $c = skpdCond('s', $skpd);
        $new = $setuju ? 'divalidasi_bp' : 'ditolak';
        $stmt = $pdo->prepare("UPDATE npd s SET s.status=? WHERE s.id=? AND s.status='divalidasi_pa'{$c[0]}");
        $stmt->execute(array_merge([$new, $id], $c[1]));
        if ($stmt->rowCount() === 0) jsonResponse(false, 'NPD tidak ditemukan / belum divalidasi PA / bukan milik instansi Anda.', [], 404);
        jsonResponse(true, $setuju ? 'NPD divalidasi (BP/BPP).' : 'NPD ditolak.');
    }

    jsonResponse(false, 'Aksi tidak dikenali.', [], 422);
}

jsonResponse(false, 'Metode request tidak diizinkan.', [], 405);
