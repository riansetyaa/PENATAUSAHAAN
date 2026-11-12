<?php

/**
 * E2E paritas modul Belanja (aksi baca) DENGAN DATA NYATA.
 *
 * Membandingkan respons legacy langsung dengan respons melalui proksi cutover
 * (legacy -> Laravel). Data uji disemai pada instansi khusus lalu dihapus
 * kembali, sehingga aman dijalankan pada basis data pengembangan.
 *
 * Prasyarat — tiga server lokal:
 *
 *   php -S 127.0.0.1:8898 -t <root>                       # legacy langsung
 *
 *   SIMTKD_CUTOVER=1 SIMTKD_CUTOVER_BASE=http://127.0.0.1:8291 \
 *   SIMTKD_CUTOVER_SECRET=<rahasia> SIMTKD_CUTOVER_ROLLOUT=100 \
 *   php -S 127.0.0.1:8899 -t <root>                       # proksi cutover
 *
 *   SIMTKD_CUTOVER_AKTIF=true SIMTKD_CUTOVER_SECRET=<rahasia> \
 *   php -S 127.0.0.1:8291 -t <laravel>/public <laravel>/public/index.php
 *
 * Lalu: php laravel/tools/difftest/e2e-belanja.php
 *
 * Port dapat diubah lewat SIMTKD_E2E_LEGACY dan SIMTKD_E2E_PROXY.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config/db.php';

$SKPD  = 'Dinas Uji Belanja';
$USER  = 'e2e_belanja';
$PASS  = 'RahasiaBelanja123';
$A     = (string) (getenv('SIMTKD_E2E_LEGACY') ?: 'http://127.0.0.1:8898');
$B     = (string) (getenv('SIMTKD_E2E_PROXY') ?: 'http://127.0.0.1:8899');
$JAR_A = '/tmp/e2b_a.jar';
$JAR_B = '/tmp/e2b_b.jar';

$pdo = db();

function bersihkan(PDO $pdo, string $skpd, string $user): void
{
    $q = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $q->execute([$user]);
    $uid = (int) ($q->fetchColumn() ?: 0);

    $s = $pdo->prepare('SELECT id FROM spp WHERE skpd = ?');
    $s->execute([$skpd]);

    foreach (array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)) as $id) {
        $pdo->prepare('DELETE FROM spp_detail WHERE spp_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM spp_potongan_pajak WHERE spp_id = ?')->execute([$id]);
    }

    $tabel = ['sp2d', 'spm', 'spp', 'spd', 'rekanan', 'lpj', 'pengajuan_tu', 'rekening_skpd', 'besaran_up', 'kebijakan_spd', 'npd'];

    foreach ($tabel as $t) {
        $pdo->prepare("DELETE FROM {$t} WHERE skpd = ?")->execute([$skpd]);
    }

    if ($uid > 0) {
        $pdo->prepare('DELETE FROM personal_access_tokens WHERE tokenable_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
    }
}

function semai(PDO $pdo, string $skpd, string $user, string $pass): int
{
    $hash = password_hash($pass, PASSWORD_DEFAULT);

    $pdo->prepare(
        "INSERT INTO users (nama_lengkap, username, email, password, instansi, kota, provinsi, peran, status, created_at, updated_at)
         VALUES ('Bendahara Uji Belanja', ?, ?, ?, ?, 'Bandung', 'Jawa Barat', 'Bendahara', 'aktif', NOW(), NOW())"
    )->execute([$user, $user . '@polban.ac.id', $hash, $skpd]);

    $uid = (int) $pdo->lastInsertId();

    // Nama rekanan memuat '&' untuk menguji escape JSON.
    $pdo->prepare(
        "INSERT INTO rekanan (user_id, skpd, nama_rekanan, npwp, alamat, bank, nomor_rekening, nama_rekening, jenis, created_at, updated_at)
         VALUES (?, ?, 'CV Uji & Rekan', '01.234.567.8-901.000', 'Jl. Uji No. 1', 'Bank Jabar', '998877', 'CV Uji', 'perusahaan', NOW(), NOW())"
    )->execute([$uid, $skpd]);

    $rekananId = (int) $pdo->lastInsertId();

    $insSpd = $pdo->prepare(
        "INSERT INTO spd (user_id, skpd, nomor_spd, tanggal, jenis, periode, jumlah, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'Barang & Jasa', ?, ?, ?, NOW(), NOW())"
    );

    $insSpd->execute([$uid, $skpd, 'SPD-UJI-1', '2026-03-01', 'Triwulan 1', '1000000.00', 'sudah_otorisasi']);
    $spdOtor = (int) $pdo->lastInsertId();
    $insSpd->execute([$uid, $skpd, 'SPD-UJI-2', '2026-03-02', 'Triwulan 1', '500000.00', 'belum_otorisasi']);

    $pdo->prepare(
        "INSERT INTO spp (user_id, skpd, nomor_spp, tanggal, jenis_spp, spd_id, rekanan_id, keperluan, jumlah, total_potongan, total_pajak, status, created_at, updated_at)
         VALUES (?, ?, 'SPP-UJI-1', '2026-03-03', 'GU', ?, ?, 'Belanja alat tulis', '400000.00', '10000.00', '5000.00', 'sudah_diverifikasi', NOW(), NOW())"
    )->execute([$uid, $skpd, $spdOtor, $rekananId]);

    $sppId = (int) $pdo->lastInsertId();

    $insDetail = $pdo->prepare(
        "INSERT INTO spp_detail (skpd, spp_id, kode_rekening, uraian, jumlah, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
    );
    $insDetail->execute([$skpd, $sppId, '5.1.02.01.01.0001', 'Kertas HVS', '250000.00']);
    $insDetail->execute([$skpd, $sppId, '5.1.02.01.01.0002', 'Tinta printer', '150000.00']);

    $insPot = $pdo->prepare(
        "INSERT INTO spp_potongan_pajak (skpd, spp_id, jenis, nama, nilai_persen, nilai, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
    );
    $insPot->execute([$skpd, $sppId, 'pajak', 'PPN', '11.00', '5000.00']);
    $insPot->execute([$skpd, $sppId, 'potongan', 'PPh 22', '1.50', '10000.00']);

    $pdo->prepare(
        "INSERT INTO spm (user_id, skpd, nomor_spm, tanggal, spp_id, jumlah, status, created_at, updated_at)
         VALUES (?, ?, 'SPM-UJI-1', '2026-03-04', ?, '400000.00', 'sudah_diverifikasi', NOW(), NOW())"
    )->execute([$uid, $skpd, $sppId]);

    $spmId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO sp2d (user_id, skpd, nomor_sp2d, tanggal, spm_id, rekening, jumlah, status, created_at, updated_at)
         VALUES (?, ?, 'SP2D-UJI-1', '2026-03-05', ?, '5140667171', '400000.00', 'sudah_dicairkan', NOW(), NOW())"
    )->execute([$uid, $skpd, $spmId]);

    $pdo->prepare(
        "INSERT INTO lpj (skpd, nomor_lpj, tanggal, uraian, jumlah, created_at)
         VALUES (?, 'LPJ-UJI-1', '2026-03-06', 'LPJ belanja uji', '400000.00', NOW())"
    )->execute([$skpd]);

    $insTu = $pdo->prepare(
        "INSERT INTO pengajuan_tu (skpd, nomor_pengajuan, tanggal, keterangan, jumlah, status, created_at, updated_at)
         VALUES (?, ?, ?, 'TU uji', ?, ?, NOW(), NOW())"
    );
    $insTu->execute([$skpd, 'TU-UJI-1', '2026-03-07', '300000.00', 'belum_otorisasi']);
    $insTu->execute([$skpd, 'TU-UJI-2', '2026-03-08', '200000.00', 'sudah_otorisasi']);

    $insRek = $pdo->prepare(
        "INSERT INTO rekening_skpd (skpd, user_id, bank, nama_pemilik, nomor_rekening, status, created_at, updated_at)
         VALUES (?, ?, 'Bank Jabar', ?, ?, ?, NOW(), NOW())"
    );
    $insRek->execute([$skpd, $uid, 'BENDAHARA UJI', '111222333', 'aktif']);
    $insRek->execute([$skpd, $uid, 'BENDAHARA UJI 2', '444555666', 'pembuatan']);

    $pdo->prepare(
        "INSERT INTO besaran_up (skpd, tahun, besaran_up, besaran_up_kkpd, updated_at)
         VALUES (?, '2026', '5000000.00', '2000000.00', NOW())"
    )->execute([$skpd]);

    // Satu kebijakan aktif saja agar alur SPD deterministik
    // (kebijakan terbaru yang dipakai legacy & Laravel).
    $insKeb = $pdo->prepare(
        "INSERT INTO kebijakan_spd (skpd, jenis_penerbitan, jenis_periode, tanggal, status, created_at)
         VALUES (?, 'Bulanan', ?, '2026-01-02', 'aktif', NOW())"
    );
    $insKeb->execute([$skpd, 'Triwulanan']);

    $insNpd = $pdo->prepare(
        "INSERT INTO npd (skpd, user_id, nomor_npd, tanggal, metode, keterangan, kegiatan, sub_kegiatan, jumlah, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'non_panjar', 'NPD uji', 'Kegiatan Uji', 'Sub Uji', ?, ?, NOW(), NOW())"
    );
    $insNpd->execute([$skpd, $uid, 'NPD-UJI-1', '2026-03-09', '300000.00', 'diajukan']);
    $insNpd->execute([$skpd, $uid, 'NPD-UJI-2', '2026-03-10', '200000.00', 'divalidasi_bp']);

    return $uid;
}

function minta(string $base, string $path, string $jar, ?string $post = null): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 20,
    ]);

    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }

    $raw    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hSize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'status' => $status,
        'head'   => substr($raw, 0, $hSize),
        'body'   => substr($raw, $hSize),
    ];
}

/** Urutkan kunci rekursif agar perbandingan tidak terpengaruh urutan kunci. */
function kanon(mixed $v): mixed
{
    if (! is_array($v)) {
        return $v;
    }

    $out = [];

    foreach ($v as $k => $x) {
        $out[$k] = kanon($x);
    }

    if (array_keys($out) !== range(0, count($out) - 1)) {
        ksort($out);
    }

    return $out;
}

