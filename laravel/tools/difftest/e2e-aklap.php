<?php

/**
 * E2E paritas modul AKLAP (peta.simtkd.com) DENGAN DATA NYATA.
 *
 * Menguji rantai autentikasi khas AKLAP: TOKEN API (?token=) pada sistem
 * legacy -> diterjemahkan proksi cutover menjadi TIKET SSO -> Laravel
 * mengautentikasi lewat Sanctum. Respons legacy langsung dan respons lewat
 * proksi dibandingkan apa adanya.
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
 * Lalu: php laravel/tools/difftest/e2e-aklap.php
 */

declare(strict_types=1);

require __DIR__ . '/../../../config/db.php';

$SKPD  = 'Dinas Uji AKLAP';
$USER  = 'e2e_aklap';
$TOKEN = 'abcdef0123456789abcdef0123456789';

$A = (string) (getenv('SIMTKD_E2E_LEGACY') ?: 'http://127.0.0.1:8898');
$B = (string) (getenv('SIMTKD_E2E_PROXY') ?: 'http://127.0.0.1:8899');

$pdo = db();

function bersihkanAklap(PDO $pdo, string $skpd, string $user): void
{
    $q = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $q->execute([$user]);
    $uid = (int) ($q->fetchColumn() ?: 0);

    $tabel = ['stbp', 'sts', 'sp2d', 'spm', 'jurnal_manual', 'akun_penerimaan', 'akun_master', 'anggaran_lra', 'neraca_awal', 'kegiatan', 'register_ppkd', 'buku_kas', 'kas_harian_item', 'kas_harian', 'rekonsiliasi_bank_item', 'rekonsiliasi_bank', 'jurnal_umum', 'jurnal_umum_detail', 'jurnal_khusus_baris', 'jurnal_khusus', 'dokumen'];

    foreach ($tabel as $t) {
        $pdo->prepare("DELETE FROM {$t} WHERE skpd = ?")->execute([$skpd]);
    }

    // Baris penandatangan yatim (dokumen induknya sudah dihapus) dibersihkan.
    $pdo->exec('DELETE t FROM dokumen_ttd t LEFT JOIN dokumen d ON d.id = t.dokumen_id WHERE d.id IS NULL');

    if ($uid > 0) {
        $pdo->prepare('DELETE FROM personal_access_tokens WHERE tokenable_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
    }
}

function semaiAklap(PDO $pdo, string $skpd, string $user, string $token): int
{
    $pdo->prepare(
        "INSERT INTO users (nama_lengkap, username, email, password, instansi, kota, provinsi, peran, status, api_token, created_at, updated_at)
         VALUES ('Bendahara AKLAP', ?, ?, ?, ?, 'Bandung', 'Jawa Barat', 'Bendahara', 'aktif', ?, NOW(), NOW())"
    )->execute([$user, $user . '@polban.ac.id', password_hash('RahasiaAklap123', PASSWORD_DEFAULT), $skpd, hash('sha256', $token)]);

    $uid = (int) $pdo->lastInsertId();

    // STBP: satu sudah approve (masuk LRA), satu belum (tidak masuk LRA).
    $insStbp = $pdo->prepare(
        "INSERT INTO stbp (user_id, skpd, nomor_stbp, tanggal, akun_kode, akun_nama, jumlah, uraian, status, jurnal_status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sudah_divalidasi', ?, NOW(), NOW())"
    );
    $insStbp->execute([$uid, $skpd, 'STBP-APL-1', '2026-03-05', '4.1.1.01.03', 'Pajak Hotel', '1000000.00', 'Setoran pajak hotel', 'sudah_approve']);
    $stbpId = (int) $pdo->lastInsertId();
    $insStbp->execute([$uid, $skpd, 'STBP-APL-2', '2026-03-06', '4.1.1.01.04', 'Pajak Restoran', '250000.00', 'Belum di-approve', 'belum_approve']);

    // STS aktif + rincian (untuk dokumen_list & jurnal).
    $pdo->prepare(
        "INSERT INTO sts (user_id, skpd, nomor_sts, nama_penyetor, tanggal_sts, tanggal_acuan_akhir, total, keterangan, status, jurnal_status, created_at, updated_at)
         VALUES (?, ?, 'STS-APL-1', 'Penyetor Uji', '2026-03-03', '2026-03-31', '500000.00', 'Setoran uji', 'aktif', 'belum_approve', NOW(), NOW())"
    )->execute([$uid, $skpd]);

    $stsId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO sts_detail (sts_id, stbp_id, nomor_stbp, tanggal, akun_kode, akun_nama, jumlah, uraian)
         VALUES (?, ?, 'STBP-APL-1', '2026-03-05', '4.1.1.01.03', 'Pajak Hotel', '500000.00', 'Rincian uji')"
    )->execute([$stsId, $stbpId]);

    // SPM + SP2D dicairkan & sudah approve (masuk BKU/LRA belanja).
    $pdo->prepare(
        "INSERT INTO spm (user_id, skpd, nomor_spm, tanggal, jumlah, status, created_at, updated_at)
         VALUES (?, ?, 'SPM-APL-1', '2026-03-04', '400000.00', 'sudah_diverifikasi', NOW(), NOW())"
    )->execute([$uid, $skpd]);

    $spmId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO sp2d (user_id, skpd, nomor_sp2d, tanggal, spm_id, rekening, jumlah, status, jurnal_status, created_at, updated_at)
         VALUES (?, ?, 'SP2D-APL-1', '2026-03-04', ?, '5140667171', '400000.00', 'sudah_dicairkan', 'sudah_approve', NOW(), NOW())"
    )->execute([$uid, $skpd, $spmId]);

    // Jurnal manual (jurnal biasa) dengan berbagai tipe & periode.
    $insJb = $pdo->prepare(
        "INSERT INTO jurnal_manual (user_id, skpd, nomor_jurnal, nomor_dokumen, kode_akun, nama_akun, tanggal, uraian, jumlah, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $insJb->execute([$uid, $skpd, 'JB-0001', 'STBP-APL-1', '4.1.1.01.03', 'Pajak Hotel', '2026-03-01', 'Setoran pajak hotel', '100000.00']);
    $insJb->execute([$uid, $skpd, 'JB-0002', 'STS-APL-1', '4.1.1.02.01', 'Retribusi', '2026-04-01', 'Retribusi parkir & retribusi', '50000.00']);

    // Akun (COA) untuk saran jurnal biasa.
    $insAkun = $pdo->prepare(
        "INSERT INTO akun_penerimaan (kode_akun, nama_akun, skpd, status_aktif, metode_input, created_at, updated_at)
         VALUES (?, ?, ?, 1, 'harian', NOW(), NOW())"
    );
    $insAkun->execute(['4.1.1.01.03', 'Pajak Hotel', $skpd]);
    $insAkun->execute(['4.1.1.02.01', 'Retribusi Parkir', $skpd]);

    // Anggaran LRA & kegiatan (untuk LRA).
    $insAng = $pdo->prepare("INSERT INTO anggaran_lra (skpd, kode_akun, tahun, anggaran) VALUES (?, ?, '2026', ?)");
    $insAng->execute([$skpd, '4.1.1.01.03', '5000000.00']);
    $insAng->execute([$skpd, '4.1.1.02.01', '2000000.00']);

    $pdo->prepare(
        "INSERT INTO kegiatan (skpd, tahun, nama_kegiatan, pagu, realisasi, created_at, updated_at)
         VALUES (?, '2026', 'Kegiatan Uji', '7000000.00', '1000000.00', NOW(), NOW())"
    )->execute([$skpd]);

    // Neraca awal.
    $pdo->prepare(
        "INSERT INTO neraca_awal (skpd, kode_akun, nama_akun, jenis, tahun, saldo)
         VALUES (?, '1.1.1.01', 'Kas', 'aset', '2026', '1500000.00')"
    )->execute([$skpd]);

    return $uid;
}

/** Permintaan HTTP sederhana (tanpa cookie: autentikasi memakai token). */
function mintaAklap(string $url, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 20,
    ]);

    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $raw    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hSize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    return [
        'status' => $status,
        'head'   => substr($raw, 0, $hSize),
        'body'   => substr($raw, $hSize),
    ];
}

