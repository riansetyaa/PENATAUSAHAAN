/**
 * SIM-TKD - Sidebar Terpusat (aplikasi utama)
 * ============================================
 * Definisi menu sidebar aplikasi utama dipusatkan di file ini agar
 * setiap perubahan menu cukup dilakukan DI SINI dan langsung berlaku
 * merata di semua halaman (Dashboard, Penerimaan, Belanja, Pengaturan).
 *
 * Cara pakai di halaman:
 *   1. Sediakan container (boleh kosong):
 *        <aside class="sidebar" id="sidebar"></aside>
 *   2. Muat script SEBELUM js/dashboard.js:
 *        <script src="js/sidebar-menu.js"></script>    (root)
 *        <script src="../js/sidebar-menu.js"></script> (dalam subfolder)
 *        <script src="../js/dashboard.js"></script>
 *
 * Fitur:
 *   - Path otomatis menyesuaikan kedalaman folder halaman (root vs ../).
 *   - Item "aktif" dideteksi otomatis dari URL sekarang (path + query).
 *   - Grup/submenu induk otomatis terbuka saat halaman aktif di dalamnya.
 *   - ID elemen (sidebarToggle, sidebarUserName, sidebarUserRole, logoutLink)
 *     dipertahankan agar js/dashboard.js & script lain tetap berfungsi.
 */