bersihkan($pdo, $SKPD, $USER);
semai($pdo, $SKPD, $USER, $PASS);

$loginA = minta($A, '/api/login.php', $JAR_A, http_build_query(['username' => $USER, 'password' => $PASS]));
$loginB = minta($B, '/api/login.php', $JAR_B, http_build_query(['username' => $USER, 'password' => $PASS]));

echo "=== E2E Belanja (data nyata): legacy langsung vs proksi -> Laravel ===\n\n";

if ($loginA['status'] !== 200 || $loginB['status'] !== 200) {
    echo "GAGAL login (A={$loginA['status']}, B={$loginB['status']})\n";
    echo $loginA['body'] . "\n" . $loginB['body'] . "\n";
    bersihkan($pdo, $SKPD, $USER);
    exit(1);
}

$kasus = [
    'summary'                              => '/api/belanja.php?action=summary',
    'rekanan_list'                         => '/api/belanja.php?action=rekanan_list',
    'spd_list'                             => '/api/belanja.php?action=spd_list',
    'spd_list status=sudah_otorisasi'      => '/api/belanja.php?action=spd_list&status=sudah_otorisasi',
    'spd_otor_list'                        => '/api/belanja.php?action=spd_otor_list',
    'spp_list'                             => '/api/belanja.php?action=spp_list',
    'spp_list status=sudah_diverifikasi'   => '/api/belanja.php?action=spp_list&status=sudah_diverifikasi',
    'spm_list'                             => '/api/belanja.php?action=spm_list',
    'spm_ver_list'                         => '/api/belanja.php?action=spm_ver_list',
    'sp2d_list'                            => '/api/belanja.php?action=sp2d_list',
    'bku_belanja'                          => '/api/belanja.php?action=bku_belanja',
    'bku_belanja periode'                  => '/api/belanja.php?action=bku_belanja&dari=2026-03-01&sampai=2026-03-31',
    'lpj_list'                             => '/api/belanja.php?action=lpj_list',
    'pengajuan_tu_list'                    => '/api/belanja.php?action=pengajuan_tu_list',
    'pengajuan_tu_list status=belum_otorisasi'       => '/api/belanja.php?action=pengajuan_tu_list&status=belum_otorisasi',
    'rekening_skpd_list status=aktif'      => '/api/belanja.php?action=rekening_skpd_list&status=aktif',
    'besaran_up_get tahun=2026'            => '/api/belanja.php?action=besaran_up_get&tahun=2026',
    'besaran_up_get tahun=2025 (kosong)'   => '/api/belanja.php?action=besaran_up_get&tahun=2025',
    'kebijakan_spd_list'                   => '/api/belanja.php?action=kebijakan_spd_list',
    'npd_list status=divalidasi_bp'        => '/api/belanja.php?action=npd_list&status=divalidasi_bp',
];