/** Urutkan kunci rekursif agar perbandingan tidak terpengaruh urutan kunci. */
function kanonAklap(mixed $v): mixed
{
    if (! is_array($v)) {
        return $v;
    }

    $out = [];

    foreach ($v as $k => $x) {
        $out[$k] = kanonAklap($x);
    }

    if (array_keys($out) !== range(0, count($out) - 1)) {
        ksort($out);
    }

    return $out;
}

bersihkanAklap($pdo, $SKPD, $USER);
semaiAklap($pdo, $SKPD, $USER, $TOKEN);

echo "=== E2E AKLAP (data nyata): token legacy -> tiket SSO -> Laravel ===\n\n";

$aksi = [
    'jurnal'          => 'Daftar jurnal (STBP + STS + SP2D)',
    'jurnal_biasa'    => 'Daftar jurnal manual',
    'akun_list'       => 'Daftar akun COA',
    'dokumen_list'    => 'Daftar dokumen sumber',
    'lra_rekap'       => 'Rekap LRA (realisasi + anggaran)',
    'neraca_rekap'    => 'Rekap neraca (saldo awal)',
    'profil'          => 'Profil pemilik token',
    'rekap'           => 'Rekap dashboard AKLAP',
];

$lulus = 0;
$gagal = 0;

foreach ($aksi as $action => $label) {
    $qs = 'action=' . $action . '&token=' . $TOKEN;

    $a = mintaAklap($A . '/peta.simtkd.com/api/aklap.php?' . $qs);
    $b = mintaAklap($B . '/peta.simtkd.com/api/aklap.php?' . $qs);

    $ka = json_encode(kanonAklap(json_decode($a['body'], true)));
    $kb = json_encode(kanonAklap(json_decode($b['body'], true)));
    $marker = preg_match('/x-simtkd-served-by:\s*cutover/i', $b['head']) === 1;

    if ($ka === $kb && $a['status'] === $b['status'] && $marker) {
        $lulus++;
        printf("  LULUS  %-14s %-38s (%d B)\n", $action, $label, strlen((string) $kb));
        continue;
    }

    $gagal++;
    printf("  GAGAL  %-14s (status A=%d B=%d, marker=%s)\n", $action, $a['status'], $b['status'], $marker ? 'ya' : 'TIDAK');

    if ($ka !== $kb) {
        echo '    LEGACY : ' . substr((string) $ka, 0, 300) . "\n";
        echo '    LARAVEL: ' . substr((string) $kb, 0, 300) . "\n";
    }
}

// Pemeriksaan isi: nilai yang harus muncul pada respons lewat proksi.
$rekap = json_decode(mintaAklap($B . '/peta.simtkd.com/api/aklap.php?action=rekap&token=' . $TOKEN)['body'], true);

/** Query bantu untuk memeriksa keadaan basis data. */
$q = static function (string $sql, array $p = []) use ($pdo): array {
    $s = $pdo->prepare($sql);
    $s->execute($p);

    return (array) ($s->fetch() ?: []);
};

$cek = static function (string $label, bool $ok, string $detail = '') use (&$lulus, &$gagal): void {
    if ($ok) {
        $lulus++;
        printf("  LULUS  %s\n", $label);

        return;
    }

    $gagal++;
    printf("  GAGAL  %s %s\n", $label, $detail);
};

$cek('rekap: 2 STBP & 1 SP2D cair', ((int) ($rekap['data']['stbp'] ?? -1) === 2 && (int) ($rekap['data']['sp2d_cair'] ?? -1) === 1));
$cek('rekap: total penerimaan hanya yang approve (1.000.000)', ((float) ($rekap['data']['total_penerimaan'] ?? 0) === 1000000.0));
$cek('rekap: total belanja dari SP2D approve (400.000)', ((float) ($rekap['data']['total_belanja'] ?? 0) === 400000.0));

$lra = json_decode(mintaAklap($B . '/peta.simtkd.com/api/aklap.php?action=lra_rekap&token=' . $TOKEN)['body'], true);
$cek('lra: realisasi per akun hanya yang approve', (($lra['realisasi_by_akun']['4.1.1.01.03'] ?? 0) == 1000000));
$cek('lra: total_penerimaan = jumlah realisasi', (($lra['total_penerimaan'] ?? 0) == 1000000));

$jurnal = json_decode(mintaAklap($B . '/peta.simtkd.com/api/aklap.php?action=jurnal&token=' . $TOKEN)['body'], true);
$cek('jurnal: 4 baris (2 STBP + 1 STS + 1 SP2D) terurut tanggal', ((int) ($jurnal['total'] ?? 0) === 4
    && $jurnal['data'][0]['tgl'] === '2026-03-03'
    && $jurnal['data'][3]['tgl'] === '2026-03-06'));

// ---------------------------------------------------------------------------
// Akuntansi manual (akuntansi.php): Master Akun + Anggaran lewat PROKSI.
// Sekaligus membuktikan data yang DITULIS Laravel dibaca identik oleh legacy.
// ---------------------------------------------------------------------------
echo "\n=== Akuntansi manual: master akun + anggaran (lewat proksi) ===\n\n";

$baseAk = '/peta.simtkd.com/api/akuntansi.php?token=' . $TOKEN . '&action=';

/** Panggilan mutasi akuntansi lewat proksi (legacy URL -> Laravel). */
$mutasi = static function (string $action, array $data) use ($B, $TOKEN, $baseAk): ?array {
    $res  = mintaAklap($B . $baseAk . $action, $data);
    $json = json_decode($res['body'], true);

    return is_array($json) ? $json : null;
};

/** Bandingkan respons legacy langsung vs proksi untuk satu aksi akuntansi. */
$bandingAk = static function (string $label, string $action, array $params) use ($A, $B, $TOKEN, &$lulus, &$gagal): void {
    $qs = 'token=' . $TOKEN . '&action=' . $action . ($params !== [] ? '&' . http_build_query($params) : '');

    $a = mintaAklap($A . '/peta.simtkd.com/api/akuntansi.php?' . $qs);
    $b = mintaAklap($B . '/peta.simtkd.com/api/akuntansi.php?' . $qs);

    $ka = json_encode(kanonAklap(json_decode($a['body'], true)));
    $kb = json_encode(kanonAklap(json_decode($b['body'], true)));

    if ($ka === $kb && $a['status'] === $b['status']) {
        $lulus++;
        printf("  LULUS  %s\n", $label);

        return;
    }

    $gagal++;
    printf("  GAGAL  %s (status A=%d B=%d)\n", $label, $a['status'], $b['status']);
    echo '    LEGACY : ' . substr((string) $ka, 0, 250) . "\n";
    echo '    LARAVEL: ' . substr((string) $kb, 0, 250) . "\n";
};

// 1) Profil & daftar akun (auto-seed COA ringkas + COA LRA standar).
$bandingAk('profil akuntansi (NIP + TTD)', 'profil', []);
$bandingAk('daftar Master Akun (auto-seed)', 'akun_list', []);

// 2) Simpan pagu batch lewat proksi (ditulis Laravel), lalu dibaca legacy.
$simpan = $mutasi('anggaran_simpan', [
    'tahun' => '2026',
    'rows'  => json_encode([
        ['kode_akun' => '4.1.1.01', 'anggaran' => '1.000,50'],
        ['kode_akun' => '5.1.2.01', 'anggaran' => '2000000'],
    ]),
]);
$cek('anggaran_simpan lewat proksi (2 akun)', (($simpan['success'] ?? false) === true && (int) ($simpan['tersimpan'] ?? 0) === 2));
$cek('pagu tersimpan & dinormalkan di DB', ((string) ($q('SELECT anggaran FROM anggaran_lra WHERE skpd = ? AND kode_akun = ? AND tahun = ?', [$SKPD, '4.1.1.01', '2026'])['anggaran'] ?? '') === '1000.50'));
$bandingAk('daftar anggaran dibaca identik', 'anggaran_list', ['tahun' => '2026']);