(function () {
    'use strict';

    // ==========================================================
    // STRUKTUR MENU (path relatif terhadap ROOT aplikasi).
    // Edit di sini saja untuk update merata di semua halaman.
    // ==========================================================
    var MENU = [
        { label: 'MENU UTAMA' },
        { href: 'dashboard/dashboard.html', icon: 'fa-th-large', text: 'Dashboard', alias: ['dashboard.html'] },

        { group: 'penerimaan', icon: 'fa-arrow-down', text: 'Penerimaan', items: [
            { group: 'rekening', icon: 'fa-university', text: 'Rekening', items: [
                { href: 'penerimaan/permohonan.html', icon: 'fa-file-signature', text: 'Permohonan' }
            ] },
            { href: 'penerimaan/skp-daerah.html', icon: 'fa-receipt', text: 'SKP - Daerah' },
            { group: 'stbp', icon: 'fa-file-invoice', text: 'STBP', items: [
                { href: 'penerimaan/stbp-pembuatan.html', icon: 'fa-plus-circle', text: 'Pembuatan' },
                { href: 'penerimaan/stbp-pembuatan.html?tahap=sudah_diverifikasi', icon: 'fa-check', text: 'Verifikasi' },
                { href: 'penerimaan/stbp-pembuatan.html?tahap=sudah_diotorisasi', icon: 'fa-signature', text: 'Otorisasi' },
                { href: 'penerimaan/stbp-pembuatan.html?tahap=sudah_divalidasi', icon: 'fa-stamp', text: 'Validasi' }
            ] },
            { href: 'penerimaan/sts-pembuatan.html', icon: 'fa-file-invoice-dollar', text: 'STS' },
            { group: 'laporan', icon: 'fa-file-alt', text: 'Laporan', items: [
                { href: 'penerimaan/bku-penerimaan.html',           icon: 'fa-book',                 text: 'BKU Penerimaan' },
                { href: 'penerimaan/buku-pembantu-penerimaan.html', icon: 'fa-book-open',            text: 'Buku Pembantu Penerimaan' },
                { href: 'penerimaan/register-sts.html',             icon: 'fa-clipboard-list',       text: 'Register STS' },
                { href: 'penerimaan/lpj-penerimaan.html',           icon: 'fa-file-invoice-dollar',  text: 'LPJ' },
                { href: 'penerimaan/rekap-penerimaan.html',         icon: 'fa-table',                text: 'Rekap Harian & Bulanan' }
            ] }
        ] },

        { group: 'belanja', icon: 'fa-arrow-up', text: 'Belanja', items: [
            { href: 'belanja/index.html',          icon: 'fa-th-large',            text: 'Dashboard' },
            { href: 'belanja/rekanan.html',        icon: 'fa-handshake',           text: 'Daftar Rekanan' },
            { href: 'belanja/rekening.html',       icon: 'fa-university',          text: 'Rekening Bank SKPD' },
            { href: 'belanja/spd.html',            icon: 'fa-file-invoice',        text: 'SPD' },
            { href: 'belanja/spp.html',            icon: 'fa-file-signature',      text: 'SPP' },
            { href: 'belanja/spm.html',            icon: 'fa-file-invoice-dollar', text: 'SPM' },
            { href: 'belanja/sp2d.html',           icon: 'fa-money-check-alt',     text: 'SP2D' },
            { href: 'belanja/lpj.html',            icon: 'fa-clipboard-check',     text: 'LPJ' },
            { href: 'belanja/pengajuan-tu.html',   icon: 'fa-paper-plane',         text: 'Pengajuan TU' },
            { href: 'belanja/npd.html',            icon: 'fa-file-invoice-dollar', text: 'NPD' },
            { href: 'belanja/besaran-up.html',     icon: 'fa-coins',               text: 'Besaran UP' },
            { href: 'belanja/kebijakan-spd.html',  icon: 'fa-gavel',               text: 'Kebijakan SPD' },
            { href: 'belanja/bku-belanja.html',    icon: 'fa-book',                text: 'BKU Belanja' }
        ] },

        { href: 'api/aklap_go.php',   icon: 'fa-book',    text: 'Akuntansi' },
        { href: 'api/dokumen_go.php', icon: 'fa-pen-nib', text: 'Tanda Tangan Dokumen' },

        { group: 'pengaturan', icon: 'fa-cog', text: 'Pengaturan', items: [
            { href: 'pengaturan/profil.html',          icon: 'fa-user',       text: 'Profil' },
            { href: 'pengaturan/akun-penerimaan.html', icon: 'fa-coins',      text: 'Akun Penerimaan' },
            { href: 'pengaturan/anggaran-neraca.html', icon: 'fa-chart-line', text: 'Anggaran & Neraca' }
        ] },

        { label: 'AKUN' },
        { href: 'api/logout.php', icon: 'fa-sign-out-alt', text: 'Keluar', extraClass: 'menu-logout', id: 'logoutLink' }
    ];

    // ---------- util ----------
    function esc(t) {
        return String(t == null ? '' : t)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /** Posisi halaman: { base: '' | '../', rootPath: 'penerimaan/x.html', query: '?a=b' } */
    function posisi() {
        var path = String(location.pathname);       // cth: /penerimaan/sts-tambah.html
        var query = String(location.search || '');  // cth: ?tahap=...
        var base = '';
        var rootPath = path;

        // Root = berada tepat di bawah document root ("/file.html")
        if (!/^\/[^/]+\.[a-z]+$/i.test(path)) {
            base = '../';
        }
        rootPath = path.replace(/^\//, '');
        return { base: base, rootPath: rootPath, query: query };
    }

    function linkAktif(item, pos) {
        if (!item.href || item.href === '#') return false;
        if (item.href.indexOf('api/') === 0) return false; // link aksi (logout/go) tidak di-highlight

        var target = item.href.split('?');
        var targetPath = target[0];
        var targetQuery = target.length > 1 ? '?' + target[1] : '';

        var pathCocok = (targetPath === pos.rootPath) ||
                        (item.alias && item.alias.indexOf(pos.rootPath) !== -1);
        if (!pathCocok) return false;

        // Item dengan ?tahap=... : aktif hanya jika query sama persis.
        // Item tanpa query       : aktif jika halaman tanpa query.
        return targetQuery === pos.query;
    }

    // ---------- render ----------
    function renderMenu(items, pos, ctx) {
        var html = '';
        items.forEach(function (it) {
            if (it.label) {
                html += '<span class="menu-label">' + esc(it.label) + '</span>';
                return;
            }

            if (it.group) {
                var anakAktif = false;
                var subCtx = { onAktif: function () { anakAktif = true; } };
                var inner = renderMenu(it.items, pos, subCtx);
                if (anakAktif) ctx.onAktif();

                html += '<div class="menu-group' + (anakAktif ? ' open' : '') + '" data-menu="' + esc(it.group) + '">' +
                            '<a href="#" class="menu-item has-submenu' + (anakAktif ? ' active' : '') + '">' +
                                '<i class="fas ' + esc(it.icon) + '"></i>' +
                                '<span>' + esc(it.text) + '</span>' +
                                '<i class="fas fa-chevron-down submenu-arrow"></i>' +
                            '</a>' +
                            '<div class="submenu">' + inner + '</div>' +
                        '</div>';
                return;
            }

            var aktif = linkAktif(it, pos);
            if (aktif) ctx.onAktif();

            html += '<a href="' + esc(pos.base + it.href) + '" class="menu-item' +
                        (it.extraClass ? ' ' + esc(it.extraClass) : '') +
                        (aktif ? ' active' : '') + '"' +
                        (it.id ? ' id="' + esc(it.id) + '"' : '') + '>' +
                        '<i class="fas ' + esc(it.icon) + '"></i>' +
                        '<span>' + esc(it.text) + '</span>' +
                    '</a>';
        });
        return html;
    }

    function render() {
        var sidebar = document.getElementById('sidebar');
        if (!sidebar) return;

        var pos = posisi();

        var header =
            '<div class="sidebar-header">' +
                '<div class="sidebar-logo">' +
                    '<img src="' + esc(pos.base + 'img/Logo Polban.png') + '" alt="Logo POLBAN">' +
                    '<div class="sidebar-title">' +
                        '<span class="title-main">SIM-TKD</span>' +
                        '<span class="title-sub">Modul Edukasi</span>' +
                    '</div>' +
                '</div>' +
                '<button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>' +
            '</div>';

        var user =
            '<div class="sidebar-user">' +
                '<div class="user-avatar"><i class="fas fa-user-tie"></i></div>' +
                '<div class="user-info">' +
                    '<span class="user-name" id="sidebarUserName">Admin Dinas</span>' +
                    '<span class="user-role" id="sidebarUserRole">Dinas Kepemudaan dan Olahraga</span>' +
                '</div>' +
            '</div>';

        var ctx = { onAktif: function () {} };
        var nav = '<nav class="sidebar-menu">' + renderMenu(MENU, pos, ctx) + '</nav>';

        sidebar.innerHTML = header + user + nav;
    }

    // Render sinkron: script ini dimuat SETELAH elemen <aside> dan
    // SEBELUM js/dashboard.js (yang langsung mengambil elemen & pasang listener).
    render();
})();