$lulus = 0;
$gagal = 0;

foreach ($kasus as $label => $path) {
    $a = minta($A, $path, $JAR_A);
    $b = minta($B, $path, $JAR_B);

    $ka = json_encode(kanon(json_decode($a['body'], true)));
    $kb = json_encode(kanon(json_decode($b['body'], true)));
    $marker = preg_match('/x-simtkd-served-by:\\s*cutover/i', $b['head']) === 1;

    if ($ka === $kb && $a['status'] === $b['status'] && $marker) {
        $lulus++;
        printf("  LULUS  %-38s (%d B, dilayani Laravel)\n", $label, strlen((string) $kb));
        continue;
    }

    $gagal++;
    printf("  GAGAL  %-38s (status A=%d B=%d, marker=%s)\n", $label, $a['status'], $b['status'], $marker ? 'ya' : 'TIDAK');

    if ($ka !== $kb) {
        echo "    LEGACY : " . substr((string) $ka, 0, 400) . "\n";
        echo "    LARAVEL: " . substr((string) $kb, 0, 400) . "\n";
    }
}

// ---------------------------------------------------------------------------
// Mutasi melalui PROKSI: membuktikan rute POST + substitusi {id} berfungsi
// dan seluruh alur kerja tersimpan benar di basis data.
// ---------------------------------------------------------------------------
echo "\n=== Mutasi lewat proksi (URL legacy -> Laravel) ===\n\n";