// 3) Tambah akun baru lewat proksi.
$akunBaru = $mutasi('akun_simpan', ['kode_akun' => '9.9.1.01', 'nama_akun' => 'Akun Uji E2E', 'tipe' => 'belanja', 'saldo_normal' => 'debet']);
$cek('akun_simpan lewat proksi', (($akunBaru['success'] ?? false) === true && ($akunBaru['message'] ?? '') === 'Akun tersimpan.'));
$bandingAk('daftar akun setelah penambahan', 'akun_list', []);

// 4) Seed ulang COA (idempoten) & sinkronisasi pagu.
$seed = $mutasi('akun_seed_coa', []);
$cek('seed COA idempoten', (($seed['message'] ?? '') === 'Seluruh COA LRA standar sudah tersedia.'));

$sinkron = $mutasi('anggaran_sinkron_master', []);
$cek('sinkron master: tidak ada kode baru', (($sinkron['message'] ?? '') === 'Semua kode anggaran sudah ada di Master Akun.'));

// 5) Hapus akun & pagu lewat proksi.
$hapusAkun = $mutasi('akun_hapus', ['id' => (int) ($akunBaru['id'] ?? 0)]);
$cek('akun_hapus lewat proksi', (($hapusAkun['message'] ?? '') === 'Akun dihapus.'));

$hapusPagu = $mutasi('anggaran_hapus', ['tahun' => '2026', 'kode_akun' => '4.1.1.01']);
$cek('anggaran_hapus lewat proksi', (($hapusPagu['message'] ?? '') === 'Pagu akun 4.1.1.01 dihapus (menjadi 0).'));
$cek('pagu benar-benar terhapus', ((int) ($q('SELECT COUNT(*) AS n FROM anggaran_lra WHERE skpd = ? AND kode_akun = ?', [$SKPD, '4.1.1.01'])['n'] ?? 1) === 0));

// ---------------------------------------------------------------------------
// Jurnal umum (double-entry) lewat PROKSI: saran -> simpan -> approve -> hapus.
// Sekaligus membuktikan jurnal yang ditulis Laravel dibaca identik oleh legacy.
// ---------------------------------------------------------------------------
echo "\n=== Jurnal umum (double-entry) lewat proksi ===\n\n";

$bandingAk('daftar jurnal (awal)', 'jurnal_list', []);
$bandingAk('saran dokumen (awal)', 'saran_dokumen', []);

// Template jurnal dari SP2D yang disemai (UP/GU/TU -> kas pengeluaran vs BUD).
$sp2dId = (int) ($q('SELECT id FROM sp2d WHERE skpd = ? ORDER BY id LIMIT 1', [$SKPD])['id'] ?? 0);
$saranJurnal = json_decode(mintaAklap($B . '/peta.simtkd.com/api/akuntansi.php?token=' . $TOKEN
    . '&action=saran_jurnal&sumber=sp2d&id=' . $sp2dId)['body'], true);

$cek('saran jurnal SP2D memuat 2 baris seimbang', ((int) count($saranJurnal['detail'] ?? []) === 2
    && ($saranJurnal['detail'][0]['posisi'] ?? '') === 'debet'
    && ($saranJurnal['detail'][1]['posisi'] ?? '') === 'kredit'));

// Simpan jurnal dari template lewat proksi (ditulis Laravel).
$simpanJurnal = $mutasi('jurnal_simpan', [
    'tanggal'     => '2026-03-04',
    'uraian'      => (string) ($saranJurnal['uraian'] ?? ''),
    'sumber_tipe' => 'sp2d',
    'sumber_id'   => $sp2dId,
    'detail'      => json_encode($saranJurnal['detail'] ?? []),
]);

$jurnalId = (int) ($simpanJurnal['id'] ?? 0);
$cek('jurnal_simpan lewat proksi', (($simpanJurnal['success'] ?? false) === true
    && preg_match('/^Jurnal JU-\d{6}-\d{4} tersimpan\.$/', (string) ($simpanJurnal['message'] ?? '')) === 1));
$cek('SP2D ditandai sudah_dijurnal', ((int) ($q('SELECT sudah_dijurnal AS n FROM sp2d WHERE id = ?', [$sp2dId])['n'] ?? 0) === 1));

// Data tulisan Laravel dibaca identik oleh legacy.
$bandingAk('daftar jurnal setelah simpan (2 baris rincian)', 'jurnal_list', []);

// Approve lewat proksi.
$approve = $mutasi('jurnal_status', ['id' => $jurnalId, 'status' => 'sudah_approve']);
$cek('jurnal_status approve lewat proksi', (($approve['message'] ?? '') === 'Jurnal di-approve.'));
$cek('status jurnal tersimpan', ((string) ($q('SELECT status AS s FROM jurnal_umum WHERE id = ?', [$jurnalId])['s'] ?? '') === 'sudah_approve'));
$bandingAk('daftar jurnal setelah approve', 'jurnal_list', []);

// Saran dokumen tidak lagi memuat SP2D tersebut.
$saranSetelah = json_decode(mintaAklap($B . '/peta.simtkd.com/api/akuntansi.php?token=' . $TOKEN
    . '&action=saran_dokumen')['body'], true);
$cek('SP2D hilang dari saran dokumen', ((int) count($saranSetelah['sp2d'] ?? []) === 0));

// Hapus lewat proksi -> penanda dibebaskan.
$hapusJurnal = $mutasi('jurnal_hapus', ['id' => $jurnalId]);
$cek('jurnal_hapus lewat proksi', (($hapusJurnal['message'] ?? '') === 'Jurnal dihapus.'));
$cek('SP2D dapat dijurnal ulang', ((int) ($q('SELECT sudah_dijurnal AS n FROM sp2d WHERE id = ?', [$sp2dId])['n'] ?? 1) === 0));
$cek('rincian jurnal ikut terhapus', ((int) ($q('SELECT COUNT(*) AS n FROM jurnal_umum_detail WHERE jurnal_id = ?', [$jurnalId])['n'] ?? 1) === 0));

// ---------------------------------------------------------------------------
// Laporan akuntansi: buku besar, neraca saldo, buku kas (umum), buku pembantu.
// Data disemai lebih dulu agar laporan benar-benar berisi (bukan daftar kosong).
// ---------------------------------------------------------------------------
echo "\n=== Laporan akuntansi (buku besar / neraca saldo / buku kas / pembantu) ===\n\n";

$uid = (int) ($q('SELECT id FROM users WHERE username = ?', [$USER])['id'] ?? 0);

// Entri buku kas: Maret 2 baris + April 1 baris (menguji total bulan & s.d. bulan).
$insBk = $pdo->prepare(
    "INSERT INTO buku_kas (skpd, user_id, tanggal, jenis, no_bukti, kode_rekening, uraian, jumlah)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
);
$insBk->execute([$SKPD, $uid, '2026-03-02', 'penerimaan', 'BK-1', '4.1.1.01.03', 'Setoran pajak hotel', '200000.00']);
$insBk->execute([$SKPD, $uid, '2026-03-10', 'pengeluaran', 'BK-2', '5.1.2.01', 'Belanja ATK', '75000.00']);
$insBk->execute([$SKPD, $uid, '2026-04-05', 'penerimaan', 'BK-3', '4.1.1.01.03', 'Setoran April', '300000.00']);

// Saldo awal dari neraca (dipakai buku besar & neraca saldo).
//  - 1.1.1.01 sudah punya saldo awal 1.500.000 dari semaian dasar -> menambah
//    baris kedua membuktikan SUM beberapa baris neraca_awal dijumlahkan.
//  - 1.1.2.01 hanya punya baris ini (uji saldo awal yang bersih).
$pdo->prepare(
    "INSERT INTO neraca_awal (skpd, tahun, kode_akun, nama_akun, saldo, jenis) VALUES
     (?, '2026', '1.1.1.01', 'Kas di Bendahara Penerimaan', '50000.00', 'aset'),
     (?, '2026', '1.1.2.01', 'Rekening Bank SKPD', '75000.00', 'aset')"
)->execute([$SKPD, $SKPD]);

// Jurnal approved supaya ada posting di buku besar / neraca saldo.
$jurnalLaporan = $mutasi('jurnal_simpan', [
    'tanggal' => '2026-03-20',
    'uraian'  => 'Jurnal laporan E2E',
    'detail'  => json_encode([
        ['kode_akun' => '1.1.1.01', 'posisi' => 'debet', 'jumlah' => '150000'],
        ['kode_akun' => '4.1.1.01.03', 'posisi' => 'kredit', 'jumlah' => '150000'],
    ]),
]);
$jurnalLaporanId = (int) ($jurnalLaporan['id'] ?? 0);
$mutasi('jurnal_status', ['id' => $jurnalLaporanId, 'status' => 'sudah_approve']);

