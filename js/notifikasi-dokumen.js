/**
 * SIM-TKD - Notifikasi Dokumen Belum Dikirim ke Tanda Tangan
 * ==========================================================
 * Menempel pada ikon lonceng di topbar. Saat diklik, panel menampilkan
 * daftar dokumen milik instansi pengguna yang belum pernah dikirim ke
 * antrean Tanda Tangan Dokumen (doc.simtkd.com).
 *
 * Sumber data: api/notifikasi_dokumen.php
 * Script ini dimuat otomatis oleh js/sidebar-menu.js pada semua halaman
 * yang memiliki topbar, sehingga tidak perlu ditambahkan satu per satu.
 */
(function () {
    'use strict';

    if (window.__simtkdNotifDokumen) {
        return;
    }
    window.__simtkdNotifDokumen = true;

    // ---------- util ----------
    function basePath() {
        var path = String(location.pathname || '');
        // Root = file tepat di bawah document root ("/dashboard.html").
        return /^\/[^/]+\.[a-z]+$/i.test(path) ? '' : '../';
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = String(text);
        return node;
    }

    function formatTanggal(nilai) {
        if (!nilai) return 'Tanggal belum diisi';
        var p = String(nilai).slice(0, 10).split('-');
        if (p.length !== 3) return String(nilai);
        return p[2] + '/' + p[1] + '/' + p[0];
    }

    function ikonJenis(jenis) {
        var map = {
            'SPD': 'fa-file-invoice',
            'SPP': 'fa-file-signature',
            'SPM': 'fa-file-invoice-dollar',
            'SP2D': 'fa-money-check-alt',
            'LPJ': 'fa-clipboard-check',
            'NPD': 'fa-file-invoice-dollar',
            'Pengajuan TU': 'fa-paper-plane',
            'SKP Daerah': 'fa-receipt',
            'STBP': 'fa-file-invoice',
            'STS': 'fa-money-bill-wave'
        };
        return map[jenis] || 'fa-file-alt';
    }

    // ---------- state ----------
    var BASE = basePath();
    var API = BASE + 'api/notifikasi_dokumen.php';
    var state = {
        data: null,
        total: 0,
        perJenis: [],
        loading: false,
        error: '',
        dibatasi: false,
        tersedia: true
    };
    var lastLoad = 0;

    var bell = null;
    var badge = null;
    var panel = null;
    var list = null;
    var headNote = null;
    var footInfo = null;
    var terbuka = false;

    // ---------- elemen topbar ----------
    function siapkanBell() {
        var right = document.querySelector('.topbar-right');
        if (!right) return false;

        bell = right.querySelector('.topbar-item.notification');
        if (!bell) {
            bell = el('div', 'topbar-item notification');
            bell.appendChild(el('i', 'fas fa-bell'));
            right.insertBefore(bell, right.firstChild);
        }
        if (!bell.querySelector('i')) {
            bell.appendChild(el('i', 'fas fa-bell'));
        }

        badge = bell.querySelector('.badge');
        if (!badge) {
            badge = el('span', 'badge');
            bell.appendChild(badge);
        }
        badge.textContent = '';
        badge.hidden = true;

        bell.setAttribute('role', 'button');
        bell.setAttribute('tabindex', '0');
        bell.setAttribute('aria-label', 'Notifikasi dokumen belum dikirim ke tanda tangan');
        bell.setAttribute('aria-haspopup', 'dialog');
        bell.setAttribute('aria-expanded', 'false');
        bell.title = 'Notifikasi tanda tangan dokumen';

        bell.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            togglePanel();
        });
        bell.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                togglePanel();
            }
        });

        return true;
    }

    // ---------- panel ----------
    function buatPanel() {
        panel = el('div', 'notif-panel');
        panel.hidden = true;
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'Dokumen belum dikirim ke tanda tangan');

        var head = el('div', 'notif-head');
        var headText = el('div');
        headText.appendChild(el('h4', null, 'Notifikasi Tanda Tangan'));
        headNote = el('p', null, 'Memuat dokumen...');
        headText.appendChild(headNote);

        var close = el('button', 'notif-close');
        close.type = 'button';
        close.setAttribute('aria-label', 'Tutup notifikasi');
        close.innerHTML = '<i class="fas fa-xmark"></i>';
        close.addEventListener('click', function (e) {
            e.preventDefault();
            tutupPanel();
        });

        head.appendChild(headText);
        head.appendChild(close);
        panel.appendChild(head);

        list = el('div', 'notif-list');
        panel.appendChild(list);

        var foot = el('div', 'notif-foot');
        footInfo = el('span', null, '');
        var tautan = el('a', null, 'Buka Tanda Tangan');
        tautan.href = BASE + 'api/dokumen_go.php';
        foot.appendChild(footInfo);
        foot.appendChild(tautan);
        panel.appendChild(foot);

        document.body.appendChild(panel);

        document.addEventListener('mousedown', function (e) {
            if (!terbuka) return;
            if (panel.contains(e.target) || bell.contains(e.target)) return;
            tutupPanel();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && terbuka) {
                tutupPanel();
            }
        });
        window.addEventListener('resize', function () {
            if (terbuka) tutupPanel();
        }, { passive: true });
    }

    function posisikanPanel() {
        if (!panel || !bell) return;
        var r = bell.getBoundingClientRect();
        var right = Math.max(12, window.innerWidth - r.right);
        panel.style.top = Math.round(r.bottom + 8) + 'px';
        panel.style.right = Math.round(right) + 'px';
        panel.style.left = 'auto';

        // Jangan sampai panel keluar dari sisi kiri pada layar kecil.
        requestAnimationFrame(function () {
            var lebar = panel.offsetWidth;
            if (r.right - lebar < 12) {
                panel.style.right = '12px';
            }
        });
    }

    function togglePanel() {
        if (terbuka) {
            tutupPanel();
        } else {
            bukaPanel();
        }
    }

    function bukaPanel() {
        if (!panel) buatPanel();
        terbuka = true;
        panel.hidden = false;
        bell.setAttribute('aria-expanded', 'true');
        posisikanPanel();
        renderIsi();

        // Muat ulang saat panel dibuka kembali: data bisa berubah setelah
        // pengguna mengirim dokumen baru dari tab/halaman lain.
        var sudahLama = Date.now() - lastLoad > 30000;
        if ((state.data === null || state.error || sudahLama) && !state.loading) {
            muatData(true);
        }
    }

    function tutupPanel() {
        if (!panel) return;
        terbuka = false;
        panel.hidden = true;
        if (bell) bell.setAttribute('aria-expanded', 'false');
    }

    // ---------- render ----------
    function renderMemuat() {
        if (!list) return;
        list.textContent = '';
        var box = el('div', 'notif-empty');
        box.appendChild(el('i', 'fas fa-spinner fa-spin'));
        box.appendChild(document.createTextNode('Memeriksa dokumen...'));
        list.appendChild(box);
        headNote.textContent = 'Mohon tunggu sebentar.';
    }

    function renderGalat() {
        if (!list) return;
        list.textContent = '';
        var box = el('div', 'notif-empty');
        box.appendChild(el('i', 'fas fa-triangle-exclamation'));
        box.appendChild(document.createTextNode(state.error || 'Gagal memuat notifikasi.'));
        list.appendChild(box);
        headNote.textContent = 'Notifikasi tidak dapat dimuat.';
        footInfo.textContent = '';
    }

    function renderIsi() {
        if (!list) return;

        if (state.loading) {
            renderMemuat();
            return;
        }
        if (state.error) {
            renderGalat();
            return;
        }
        if (state.data === null) {
            return;
        }

        if (!state.tersedia) {
            list.textContent = '';
            var belum = el('div', 'notif-empty');
            belum.appendChild(el('i', 'fas fa-database'));
            belum.appendChild(document.createTextNode('Data dokumen tanda tangan belum tersedia di server.'));
            list.appendChild(belum);
            headNote.textContent = 'Fitur tanda tangan belum dikonfigurasi.';
            footInfo.textContent = '';
            return;
        }

        headNote.textContent = state.total > 0
            ? 'Dokumen berikut belum pernah dikirim ke antrean tanda tangan.'
            : 'Tidak ada dokumen yang tertunda.';

        footInfo.textContent = state.dibatasi
            ? 'Menampilkan 100 dari ' + state.total + ' dokumen'
            : (state.total > 0 ? state.total + ' dokumen tertunda' : 'Semua sudah dikirim');

        list.textContent = '';

        if (!state.data.length) {
            var kosong = el('div', 'notif-empty');
            kosong.appendChild(el('i', 'fas fa-circle-check'));
            kosong.appendChild(document.createTextNode('Semua dokumen sudah dimasukkan ke Tanda Tangan Dokumen.'));
            list.appendChild(kosong);
            return;
        }

        state.data.forEach(function (d) {
            var item = el('a', 'notif-item');
            item.href = BASE + (d.url || 'api/dokumen_go.php');

            var ico = el('span', 'notif-ico');
            ico.appendChild(el('i', 'fas ' + ikonJenis(d.jenis)));
            item.appendChild(ico);

            var main = el('span', 'notif-main');
            var title = el('span', 'notif-title');
            title.appendChild(el('span', null, d.judul || d.jenis || 'Dokumen'));
            title.appendChild(el('span', 'notif-type', d.jenis || 'Dokumen'));
            main.appendChild(title);
            main.appendChild(el('span', 'notif-sub',
                formatTanggal(d.tanggal) + ' · Belum dikirim ke TTD'));
            item.appendChild(main);

            list.appendChild(item);
        });
    }

    function perbaruiBadge() {
        if (!badge) return;
        if (!state.tersedia || !state.total) {
            badge.hidden = true;
            badge.textContent = '';
            return;
        }
        badge.hidden = false;
        badge.textContent = state.total > 99 ? '99+' : String(state.total);
        badge.style.minWidth = state.total > 9 ? '22px' : '17px';
    }

    // ---------- data ----------
    function muatData(paksa) {
        if (state.loading) return;
        if (state.data !== null && !paksa) return;

        state.loading = true;
        state.error = '';
        if (terbuka) renderIsi();

        fetch(API, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (res) {
                if (res.status === 401) {
                    throw new Error('Sesi berakhir.');
                }
                return res.json();
            })
            .then(function (d) {
                if (!d || !d.success) {
                    throw new Error((d && d.message) || 'Notifikasi tidak dapat dimuat.');
                }
                state.data = Array.isArray(d.data) ? d.data : [];
                state.total = Number(d.total || state.data.length);
                state.perJenis = Array.isArray(d.per_jenis) ? d.per_jenis : [];
                state.tersedia = d.tersedia !== false;
                state.dibatasi = !!d.dibatasi;
            })
            .catch(function (err) {
                state.data = [];
                state.total = 0;
                state.error = (err && err.message) ? err.message : 'Gagal memuat notifikasi.';
            })
            .then(function () {
                state.loading = false;
                lastLoad = Date.now();
                perbaruiBadge();
                if (terbuka) renderIsi();
            });
    }

    // ---------- init ----------
    function init() {
        if (!siapkanBell()) return;
        // Muat lebih awal agar badge langsung menampilkan jumlah terbaru.
        muatData(false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
