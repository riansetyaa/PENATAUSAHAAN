{{--
=====================================================================
 Landing AKLAP (Dashboard) — Fase 4.

 Berkas ini SALINAN PERSIS peta.simtkd.com/index.html (markup + CSS +
 skrip render), hanya tiga hal yang diubah:
   1. logo memakai asset() Laravel;
   2. js/config.js + menu.js + sidebar.js dimuat dari /js/aklap/
      (menu.js & sidebar.js salinan persis; config.js = shim API);
   3. tiga panggilan data (profil, rekap, jurnal_list) menunjuk ke
      endpoint Laravel yang sudah lolos uji paritas.

 Dengan begitu tampilan & perilaku halaman tetap sama seperti web
 sebelumnya, tetapi datanya dilayani Laravel.
=====================================================================
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="referrer" content="no-referrer">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - AKLAP | peta.simtkd.com</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================================
           AKLAP - Dashboard
           Halaman utama modul akuntansi (peta.simtkd.com).
           Data diambil dari API (dilindungi token).
           ============================================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --navy: #16305a;
            --navy-dark: #0e2140;
            --navy-mid: #1d3a6e;
            --blue: #1f6feb;
            --green: #16a34a;
            --green-dark: #15803d;
            --bg: #eef1f6;
            --card: #ffffff;
            --border: #d8dee9;
            --text: #1f2937;
            --muted: #6b7280;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* ---------- Topbar ---------- */
        .topbar {
            background: var(--navy);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            height: 58px;
            box-shadow: 0 2px 6px rgba(0,0,0,.2);
            position: relative;
            z-index: 20;
        }
        .topbar-left { display: flex; align-items: center; gap: 12px; }
        .topbar-left .logo-badge {
            width: 38px; height: 38px; border-radius: 50%;
            background: #fff; color: var(--navy);
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
        }
        .topbar-left .logo-badge img {
            width: 100%; height: 100%; object-fit: cover; border-radius: 50%;
        }
        .topbar-left .brand { line-height: 1.15; }
        .topbar-left .brand b { font-size: 15px; letter-spacing: .3px; display: block; }
        .topbar-left .brand span { font-size: 11.5px; opacity: .85; display: block; }

        .topbar-right { display: flex; align-items: center; gap: 14px; }
        .topbar-right .region { text-align: right; line-height: 1.15; }
        .topbar-right .region b { font-size: 12.5px; display: block; }
        .topbar-right .region span { font-size: 11px; opacity: .85; display: block; }
        .topbar-user { display: flex; align-items: center; gap: 8px; }
        .topbar-user .avatar {
            width: 32px; height: 32px; border-radius: 50%;
            background: #fff; color: var(--navy);
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; font-weight: 700;
        }
        .region-select {
            height: 28px; border: 1px solid rgba(255,255,255,.4); border-radius: 6px;
            background: rgba(255,255,255,.12); color: #fff; font-size: 12px; padding: 0 8px;
            max-width: 220px; cursor: pointer; margin-bottom: 2px;
        }
        .region-select option { color: #1f2937; }

        /* ---------- Body layout ---------- */
        .body-wrap { display: flex; flex: 1; min-height: 0; }

        /* ---------- Sidebar ---------- */
        .sidebar {
            width: 236px;
            background: var(--navy-dark);
            color: #cbd5e1;
            flex-shrink: 0;
            overflow-y: auto;
            padding: 10px 0 20px;
            transition: margin-left .25s ease;
        }
        .sidebar.collapsed { margin-left: -236px; }
        .sidebar-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px; height: 34px;
            border: 1px solid rgba(255,255,255,.25);
            background: rgba(255,255,255,.08);
            color: #fff;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            flex-shrink: 0;
            transition: background .2s ease;
        }
        .sidebar-toggle:hover { background: rgba(255,255,255,.2); }
        .side-title {
            font-size: 10.5px; letter-spacing: 1.4px; text-transform: uppercase;
            color: #7d8db1; padding: 14px 18px 6px;
        }
        .side-link {
            display: flex; align-items: center; gap: 11px;
            padding: 10px 18px; color: #cbd5e1; text-decoration: none;
            font-size: 13.5px; border-left: 3px solid transparent;
        }
        .side-link i { width: 18px; text-align: center; font-size: 14px; }
        .side-link:hover { background: rgba(255,255,255,.06); color: #fff; }
        .side-link.active {
            background: var(--navy-mid); color: #fff; border-left-color: #4c9aff;
        }
        .side-group .side-link { cursor: pointer; }
        .side-arrow { margin-left: auto; font-size: 11px; transition: transform .2s; color: #7d8db1; }
        .side-group.open .side-arrow { transform: rotate(90deg); }
        .side-sub { display: none; padding-left: 10px; }
        .side-sub.open { display: block; }
        .side-sub .side-link { padding: 8px 16px; font-size: 13px; color: #9fb0d3; }
        .side-sub .side-link i { font-size: 12px; }
        .side-sub .side-link:hover { color: #fff; }
        .side-sub .side-link.active {
            color: #fff; background: var(--navy-mid); border-left-color: #4c9aff;
        }

        /* ---------- Main ---------- */
        .main { flex: 1; min-width: 0; display: flex; flex-direction: column; }

        .breadcrumb-bar {
            background: #fff; border-bottom: 1px solid var(--border);
            padding: 10px 22px; display: flex; align-items: center; justify-content: space-between;
            gap: 10px; flex-wrap: wrap;
        }
        .breadcrumb-bar .crumb { font-size: 13px; color: var(--muted); }
        .breadcrumb-bar .crumb i { font-size: 11px; margin: 0 7px; color: #9aa4b2; }
        .crumb a { color: var(--blue); text-decoration: none; }
        .crumb .current { color: var(--text); font-weight: 600; }
        .btn-back {
            background: var(--navy); color: #fff; border: none;
            padding: 7px 14px; border-radius: 6px; cursor: pointer;
            font-size: 12.5px; display: inline-flex; align-items: center; gap: 7px;
            text-decoration: none;
        }
        .btn-back:hover { background: var(--navy-mid); }

        .content { padding: 20px 22px; flex: 1; }

        .page-head { margin-bottom: 16px; }
        .page-head h1 { font-size: 19px; color: var(--navy); }
        .page-head p { font-size: 12.5px; color: var(--muted); margin-top: 2px; }

        .card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: 10px; box-shadow: 0 1px 3px rgba(16,24,40,.06);
            margin-bottom: 18px;
        }
        .card-head {
            padding: 12px 18px; border-bottom: 1px solid var(--border);
            font-weight: 700; font-size: 13.5px; color: var(--navy);
            display: flex; align-items: center; gap: 8px;
        }
        .card-body { padding: 16px 18px; }

        /* ---------- Statistik ---------- */
        .stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 18px; }
        .stat-card {
            background: #fff; border: 1px solid var(--border); border-radius: 10px;
            padding: 18px; display: flex; align-items: center; gap: 14px;
            box-shadow: 0 1px 3px rgba(16,24,40,.06);
        }
        .stat-card .ic {
            width: 48px; height: 48px; border-radius: 10px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 20px; color: #fff;
        }
        .ic.navy { background: linear-gradient(135deg, var(--navy), var(--navy-mid)); }
        .ic.blue { background: linear-gradient(135deg, #1f6feb, #4c9aff); }
        .ic.green { background: linear-gradient(135deg, #12835c, #16a34a); }
        .ic.amber { background: linear-gradient(135deg, #d97706, #f59e0b); }
        .stat-card .info b { display: block; font-size: 22px; color: var(--navy); font-variant-numeric: tabular-nums; }
        .stat-card .info span { font-size: 12px; color: var(--muted); font-weight: 600; }

        /* ---------- Akses cepat ---------- */
        .quick-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 18px; }
        .quick-card {
            background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 20px;
            text-decoration: none; color: inherit; display: flex; align-items: center; gap: 16px;
            box-shadow: 0 1px 3px rgba(16,24,40,.06); transition: all .2s ease;
        }
        .quick-card:hover { transform: translateY(-3px); box-shadow: 0 10px 22px rgba(0,0,0,.1); border-color: var(--blue); }
        .quick-card .ic {
            width: 52px; height: 52px; border-radius: 12px; flex-shrink: 0;
            background: linear-gradient(135deg, var(--navy), var(--navy-mid)); color: #fff;
            display: flex; align-items: center; justify-content: center; font-size: 22px;
        }
        .quick-card.green .ic { background: linear-gradient(135deg, #12835c, #16a34a); }
        .quick-card .info h3 { font-size: 15px; color: var(--navy); }
        .quick-card .info p { font-size: 12px; color: var(--muted); margin-top: 3px; }
        .quick-card .arrow { margin-left: auto; color: var(--blue); font-size: 15px; }

        /* ---------- Tabel jurnal terbaru ---------- */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        thead th {
            background: #f1f5f9; color: #334155; text-align: left;
            padding: 10px 12px; border-bottom: 1px solid var(--border);
            font-size: 11.5px; text-transform: uppercase; letter-spacing: .3px;
            white-space: nowrap;
        }
        tbody td { padding: 10px 12px; border-bottom: 1px solid #edf0f5; vertical-align: middle; }
        tbody tr:hover { background: #f8fafc; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .empty { text-align: center; color: #9aa4b2; padding: 28px; font-size: 13px; }

        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
        .badge-approved { background: #dcfce7; color: #15803d; }
        .badge-pending { background: #fef3c7; color: #b45309; }
        .badge-rejected { background: #fee2e2; color: #b91c1c; }
        .badge-navy { background: #e0e7ff; color: #3730a3; }

        /* ---------- Footer / Toast ---------- */
        .footer {
            text-align: center; padding: 14px; font-size: 12px;
            color: var(--muted); border-top: 1px solid var(--border); background: #fff;
        }
        #toast {
            position: fixed; bottom: 24px; right: 24px; z-index: 99;
            background: var(--navy-dark); color: #fff; padding: 12px 18px;
            border-radius: 8px; font-size: 13px; box-shadow: 0 6px 18px rgba(0,0,0,.25);
            opacity: 0; transform: translateY(12px); transition: all .25s ease; pointer-events: none;
        }
        #toast.show { opacity: 1; transform: translateY(0); }

        @media (max-width: 1100px) {
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .stat-grid, .quick-grid { grid-template-columns: 1fr; }
            .sidebar { position: fixed; left: 0; top: 58px; bottom: 0; z-index: 40; }
        }
    </style>
</head>
<body>

    <!-- ================= TOPBAR ================= -->
    <header class="topbar">
        <div class="topbar-left">
            <div class="logo-badge"><img src="{{ asset('img/logo-polban.png') }}" alt="Logo POLBAN"></div>
            <div class="brand">
                <b>AKLAP · SIM-TKD</b>
                <span>Aplikasi Laporan Akuntansi Pemerintahan</span>
            </div>
        </div>
        <div class="topbar-right">
            <div class="region">
                <b id="regionProvinsi">-</b>
                <span id="regionKota">-</span>
            </div>
            <div class="topbar-user">
                <div class="avatar" id="regionAvatar">?</div>
                <span id="regionNama" style="font-size:13px;">-</span>
            </div>
        </div>
    </header>

    <div class="body-wrap">

        <!-- ================= SIDEBAR ================= -->
        <aside class="sidebar" id="sidebar"></aside>

        <!-- ================= MAIN ================= -->
        <main class="main">
            <div class="breadcrumb-bar">
                <div class="crumb">
                    <i class="fas fa-house"></i> <a href="index.html">Home</a>
                    <i class="fas fa-chevron-right"></i> <span class="current">Dashboard</span>
                </div>
                <a href="https://simtkd.com/dashboard.html" class="btn-back" data-back-main><i class="fas fa-arrow-left"></i> Kembali ke Penatausahaan</a>
            </div>

            <div class="content">
                <!-- Judul -->
                <div class="page-head">
                    <h1>Dashboard</h1>
                    <p>Ringkasan data penatausahaan dari database (data asli, dilindungi token API).</p>
                </div>

                <!-- Statistik -->
                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="ic navy"><i class="fas fa-file-invoice"></i></div>
                        <div class="info"><b id="stStbp">-</b><span>Total STBP</span></div>
                    </div>
                    <div class="stat-card">
                        <div class="ic blue"><i class="fas fa-file-invoice-dollar"></i></div>
                        <div class="info"><b id="stSts">-</b><span>Total STS</span></div>
                    </div>
                    <div class="stat-card">
                        <div class="ic green"><i class="fas fa-file-signature"></i></div>
                        <div class="info"><b id="stPermohonan">-</b><span>Permohonan Rekening</span></div>
                    </div>
                    <div class="stat-card">
                        <div class="ic amber"><i class="fas fa-tasks"></i></div>
                        <div class="info"><b id="stKegiatan">-</b><span>Jumlah Kegiatan</span></div>
                    </div>
                    <div class="stat-card">
                        <div class="ic green"><i class="fas fa-arrow-down"></i></div>
                        <div class="info"><b id="stPenerimaan">Rp -</b><span>Total Penerimaan</span></div>
                    </div>
                    <div class="stat-card">
                        <div class="ic navy"><i class="fas fa-coins"></i></div>
                        <div class="info"><b id="stPagu">Rp -</b><span>Pagu Anggaran</span></div>
                    </div>
                </div>

                <!-- Akses cepat -->
                <div class="quick-grid">
                    <a href="jurnal-umum.html" class="quick-card">
                        <div class="ic"><i class="fas fa-book"></i></div>
                        <div class="info">
                            <h3>Jurnal Umum (Manual)</h3>
                            <p>Input jurnal manual dua sisi Debet/Kredit (minimal 2 baris)</p>
                        </div>
                        <i class="fas fa-arrow-right arrow"></i>
                    </a>
                    <a href="lra.html" class="quick-card green">
                        <div class="ic"><i class="fas fa-chart-column"></i></div>
                        <div class="info">
                            <h3>Laporan Realisasi Anggaran (LRA)</h3>
                            <p>Laporan realisasi anggaran pendapatan &amp; belanja daerah</p>
                        </div>
                        <i class="fas fa-arrow-right arrow"></i>
                    </a>
                </div>

                <!-- Jurnal terbaru -->
                <div class="card">
                    <div class="card-head"><i class="fas fa-clock-rotate-left"></i> Jurnal Terbaru</div>
                    <div class="card-body" style="padding:0;">
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nomor Jurnal</th>
                                        <th>Uraian</th>
                                        <th>Tanggal</th>
                                        <th class="num">Jumlah</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="recentBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="footer">
                &copy; 2026 AKLAP - Modul Edukasi SIM-TKD | Politeknik Negeri Bandung (Jurusan Akuntansi)
            </div>
        </main>
    </div>

    <div id="toast"></div>

    {{-- Aset frontend AKLAP versi Laravel (Fase 4).
         menu.js & sidebar.js adalah SALINAN PERSIS dari
         peta.simtkd.com/js/ agar tampilan sidebar tidak berubah;
         config.js diganti shim yang mengarah ke API Laravel. --}}
    <script>window.SIMTKD_LEGACY_BASE = @json($legacyBase);</script>
    <script src="{{ asset('js/aklap/config.js') }}"></script>
    <script src="{{ asset('js/aklap/menu.js') }}"></script>
    <script src="{{ asset('js/aklap/sidebar.js') }}"></script>
    <script>
        // Profil akun dari database (pendaftaran) - tanpa dropdown
        (function () {
            function set(txt, id) { var el = document.getElementById(id); if (el) el.textContent = (txt && txt !== '') ? txt : '-'; }
            var url = SIMTKD_API.endpoint + '/profil';
            fetch(url)
                .then(function (res) { return res.json(); })
                .then(function (d) {
                    if (!(d && d.success) || !d.user) return;
                    var u = d.user;
                    set(u.provinsi, 'regionProvinsi');
                    set(u.kota ? ('Kota/Kab: ' + u.kota) : '', 'regionKota');
                    var nama = u.nama || u.username || '';
                    set(nama, 'regionNama');
                    var init = nama.trim().charAt(0).toUpperCase() || '?';
                    var av = document.getElementById('regionAvatar');
                    if (av) av.textContent = init;
                })
                .catch(function () {});
        })();
    </script>
    <script>
        (function () {
            'use strict';

            var BULAN = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

            function fmtRp(v) { return 'Rp ' + Number(v || 0).toLocaleString('id-ID'); }
            function fmtNum(v) { return Number(v || 0).toLocaleString('id-ID'); }
            function fmtTgl(s) {
                if (!s) return '-';
                var p = String(s).split('-');
                return p[2] + ' ' + BULAN[parseInt(p[1], 10) - 1] + ' ' + p[0];
            }
            function fmtTglRange(a, b) { return fmtTgl(a) + ' s.d. ' + fmtTgl(b || a); }
            function esc(t) {
                var d = document.createElement('div');
                d.textContent = String(t || '');
                return d.innerHTML;
            }
            function toast(msg) {
                var t = document.getElementById('toast');
                t.textContent = msg;
                t.classList.add('show');
                clearTimeout(toast._t);
                toast._t = setTimeout(function () { t.classList.remove('show'); }, 2400);
            }

            // Endpoint Laravel (paritas sudah diuji di Fase 3).
            // Bentuk respons sama dengan legacy sehingga render tidak berubah.
            function api(action) {
                return SIMTKD_API.endpoint + '/' + (action === 'rekap' ? 'rekap' : action);
            }
            function apiAku(action) {
                return SIMTKD_API.endpoint + '/' + (action === 'jurnal_list' ? 'jurnal-umum' : action);
            }

            function renderRecent(journals) {
                var body = document.getElementById('recentBody');
                var list = (journals || []).slice(-5).reverse(); // jurnal_list ASC -> ambil 5 terbaru
                if (!list.length) {
                    body.innerHTML = '<tr><td colspan="5" class="empty">Belum ada jurnal. Buat melalui menu Jurnal Umum.</td></tr>';
                    return;
                }
                body.innerHTML = list.map(function (r) {
                    var st = r.status === 'sudah_approve' ? 'Sudah Approve' : (r.status === 'ditolak' ? 'Ditolak' : 'Draft');
                    var badgeCls = r.status === 'sudah_approve' ? 'badge-approved' : (r.status === 'ditolak' ? 'badge-rejected' : 'badge-pending');
                    var tx = r.sumber_tipe === 'sts' ? '<span class="badge badge-navy">STS</span>' : (r.sumber_tipe === 'sp2d' ? '<span class="badge badge-approved">SP2D</span>' : '<span class="badge badge-navy">Manual</span>');
                    return '<tr>' +
                        '<td>' + esc(r.nomor_jurnal) + '</td>' +
                        '<td>' + esc(r.uraian) + '</td>' +
                        '<td>' + fmtTgl(r.tanggal) + '</td>' +
                        '<td class="num">' + fmtRp(r.total_debet) + '</td>' +
                        '<td>' + tx + ' <span class="badge ' + badgeCls + '">' + st + '</span></td>' +
                    '</tr>';
                }).join('');
            }

            // Muat rekap + jurnal dari API
            Promise.all([
                fetch(api('rekap')).then(function (r) { return r.json(); }).catch(function () { return { success: false }; }),
                fetch(apiAku('jurnal_list')).then(function (r) { return r.json(); }).catch(function () { return { success: false }; })
            ]).then(function (results) {
                var rekap = results[0];
                var jurnal = results[1];

                if (!rekap.success) {
                    toast((rekap.message) || 'Gagal memuat statistik. Periksa token & koneksi.');
                } else {
                    var d = rekap.data || {};
                    document.getElementById('stStbp').textContent = fmtNum(d.stbp);
                    document.getElementById('stSts').textContent = fmtNum(d.sts);
                    document.getElementById('stPermohonan').textContent = fmtNum(d.permohonan);
                    document.getElementById('stKegiatan').textContent = fmtNum(d.kegiatan);
                    document.getElementById('stPenerimaan').textContent = fmtRp(d.total_penerimaan);
                    document.getElementById('stPagu').textContent = fmtRp(d.total_pagu);
                }

                if (jurnal.success) {
                    renderRecent(jurnal.data || []);
                } else {
                    toast((jurnal.message) || 'Gagal memuat jurnal.');
                }
            });
        })();
    </script>
</body>
</html>