$bandingAk('buku besar akun pendapatan (periode Maret)', 'buku_besar', ['kode_akun' => '4.1.1.01.03', 'dari' => '2026-03-01', 'akhir' => '2026-03-31']);
$bandingAk('buku besar akun kas (saldo awal + posting)', 'buku_besar', ['kode_akun' => '1.1.1.01']);
$bandingAk('buku besar tanpa kode akun (422)', 'buku_besar', []);
$bandingAk('buku besar periode tidak valid (diabaikan)', 'buku_besar', ['kode_akun' => '1.1.1.01', 'dari' => '2026-13-99']);

$bb = json_decode(mintaAklap($B . $baseAk . 'buku_besar&kode_akun=4.1.1.01.03&dari=2026-03-01&akhir=2026-03-31')['body'], true);
$cek('buku besar: posting kredit 150.000 & saldo normal kredit', (($bb['success'] ?? false) === true
    && ($bb['saldo_normal'] ?? '') === 'kredit'
    && (float) ($bb['total_kredit'] ?? 0) === 150000.0
    && (float) ($bb['saldo_akhir'] ?? 0) === 150000.0
    && (int) ($bb['total'] ?? 0) === 1));

$bbKas = json_decode(mintaAklap($B . $baseAk . 'buku_besar&kode_akun=1.1.1.01')['body'], true);
$cek('buku besar kas: saldo awal 1.550.000 (2 baris neraca) + debet 150.000', ((float) ($bbKas['saldo_awal'] ?? 0) === 1550000.0
    && (float) ($bbKas['total_debet'] ?? 0) === 150000.0
    && (float) ($bbKas['saldo_akhir'] ?? 0) === 1700000.0));

$bbBank = json_decode(mintaAklap($B . $baseAk . 'buku_besar&kode_akun=1.1.2.01')['body'], true);
$cek('buku besar rekening bank: saldo awal 75.000 tanpa posting', (($bbBank['nama_akun'] ?? '') === 'Rekening Bank SKPD'
    && (float) ($bbBank['saldo_awal'] ?? 0) === 75000.0
    && (int) ($bbBank['total'] ?? -1) === 0
    && (float) ($bbBank['saldo_akhir'] ?? 0) === 75000.0));



$bandingAk('neraca saldo (seluruh akun)', 'neraca_saldo', []);
$bandingAk('neraca saldo periode Maret', 'neraca_saldo', ['dari' => '2026-03-01', 'akhir' => '2026-03-31']);

$ns = json_decode(mintaAklap($B . $baseAk . 'neraca_saldo&dari=2026-03-01&akhir=2026-03-31')['body'], true);
$barisNs = [];

foreach ((array) ($ns['data'] ?? []) as $r) {
    $barisNs[(string) $r['kode_akun']] = $r;
}

$cek('neraca saldo: mutasi pendapatan 150.000 (kredit)', ((float) ($barisNs['4.1.1.01.03']['mutasi_kredit'] ?? 0) === 150000.0
    && (float) ($barisNs['4.1.1.01.03']['saldo_akhir'] ?? 0) === 150000.0));
$cek('neraca saldo: kas saldo awal 1.550.000 + mutasi debet 150.000', ((float) ($barisNs['1.1.1.01']['saldo_awal'] ?? 0) === 1550000.0
    && (float) ($barisNs['1.1.1.01']['mutasi_debet'] ?? 0) === 150000.0
    && (float) ($barisNs['1.1.1.01']['saldo_akhir'] ?? 0) === 1700000.0));
$cek('neraca saldo: rekening bank 75.000 tanpa mutasi', ((float) ($barisNs['1.1.2.01']['saldo_awal'] ?? 0) === 75000.0
    && (float) ($barisNs['1.1.2.01']['saldo_akhir'] ?? 0) === 75000.0));

$bandingAk('buku kas seluruh entri (saldo berjalan)', 'buku_kas_list', []);
$bandingAk('buku kas difilter pengeluaran', 'buku_kas_list', ['jenis' => 'pengeluaran']);
$bandingAk('buku kas periode Maret', 'buku_kas_list', ['dari' => '2026-03-01', 'akhir' => '2026-03-31']);
$bandingAk('buku kas umum (saldo awal periode)', 'buku_kas_umum', ['dari' => '2026-03-01']);
$bandingAk('buku kas umum (saldo awal manual 1.500.000,50)', 'buku_kas_umum', ['dari' => '2026-03-01', 'saldo_awal' => '1.500.000,50']);
$bandingAk('buku kas umum periode Maret + saldo awal manual', 'buku_kas_umum', ['dari' => '2026-03-01', 'akhir' => '2026-03-31', 'saldo_awal' => '1.500.000,50']);

$bk = json_decode(mintaAklap($B . $baseAk . 'buku_kas_list')['body'], true);
$cek('buku kas: 3 entri, saldo akhir 425.000', ((int) ($bk['total'] ?? 0) === 3
    && (float) ($bk['saldo_akhir'] ?? 0) === 425000.0
    && (float) ($bk['total_penerimaan'] ?? 0) === 500000.0
    && (float) ($bk['total_pengeluaran'] ?? 0) === 75000.0));

$bku = json_decode(mintaAklap($B . $baseAk . 'buku_kas_umum&dari=2026-03-01&akhir=2026-03-31&saldo_awal=1.500.000,50')['body'], true);
$cek('buku kas umum: saldo awal manual & saldo akhir 1.625.000,50', ((float) ($bku['saldo_awal'] ?? 0) === 1500000.5
    && (int) ($bku['total'] ?? 0) === 2
    && (float) ($bku['saldo_akhir'] ?? 0) === 1625000.5
    && ($bku['instansi'] ?? '') === $SKPD
    && ($bku['bendahara'] ?? '') === 'Bendahara AKLAP'));

$bandingAk('buku pembantu objek pendapatan (April)', 'buku_pembantu_list', ['jenis' => 'penerimaan', 'kode_akun' => '4.1.1.01.03', 'bulan' => '4', 'tahun' => '2026']);
$bandingAk('buku pembantu objek belanja (Maret)', 'buku_pembantu_list', ['jenis' => 'pengeluaran', 'kode_akun' => '5.1.2.01', 'bulan' => '3', 'tahun' => '2026']);
$bandingAk('buku pembantu tanpa jenis (422)', 'buku_pembantu_list', ['jenis' => '', 'kode_akun' => '4.1.1.01.03']);
$bandingAk('buku pembantu tanpa kode (422)', 'buku_pembantu_list', ['jenis' => 'penerimaan', 'kode_akun' => '']);
$bandingAk('pemilih objek buku pembantu (penerimaan)', 'buku_pembantu_obyek_list', ['jenis' => 'penerimaan']);
$bandingAk('pemilih objek buku pembantu (jenis salah -> 422)', 'buku_pembantu_obyek_list', ['jenis' => 'salah']);

$bp = json_decode(mintaAklap($B . $baseAk . 'buku_pembantu_list&jenis=penerimaan&kode_akun=4.1.1.01.03&bulan=4&tahun=2026')['body'], true);
$cek('buku pembantu: 1 baris April, s.d. bulan lalu 200.000, s.d. bulan ini 500.000', ((int) count((array) ($bp['rows'] ?? [])) === 1
    && (float) ($bp['jumlah_bulan_ini'] ?? 0) === 300000.0
    && (float) ($bp['jumlah_sd_bulan_lalu'] ?? 0) === 200000.0
    && (float) ($bp['jumlah_sd_bulan_ini'] ?? 0) === 500000.0
    && (float) ($bp['anggaran'] ?? 0) === 5000000.0));

$bo = json_decode(mintaAklap($B . $baseAk . 'buku_pembantu_obyek_list&jenis=penerimaan')['body'], true);
$cek('pemilih objek: 1 objek penerimaan total 500.000', ((int) ($bo['total'] ?? 0) === 1
    && ($bo['obyek'][0]['kode_akun'] ?? '') === '4.1.1.01.03'
    && (float) ($bo['obyek'][0]['total'] ?? 0) === 500000.0));