$mutasiLulus = 0;
$mutasiGagal = 0;

$post = static fn (string $path, array $data): array => minta($B, $path, $JAR_B, http_build_query($data));

$periksa = static function (string $label, array $res, string $pesanHarus) use (&$mutasiLulus, &$mutasiGagal): ?array {
    $json = json_decode($res['body'], true);

    if (is_array($json) && ($json['success'] ?? false) === true && ($json['message'] ?? '') === $pesanHarus) {
        $mutasiLulus++;
        printf("  LULUS  %s\n", $label);

        return $json;
    }

    $mutasiGagal++;
    printf("  GAGAL  %s (HTTP %d): %s\n", $label, $res['status'], substr((string) $res['body'], 0, 200));

    return null;
};

// --- Master pendukung ---
$rek = $periksa('rekanan_create', $post('/api/belanja.php', [
    'action' => 'rekanan_create', 'nama_rekanan' => 'CV Uji Mutasi', 'bank' => 'Bank Jabar',
]), 'Rekanan berhasil disimpan.');

$lpj = $periksa('lpj_create', $post('/api/belanja.php', [
    'action' => 'lpj_create', 'tanggal' => '2026-03-06', 'uraian' => 'LPJ mutasi', 'jumlah' => 100000,
]), 'LPJ berhasil dibuat.');

