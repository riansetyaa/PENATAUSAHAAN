<?php
/**
 * SIM-TKD - API Monitoring Pengguna (Admin/Dosen)
 * ============================================
 * Menampilkan daftar akun beserta status login dan progres pengerjaan.
 * Wajib login DAN berperan Admin Dinas / Kepala Dinas / Dosen (atau
 * username "admin"). Endpoint ini sengaja TIDAK memakai requireInstansi()
 * karena admin/dosen perlu melihat seluruh akun lintas instansi.
 *
 * Progres dihitung dari data yang DIBUAT oleh masing-masing akun
 * (kolom user_id pada tabel modul), bukan dari data instansi. Dengan
 * begitu, beberapa akun yang memakai instansi sama tetap punya progres
 * masing-masing.
 *
 * Metode: GET. Respons JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    jsonResponse(false, 'Tidak terautentikasi.', [], 401);
}

$peran    = (string) ($_SESSION['peran'] ?? '');
$username = (string) ($_SESSION['username'] ?? '');
$boleh    = in_array($peran, ['Admin Dinas', 'Kepala Dinas', 'Dosen'], true)
            || $username === 'admin';

if (!$boleh) {
    jsonResponse(
        false,
        'Akses ditolak. Halaman Monitoring Pengguna hanya untuk admin/dosen.',
        [],
        403
    );
}

$pdo = db();
ensureUsersColumns($pdo);

// Kolom pelacakan login bisa saja belum ada bila ALTER TABLE ditolak
// (hak akses DB terbatas). Endpoint tetap berjalan dengan data login kosong.
$adaKolomLogin = true;
try {
    $pdo->query("SELECT last_login_at, login_count, last_ip, last_activity_at FROM users LIMIT 0");
} catch (Throwable $e) {
    $adaKolomLogin = false;
    error_log('[SIM-TKD monitoring] kolom login belum tersedia: ' . $e->getMessage());
}

try {
    if ($adaKolomLogin) {
        $ringkasan = $pdo->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'aktif'    THEN 1 ELSE 0 END) AS aktif,
                SUM(CASE WHEN status = 'pending'  THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'nonaktif' THEN 1 ELSE 0 END) AS nonaktif,
                SUM(CASE WHEN last_login_at IS NOT NULL THEN 1 ELSE 0 END) AS sudah_login,
                SUM(CASE WHEN last_login_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS login_7hari
            FROM users
        ")->fetch();
    } else {
        $ringkasan = $pdo->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'aktif'    THEN 1 ELSE 0 END) AS aktif,
                SUM(CASE WHEN status = 'pending'  THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'nonaktif' THEN 1 ELSE 0 END) AS nonaktif,
                0 AS sudah_login,
                0 AS login_7hari
            FROM users
        ")->fetch();
    }

    $kolomLogin = $adaKolomLogin
        ? 'last_login_at, login_count, last_ip, last_activity_at'
        : 'NULL AS last_login_at, 0 AS login_count, NULL AS last_ip, NULL AS last_activity_at';

    $penggunaMentah = $pdo->query("
        SELECT id, nama_lengkap, nip, username, email, instansi, peran, status,
               created_at, otp_verifikasi_at, updated_at, $kolomLogin
        FROM users
        ORDER BY nama_lengkap ASC
        LIMIT 1000
    ")->fetchAll();
} catch (Throwable $e) {
    error_log('[SIM-TKD monitoring] query utama gagal: ' . $e->getMessage());
    jsonResponse(false, 'Gagal membaca data pengguna dari basis data.', [], 500);
}

// ------------------------------------------------------------------
// Definisi langkah progres. Satu langkah dianggap selesai bila akun
// memiliki minimal satu baris pada tabel terkait (milik user_id-nya).
// Urutan mengikuti alur kerja di docs/flowchart-modul.md.
// ------------------------------------------------------------------
$definisi = [
    ['key' => 'permohonan',    'modul' => 'penerimaan', 'label' => 'Rekening / Permohonan', 'table' => 'permohonan',       'where' => '1=1'],
    ['key' => 'skp',           'modul' => 'penerimaan', 'label' => 'SKP Daerah',             'table' => 'skp_daerah',       'where' => "status <> 'dihapus'"],
    ['key' => 'stbp',          'modul' => 'penerimaan', 'label' => 'STBP',                   'table' => 'stbp',             'where' => "status <> 'dihapus'"],
    ['key' => 'sts',           'modul' => 'penerimaan', 'label' => 'STS',                    'table' => 'sts',              'where' => "status <> 'dihapus'"],

    ['key' => 'rekanan',       'modul' => 'belanja',    'label' => 'Rekanan',                'table' => 'rekanan',          'where' => '1=1'],
    ['key' => 'rekening_skpd', 'modul' => 'belanja',    'label' => 'Rekening Bank SKPD',     'table' => 'rekening_skpd',    'where' => '1=1'],
    ['key' => 'spd',           'modul' => 'belanja',    'label' => 'SPD',                    'table' => 'spd',              'where' => '1=1'],
    ['key' => 'spp',           'modul' => 'belanja',    'label' => 'SPP',                    'table' => 'spp',              'where' => "status <> 'ditolak'"],
    ['key' => 'spm',           'modul' => 'belanja',    'label' => 'SPM',                    'table' => 'spm',              'where' => "status <> 'ditolak'"],
    ['key' => 'sp2d',          'modul' => 'belanja',    'label' => 'SP2D',                   'table' => 'sp2d',             'where' => "status <> 'ditolak'"],
    ['key' => 'npd',           'modul' => 'belanja',    'label' => 'NPD',                    'table' => 'npd',              'where' => "status <> 'ditolak'"],

    ['key' => 'jurnal_umum',   'modul' => 'akuntansi',  'label' => 'Jurnal Umum',            'table' => 'jurnal_umum',      'where' => "status <> 'ditolak'"],
    ['key' => 'jurnal_khusus', 'modul' => 'akuntansi',  'label' => 'Jurnal Khusus',          'table' => 'jurnal_khusus',    'where' => '1=1'],
    ['key' => 'buku_kas',      'modul' => 'akuntansi',  'label' => 'Buku Kas',               'table' => 'buku_kas',         'where' => '1=1'],
    ['key' => 'rekonsiliasi',  'modul' => 'akuntansi',  'label' => 'Rekonsiliasi Bank',      'table' => 'rekonsiliasi_bank','where' => '1=1'],

    ['key' => 'dokumen',       'modul' => 'dokumen',    'label' => 'Dokumen dikirim ke TTE', 'table' => 'dokumen',          'where' => '1=1'],
    ['key' => 'dokumen_ttd',   'modul' => 'dokumen',    'label' => 'Dokumen ditandatangani', 'table' => 'dokumen',          'where' => "status = 'ditandatangani'"],
];

$totalLangkahModul = ['penerimaan' => 0, 'belanja' => 0, 'akuntansi' => 0, 'dokumen' => 0];
foreach ($definisi as $d) {
    $totalLangkahModul[$d['modul']]++;
}

// Satu query GROUP BY per tabel (bukan per pengguna) agar tetap ringan.
$hitung = [];
foreach ($definisi as $d) {
    $sql = "SELECT user_id, COUNT(*) AS c
            FROM `{$d['table']}`
            WHERE user_id IS NOT NULL AND user_id <> 0 AND ({$d['where']})
            GROUP BY user_id";
    try {
        foreach ($pdo->query($sql)->fetchAll() as $r) {
            $hitung[$d['key']][(int) $r['user_id']] = (int) $r['c'];
        }
    } catch (Throwable $e) {
        error_log('[SIM-TKD monitoring] hitung ' . $d['key'] . ': ' . $e->getMessage());
    }
}

$pengguna = [];
$totalProgresMahasiswa = 0;
$jumlahMahasiswa       = 0;

foreach ($penggunaMentah as $u) {
    $userId = (int) $u['id'];

    $rincian      = [];
    $selesaiModul = ['penerimaan' => 0, 'belanja' => 0, 'akuntansi' => 0, 'dokumen' => 0];

    foreach ($definisi as $d) {
        $jumlah  = (int) ($hitung[$d['key']][$userId] ?? 0);
        $selesai = $jumlah > 0;
        if ($selesai) {
            $selesaiModul[$d['modul']]++;
        }
        $rincian[] = [
            'key'    => $d['key'],
            'modul'  => $d['modul'],
            'label'  => $d['label'],
            'count'  => $jumlah,
            'selesai'=> $selesai,
        ];
    }

    $modul        = [];
    $jumlahPersen = 0;
    foreach ($totalLangkahModul as $kode => $total) {
        $selesai = $selesaiModul[$kode];
        $persen  = $total > 0 ? (int) round(($selesai / $total) * 100) : 0;
        $modul[$kode] = [
            'selesai' => $selesai,
            'total'   => $total,
            'persen'  => $persen,
        ];
        $jumlahPersen += $persen;
    }

    // Bobot tiap modul sama (25%) supaya modul Belanja yang punya lebih
    // banyak langkah tidak mendominasi angka progres keseluruhan.
    $persenTotal = (int) round($jumlahPersen / max(1, count($totalLangkahModul)));
    $langkahSelesai = array_sum($selesaiModul);

    if ($persenTotal >= 100) {
        $labelProgres = 'Selesai';
    } elseif ($persenTotal >= 70) {
        $labelProgres = 'Hampir selesai';
    } elseif ($persenTotal >= 30) {
        $labelProgres = 'Berjalan';
    } elseif ($persenTotal > 0) {
        $labelProgres = 'Baru mulai';
    } else {
        $labelProgres = 'Belum mulai';
    }

    $lastLogin = $u['last_login_at'] ?? null;
    $aktivitas = $u['last_activity_at'] ?? null;

    $baris = [
        'id'                 => $userId,
        'nama'               => (string) $u['nama_lengkap'],
        'nip'                => (string) ($u['nip'] ?? ''),
        'username'           => (string) $u['username'],
        'email'              => (string) $u['email'],
        'instansi'           => (string) ($u['instansi'] ?? ''),
        'peran'              => (string) ($u['peran'] ?? ''),
        'status'             => (string) ($u['status'] ?? ''),
        'dibuat'             => (string) ($u['created_at'] ?? ''),
        'otp_verifikasi_at'  => $u['otp_verifikasi_at'] ?? null,
        'last_login_at'      => $lastLogin,
        'login_count'        => (int) ($u['login_count'] ?? 0),
        'last_ip'            => (string) ($u['last_ip'] ?? ''),
        'aktivitas_terakhir' => $aktivitas ?: ($u['updated_at'] ?? null),
        'aktivitas_perkiraan'=> !$aktivitas,
        'progres'            => [
            'persen'          => $persenTotal,
            'label'           => $labelProgres,
            'langkah_selesai' => $langkahSelesai,
            'langkah_total'   => count($definisi),
            'modul'           => $modul,
            'rincian'         => $rincian,
        ],
    ];

    $pengguna[] = $baris;

    // Rata-rata hanya dari akun mahasiswa (bukan admin/dosen).
    if ($baris['username'] !== 'admin'
        && !in_array($baris['peran'], ['Admin Dinas', 'Kepala Dinas', 'Dosen'], true)) {
        $totalProgresMahasiswa += $persenTotal;
        $jumlahMahasiswa++;
    }
}

$ringkasanOut = [
    'total'             => (int) ($ringkasan['total'] ?? 0),
    'aktif'             => (int) ($ringkasan['aktif'] ?? 0),
    'pending'           => (int) ($ringkasan['pending'] ?? 0),
    'nonaktif'          => (int) ($ringkasan['nonaktif'] ?? 0),
    'sudah_login'       => (int) ($ringkasan['sudah_login'] ?? 0),
    'login_7hari'       => (int) ($ringkasan['login_7hari'] ?? 0),
    'belum_login'       => 0,
    'rata_rata_progres' => $jumlahMahasiswa > 0
        ? (int) round($totalProgresMahasiswa / $jumlahMahasiswa)
        : 0,
];
$ringkasanOut['belum_login'] = max(0, $ringkasanOut['total'] - $ringkasanOut['sudah_login']);

jsonResponse(true, 'OK', [
    'ringkasan' => $ringkasanOut,
    'pengguna'  => $pengguna,
    'meta'      => [
        'dihasilkan_pada'  => date('Y-m-d H:i:s'),
        'ada_kolom_login'  => $adaKolomLogin,
        'batas_baris'      => 1000,
        'jumlah_langkah'   => count($definisi),
        'peran_tersedia'   => daftarPeran($pdo),
        'bisa_ubah_peran'  => ($peran === 'Admin Dinas' || $username === 'admin'),
    ],
]);