// Bersihkan jurnal laporan agar keadaan akhir run tetap bersih.
$mutasi('jurnal_hapus', ['id' => $jurnalLaporanId]);
// ---------------------------------------------------------------------------
// Mutasi kas lewat PROKSI: buku kas (simpan/ubah/hapus) & laporan posisi kas
// harian (simpan baca ulang, saran posisi H-1, hapus).
// ---------------------------------------------------------------------------
echo "\n=== Mutasi kas: buku kas & posisi kas harian (lewat proksi) ===\n\n";

$bandingAk('buku kas sebelum mutasi', 'buku_kas_list', []);

// 1) Tambah entri lewat proksi (ditulis Laravel).
$bkBaru = $mutasi('buku_kas_simpan', [
    'tanggal'       => '2026-03-18',
    'jenis'         => 'pengeluaran',
    'no_bukti'      => 'BK-E1',
    'kode_rekening' => '5.1.2.01',
    'uraian'        => 'Belanja E2E',
    'jumlah'        => '150000',
]);
$bkId = (int) ($bkBaru['id'] ?? 0);

$cek('buku_kas_simpan lewat proksi', (($bkBaru['message'] ?? '') === 'Transaksi tersimpan.' && $bkId > 0));
$cek('entri buku kas tersimpan di DB', ((string) ($q('SELECT jumlah AS j FROM buku_kas WHERE id = ? AND skpd = ?', [$bkId, $SKPD])['j'] ?? '') === '150000.00'));
$bandingAk('buku kas setelah simpan (dibaca legacy)', 'buku_kas_list', []);

// 2) Ubah entri yang sama.
$bkUbah = $mutasi('buku_kas_simpan', [
    'id'            => $bkId,
    'tanggal'       => '2026-03-18',
    'jenis'         => 'pengeluaran',
    'no_bukti'      => 'BK-E1',
    'kode_rekening' => '5.1.2.01',
    'uraian'        => 'Belanja E2E (revisi)',
    'jumlah'        => '175.000',
]);
$cek('buku_kas_simpan ubah lewat proksi', (($bkUbah['message'] ?? '') === 'Transaksi diperbarui.' && (int) ($bkUbah['id'] ?? 0) === $bkId));
$cek('perubahan buku kas tersimpan', ((string) ($q('SELECT jumlah AS j FROM buku_kas WHERE id = ?', [$bkId])['j'] ?? '') === '175.00'));

// 3) Hapus entri.
$bkHapus = $mutasi('buku_kas_hapus', ['id' => $bkId]);
$cek('buku_kas_hapus lewat proksi', (($bkHapus['message'] ?? '') === 'Transaksi dihapus.'));
$cek('entri buku kas benar-benar terhapus', ((int) ($q('SELECT COUNT(*) AS n FROM buku_kas WHERE id = ?', [$bkId])['n'] ?? 1) === 0));
$cek('buku_kas_hapus entri hilang', (($mutasi('buku_kas_hapus', ['id' => $bkId])['message'] ?? '') === 'Transaksi tidak ditemukan.'));
$bandingAk('buku kas setelah hapus', 'buku_kas_list', []);


// 4) Laporan Posisi Kas Harian: saran H-1, simpan (2 baris, 1 baris kosong
//    dibuang), baca ulang, lalu hapus.
$khKosong = json_decode(mintaAklap($B . $baseAk . 'kas_harian&tanggal=2026-03-18')['body'], true);
$cek('kas harian belum ada: saran posisi nol', (($khKosong['sumber_posisi_h1'] ?? '') === 'nol'
    && ($khKosong['tersimpan'] ?? true) === false
    && (float) ($khKosong['posisi_h'] ?? -1) === 0.0
    && (int) count((array) ($khKosong['items'] ?? [])) === 0));

$khSimpan = $mutasi('kas_harian_simpan', [
    'tanggal'     => '2026-03-18',
    'posisi_h1'   => '1.000.000',
    'bank1_nama'  => 'Bank Daerah',
    'bank1_saldo' => '2.500.000,50',
    'bank2_saldo' => '500000',
    'catatan'     => 'Catatan E2E',
    'items'       => json_encode([
        ['no_sts' => 'STS-APL-1', 'uraian' => 'Setoran STS', 'penerimaan' => '500.000', 'pengeluaran' => '0'],
        ['no_sp2d' => 'SP2D-APL-1', 'uraian' => 'Pencairan SP2D', 'penerimaan' => '0', 'pengeluaran' => '250.000'],
        ['no_sp2d' => '', 'no_sts' => '', 'no_lain' => '', 'uraian' => '', 'penerimaan' => '0', 'pengeluaran' => '0'],
    ]),
]);

$cek('kas_harian_simpan: 2 baris (baris kosong dibuang)', (($khSimpan['message'] ?? '') === 'Laporan Posisi Kas Harian tersimpan (2 baris).'
    && (int) ($khSimpan['laporan_id'] ?? 0) > 0
    && (int) count((array) ($khSimpan['items'] ?? [])) === 2
    && (float) ($khSimpan['total_penerimaan'] ?? 0) === 500000.0
    && (float) ($khSimpan['total_pengeluaran'] ?? 0) === 250000.0
    && (float) ($khSimpan['perubahan'] ?? 0) === 250000.0
    && (float) ($khSimpan['posisi_h1'] ?? 0) === 1000000.0
    && (float) ($khSimpan['posisi_h'] ?? 0) === 1250000.0
    && (float) ($khSimpan['bank1_saldo'] ?? 0) === 2500000.5
    && (float) ($khSimpan['total_saldo'] ?? 0) === 3000000.5
    && ($khSimpan['hari'] ?? '') === 'Rabu'
    && ($khSimpan['periode'] ?? '') === 'Maret'
    && ($khSimpan['bank1_nama'] ?? '') === 'Bank Daerah'
    && ($khSimpan['tersimpan'] ?? false) === true));

$cek('dokumen & baris kas harian tersimpan di DB', ((int) ($q('SELECT COUNT(*) AS n FROM kas_harian WHERE skpd = ? AND tanggal = ?', [$SKPD, '2026-03-18'])['n'] ?? 0) === 1
    && (int) ($q('SELECT COUNT(*) AS n FROM kas_harian_item i INNER JOIN kas_harian k ON k.id = i.kas_harian_id WHERE k.skpd = ? AND k.tanggal = ?', [$SKPD, '2026-03-18'])['n'] ?? 0) === 2));

// Data tulisan Laravel dibaca identik oleh legacy.
$bandingAk('kas harian setelah simpan (dibaca legacy)', 'kas_harian', ['tanggal' => '2026-03-18']);
$bandingAk('daftar kas harian setelah simpan', 'kas_harian_daftar', []);

// Hari berikutnya: posisi H-1 disarankan dari posisi H dokumen sebelumnya.
$khSaran = json_decode(mintaAklap($B . $baseAk . 'kas_harian&tanggal=2026-03-19')['body'], true);
$cek('kas harian hari berikutnya: saran posisi dari hari sebelumnya', (($khSaran['sumber_posisi_h1'] ?? '') === 'hari_sebelumnya'
    && ($khSaran['posisi_h1_dari'] ?? '') === '2026-03-18'
    && (float) ($khSaran['posisi_h1'] ?? 0) === 1250000.0));

$khHapus = $mutasi('kas_harian_hapus', ['tanggal' => '2026-03-18']);
$cek('kas_harian_hapus lewat proksi', (($khHapus['message'] ?? '') === 'Laporan tanggal 2026-03-18 dihapus.'));
$cek('dokumen & baris kas harian terhapus', ((int) ($q('SELECT COUNT(*) AS n FROM kas_harian WHERE skpd = ? AND tanggal = ?', [$SKPD, '2026-03-18'])['n'] ?? 1) === 0
    && (int) ($q('SELECT COUNT(*) AS n FROM kas_harian_item WHERE skpd = ?', [$SKPD])['n'] ?? 1) === 0));