// --- SPD (periode mengikuti Kebijakan SPD aktif: Triwulanan) ---
$spd = $periksa('spd_create', $post('/api/belanja.php', [
    'action' => 'spd_create', 'tanggal' => '2026-03-01', 'jenis' => 'Barang & Jasa',
    'periode' => 'Triwulanan', 'jumlah' => 1000000,
]), 'SPD berhasil dibuat. Periode mengikuti Kebijakan SPD (Triwulanan).');

$spdId = (int) ($spd['id'] ?? 0);

$periksa('spd_otorisasi', $post('/api/belanja.php', [
    'action' => 'spd_otorisasi', 'id' => $spdId,
]), 'SPD berhasil diotorisasi.');

// --- SPP (pajak 11% -> netto 356.000) ---
$spp = $periksa('spp_create', $post('/api/belanja.php', [
    'action' => 'spp_create', 'tanggal' => '2026-03-03', 'jenis_spp' => 'LS Barang & Jasa',
    'spd_id' => $spdId, 'rekanan_id' => (int) ($rek['id'] ?? 0), 'keperluan' => 'Belanja alat tulis',
    'jumlah' => 400000, 'pajak' => [['nama' => 'PPN', 'nilai_persen' => 11]],
]), 'SPP berhasil dibuat.');

$sppId = (int) ($spp['id'] ?? 0);

$periksa('spp_verifikasi (setuju, buat SPM)', $post('/api/belanja.php', [
    'action' => 'spp_verifikasi', 'id' => $sppId, 'setuju' => 1,
]), 'SPP diverifikasi & SPM berhasil dibuat.');

$spm = $pdo->prepare('SELECT id, jumlah, status FROM spm WHERE spp_id = ?');
$spm->execute([$sppId]);
$spmRow = (array) ($spm->fetch() ?: []);
$spmId  = (int) ($spmRow['id'] ?? 0);

if ((float) ($spmRow['jumlah'] ?? 0) === 356000.0) {
    $mutasiLulus++;
    echo "  LULUS  SPM otomatis bernilai netto (356.000)\n";
} else {
    $mutasiGagal++;
    echo "  GAGAL  SPM otomatis: jumlah=" . ($spmRow['jumlah'] ?? 'null') . "\n";
}

// --- SPM -> SP2D -> pencairan ---
$periksa('spm_persetujuan', $post('/api/belanja.php', [
    'action' => 'spm_persetujuan', 'id' => $spmId,
]), 'SPM disetujui (SPTJM SPM dibuat).');

$periksa('spm_verifikasi (setuju)', $post('/api/belanja.php', [
    'action' => 'spm_verifikasi', 'id' => $spmId, 'setuju' => 1,
]), 'SPM berhasil diverifikasi.');

$sp2d = $periksa('sp2d_create', $post('/api/belanja.php', [
    'action' => 'sp2d_create', 'tanggal' => '2026-03-05', 'spm_id' => $spmId,
    'rekening' => '5140667171', 'jumlah' => 356000,
]), 'SP2D berhasil dibuat.');

$sp2dId = (int) ($sp2d['id'] ?? 0);

$periksa('sp2d_verifikasi (setuju)', $post('/api/belanja.php', [
    'action' => 'sp2d_verifikasi', 'id' => $sp2dId, 'setuju' => 1,
]), 'SP2D berhasil diverifikasi.');

