/* ============================================================
 * SIM-TKD - Popup Notifikasi (pengganti alert() bawaan browser)
 * ============================================================
 * Cara pakai:
 *   popupAlert('Pesan Anda')                     -> tipe info
 *   popupAlert('Pesan Anda', 'success')          -> sukses
 *   popupAlert('Pesan', 'error', 'Gagal!')       -> error + judul
 *   popupAlert('Pesan', 'warning', 'Judul', 5000)-> auto-tutup
 *
 * window.alert() juga ditimpa agar seluruh pemanggilan alert()
 * lama otomatis tampil sebagai popup ini.
 * ============================================================ */
(function () {
    'use strict';

    var STYLES = {
        success: { icon: 'fa-check-circle', color: '#16a34a', bg: '#dcfce7', title: 'Berhasil' },
        error:   { icon: 'fa-times-circle', color: '#dc2626', bg: '#fee2e2', title: 'Gagal' },
        warning: { icon: 'fa-exclamation-triangle', color: '#d97706', bg: '#fef3c7', title: 'Perhatian' },
        info:    { icon: 'fa-info-circle',  color: '#2563eb', bg: '#dbeafe', title: 'Informasi' }
    };

    var host = null;

    function ensureHost() {
        if (host && document.body.contains(host)) return host;
        host = document.createElement('div');
        host.className = 'simtkd-popup-host simtkd-hidden';
        var css = '' +
            '.simtkd-popup-host{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;}' +
            '.simtkd-popup-host.simtkd-hidden{display:none;}' +
            '.simtkd-popup-overlay{position:absolute;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(2px);opacity:0;transition:opacity .18s ease;}' +
            '.simtkd-popup-host.simtkd-show .simtkd-popup-overlay{opacity:1;}' +
            '.simtkd-popup{position:relative;width:100%;max-width:380px;background:#fff;border-radius:14px;box-shadow:0 20px 50px rgba(2,8,23,.35);overflow:hidden;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;transform:translateY(14px) scale(.97);opacity:0;transition:transform .2s cubic-bezier(.2,.9,.3,1.2),opacity .18s ease;}' +
            '.simtkd-popup-host.simtkd-show .simtkd-popup{transform:translateY(0) scale(1);opacity:1;}' +
            '.simtkd-popup-bar{height:5px;}' +
            '.simtkd-popup-body{padding:22px 22px 6px;text-align:center;}' +
            '.simtkd-popup-icon{width:58px;height:58px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:26px;}' +
            '.simtkd-popup-title{margin:0 0 6px;font-size:16.5px;font-weight:700;color:#0f172a;}' +
            '.simtkd-popup-msg{margin:0;font-size:13.5px;line-height:1.55;color:#475569;word-wrap:break-word;}' +
            '.simtkd-popup-actions{padding:16px 22px 20px;display:flex;justify-content:center;}' +
            '.simtkd-popup-btn{min-width:110px;padding:9px 18px;border:none;border-radius:9px;background:#16305a;color:#fff;font-size:13.5px;font-weight:600;cursor:pointer;transition:background .15s ease,transform .1s ease;font-family:inherit;}' +
            '.simtkd-popup-btn:hover{background:#1f417d;}' +
            '.simtkd-popup-btn:active{transform:scale(.97);}';
        var style = document.createElement('style');
        style.textContent = css;
        host.appendChild(style);
        document.body.appendChild(host);
        return host;
    }

    function popupAlert(message, type, title, autoCloseMs) {
        var conf = STYLES[type] || STYLES.info;
        var el = ensureHost();
        var timer = null;
        // hapus popup sebelumnya (jangan hapus host beserta <style>-nya)
        Array.prototype.slice.call(el.children).forEach(function (c) {
            if (!c.tagName || c.tagName.toLowerCase() !== 'style') c.remove();
        });
        el.classList.remove('simtkd-hidden');

        var overlay = document.createElement('div');
        overlay.className = 'simtkd-popup-overlay';

        var box = document.createElement('div');
        box.className = 'simtkd-popup';
        box.setAttribute('role', 'alertdialog');
        box.setAttribute('aria-modal', 'true');

        var bar = document.createElement('div');
        bar.className = 'simtkd-popup-bar';
        bar.style.background = conf.color;

        var body = document.createElement('div');
        body.className = 'simtkd-popup-body';

        var iconWrap = document.createElement('div');
        iconWrap.className = 'simtkd-popup-icon';
        iconWrap.style.background = conf.bg;
        iconWrap.style.color = conf.color;
        var icon = document.createElement('i');
        icon.className = 'fas ' + conf.icon;
        iconWrap.appendChild(icon);

        var h = document.createElement('h3');
        h.className = 'simtkd-popup-title';
        h.textContent = title || conf.title;

        var msg = document.createElement('p');
        msg.className = 'simtkd-popup-msg';
        msg.textContent = String(message == null ? '' : message);

        body.appendChild(iconWrap);
        body.appendChild(h);
        body.appendChild(msg);

        var actions = document.createElement('div');
        actions.className = 'simtkd-popup-actions';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'simtkd-popup-btn';
        btn.textContent = 'OK';
        actions.appendChild(btn);

        box.appendChild(bar);
        box.appendChild(body);
        box.appendChild(actions);

        function close() {
            el.classList.remove('simtkd-show');
            if (timer) { clearTimeout(timer); timer = null; }
            setTimeout(function () {
                el.classList.add('simtkd-hidden');
                Array.prototype.slice.call(el.children).forEach(function (c) {
                    if (!c.tagName || c.tagName.toLowerCase() !== 'style') c.remove();
                });
            }, 200);
        }

        btn.addEventListener('click', close);
        overlay.addEventListener('click', close);
        document.addEventListener('keydown', function onKey(e) {
            if (e.key === 'Escape') {
                close();
                document.removeEventListener('keydown', onKey);
            }
        });

        el.appendChild(overlay);
        el.appendChild(box);
        void el.offsetWidth; /* paksa reflow agar animasi jalan */
        el.classList.add('simtkd-show');
        btn.focus();

        var ms = Number(autoCloseMs);
        if (ms > 0) timer = setTimeout(close, ms);

        return close;
    }

    window.popupAlert = popupAlert;

    // Toast alias (kompatibel dengan pemanggilan showToast lama)
    window.showToast = function (message, type) {
        popupAlert(message, type === 'success' ? 'success' : (type || 'info'), null, 4000);
    };

    // Timpa alert() bawaan: semua alert() lama otomatis jadi popup
    window.alert = function (message) { popupAlert(message); };
})();