$cek('kas_harian_hapus dokumen hilang', (($mutasi('kas_harian_hapus', ['tanggal' => '2026-03-18'])['message'] ?? '') === 'Laporan tanggal tersebut belum ada.'));
$bandingAk('kas harian setelah hapus', 'kas_harian', ['tanggal' => '2026-03-18']);


// ---------------------------------------------------------------------------
// Rekonsiliasi bank lewat PROKSI: dokumen kosong -> simpan -> baca ulang ->
// ubah (ganti rincian) -> hapus.
// ---------------------------------------------------------------------------
echo "\n=== Rekonsiliasi bank (blanko Modul-3E-LK) lewat proksi ===\n\n";

$bandingAk('rekonsiliasi bank periode Maret (belum tersimpan)', 'rekonsiliasi_bank', ['tahun' => '2026', 'bulan' => '3']);

$rbKosong = json_decode(mintaAklap($B . $baseAk . 'rekonsiliasi_bank&tahun=2026&bulan=3')['body'], true);
$cek('rekonsiliasi belum ada: periode/tgl_ttd disarankan, saran saldo buku 125.000', (($rbKosong['periode'] ?? '') === 'Maret'
    && ($rbKosong['tgl_ttd'] ?? '') === '2026-03-31'
    && ($rbKosong['tersimpan'] ?? true) === false
    && (float) ($rbKosong['saran_saldo_buku'] ?? 0) === 125000.0   // buku kas s.d. 2026-03-31
    && (float) ($rbKosong['total_buku'] ?? -1) === 0.0));

$rbSimpan = $mutasi('rekonsiliasi_bank_simpan', [
    'tahun'      => '2026',
    'bulan'      => '3',
    'tgl_ttd'    => '',                      // -> default akhir bulan
    'saldo_buku' => '10.000.000',
    'saldo_bank' => '9.000.000,25',
    'catatan'    => 'Rekonsiliasi uji E2E',
    'items'      => json_encode([
        'A' => [['uraian' => 'Setoran belum tercatat bank', 'nilai' => '1.000.000']],
        'B' => [['uraian' => '', 'nilai' => '0']],                  // baris kosong dibuang
        'C' => [['uraian' => 'Biaya administrasi bank', 'nilai' => '25.000,50']],
        'D' => [['uraian' => 'Kliring belum cair', 'nilai' => '500000']],
    ]),
]);

$rbId = (int) ($rbSimpan['laporan_id'] ?? 0);
$cek('rekonsiliasi_bank_simpan lewat proksi (3 baris rincian)', (($rbSimpan['message'] ?? '') === 'Rekonsiliasi Bank tersimpan (3 baris rincian).'
    && $rbId > 0
    && (float) ($rbSimpan['saldo_buku'] ?? 0) === 10000000.0
    && (float) ($rbSimpan['saldo_bank'] ?? 0) === 9000000.25
    && (float) ($rbSimpan['selisih'] ?? 0) === 999999.75
    && (float) ($rbSimpan['subtotal']['C'] ?? 0) === 25000.5
    && (float) ($rbSimpan['total_buku'] ?? 0) === 1000000.0
    && (float) ($rbSimpan['total_bank'] ?? 0) === 525000.5
    && ($rbSimpan['tgl_ttd'] ?? '') === '2026-03-31'
    && (int) count((array) ($rbSimpan['bagian']['B'] ?? [])) === 0));

$cek('dokumen & rincian rekonsiliasi tersimpan di DB', ((int) ($q('SELECT COUNT(*) AS n FROM rekonsiliasi_bank WHERE skpd = ? AND tahun = 2026 AND bulan = 3', [$SKPD])['n'] ?? 0) === 1
    && (int) ($q('SELECT COUNT(*) AS n FROM rekonsiliasi_bank_item WHERE skpd = ?', [$SKPD])['n'] ?? 0) === 3));

// Data tulisan Laravel dibaca identik oleh legacy.
$bandingAk('rekonsiliasi bank setelah simpan (dibaca legacy)', 'rekonsiliasi_bank', ['tahun' => '2026', 'bulan' => '3']);
$bandingAk('daftar rekonsiliasi setelah simpan', 'rekonsiliasi_bank_daftar', []);

// Ubah periode yang sama -> dokumen diperbarui, rincian lama diganti.
$rbUlang = $mutasi('rekonsiliasi_bank_simpan', [
    'tahun'      => '2026',
    'bulan'      => '3',
    'tgl_ttd'    => '2026-04-02',
    'saldo_buku' => '11000000',
    'saldo_bank' => '11000000',
    'items'      => json_encode(['A' => [['uraian' => 'Hanya satu baris', 'nilai' => '1500000']]]),
]);

$cek('rekonsiliasi disimpan ulang (1 baris, selisih nol)', (($rbUlang['message'] ?? '') === 'Rekonsiliasi Bank tersimpan (1 baris rincian).'
    && (int) ($rbUlang['laporan_id'] ?? 0) === $rbId
    && (float) ($rbUlang['selisih'] ?? -1) === 0.0
    && ($rbUlang['tgl_ttd'] ?? '') === '2026-04-02'));

$cek('rincian rekonsiliasi lama diganti', ((int) ($q('SELECT COUNT(*) AS n FROM rekonsiliasi_bank_item WHERE rekonsiliasi_id = ?', [$rbId])['n'] ?? 0) === 1));

$rbHapus = $mutasi('rekonsiliasi_bank_hapus', ['tahun' => '2026', 'bulan' => '3']);
$cek('rekonsiliasi_bank_hapus lewat proksi', (($rbHapus['message'] ?? '') === 'Rekonsiliasi Bank periode Maret 2026 dihapus.'));
$cek('dokumen & rincian rekonsiliasi terhapus', ((int) ($q('SELECT COUNT(*) AS n FROM rekonsiliasi_bank WHERE skpd = ?', [$SKPD])['n'] ?? 1) === 0
    && (int) ($q('SELECT COUNT(*) AS n FROM rekonsiliasi_bank_item WHERE skpd = ?', [$SKPD])['n'] ?? 1) === 0));
$cek('rekonsiliasi_bank_hapus periode hilang', (($mutasi('rekonsiliasi_bank_hapus', ['tahun' => '2026', 'bulan' => '3'])['message'] ?? '') === 'Rekonsiliasi periode tersebut belum ada.'));
$bandingAk('daftar rekonsiliasi setelah hapus', 'rekonsiliasi_bank_daftar', []);


// ---------------------------------------------------------------------------
// Register SPP/SPM/SP2D & LPJ PPKD lewat PROKSI (turunan Buku Pengeluaran).
// ---------------------------------------------------------------------------
echo "\n=== Register & LPJ PPKD (lewat proksi) ===\n\n";

$bkKeluar   = (int) ($q('SELECT id FROM buku_kas WHERE skpd = ? AND no_bukti = ? LIMIT 1', [$SKPD, 'BK-2'])['id'] ?? 0);
$bkTerima   = (int) ($q('SELECT id FROM buku_kas WHERE skpd = ? AND no_bukti = ? LIMIT 1', [$SKPD, 'BK-1'])['id'] ?? 0);

$cek('data buku kas uji tersedia (1 pengeluaran + 1 penerimaan Maret)', ($bkKeluar > 0 && $bkTerima > 0));

$bandingAk('register PPKD sebelum diisi', 'register_ppkd_list', []);

// 1) Isi tanggal & nomor SPP/SPM/SP2D untuk transaksi pengeluaran.
$regSimpan = $mutasi('register_ppkd_simpan', [
    'buku_kas_id' => $bkKeluar,
    'spp_tgl'     => '2026-03-08',
    'spp_no'      => ' SPP-E2E-1 ',
    'spm_tgl'     => '2026-03-09',
    'spm_no'      => 'SPM-E2E-1',
    'sp2d_tgl'    => '2026-03-10',
    'sp2d_no'     => 'SP2D-E2E-1',
    'ket'         => 'Uji register',
]);

$cek('register_ppkd_simpan lewat proksi', (($regSimpan['message'] ?? '') === 'Register SPP/SPM/SP2D tersimpan.'
    && (int) ($regSimpan['buku_kas_id'] ?? 0) === $bkKeluar));