$periksa('sp2d_pencairan', $post('/api/belanja.php', [
    'action' => 'sp2d_pencairan', 'id' => $sp2dId, 'tanggal' => '2026-03-20',
]), 'Dana SP2D berhasil dicairkan (transfer).');

// --- Pengajuan TU (dipakai SPP TU) ---
$tu = $periksa('pengajuan_tu_create', $post('/api/belanja.php', [
    'action' => 'pengajuan_tu_create', 'tanggal' => '2026-03-07', 'keterangan' => 'TU mutasi', 'jumlah' => 200000,
]), 'Pengajuan TU berhasil dibuat.');

$tuId = (int) ($tu['id'] ?? 0);

$periksa('pengajuan_tu_otorisasi', $post('/api/belanja.php', [
    'action' => 'pengajuan_tu_otorisasi', 'id' => $tuId,
]), 'Pengajuan TU berhasil diotorisasi (BUD).');

$periksa('pengajuan_tu_validasi', $post('/api/belanja.php', [
    'action' => 'pengajuan_tu_validasi', 'id' => $tuId,
]), 'Pengajuan TU berhasil divalidasi (PA/KPA).');

// --- SPP GU (pakai LPJ) & SPP TU (pakai pengajuan TU) ---
$periksa('spp_create GU (dengan LPJ)', $post('/api/belanja.php', [
    'action' => 'spp_create', 'tanggal' => '2026-03-08', 'jenis_spp' => 'GU',
    'spd_id' => $spdId, 'lpj_id' => (int) ($lpj['id'] ?? 0), 'jumlah' => 50000,
]), 'SPP berhasil dibuat.');

$periksa('spp_create TU (dengan pengajuan TU)', $post('/api/belanja.php', [
    'action' => 'spp_create', 'tanggal' => '2026-03-09', 'jenis_spp' => 'TU',
    'spd_id' => $spdId, 'pengajuan_tu_id' => $tuId, 'jumlah' => 25000,
]), 'SPP berhasil dibuat.');

// --- Rekening SKPD: permohonan -> pengajuan -> pembuatan -> aktif ---
$rsk = $periksa('rekening_skpd_create', $post('/api/belanja.php', [
    'action' => 'rekening_skpd_create', 'bank' => 'Bank Jabar', 'nama_pemilik' => 'BENDAHARA UJI',
]), 'Permohonan rekening dibuat (BP).');

$rskId = (int) ($rsk['id'] ?? 0);

$periksa('rekening_skpd_ajukan', $post('/api/belanja.php', [
    'action' => 'rekening_skpd_ajukan', 'id' => $rskId,
]), 'Rekening diajukan (PA).');

$periksa('rekening_skpd_nomor', $post('/api/belanja.php', [
    'action' => 'rekening_skpd_nomor', 'id' => $rskId, 'nomor_rekening' => '111222333',
]), 'Nomor rekening dibuat (BUD).');

$periksa('rekening_skpd_validasi', $post('/api/belanja.php', [
    'action' => 'rekening_skpd_validasi', 'id' => $rskId, 'setuju' => 1, 'nomor_rekening' => '999888777',
]), 'Rekening divalidasi & aktif.');

// --- Besaran UP & Kebijakan SPD ---
$periksa('besaran_up_set', $post('/api/belanja.php', [
    'action' => 'besaran_up_set', 'tahun' => '2026', 'besaran_up' => 5000000, 'besaran_up_kkpd' => 2000000,
]), 'Besaran UP berhasil disimpan.');

$periksa('kebijakan_spd_create', $post('/api/belanja.php', [
    'action' => 'kebijakan_spd_create', 'jenis_penerbitan' => 'Bulanan', 'jenis_periode' => 'Bulanan',
]), 'Kebijakan SPD berhasil dibuat.');

