/**
 * SIM-TKD (doc.simtkd.com) - Konfigurasi API Frontend
 * ============================================================
 * Token API per-user (multi-tenant), pola sama dengan peta.simtkd.com:
 * token dibawa via URL ?token= saat masuk dari aplikasi utama
 * (api/dokumen_go.php), lalu disimpan di sessionStorage.
 *
 * Sumber token (prioritas):
 *   1. URL   ?token=...  (dibawa dari SIM-TKD saat klik menu Tanda Tangan)
 *   2. sessionStorage     (diteruskan antar halaman)
 *   Jika tidak ada -> halaman menampilkan peringatan, BUKAN data orang lain.
 */
var SIMTKD_DOC = {
    endpoint: 'api/dokumen.php',
    _stored: '',

    // Token efektif per-user (tanpa fallback)
    getToken: function () {
        // 1) URL ?token=
        try {
            var t = new URLSearchParams(window.location.search).get('token');
            if (t) { this._stored = t; try { sessionStorage.setItem('simtkd_doc_token', t); } catch (e) {} return t; }
        } catch (e) {}
        // 2) sessionStorage (propagasi antar halaman)
        if (this._stored) return this._stored;
        try {
            var s = sessionStorage.getItem('simtkd_doc_token');
            if (s) { this._stored = s; return s; }
        } catch (e) {}
        return '';
    },

    // Simpan token secara manual
    setToken: function (t) {
        if (!t) return;
        this._stored = t;
        try { sessionStorage.setItem('simtkd_doc_token', t); } catch (e) {}
    },

    hasToken: function () {
        return this.getToken() !== '';
    },

    // Bangun URL endpoint/halaman internal dengan token terlampir
    url: function (path) {
        var t = this.getToken();
        var sep = (path.indexOf('?') === -1) ? '?' : '&';
        return t ? (path + sep + 'token=' + encodeURIComponent(t)) : path;
    },

    // Keluar: hapus token dari sesi browser
    keluar: function () {
        try { sessionStorage.removeItem('simtkd_doc_token'); } catch (e) {}
        window.location.href = 'index.html';
    },

    // Inisialisasi: peringatan tanpa token + tautan kembali ke app utama
    init: function () {
        var t = this.getToken();

        // Tombol "Kembali ke SIM-TKD": produksi punya document root sendiri.
        // Saat diuji LOKAL, arahkan ke dashboard app utama yang sejajar folder.
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
                var box = document.getElementById('tokenWarning');
                if (box) { box.classList.remove('hidden'); }
            });
        }
    }
};

// Jalankan inisialisasi token segera
SIMTKD_DOC.init();