$cek('nomor SPP tersimpan tanpa spasi tepi', ((string) ($q('SELECT spp_no AS n FROM register_ppkd WHERE skpd = ? AND buku_kas_id = ?', [$SKPD, $bkKeluar])['n'] ?? '') === 'SPP-E2E-1'));
$bandingAk('register PPKD setelah diisi (dibaca legacy)', 'register_ppkd_list', []);

$reg = json_decode(mintaAklap($B . $baseAk . 'register_ppkd_list')['body'], true);
$cek('register: 1 baris lengkap, total 75.000', ((int) ($reg['total'] ?? 0) === 1
    && (float) ($reg['total_jumlah'] ?? 0) === 75000.0
    && (int) ($reg['jumlah_lengkap'] ?? 0) === 1
    && ($reg['data'][0]['sp2d_no'] ?? '') === 'SP2D-E2E-1'
    && (int) ($reg['data'][0]['lengkap'] ?? 0) === 1));

// 2) Simpan ulang (upsert) dengan data lebih sedikit -> tetap satu baris.
$regUlang = $mutasi('register_ppkd_simpan', [
    'buku_kas_id' => $bkKeluar,
    'spp_no'      => 'SPP-E2E-2',
]);

$cek('register upsert tetap satu baris & tanggal dikosongkan', (($regUlang['message'] ?? '') === 'Register SPP/SPM/SP2D tersimpan.'
    && (int) ($q('SELECT COUNT(*) AS n FROM register_ppkd WHERE skpd = ?', [$SKPD])['n'] ?? 0) === 1
    && (string) ($q('SELECT spp_no AS n FROM register_ppkd WHERE skpd = ?', [$SKPD])['n'] ?? '') === 'SPP-E2E-2'));

$regIsi = json_decode(mintaAklap($B . $baseAk . 'register_ppkd_list')['body'], true);
$cek('register: baris tidak lagi lengkap', ((int) ($regIsi['jumlah_lengkap'] ?? -1) === 0
    && ($regIsi['data'][0]['spp_tgl'] ?? 'x') === ''
    && (int) ($regIsi['data'][0]['lengkap'] ?? 1) === 0));

// 3) LPJ PPKD: rincian pengeluaran + rekonsiliasi bucket + saldo kas.
$bandingAk('LPJ PPKD periode Maret', 'lpj_ppkd', ['tahun' => '2026', 'bulan' => '3']);
$bandingAk('LPJ PPKD periode April', 'lpj_ppkd', ['tahun' => '2026', 'bulan' => '4']);

$lpj = json_decode(mintaAklap($B . $baseAk . 'lpj_ppkd&tahun=2026&bulan=3')['body'], true);
$barisLpj = (array) ($lpj['rincian'][0] ?? []);

$cek('LPJ: 1 baris rincian (belanja Maret 75.000, pagu 2.000.000)', ((int) ($lpj['jumlah_baris'] ?? 0) === 1
    && (int) ($lpj['tanpa_pagu'] ?? -1) === 0
    && (string) $barisLpj['kode_rekening'] === '5.1.2.01'
    && (string) $barisLpj['uraian'] === 'Belanja Barang dan Jasa'
    && (float) $barisLpj['anggaran'] === 2000000.0
    && (float) $barisLpj['bulan_ini'] === 75000.0
    && (float) $barisLpj['sd_lalu'] === 0.0
    && (float) $barisLpj['sisa'] === 1925000.0
    && ($lpj['bulan_nama'] ?? '') === 'Maret'
    && ($lpj['tgl_laporan'] ?? '') === '2026-03-31'));

$cek('LPJ: rekonsiliasi & saldo kas (penerimaan 200.000 - pengeluaran 75.000)', (($lpj['penerimaan']['sp2d']['bulan_ini'] ?? 0) == 200000
    && (float) ($lpj['pengeluaran']['spj']['bulan_ini'] ?? 0) === 75000.0
    && (float) ($lpj['saldo_kas']['bulan_ini'] ?? 0) === 125000.0
    && (float) ($lpj['saldo_kas']['sd_ini'] ?? 0) === 125000.0
    && (float) ($lpj['jumlah_rincian']['bulan_ini'] ?? 0) === 75000.0));

$lpjApril = json_decode(mintaAklap($B . $baseAk . 'lpj_ppkd&tahun=2026&bulan=4')['body'], true);
$cek('LPJ April: pengeluaran Maret masuk sd_lalu', ((float) ($lpjApril['pengeluaran']['spj']['sd_lalu'] ?? -1) === 75000.0
    && (float) ($lpjApril['pengeluaran']['spj']['bulan_ini'] ?? -1) === 0.0
    && (float) ($lpjApril['penerimaan']['sp2d']['bulan_ini'] ?? -1) === 300000.0));


// ---------------------------------------------------------------------------
// Buku Jurnal Khusus (blanko Modul-3E-LK) lewat PROKSI.
// ---------------------------------------------------------------------------
echo "\n=== Jurnal khusus (lewat proksi) ===\n\n";

