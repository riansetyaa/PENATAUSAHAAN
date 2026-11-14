/**
 * SIM-TKD (peta.simtkd.com) - Konfigurasi API Frontend
 * ============================================
 * Token API per-user (multi-tenant). TIDAK ada fallback ke token admin,
 * agar data antar instansi tidak bocor.
 *
 * Sumber token (prioritas):
 *   1. URL   ?token=...  (dibawa dari SIM-TKD saat klik menu Akuntansi)
 *   2. sessionStorage     (diteruskan antar halaman AKLAP yang sama)
 *   Jika tidak ada -> halaman menampilkan peringatan, BUKAN data admin.
 */
var SIMTKD_API = {
    endpoint: 'api/aklap.php',
    token: '',
    _stored: '',

    // Token efektif per-user (tanpa fallback admin)
    getToken: function () {
        // 1) URL ?token=
        try {
            var t = new URLSearchParams(window.location.search).get('token');
            if (t) { this._stored = t; try { sessionStorage.setItem('simtkd_aklap_token', t); } catch (e) {} return t; }
        } catch (e) {}
        // 2) sessionStorage (propagasi antar halaman AKLAP)
        if (this._stored) return this._stored;
        try {
            var s = sessionStorage.getItem('simtkd_aklap_token');
            if (s) { this._stored = s; return s; }
        } catch (e) {}
        return '';
    },

    // Simpan token secara manual (mis. setelah berhasil verifikasi)
    setToken: function (t) {
        if (!t) return;
        this._stored = t;
        try { sessionStorage.setItem('simtkd_aklap_token', t); } catch (e) {}
    },

    hasToken: function () {
        return this.getToken() !== '';
    },

    // Teruskan token ke link internal AKLAP agar navigasi tidak kehilangan token
    init: function () {
        var self = this;
        var t = this.getToken();

        // Tombol "Kembali ke Penatausahaan": di produksi subdomain punya document
        // root sendiri (href absolut https://simtkd.com/...). Saat diuji LOKAL,
        // arahkan ke dashboard app utama yang sejajar folder.
        try {
            var host = window.location.hostname;
            if (host === '127.0.0.1' || host === 'localhost') {
                document.addEventListener('DOMContentLoaded', function () {
                    Array.prototype.forEach.call(document.querySelectorAll('a[data-back-main]'), function (a) {
                        a.setAttribute('href', '/dashboard.html');
                    });
                });
            }
        } catch (e) {}

        // Peringatan bila tidak ada token (cegah tampil data orang lain)
        if (!t) {
            document.addEventListener('DOMContentLoaded', function () {
                var box = document.createElement('div');
                box.id = 'simtkd-aklap-warning';
                box.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#b91c1c;color:#fff;padding:12px 16px;text-align:center;font:600 14px/1.4 -apple-system,Segoe UI,Roboto,sans-serif;';
                box.textContent = 'Anda belum terhubung ke akun. Buka modul Akuntansi (AKLAP) melalui menu Akuntansi di SIM-TKD.';
                (document.body || document.documentElement).appendChild(box);
            });
            return;
        }

        // Rewrite link internal (relatif) agar membawa ?token=
        function decorate() {
            var links = document.querySelectorAll('a[href]');
            Array.prototype.forEach.call(links, function (a) {
                var href = a.getAttribute('href');
                if (!href) return;
                // hanya link internal (relatif) - abaikan eksternal/mailto/#/javascript
                if (/^(https?:)?\/\//.test(href) || /^(mailto:|tel:|javascript:|#)/.test(href)) return;
                if (a.href.indexOf('token=') !== -1) return;
                var sep = (href.indexOf('?') === -1) ? '?' : '&';
                a.href = href + sep + 'token=' + encodeURIComponent(t);
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', decorate);
        } else {
            decorate();
        }
    }
};

// Jalankan inisialisasi token segera
SIMTKD_API.init();