// --- NPD: diajukan -> divalidasi_pa -> divalidasi_bp ---
$npd = $periksa('npd_create', $post('/api/belanja.php', [
    'action' => 'npd_create', 'tanggal' => '2026-03-10', 'metode' => 'non_panjar', 'jumlah' => 300000,
]), 'NPD berhasil diajukan.');

$npdId = (int) ($npd['id'] ?? 0);

$periksa('npd_validasi_pa', $post('/api/belanja.php', [
    'action' => 'npd_validasi_pa', 'id' => $npdId, 'setuju' => 1,
]), 'NPD divalidasi (PA/KPA).');

$periksa('npd_validasi_bp', $post('/api/belanja.php', [
    'action' => 'npd_validasi_bp', 'id' => $npdId, 'setuju' => 1,
]), 'NPD divalidasi (BP/BPP).');

// --- Periksa keadaan akhir di basis data ---
$cekDb = static function (string $label, bool $ok, string $detail = '') use (&$mutasiLulus, &$mutasiGagal): void {
    if ($ok) {
        $mutasiLulus++;
        printf("  LULUS  %s\n", $label);

        return;
    }

    $mutasiGagal++;
    printf("  GAGAL  %s %s\n", $label, $detail);
};

$q = static function (string $sql, array $p = []) use ($pdo): array {
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return (array) ($s->fetch() ?: []);
};

$cekDb('SPD terotorisasi', (($q('SELECT status FROM spd WHERE id = ?', [$spdId])['status'] ?? '') === 'sudah_otorisasi'));
$cekDb('SPP tervalidasi & SPM terverifikasi', (($q('SELECT status FROM spm WHERE id = ?', [$spmId])['status'] ?? '') === 'sudah_diverifikasi'));
$sp2dDb = $q('SELECT status, tanggal FROM sp2d WHERE id = ?', [$sp2dId]);
$cekDb(
    'SP2D dicairkan dengan tanggal pencairan',
    (($sp2dDb['status'] ?? '') === 'sudah_dicairkan' && substr((string) ($sp2dDb['tanggal'] ?? ''), 0, 10) === '2026-03-20')
);

$rskDb = $q('SELECT status, nomor_rekening FROM rekening_skpd WHERE id = ?', [$rskId]);
$cekDb(
    'Rekening aktif dengan nomor baru',
    (($rskDb['status'] ?? '') === 'aktif' && ($rskDb['nomor_rekening'] ?? '') === '999888777')
);

$cekDb('NPD sampai divalidasi BP', (($q('SELECT status FROM npd WHERE id = ?', [$npdId])['status'] ?? '') === 'divalidasi_bp'));
$cekDb('Besaran UP tersimpan', ((float) ($q('SELECT besaran_up FROM besaran_up WHERE skpd = ? AND tahun = ?', [$SKPD, '2026'])['besaran_up'] ?? 0) === 5000000.0));

// BKU lewat proksi memuat SP2D yang baru dicairkan: 400.000 (data semai)
// + 356.000 (SP2D baru dari alur mutasi) = 756.000.
$bku = minta($B, '/api/belanja.php?action=bku_belanja&dari=2026-03-01&sampai=2026-03-31', $JAR_B);
$bkuJson = json_decode($bku['body'], true);
$cekDb('BKU periode memuat 756.000', ((float) ($bkuJson['total'] ?? 0) === 756000.0), '(total=' . ($bkuJson['total'] ?? 'null') . ')');

echo "\n--------------------------------------------------------------\n";
printf("BACA   TOTAL %d   LULUS %d   GAGAL %d\n", count($kasus), $lulus, $gagal);
printf("MUTASI TOTAL %d   LULUS %d   GAGAL %d\n", $mutasiLulus + $mutasiGagal, $mutasiLulus, $mutasiGagal);

bersihkan($pdo, $SKPD, $USER);
@unlink($JAR_A);
@unlink($JAR_B);

exit(($gagal === 0 && $mutasiGagal === 0) ? 0 : 1);