$bandingAk('template jurnal khusus (6 blanko)', 'jurnal_khusus_template', []);
$bandingAk('jurnal khusus Belanja Pemda Maret (belum tersimpan)', 'jurnal_khusus', ['jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '3']);

$jkKosong = json_decode(mintaAklap($B . $baseAk . 'jurnal_khusus&jenis=belanja_pemda&tahun=2026&bulan=3')['body'], true);
$cek('jurnal khusus kosong: judul/kolom/tgl_ttd sesuai blanko', (($jkKosong['judul'] ?? '') === 'Buku Jurnal Khusus Belanja Pemda'
    && ($jkKosong['bulan_nama'] ?? '') === 'Maret'
    && ($jkKosong['tgl_ttd'] ?? '') === '2026-03-31'
    && ($jkKosong['tersimpan'] ?? true) === false
    && (int) $jkKosong['jumlah_baris'] === 0
    && (float) $jkKosong['total_debet'] === 0.0
    && count((array) $jkKosong['total_kolom']['debet']) === 8));

// Simpan 2 baris berisi (+1 baris kosong yang harus dibuang); baris ke-2 timpang.
$jkSimpan = $mutasi('jurnal_khusus_simpan', [
    'jenis'      => 'belanja_pemda',
    'tahun'      => '2026',
    'bulan'      => '3',
    'skp'        => 'SKPKD Uji',
    'kode_extra' => json_encode(['debet' => ['5' => '5.1.9.99.01', '6' => '  ', '99' => 'X'], 'kredit' => ['2' => '3.1.2.01.02']]),
    'baris'      => json_encode([
        ['tanggal' => '2026-03-05', 'nomor_dok' => 'BKU-1', 'uraian' => 'Belanja ATK', 'debet' => ['1.000.000'], 'kredit' => ['1.000.000']],
        ['tanggal' => '2026-03-06', 'nomor_dok' => 'BKU-2', 'uraian' => 'Belanja timpang', 'debet' => ['500.000'], 'kredit' => ['250.000']],
        ['tanggal' => '', 'nomor_dok' => '', 'uraian' => '', 'debet' => ['0'], 'kredit' => ['0']],
    ]),
]);

$jkId = (int) ($jkSimpan['id'] ?? 0);
$cek('jurnal_khusus_simpan lewat proksi (2 baris, 1 timpang)', (($jkSimpan['message'] ?? '') === 'Buku Jurnal Khusus Belanja Pemda Maret 2026 tersimpan (2 baris). Catatan: baris ke-2 belum seimbang (Jumlah Debet <> Jumlah Kredit).'
    && $jkId > 0
    && (int) $jkSimpan['baris'] === 2
    && (int) $jkSimpan['baris_tidak_seimbang'] === 1));

$cek('baris jurnal khusus tersimpan di DB (nilai & kode_extra)', ((int) ($q('SELECT COUNT(*) AS n FROM jurnal_khusus_baris WHERE jurnal_id = ?', [$jkId])['n'] ?? 0) === 2
    && (string) ($q('SELECT debet_1 AS d FROM jurnal_khusus_baris WHERE jurnal_id = ? ORDER BY urut LIMIT 1', [$jkId])['d'] ?? '') === '1000000.00'
    && (string) ($q('SELECT skp_label AS s FROM jurnal_khusus WHERE id = ?', [$jkId])['s'] ?? '') === 'SKPKD Uji'
    && str_contains((string) ($q('SELECT kode_extra AS k FROM jurnal_khusus WHERE id = ?', [$jkId])['k'] ?? ''), '5.1.9.99.01')));

// Data tulisan Laravel dibaca identik oleh legacy (20 kolom uang + kode_extra).
$bandingAk('jurnal khusus setelah simpan (dibaca legacy)', 'jurnal_khusus', ['jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '3']);
$bandingAk('daftar jurnal khusus setelah simpan', 'jurnal_khusus_daftar', ['jenis' => 'belanja_pemda']);

$jkIsi = json_decode(mintaAklap($B . $baseAk . 'jurnal_khusus&jenis=belanja_pemda&tahun=2026&bulan=3')['body'], true);
$cek('jurnal khusus terisi: total kolom & kode_extra terbaca', ((int) $jkIsi['jumlah_baris'] === 2
    && (int) $jkIsi['baris_tidak_seimbang'] === 1
    && (float) $jkIsi['total_debet'] === 1500000.0
    && (float) $jkIsi['total_kredit'] === 1250000.0
    && (float) $jkIsi['selisih'] === 250000.0
    && ($jkIsi['kode_extra']['debet']['5'] ?? '') === '5.1.9.99.01'
    && ($jkIsi['skp'] ?? '') === 'SKPKD Uji'));


// Simpan ulang tanpa mengirim `skp` -> SKPKD lama dipertahankan, baris diganti.
$jkUlang = $mutasi('jurnal_khusus_simpan', [
    'jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '3',
    'baris' => json_encode([['tanggal' => '2026-03-07', 'nomor_dok' => 'BKU-9', 'uraian' => 'Satu baris', 'debet' => ['250.000'], 'kredit' => ['250.000']]]),
]);

$cek('jurnal khusus disimpan ulang (1 baris, SKPKD dipertahankan)', ((int) $jkUlang['baris'] === 1
    && (int) $jkUlang['baris_tidak_seimbang'] === 0
    && (string) ($q('SELECT skp_label AS s FROM jurnal_khusus WHERE id = ?', [$jkId])['s'] ?? '') === 'SKPKD Uji'
    && (int) ($q('SELECT COUNT(*) AS n FROM jurnal_khusus_baris WHERE jurnal_id = ?', [$jkId])['n'] ?? 0) === 1));

$jkHapus = $mutasi('jurnal_khusus_hapus', ['jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '3']);
$cek('jurnal_khusus_hapus lewat proksi', (($jkHapus['message'] ?? '') === 'Buku Jurnal Khusus Belanja Pemda Maret 2026 dihapus.'));
$cek('dokumen & baris jurnal khusus terhapus', ((int) ($q('SELECT COUNT(*) AS n FROM jurnal_khusus WHERE id = ?', [$jkId])['n'] ?? 1) === 0
    && (int) ($q('SELECT COUNT(*) AS n FROM jurnal_khusus_baris WHERE jurnal_id = ?', [$jkId])['n'] ?? 1) === 0));
$cek('jurnal_khusus_hapus periode hilang', (($mutasi('jurnal_khusus_hapus', ['jenis' => 'belanja_pemda', 'tahun' => '2026', 'bulan' => '3'])['message'] ?? '') === 'Jurnal periode tersebut belum ada.'));

// ---------------------------------------------------------------------------
// Kirim dokumen ke antrean tanda tangan (doc.simtkd.com) lewat PROKSI.
// ---------------------------------------------------------------------------
echo "\n=== Kirim dokumen TTD (lewat proksi) ===\n\n";

$konten = '<h3>BKU Uji</h3><p>' . str_repeat('Isi dokumen uji E2E jurnal khusus. ', 10) . '</p>';

$kirim = $mutasi('dokumen_kirim', [
    'jenis'       => 'BKU',
    'nomor'       => 'BKU-E2E-1',
    'judul'       => 'BKU Uji E2E',
    'tanggal'     => '2026-03-31',
    'konten_html' => $konten,
    'nip'         => '198001012006041001',
]);

$kirimId = (int) ($kirim['id'] ?? 0);
$cek('dokumen_kirim lewat proksi (kode verifikasi TTD-)', (($kirim['message'] ?? '') === 'Dokumen terkirim ke antrean tanda tangan (doc.simtkd.com).'
    && $kirimId > 0
    && preg_match('/^TTD-[0-9A-F]{8}$/', (string) ($kirim['kode_verifikasi'] ?? '')) === 1));
$cek('dokumen & penandatangan tersimpan di DB', ((int) ($q('SELECT COUNT(*) AS n FROM dokumen WHERE id = ? AND skpd = ? AND status = ?', [$kirimId, $SKPD, 'menunggu_ttd'])['n'] ?? 0) === 1
    && (string) ($q('SELECT hash_original AS h FROM dokumen WHERE id = ?', [$kirimId])['h'] ?? '') === hash('sha256', $konten)
    && (int) ($q('SELECT COUNT(*) AS n FROM dokumen_ttd WHERE dokumen_id = ?', [$kirimId])['n'] ?? 0) === 1
    && (string) ($q('SELECT jabatan AS j FROM dokumen_ttd WHERE dokumen_id = ?', [$kirimId])['j'] ?? '') === 'Bendahara'));

// Kirim ulang jenis+judul sama -> dokumen yang menunggu TTD diperbarui.
$konten2    = '<h3>BKU Uji (revisi)</h3><p>' . str_repeat('Isi dokumen uji E2E yang sudah diperbarui. ', 10) . '</p>';
$kirimUlang = $mutasi('dokumen_kirim', [
    'jenis' => 'BKU', 'nomor' => 'BKU-E2E-1R', 'judul' => 'BKU Uji E2E', 'konten_html' => $konten2,
]);

$cek('kirim ulang memperbarui dokumen yang sama', (($kirimUlang['message'] ?? '') === 'Dokumen diperbarui di antrean tanda tangan.'
    && (int) ($kirimUlang['id'] ?? 0) === $kirimId
    && (string) ($q('SELECT hash_original AS h FROM dokumen WHERE id = ?', [$kirimId])['h'] ?? '') === hash('sha256', $konten2)
    && (int) ($q('SELECT COUNT(*) AS n FROM dokumen WHERE skpd = ?', [$SKPD])['n'] ?? 0) === 1));

// Penandatangan eksplisit (judul beda -> dokumen baru), urutan di-clamp 1..20.
$kirim2     = $mutasi('dokumen_kirim', [
    'jenis'         => 'BKU',
    'judul'         => 'BKU Uji E2E Kedua',
    'konten_html'   => $konten,
    'nip'           => '198001012006041001',
    'penandatangan' => json_encode([
        ['user' => 'self', 'urutan' => 0, 'jabatan' => 'Bendahara Pengeluaran', 'nama' => 'Bendahara AKLAP'],
        ['user' => 'lain', 'urutan' => 99, 'jabatan' => 'Kepala SKPD', 'nama' => 'Kepala Dinas'],
    ]),
]);

$kirim2Id = (int) ($kirim2['id'] ?? 0);
$cek('kirim dengan penandatangan eksplisit (2 baris, urutan diklem)', ($kirim2Id > $kirimId
    && (int) ($q('SELECT COUNT(*) AS n FROM dokumen_ttd WHERE dokumen_id = ?', [$kirim2Id])['n'] ?? 0) === 2
    && (string) ($q('SELECT urutan AS u FROM dokumen_ttd WHERE dokumen_id = ? ORDER BY id LIMIT 1', [$kirim2Id])['u'] ?? '') === '1'
    && (string) ($q('SELECT urutan AS u FROM dokumen_ttd WHERE dokumen_id = ? ORDER BY id DESC LIMIT 1', [$kirim2Id])['u'] ?? '') === '20'
    && (int) ($q('SELECT COUNT(*) AS n FROM dokumen_ttd WHERE dokumen_id = ? AND user_id IS NULL', [$kirim2Id])['n'] ?? 0) === 1));


echo "\n--------------------------------------------------------------\n";
printf("TOTAL %d   LULUS %d   GAGAL %d\n", $lulus + $gagal, $lulus, $gagal);

bersihkanAklap($pdo, $SKPD, $USER);


exit($gagal === 0 ? 0 : 1);