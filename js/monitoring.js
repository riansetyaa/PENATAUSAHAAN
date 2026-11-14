/**
 * SIM-TKD - Monitoring Pengguna (Admin/Dosen)
 * ============================================
 * Halaman: pengaturan/monitoring.html
 * API    : api/monitoring.php
 */

(function () {
    'use strict';

    var DATA = null;
    var BARIS = [];
    var SAYA = null;
    var BISA_UBAH_PERAN = false;
    var DOKUMEN_AKTIF = 0;
    var PERAN_TERSEDIA = [
        'Admin Dinas', 'Operator', 'Bendahara',
        'Verifikator', 'Kepala Dinas', 'Pengguna Umum'
    ];

    var BULAN = [
        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];

    var MODUL = {
        penerimaan: { label: 'Penerimaan', icon: 'fa-arrow-down' },
        belanja:    { label: 'Belanja',    icon: 'fa-arrow-up' },
        akuntansi:  { label: 'Akuntansi',  icon: 'fa-book' },
        dokumen:    { label: 'Dokumen',    icon: 'fa-pen-nib' }
    };

    function el(id) {
        return document.getElementById(id);
    }

    function angka(n) {
        n = Number(n);
        return isFinite(n) ? n : 0;
    }

    function esc(t) {
        return String(t == null ? '' : t)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /** "2026-11-12 08:45:00" -> "12 November 2026 08.45" */
    function fmtWaktu(s) {
        if (!s) return '—';
        var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
        if (!m) return String(s);

        var hasil = parseInt(m[3], 10) + ' ' + BULAN[parseInt(m[2], 10) - 1] + ' ' + m[1];
        if (m[4]) hasil += ' ' + m[4] + '.' + m[5];
        return hasil;
    }

    function fmtTanggal(s) {
        if (!s) return '—';
        var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (!m) return String(s);
        return parseInt(m[3], 10) + ' ' + BULAN[parseInt(m[2], 10) - 1] + ' ' + m[1];
    }

    function keWaktu(s) {
        if (!s) return null;
        var d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    }

    function relatif(s) {
        var d = keWaktu(s);
        if (!d) return '';
        var selisih = Date.now() - d.getTime();
        if (selisih < 0) return '';
        if (selisih < 60000) return 'baru saja';
        if (selisih < 3600000) return Math.floor(selisih / 60000) + ' menit lalu';
        if (selisih < 86400000) return Math.floor(selisih / 3600000) + ' jam lalu';
        if (selisih < 2592000000) return Math.floor(selisih / 86400000) + ' hari lalu';
        return '';
    }

    function dalam7Hari(s) {
        var d = keWaktu(s);
        return d ? (Date.now() - d.getTime()) <= 7 * 86400000 : false;
    }

    function jamSekarang() {
        var d = new Date();
        return ('0' + d.getHours()).slice(-2) + '.' + ('0' + d.getMinutes()).slice(-2);
    }

    function kelasProgres(p) {
        if (p >= 100) return 'is-done';
        if (p >= 70) return 'is-high';
        if (p >= 30) return 'is-mid';
        if (p > 0) return 'is-low';
        return 'is-zero';
    }

    // ------------------------------------------------------------
    // Hasil dokumen mahasiswa
    // ------------------------------------------------------------
    var IKON_DOKUMEN = {
        'SPD': 'fa-file-invoice',
        'SPP': 'fa-file-signature',
        'SPM': 'fa-file-invoice-dollar',
        'SP2D': 'fa-money-check-alt',
        'LPJ': 'fa-clipboard-check',
        'NPD': 'fa-file-invoice-dollar',
        'Pengajuan TU': 'fa-paper-plane',
        'SKP Daerah': 'fa-receipt',
        'STBP': 'fa-file-invoice',
        'STS': 'fa-money-bill-wave',
        'Permohonan': 'fa-file-signature',
        'Rekening Bank SKPD': 'fa-university',
        'Rekanan': 'fa-handshake',
        'Jurnal Umum': 'fa-book',
        'Jurnal Khusus': 'fa-book-open',
        'Jurnal Manual': 'fa-book',
        'Buku Kas': 'fa-book',
        'Buku Kas Harian': 'fa-calendar-day',
        'Rekonsiliasi Bank': 'fa-scale-balanced',
        'Akun Penerimaan': 'fa-coins'
    };

    function ikonDokumen(jenis) {
        return IKON_DOKUMEN[jenis] || 'fa-file-lines';
    }

    /** 'sudah_divalidasi' -> 'Sudah divalidasi' */
    function labelStatus(s) {
        if (!s) return '—';
        var t = String(s).replace(/_/g, ' ');
        return t.charAt(0).toUpperCase() + t.slice(1);
    }

    function rupiah(n) {
        if (n === null || n === undefined || n === '') return '';
        var v = Number(n);
        if (!isFinite(v)) return '';
        return 'Rp ' + v.toLocaleString('id-ID', { maximumFractionDigits: 0 });
    }

    function badgeTtd(x) {
        if (!x.alur_ttd) return '';
        if (!x.ttd) {
            return '<span class="doc-badge none" title="Belum dikirim ke Tanda Tangan Dokumen">Belum dikirim</span>';
        }
        if (x.ttd.status === 'ditandatangani') {
            return '<span class="doc-badge ok" title="Sudah ditandatangani">Ditandatangani</span>';
        }
        return '<span class="doc-badge wait" title="Sudah dikirim, menunggu tanda tangan">Menunggu TTD</span>';
    }

    function htmlDokumen(d) {
        var r = (d && d.ringkasan) || {};
        var entri = (d && d.entri) || [];
        var dokumen = (d && d.dokumen) || [];

        var html = '<div class="doc-summary">' +
            '<span class="doc-chip"><b>' + angka(r.total_entri) + '</b> entri data</span>' +
            '<span class="doc-chip"><b>' + angka(r.dokumen_kirim) + '</b> dokumen terkirim</span>' +
            '<span class="doc-chip done"><b>' + angka(r.ditandatangani) + '</b> ditandatangani</span>' +
            '<span class="doc-chip wait"><b>' + angka(r.menunggu_ttd) + '</b> menunggu TTD</span>' +
        '</div>';

        if (d && d.tersedia === false) {
            html += '<div class="doc-empty"><i class="fas fa-triangle-exclamation"></i> ' +
                'Tabel dokumen tanda tangan belum tersedia di server.</div>';
        }

        // --- Dokumen yang sudah dikirim ke TTD ---
        html += '<div class="doc-block">' +
            '<div class="doc-block-head"><span><i class="fas fa-pen-nib"></i> Dokumen Terkirim ke Tanda Tangan</span>' +
                '<span>' + dokumen.length + '</span></div>';

        if (!dokumen.length) {
            html += '<div class="doc-empty">Belum ada dokumen yang dikirim ke antrean tanda tangan.</div>';
        } else {
            html += dokumen.map(function (x) {
                var sudah = x.status === 'ditandatangani';
                var sub = [];
                if (x.tanggal) sub.push(fmtTanggal(x.tanggal));
                sub.push(sudah ? 'Ditandatangani' : 'Menunggu tanda tangan');
                if (x.kode_verifikasi) sub.push('Kode ' + x.kode_verifikasi);

                return '<div class="doc-row">' +
                    '<span class="doc-ico"><i class="fas ' + ikonDokumen(x.jenis) + '"></i></span>' +
                    '<span class="doc-main">' +
                        '<span class="doc-title">' + esc(x.jenis || 'Dokumen') +
                            (x.nomor ? ' <b>' + esc(x.nomor) + '</b>' : '') + '</span>' +
                        '<span class="doc-sub">' + esc(sub.join(' · ')) + '</span>' +
                    '</span>' +
                    '<span class="doc-badge ' + (sudah ? 'ok' : 'wait') + '">' +
                        (sudah ? 'Ditandatangani' : 'Menunggu TTD') + '</span>' +
                    '<button type="button" class="action-btn" data-pratinjau="' + angka(x.id) + '" ' +
                        'data-pemilik="' + angka(r.pemilik_id || 0) + '" title="Pratinjau dokumen">' +
                        '<i class="fas fa-eye"></i></button>' +
                '</div>';
            }).join('');
        }
        html += '</div>';

        // --- Data per modul ---
        html += '<div class="doc-block">' +
            '<div class="doc-block-head"><span><i class="fas fa-list-check"></i> Data per Modul</span>' +
                '<span>' + entri.length + '</span></div>';

        if (!entri.length) {
            html += '<div class="doc-empty">Akun ini belum membuat data pada modul mana pun.</div>';
        } else {
            var batas = 150;
            var tampil = entri.slice(0, batas);

            Object.keys(MODUL).forEach(function (key) {
                var baris = tampil.filter(function (x) { return x.modul === key; });
                if (!baris.length) return;

                html += '<div class="doc-group">' +
                    '<div class="doc-group-head"><span><i class="fas ' + MODUL[key].icon + '"></i> ' +
                        esc(MODUL[key].label) + '</span><b>' + baris.length + '</b></div>';

                html += baris.map(function (x) {
                    var sub = [];
                    if (x.tanggal) sub.push(fmtTanggal(x.tanggal));
                    if (x.judul && x.judul !== x.nomor) sub.push(x.judul);
                    if (x.nilai !== null && x.nilai !== undefined) sub.push(rupiah(x.nilai));

                    return '<div class="doc-row">' +
                        '<span class="doc-ico"><i class="fas ' + ikonDokumen(x.jenis) + '"></i></span>' +
                        '<span class="doc-main">' +
                            '<span class="doc-title">' + esc(x.jenis) +
                                (x.nomor ? ' <b>' + esc(x.nomor) + '</b>' : '') + '</span>' +
                            '<span class="doc-sub">' + esc(sub.join(' · ') || 'Tanpa tanggal') + '</span>' +
                        '</span>' +
                        (x.status ? '<span class="doc-badge">' + esc(labelStatus(x.status)) + '</span>'
                                  : '<span></span>') +
                        badgeTtd(x) +
                    '</div>';
                }).join('');

                html += '</div>';
            });

            if (entri.length > batas) {
                html += '<div class="doc-more">Menampilkan ' + batas + ' dari ' + entri.length +
                    ' entri terbaru.</div>';
            }
            if (r.dibatasi) {
                html += '<div class="doc-more">Sebagian modul memiliki lebih dari 100 entri; ' +
                    'daftar pada modul tersebut dibatasi.</div>';
            }
        }
        html += '</div>';

        return html;
    }

    function muatDokumen(userId) {
        var wrap = el('detailDokumenBody');
        if (!wrap) return;

        DOKUMEN_AKTIF = Number(userId);
        wrap.innerHTML = '<div class="doc-empty"><i class="fas fa-spinner fa-spin"></i> Memuat dokumen...</div>';

        fetch('../api/monitoring_dokumen.php?user_id=' + encodeURIComponent(userId), { cache: 'no-store' })
            .then(function (res) { return res.json(); })
            .then(function (d) {
                if (DOKUMEN_AKTIF !== Number(userId)) return; // modal sudah menampilkan akun lain
                if (!d || !d.success) {
                    wrap.innerHTML = '<div class="doc-empty"><i class="fas fa-triangle-exclamation"></i> ' +
                        esc((d && d.message) || 'Gagal memuat dokumen mahasiswa.') + '</div>';
                    return;
                }
                d.ringkasan = d.ringkasan || {};
                d.ringkasan.pemilik_id = userId;
                wrap.innerHTML = htmlDokumen(d);
            })
            .catch(function (err) {
                if (DOKUMEN_AKTIF !== Number(userId)) return;
                console.error('Monitoring: gagal memuat dokumen', err);
                wrap.innerHTML = '<div class="doc-empty"><i class="fas fa-triangle-exclamation"></i> ' +
                    'Gagal menghubungi server saat memuat dokumen.</div>';
            });
    }

    function bukaPratinjau(userId, dokumenId) {
        var frame = el('pratinjauFrame');
        if (!frame) return;

        el('pratinjauTitle').textContent = 'Pratinjau Dokumen';
        el('pratinjauSubtitle').textContent = 'Memuat isi dokumen...';
        frame.srcdoc = '';
        el('modalPratinjau').hidden = false;
        document.body.classList.add('modal-open');

        fetch('../api/monitoring_dokumen.php?user_id=' + encodeURIComponent(userId) +
              '&dokumen_id=' + encodeURIComponent(dokumenId), { cache: 'no-store' })
            .then(function (res) { return res.json(); })
            .then(function (d) {
                if (!d || !d.success) {
                    throw new Error((d && d.message) || 'Dokumen tidak dapat dibuka.');
                }
                var doc = d.dokumen || {};

                el('pratinjauTitle').textContent =
                    (doc.jenis || 'Dokumen') + (doc.nomor ? ' ' + doc.nomor : '');

                var ket = [];
                if (doc.tanggal) ket.push(fmtTanggal(doc.tanggal));
                ket.push(doc.status === 'ditandatangani' ? 'Sudah ditandatangani' : 'Menunggu tanda tangan');
                if (doc.kode_verifikasi) ket.push('Kode ' + doc.kode_verifikasi);
                el('pratinjauSubtitle').textContent = ket.join(' · ');

                frame.srcdoc = doc.konten_html ||
                    '<p style="font-family:sans-serif;padding:24px;color:#64748b">Dokumen belum memiliki konten.</p>';
            })
            .catch(function (err) {
                console.error('Monitoring: gagal membuka dokumen', err);
                el('pratinjauSubtitle').textContent = (err && err.message)
                    ? err.message
                    : 'Gagal membuka dokumen.';
            });
    }

    function tutupPratinjau() {
        var modal = el('modalPratinjau');
        if (!modal || modal.hidden) return;
        modal.hidden = true;
        var frame = el('pratinjauFrame');
        if (frame) frame.srcdoc = '';
        if (el('modalDetail').hidden) document.body.classList.remove('modal-open');
    }

    // ------------------------------------------------------------
    // Ringkasan
    // ------------------------------------------------------------
    function renderRingkasan(r) {
        r = r || {};

        el('statTotal').textContent = angka(r.total);
        el('statAktif').textContent = angka(r.aktif);
        el('statLogin').textContent = angka(r.sudah_login);
        el('statLogin7').textContent = angka(r.login_7hari);
        el('statBelum').textContent = angka(r.belum_login);
        el('statRata').textContent = angka(r.rata_rata_progres) + '%';
    }

    // ------------------------------------------------------------
    // Filter peran (opsi dibangun dari data)
    // ------------------------------------------------------------
    function isiFilterPeran(pengguna) {
        var select = el('filterPeran');
        var sekarang = select.value;
        var peran = {};

        (pengguna || []).forEach(function (u) {
            if (u.peran) peran[u.peran] = true;
        });

        var opsi = Object.keys(peran).sort();
        select.innerHTML = '<option value="">Semua peran</option>' +
            opsi.map(function (p) {
                return '<option value="' + esc(p) + '">' + esc(p) + '</option>';
            }).join('');

        if (sekarang && peran[sekarang]) select.value = sekarang;
    }

    // ------------------------------------------------------------
    // Tabel
    // ------------------------------------------------------------
    function barisTampil() {
        var cari = (el('filterCari').value || '').toLowerCase().trim();
        var status = el('filterStatus').value;
        var login = el('filterLogin').value;
        var peran = el('filterPeran').value;
        var urut = el('filterUrut').value;

        var rows = (DATA.pengguna || []).filter(function (u) {
            if (status && u.status !== status) return false;
            if (peran && u.peran !== peran) return false;

            if (login === 'sudah' && !u.last_login_at) return false;
            if (login === 'belum' && u.last_login_at) return false;
            if (login === '7hari' && !dalam7Hari(u.last_login_at)) return false;

            if (cari) {
                var hay = [
                    u.nama, u.username, u.email, u.instansi, u.peran
                ].join(' ').toLowerCase();
                if (hay.indexOf(cari) === -1) return false;
            }
            return true;
        });

        rows.sort(function (a, b) {
            var pa = angka(a.progres && a.progres.persen);
            var pb = angka(b.progres && b.progres.persen);

            if (urut === 'progres_asc')  return pa - pb || String(a.nama).localeCompare(String(b.nama));
            if (urut === 'login_desc') {
                var la = keWaktu(a.last_login_at);
                var lb = keWaktu(b.last_login_at);
                return (lb ? lb.getTime() : 0) - (la ? la.getTime() : 0) || String(a.nama).localeCompare(String(b.nama));
            }
            if (urut === 'login_asc') {
                var la2 = keWaktu(a.last_login_at);
                var lb2 = keWaktu(b.last_login_at);
                return (la2 ? la2.getTime() : 0) - (lb2 ? lb2.getTime() : 0) || String(a.nama).localeCompare(String(b.nama));
            }
            if (urut === 'nama_asc')     return String(a.nama).localeCompare(String(b.nama));
            if (urut === 'instansi_asc') return String(a.instansi).localeCompare(String(b.instansi)) || String(a.nama).localeCompare(String(b.nama));

            // Default: progres tertinggi
            return pb - pa || String(a.nama).localeCompare(String(b.nama));
        });

        return rows;
    }

    function pilModul(u) {
        var modul = (u.progres && u.progres.modul) || {};
        return Object.keys(MODUL).map(function (key) {
            var m = modul[key] || { selesai: 0, total: 0, persen: 0 };
            var kelas = m.persen >= 100 ? 'done' : (m.selesai > 0 ? 'partial' : 'empty');
            return '<span class="mod-pill ' + kelas + '" title="' + esc(MODUL[key].label) + '">' +
                '<i class="fas ' + MODUL[key].icon + '"></i> ' +
                esc(MODUL[key].label) + ' <b>' + angka(m.selesai) + '/' + angka(m.total) + '</b>' +
                '</span>';
        }).join('');
    }

    function htmlBaris(u) {
        var p = u.progres || {};
        var persen = angka(p.persen);

        var labelStatus = {
            aktif: 'Aktif',
            pending: 'Pending',
            nonaktif: 'Nonaktif'
        }[u.status] || (u.status || '—');
        var kelasStatus = 'badge-' + (u.status || 'unknown');

        var loginHtml;
        if (u.last_login_at) {
            var rel = relatif(u.last_login_at);
            loginHtml = '<span class="login-ok"><i class="fas fa-circle-check"></i> ' +
                esc(fmtWaktu(u.last_login_at)) + '</span>' +
                '<small>' + angka(u.login_count) + '× login' +
                (rel ? ' · ' + esc(rel) : '') + '</small>';
        } else {
            loginHtml = '<span class="login-never"><i class="fas fa-circle-minus"></i> Belum pernah</span>';
        }

        var aktivitas = u.aktivitas_terakhir
            ? fmtWaktu(u.aktivitas_terakhir) + (u.aktivitas_perkiraan ? ' (perkiraan)' : '')
            : '—';

        return '<tr>' +
            '<td>' +
                '<div class="monitor-user">' +
                    '<span class="monitor-avatar"><i class="fas fa-user"></i></span>' +
                    '<div>' +
                        '<span class="monitor-name">' + esc(u.nama) + '</span>' +
                        '<span class="monitor-sub">@' + esc(u.username) + ' · ' + esc(u.email) + '</span>' +
                    '</div>' +
                '</div>' +
            '</td>' +
            '<td><span class="monitor-instansi">' + esc(u.instansi || '—') + '</span>' +
                '<small>Terdaftar ' + esc(fmtTanggal(u.dibuat)) + '</small></td>' +
            '<td class="col-peran"><span class="type-badge type-muted">' + esc(u.peran || '—') + '</span></td>' +
            '<td class="col-akun"><span class="type-badge ' + kelasStatus + '">' + esc(labelStatus) + '</span>' +
                (u.otp_verifikasi_at ? '<small>OTP terverifikasi</small>' : '') + '</td>' +
            '<td>' + loginHtml + '</td>' +
            '<td><span class="monitor-instansi">' + esc(aktivitas) + '</span></td>' +
            '<td>' +
                '<div class="progress-wrap ' + kelasProgres(persen) + '">' +
                    '<div class="progress-top">' +
                        '<span>' + persen + '%</span>' +
                        '<small>' + angka(p.langkah_selesai) + '/' + angka(p.langkah_total) + ' langkah</small>' +
                    '</div>' +
                    '<div class="progress-track">' +
                        '<div class="progress-fill" style="width:' + Math.min(100, persen) + '%"></div>' +
                    '</div>' +
                '</div>' +
            '</td>' +
            '<td><div class="mod-pills">' + pilModul(u) + '</div></td>' +
            '<td>' +
                '<div class="monitor-actions">' +
                    '<button type="button" class="action-btn" data-detail="' + angka(u.id) + '" title="Detail pengguna">' +
                        '<i class="fas fa-eye"></i>' +
                    '</button>' +
                    '<button type="button" class="action-btn" data-dokumen="' + angka(u.id) + '" title="Dokumen yang dikerjakan">' +
                        '<i class="fas fa-folder-open"></i>' +
                    '</button>' +
                    (BISA_UBAH_PERAN
                        ? '<button type="button" class="action-btn" data-detail="' + angka(u.id) + '" title="Ubah peran">' +
                            '<i class="fas fa-user-shield"></i>' +
                          '</button>'
                        : '') +
                '</div>' +
            '</td>' +
        '</tr>';
    }

    function renderBaris() {
        if (!DATA) return;

        BARIS = barisTampil();
        var tbody = el('tbodyMonitoring');
        var kosong = el('monitorEmpty');

        if (!BARIS.length) {
            tbody.innerHTML = '';
            kosong.style.display = '';
            el('btnExport').disabled = true;
            return;
        }

        kosong.style.display = 'none';
        tbody.innerHTML = BARIS.map(htmlBaris).join('');
        el('btnExport').disabled = false;
    }

    // ------------------------------------------------------------
    // Ubah peran (khusus Admin Dinas / username admin)
    // ------------------------------------------------------------
    function cariPengguna(id) {
        var list = (DATA && DATA.pengguna) || [];
        for (var i = 0; i < list.length; i++) {
            if (Number(list[i].id) === Number(id)) return list[i];
        }
        return null;
    }

    function setRoleStatus(pesan, jenis) {
        var node = el('roleStatus');
        if (!node) return;
        node.className = 'role-status' + (jenis ? ' ' + jenis : '');
        node.textContent = pesan || '';
    }

    function htmlEditorPeran(u) {
        if (!BISA_UBAH_PERAN) return '';

        var terkunci = String(u.username) === 'admin'
            || (SAYA && Number(SAYA.id) === Number(u.id));

        var judul = '<div class="detail-role-head">' +
            '<span><i class="fas fa-user-shield"></i> Ubah peran pengguna</span>' +
            '</div>';

        if (terkunci) {
            var alasan = String(u.username) === 'admin'
                ? 'Akun admin utama tidak dapat diubah dari halaman ini.'
                : 'Peran akun sendiri tidak dapat diubah dari halaman ini.';
            return '<div class="detail-role">' + judul +
                '<p class="role-hint role-locked"><i class="fas fa-lock"></i> ' + esc(alasan) + '</p>' +
                '</div>';
        }

        var opsi = PERAN_TERSEDIA.map(function (p) {
            return '<option value="' + esc(p) + '"' +
                (p === u.peran ? ' selected' : '') + '>' + esc(p) + '</option>';
        }).join('');

        return '<div class="detail-role">' + judul +
            '<div class="role-editor">' +
                '<select id="selectPeranUbah" aria-label="Peran baru">' + opsi + '</select>' +
                '<button type="button" class="btn btn-primary btn-sm" data-simpan-peran="' + angka(u.id) + '">' +
                    '<i class="fas fa-floppy-disk"></i> Simpan Peran' +
                '</button>' +
            '</div>' +
            '<p class="role-hint">Perubahan berlaku saat pengguna membuka halaman berikutnya.</p>' +
            '<p class="role-status" id="roleStatus" role="status" aria-live="polite"></p>' +
            '</div>';
    }

    function simpanPeran(btn) {
        var id = Number(btn.getAttribute('data-simpan-peran'));
        var select = el('selectPeranUbah');
        var u = cariPengguna(id);

        if (!select || !u) return;

        var peranBaru = select.value;
        if (!peranBaru) {
            setRoleStatus('Pilih peran terlebih dahulu.', 'error');
            return;
        }
        if (peranBaru === u.peran) {
            setRoleStatus('Peran tidak berubah.', 'info');
            return;
        }
        if (!window.confirm('Ubah peran ' + u.nama + ' dari "' + u.peran + '" menjadi "' + peranBaru + '"?')) {
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
        setRoleStatus('Menyimpan perubahan...', 'info');

        fetch('../api/monitoring_peran.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ user_id: String(id), peran: peranBaru })
        })
            .then(function (res) {
                return res.json().catch(function () {
                    return { success: false, message: 'Respons server tidak valid.' };
                });
            })
            .then(function (d) {
                if (!d || !d.success) {
                    setRoleStatus((d && d.message) || 'Gagal mengubah peran.', 'error');
                    return;
                }

                u.peran = (d.user && d.user.peran) || peranBaru;
                if (SAYA && Number(SAYA.id) === Number(u.id)) SAYA.peran = u.peran;

                isiFilterPeran(DATA.pengguna || []);
                renderBaris();

                el('modalSubtitle').textContent =
                    '@' + u.username + ' · ' + (u.instansi || '—') + ' · ' + (u.peran || '—');
                setRoleStatus((d.message || ('Peran berhasil diubah menjadi ' + u.peran + '.')), 'success');
            })
            .catch(function (err) {
                console.error('Monitoring: gagal mengubah peran', err);
                setRoleStatus('Gagal menghubungi server. Coba lagi.', 'error');
            })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-floppy-disk"></i> Simpan Peran';
            });
    }

    // ------------------------------------------------------------
    // Modal detail
    // ------------------------------------------------------------
    function bukaDetail(u, fokusDokumen) {
        var p = u.progres || {};
        var modul = p.modul || {};
        var rincian = p.rincian || [];
        var persen = angka(p.persen);

        el('modalTitle').textContent = u.nama;
        el('modalSubtitle').textContent =
            '@' + u.username + ' · ' + (u.instansi || '—') + ' · ' + (u.peran || '—');

        var html = '<div class="detail-grid">' +
            '<div class="detail-item"><span>Status akun</span><b>' + esc(u.status || '—') + '</b></div>' +
            '<div class="detail-item"><span>Email</span><b>' + esc(u.email || '—') + '</b></div>' +
            '<div class="detail-item"><span>NIP</span><b>' + esc(u.nip || '—') + '</b></div>' +
            '<div class="detail-item"><span>Terdaftar</span><b>' + esc(fmtWaktu(u.dibuat)) + '</b></div>' +
            '<div class="detail-item"><span>Login terakhir</span><b>' +
                (u.last_login_at ? esc(fmtWaktu(u.last_login_at)) : 'Belum pernah') + '</b></div>' +
            '<div class="detail-item"><span>Jumlah login</span><b>' + angka(u.login_count) + '×</b></div>' +
            '<div class="detail-item"><span>Aktivitas terakhir</span><b>' +
                esc(u.aktivitas_terakhir ? fmtWaktu(u.aktivitas_terakhir) : '—') + '</b></div>' +
            '<div class="detail-item"><span>IP terakhir</span><b>' + esc(u.last_ip || '—') + '</b></div>' +
        '</div>';

        html += htmlEditorPeran(u);

        html += '<div class="detail-progress-head"><b>Progres keseluruhan</b><span>' + persen + '%</span></div>' +
            '<div class="progress-track big"><div class="progress-fill" style="width:' +
            Math.min(100, persen) + '%"></div></div>';

        Object.keys(MODUL).forEach(function (key) {
            var m = modul[key] || { selesai: 0, total: 0, persen: 0 };
            html += '<div class="detail-modul">' +
                '<div class="detail-modul-head">' +
                    '<span><i class="fas ' + MODUL[key].icon + '"></i> ' + esc(MODUL[key].label) + '</span>' +
                    '<b>' + angka(m.selesai) + '/' + angka(m.total) + ' · ' + angka(m.persen) + '%</b>' +
                '</div>' +
                '<ul class="detail-langkah">';

            rincian.filter(function (x) { return x.modul === key; }).forEach(function (x) {
                html += '<li class="' + (x.selesai ? 'done' : '') + '">' +
                    '<i class="fas ' + (x.selesai ? 'fa-circle-check' : 'fa-circle') + '"></i>' +
                    '<span>' + esc(x.label) + '</span>' +
                    '<b>' + angka(x.count) + '</b>' +
                '</li>';
            });

            html += '</ul></div>';
        });

        // Hasil dokumen yang benar-benar dikerjakan akun ini (dimuat terpisah
        // karena bisa berisi banyak baris dan konten dokumen).
        html += '<div class="detail-dokumen" id="detailDokumen">' +
            '<div class="detail-dokumen-head">' +
                '<span><i class="fas fa-folder-open"></i> Hasil Dokumen yang Dikerjakan</span>' +
            '</div>' +
            '<div id="detailDokumenBody"></div>' +
        '</div>';

        el('modalBody').innerHTML = html;
        el('modalDetail').hidden = false;
        document.body.classList.add('modal-open');

        muatDokumen(u.id);

        if (fokusDokumen) {
            requestAnimationFrame(function () {
                var bagian = el('detailDokumen');
                if (bagian) bagian.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }
    }

    function tutupDetail() {
        el('modalDetail').hidden = true;
        document.body.classList.remove('modal-open');
    }

    // ------------------------------------------------------------
    // Unduh CSV (mengikuti filter yang sedang tampil)
    // ------------------------------------------------------------
    function modulTeks(m) {
        if (!m) return '0/0';
        return angka(m.selesai) + '/' + angka(m.total);
    }

    function unduhCsv() {
        if (!BARIS.length) return;

        var head = [
            'Nama', 'Username', 'Email', 'Instansi', 'Peran', 'Status',
            'Login Terakhir', 'Jumlah Login', 'Aktivitas Terakhir',
            'Progres (%)', 'Penerimaan', 'Belanja', 'Akuntansi', 'Dokumen'
        ];

        var lines = [head];
        BARIS.forEach(function (u) {
            var p = u.progres || {};
            var m = p.modul || {};
            lines.push([
                u.nama || '',
                u.username || '',
                u.email || '',
                u.instansi || '',
                u.peran || '',
                u.status || '',
                u.last_login_at ? fmtWaktu(u.last_login_at) : 'Belum pernah',
                angka(u.login_count),
                u.aktivitas_terakhir ? fmtWaktu(u.aktivitas_terakhir) : '',
                angka(p.persen),
                modulTeks(m.penerimaan),
                modulTeks(m.belanja),
                modulTeks(m.akuntansi),
                modulTeks(m.dokumen)
            ]);
        });

        var csv = lines.map(function (row) {
            return row.map(function (v) {
                return '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
            }).join(',');
        }).join('\r\n');

        var blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'monitoring-simtkd-' + new Date().toISOString().slice(0, 10) + '.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    // ------------------------------------------------------------
    // Akses & pemuatan data
    // ------------------------------------------------------------
    function tampilkanDitolak(pesan) {
        el('monitorContent').style.display = 'none';
        el('aksesDitolak').style.display = '';
        if (pesan) el('aksesDitolakPesan').textContent = pesan;
        el('btnExport').disabled = true;
    }

    function load() {
        var btn = el('btnRefresh');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memuat...';

        fetch('../api/session.php', { cache: 'no-store' })
            .then(function (res) { return res.json(); })
            .then(function (sesi) {
                if (!sesi || !sesi.success) {
                    window.location.href = '../login.html';
                    return null;
                }

                var u = sesi.user || {};
                SAYA = u;

                var boleh = ['Admin Dinas', 'Kepala Dinas', 'Dosen'].indexOf(u.peran) !== -1
                    || u.username === 'admin';

                if (!boleh) {
                    tampilkanDitolak('Halaman Monitoring Pengguna hanya dapat diakses oleh akun admin/dosen.');
                    return null;
                }

                BISA_UBAH_PERAN = u.peran === 'Admin Dinas' || u.username === 'admin';

                return fetch('../api/monitoring.php', { cache: 'no-store' })
                    .then(function (res) { return res.json(); });
            })
            .then(function (d) {
                if (!d) return;

                if (!d.success) {
                    tampilkanDitolak(d.message || 'Gagal mengakses data monitoring.');
                    return;
                }

                DATA = d;

                if (d.meta && Array.isArray(d.meta.peran_tersedia) && d.meta.peran_tersedia.length) {
                    PERAN_TERSEDIA = d.meta.peran_tersedia;
                }
                if (d.meta && d.meta.bisa_ubah_peran === false) {
                    BISA_UBAH_PERAN = false;
                }

                el('aksesDitolak').style.display = 'none';
                el('monitorContent').style.display = '';
                el('peringatanKolom').style.display =
                    (d.meta && d.meta.ada_kolom_login === false) ? '' : 'none';

                renderRingkasan(d.ringkasan || {});
                isiFilterPeran(d.pengguna || []);
                renderBaris();

                el('lastUpdated').textContent = 'Diperbarui pukul ' + jamSekarang();
            })
            .catch(function (err) {
                console.error('Monitoring: gagal memuat data', err);
                el('tbodyMonitoring').innerHTML =
                    '<tr><td colspan="9" style="text-align:center;color:#b91c1c;padding:36px;">' +
                    'Gagal memuat data. Periksa koneksi ke server lalu klik Muat Ulang.</td></tr>';
                el('btnExport').disabled = true;
            })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-sync-alt"></i> Muat Ulang';
            });
    }

    // ------------------------------------------------------------
    // Inisialisasi
    // ------------------------------------------------------------
    function init() {
        el('filterCari').addEventListener('input', renderBaris);
        ['filterStatus', 'filterLogin', 'filterPeran', 'filterUrut'].forEach(function (id) {
            el(id).addEventListener('change', renderBaris);
        });

        el('btnRefresh').addEventListener('click', load);
        el('btnExport').addEventListener('click', unduhCsv);

        el('tbodyMonitoring').addEventListener('click', function (e) {
            var btn = e.target.closest('[data-detail], [data-dokumen]');
            if (!btn || !DATA) return;

            var fokusDokumen = btn.hasAttribute('data-dokumen');
            var id = Number(btn.getAttribute(fokusDokumen ? 'data-dokumen' : 'data-detail'));
            var u = (DATA.pengguna || []).filter(function (x) {
                return Number(x.id) === id;
            })[0];

            if (u) bukaDetail(u, fokusDokumen);
        });

        el('modalBody').addEventListener('click', function (e) {
            var btn = e.target.closest('[data-simpan-peran]');
            if (btn) {
                simpanPeran(btn);
                return;
            }

            var pv = e.target.closest('[data-pratinjau]');
            if (!pv) return;

            var pemilik = Number(pv.getAttribute('data-pemilik')) || DOKUMEN_AKTIF;
            bukaPratinjau(pemilik, Number(pv.getAttribute('data-pratinjau')));
        });

        document.querySelectorAll('[data-close]').forEach(function (node) {
            node.addEventListener('click', tutupDetail);
        });

        document.querySelectorAll('[data-close-pratinjau]').forEach(function (node) {
            node.addEventListener('click', tutupPratinjau);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            // Tutup lapisan paling atas lebih dulu.
            if (!el('modalPratinjau').hidden) {
                tutupPratinjau();
                return;
            }
            if (!el('modalDetail').hidden) tutupDetail();
        });

        load();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
