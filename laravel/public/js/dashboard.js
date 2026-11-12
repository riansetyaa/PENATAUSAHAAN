/**
 * SIM-TKD - Dashboard Scripts
 * ============================================
 */

(function () {
    'use strict';

    // ==========================================
    // DOM Elements
    // ==========================================
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const topbarToggle = document.getElementById('topbarToggle');
    const overlay = document.getElementById('overlay');
    const currentDateEl = document.getElementById('currentDate');
    const toolBtns = document.querySelectorAll('.tool-btn');
    const menuItems = document.querySelectorAll('.menu-item');

    // Token API user (diisi saat sesi berhasil) -> untuk link modul AKLAP
    let SESSION_API_TOKEN = '';

    // ==========================================
    // Sidebar collapsed state (persisten antar halaman)
    // ==========================================
    const SIDEBAR_KEY = 'simtkd_sidebar_collapsed';
    function sidebarSimpan() {
        try {
            localStorage.setItem(SIDEBAR_KEY, sidebar.classList.contains('collapsed') ? '1' : '0');
        } catch (e) { /* localStorage tidak tersedia */ }
    }
    function sidebarMuat() {
        try {
            if (localStorage.getItem(SIDEBAR_KEY) === '1') sidebar.classList.add('collapsed');
        } catch (e) { /* localStorage tidak tersedia */ }
    }
    sidebarMuat(); // terapkan segera agar sidebar tidak "melebar lagi" saat pindah halaman

    // ==========================================
    // Format Date (Indonesian)
    // ==========================================
    function formatDate(date) {
        const months = [
            'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
        ];
        const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

        const dayName = days[date.getDay()];
        const day = date.getDate();
        const month = months[date.getMonth()];
        const year = date.getFullYear();

        return `${dayName}, ${day} ${month} ${year}`;
    }

    // ==========================================
    // Sidebar Toggle (desktop)
    // ==========================================
    sidebarToggle.addEventListener('click', function () {
        sidebar.classList.toggle('collapsed');
        sidebarSimpan();
        // Remove open state if any
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
    });

    // ==========================================
    // Mobile sidebar open
    // ==========================================
    function openMobileSidebar() {
        sidebar.classList.remove('collapsed');
        sidebar.classList.add('open');
        overlay.classList.add('show');
    }

    topbarToggle.addEventListener('click', function () {
        if (window.innerWidth <= 768) {
            openMobileSidebar();
        } else {
            sidebar.classList.toggle('collapsed');
            sidebarSimpan();
        }
    });

    // Close sidebar on overlay click
    overlay.addEventListener('click', function () {
        sidebar.classList.remove('open');
        this.classList.remove('show');
    });

    // ==========================================
    // Sidebar Submenu (dropdown) toggle
    // ==========================================
    document.querySelectorAll('.menu-group > .has-submenu').forEach(function (item) {
        item.addEventListener('click', function (e) {
            e.preventDefault();
            const group = this.parentElement;

            // Tutup group lain di level yang sama
            const siblings = group.parentElement.querySelectorAll(':scope > .menu-group.open');
            siblings.forEach(function (s) {
                if (s !== group) s.classList.remove('open');
            });

            group.classList.toggle('open');

            // Jangan tutup sidebar mobile saat membuka submenu
        });
    });

    // ==========================================
    // Menu item active state
    // ==========================================
    menuItems.forEach(function (item) {
        // Menu dengan submenu ditangani oleh handler toggle di atas
        if (item.classList.contains('has-submenu')) return;

        item.addEventListener('click', function (e) {
            // Link yang mengarah ke halaman nyata (bukan #) biarkan navigasi normal
            const href = this.getAttribute('href');
            if (href && href !== '#') return;

            e.preventDefault();
            // Highlight active
            menuItems.forEach(function (i) {
                if (!i.classList.contains('has-submenu')) i.classList.remove('active');
            });
            this.classList.add('active');

            // Close mobile sidebar
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('open');
                overlay.classList.remove('show');
            }
        });
    });

    // ==========================================
    // Chart Tool Buttons
    // ==========================================
    toolBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            toolBtns.forEach(function (b) {
                b.classList.remove('active');
            });
            this.classList.add('active');
            // Could update chart data per year here
            updateChart(this.textContent.trim());
        });
    });

    // ==========================================
    // APBD Chart
    // ==========================================
    let apbdChart = null;

    // Data grafik dari backend (window.SIMTKD_CHART)
    const backendChartData = window.SIMTKD_CHART || null;

    function formatRupiah(value) {
        return 'Rp ' + value.toLocaleString('id-ID');
    }

    // Bangun data chart dari backend (per tahun). Mengembalikan null jika kosong.
    function buildChartData() {
        if (backendChartData && Object.keys(backendChartData).length > 0) {
            const labels = Object.keys(backendChartData).sort();
            return {
                labels: labels,
                pagu: labels.map(function (y) {
                    return (backendChartData[y].pagu || 0) / 1000000;
                }),
                realisasi: labels.map(function (y) {
                    return (backendChartData[y].realisasi || 0) / 1000000;
                }),
                unit: 'Jt'
            };
        }

        return null; // tidak ada data
    }

    // Tampilkan pesan "belum ada data" pada area grafik
    function showChartEmpty() {
        const ctx = document.getElementById('apbdChart');
        const legend = document.getElementById('chartLegend');
        if (!ctx) return;

        ctx.style.display = 'none';
        if (legend) legend.style.display = 'none';

        let empty = document.getElementById('chartEmpty');
        if (!empty) {
            empty = document.createElement('div');
            empty.id = 'chartEmpty';
            empty.className = 'chart-empty';
            empty.textContent = 'Belum ada data kegiatan untuk ditampilkan.';
            ctx.parentNode.appendChild(empty);
        }
    }

    function hideChartEmpty() {
        const ctx = document.getElementById('apbdChart');
        const legend = document.getElementById('chartLegend');
        if (ctx) ctx.style.display = 'block';
        if (legend) legend.style.display = '';
        const empty = document.getElementById('chartEmpty');
        if (empty) empty.remove();
    }

    function initChart(year) {
        const ctx = document.getElementById('apbdChart');
        if (!ctx) return;

        const data = buildChartData();

        if (apbdChart) {
            apbdChart.destroy();
            apbdChart = null;
        }

        // Jika tidak ada data, tampilkan pesan kosong
        if (!data) {
            showChartEmpty();
            return;
        }

        hideChartEmpty();

        apbdChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [
                    {
                        label: 'Pagu',
                        data: data.pagu,
                        backgroundColor: 'rgba(26, 58, 107, 0.85)',
                        borderColor: '#1a3a6b',
                        borderWidth: 1,
                        borderRadius: 4,
                        barPercentage: 0.5,
                        categoryPercentage: 0.6
                    },
                    {
                        label: 'Realisasi',
                        data: data.realisasi,
                        backgroundColor: 'rgba(245, 130, 32, 0.85)',
                        borderColor: '#f58220',
                        borderWidth: 1,
                        borderRadius: 4,
                        barPercentage: 0.5,
                        categoryPercentage: 0.6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.dataset.label + ': ' + formatRupiah(context.parsed.y * 1000000);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#5a6c7d',
                            font: {
                                size: 11
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#eef1f6'
                        },
                        ticks: {
                            color: '#5a6c7d',
                            font: {
                                size: 11
                            },
                            callback: function (value) {
                                return value + ' ' + data.unit;
                            }
                        }
                    }
                }
            }
        });
    }

    function updateChart(year) {
        initChart(year);
    }

    // ==========================================
    // Toast Notification
    // ==========================================
    function showToast(message, type) {
        const toast = document.createElement('div');
        toast.className = 'toast toast-' + (type || 'success');
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-times-circle',
            warning: 'fa-exclamation-triangle'
        };
        const span = document.createElement('span');
        // textContent (bukan innerHTML) agar pesan dari API tidak bisa menyuntik HTML (XSS)
        span.textContent = message;
        const icon = document.createElement('i');
        icon.className = 'fas ' + (icons[type] || icons.success);
        toast.appendChild(icon);
        toast.appendChild(span);
        document.body.appendChild(toast);
        setTimeout(function () {
            toast.remove();
        }, 3000);
    }

    // ==========================================
    // Notification click
    // ==========================================
    const notifBtn = document.querySelector('.topbar-item.notification');
    if (notifBtn) {
        notifBtn.addEventListener('click', function () {
            showToast('Anda memiliki 3 notifikasi baru.', 'warning');
        });
    }

    // ==========================================
    // Logout (konfirmasi)
    // ==========================================
    const logoutLink = document.getElementById('logoutLink');
    if (logoutLink) {
        logoutLink.addEventListener('click', function (e) {
            e.preventDefault();
            if (confirm('Yakin ingin keluar dari aplikasi?')) {
                window.location.href = this.getAttribute('href');
            }
        });
    }

    // ==========================================
    // Helper - format angka & rupiah
    // ==========================================
    function formatRupiah(value) {
        return 'Rp ' + Number(value || 0).toLocaleString('id-ID');
    }

    function formatNumber(value) {
        return Number(value || 0).toLocaleString('id-ID');
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str || '');
        return div.innerHTML;
    }

    // ==========================================
    // Populate dashboard dari data backend
    // ==========================================
    function populateSummary(data) {
        const user = data.user || {};
        const summary = data.summary || {};
        const tahun = data.tahun || new Date().getFullYear();
        const persen = summary.persen || 0;

        // Info pengguna
        if (user.nama) {
            [document.getElementById('sidebarUserName'), document.getElementById('topbarUserName')]
                .forEach(function (el) { if (el) el.textContent = user.nama; });
        }
        if (user.instansi) {
            const roleEl = document.getElementById('sidebarUserRole');
            if (roleEl) roleEl.textContent = user.instansi;
        }

        // Judul halaman (khusus dashboard; halaman lain punya judul sendiri)
        const isDashboard = !!document.getElementById('cardBelanjaValue');
        const pageTitle = document.getElementById('pageTitle');
        if (isDashboard && pageTitle && user.instansi) {
            pageTitle.textContent = 'Ringkasan APBD ' + user.instansi;
        }
        const pageSubtitle = document.getElementById('pageSubtitle');
        if (pageSubtitle) pageSubtitle.textContent = 'Tahun Anggaran ' + tahun;

        // Kartu: Belanja Daerah
        const belanjaValue = document.getElementById('cardBelanjaValue');
        if (belanjaValue) belanjaValue.textContent = formatRupiah(summary.total_realisasi);
        const belanjaTrend = document.getElementById('cardBelanjaTrend');
        if (belanjaTrend) {
            belanjaTrend.innerHTML = '<i class="fas fa-arrow-up"></i> ' +
                persen.toFixed(2).replace('.', ',') + '% <span>dari pagu</span>';
        }

        // Kartu: Pagu Anggaran
        const paguValue = document.getElementById('cardPaguValue');
        if (paguValue) paguValue.textContent = formatRupiah(summary.total_pagu);
        const paguTrend = document.getElementById('cardPaguTrend');
        if (paguTrend) {
            paguTrend.innerHTML = '<i class="fas fa-arrow-up"></i> ' +
                (100 - persen).toFixed(2).replace('.', ',') + '% <span>sisa pagu</span>';
        }

        // Kartu: Jumlah Kegiatan
        const kegiatanValue = document.getElementById('cardKegiatanValue');
        if (kegiatanValue) kegiatanValue.textContent = (summary.jumlah_kegiatan || 0) + ' Kegiatan';
        const kegiatanTrend = document.getElementById('cardKegiatanTrend');
        if (kegiatanTrend) {
            kegiatanTrend.innerHTML = '<i class="fas fa-arrow-down"></i> ' + tahun + ' <span>tahun berjalan</span>';
        }

        // Kartu: Modul Penerimaan & Belanja (data lintas database)
        const modul = data.modul || {};
        const penerimaan = modul.penerimaan || {};
        const belanjaMod = modul.belanja || {};
        const stbpValue = document.getElementById('cardStbpValue');
        if (stbpValue) stbpValue.textContent = formatRupiah(penerimaan.stbp);
        const stsValue = document.getElementById('cardStsValue');
        if (stsValue) stsValue.textContent = formatRupiah(penerimaan.sts);
        const sp2dValue = document.getElementById('cardSp2dValue');
        if (sp2dValue) sp2dValue.textContent = formatRupiah(belanjaMod.sp2d_dicairkan);

        // Ring progres jadwal
        const ring = document.getElementById('progressRing');
        if (ring) ring.style.setProperty('--p', Math.round(persen));
        const progressValue = document.getElementById('progressValue');
        if (progressValue) progressValue.textContent = persen.toFixed(0) + '%';
        const scheduleTitle = document.getElementById('scheduleTitle');
        if (scheduleTitle) scheduleTitle.textContent = 'Realisasi APBD ' + tahun;
        const statRealisasi = document.getElementById('statRealisasi');
        if (statRealisasi) statRealisasi.textContent = persen.toFixed(1).replace('.', ',') + '%';
        const statPagu = document.getElementById('statPagu');
        if (statPagu) statPagu.textContent = formatRupiah(summary.total_pagu);

        // Tabel
        const tableTitleYear = document.getElementById('tableTitleYear');
        if (tableTitleYear) tableTitleYear.textContent = tahun;
        populateKegiatanTable(data.kegiatan || []);

        // Grafik
        window.SIMTKD_CHART = data.chart || null;
        window.SIMTKD_TAHUN = tahun;
        buildChartTools(data.chart || {}, tahun);
        initChart(tahun);
    }

    // ==========================================
    // Isi tabel kegiatan
    // ==========================================
    function populateKegiatanTable(items) {
        const tbody = document.getElementById('kegiatanTableBody');
        if (!tbody) return;

        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#9098a5;">Belum ada data kegiatan.</td></tr>';
            return;
        }

        tbody.innerHTML = items.map(function (k, i) {
            const persen = k.pagu > 0 ? ((k.realisasi / k.pagu) * 100) : 0;
            const statusClass = k.status === 'selesai' ? 'type-in' : (k.status === 'batal' ? 'type-warn' : 'type-out');
            const statusLabel = k.status.charAt(0).toUpperCase() + k.status.slice(1);
            return '<tr>' +
                '<td>' + (i + 1) + '</td>' +
                '<td>' + escapeHtml(k.nama_kegiatan) + '</td>' +
                '<td class="num">' + formatNumber(k.pagu) + '</td>' +
                '<td class="num">' + formatNumber(k.realisasi) + '</td>' +
                '<td class="num">' + persen.toFixed(2).replace('.', ',') + '%</td>' +
                '<td><span class="type-badge ' + statusClass + '">' + statusLabel + '</span></td>' +
                '</tr>';
        }).join('');
    }

    // ==========================================
    // Bangun tombol tahun grafik dari backend
    // ==========================================
    function buildChartTools(chart, activeYear) {
        const container = document.getElementById('chartTools');
        if (!container) return;

        const years = Object.keys(chart || {}).sort().reverse();

        // Kosongkan jika tidak ada data
        if (!years.length) {
            container.innerHTML = '';
            return;
        }

        container.innerHTML = years.map(function (y) {
            const cls = String(y) === String(activeYear) ? 'tool-btn active' : 'tool-btn';
            return '<button type="button" class="' + cls + '" data-tahun="' + y + '">' + y + '</button>';
        }).join('');

        container.querySelectorAll('.tool-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                container.querySelectorAll('.tool-btn').forEach(function (b) { b.classList.remove('active'); });
                this.classList.add('active');
            });
        });
    }

    // ==========================================
    // Muat data dashboard (cek sesi + ringkasan)
    // ==========================================
    function loadDashboard() {
        // 1. Cek sesi terlebih dahulu
        fetch('../api/session.php')
            .then(function (res) { return res.json(); })
            .then(function (sessionData) {
                if (!sessionData.success) {
                    window.location.href = '../login.html';
                    return null;
                }
                // Kirim token API user ke link modul AKLAP (multi-tenant)
                var apiToken = sessionData.user && sessionData.user.api_token;
                SESSION_API_TOKEN = apiToken || '';
                if (SESSION_API_TOKEN) {
                    document.querySelectorAll('a[href*="peta.simtkd.com"]').forEach(function (a) {
                        var sep = (a.href.indexOf('?') === -1) ? '?' : '&';
                        a.href = a.href + sep + 'token=' + encodeURIComponent(SESSION_API_TOKEN);
                    });
                }
                // 2. Ambil data ringkasan
                return fetch('../api/summary.php').then(function (res) { return res.json(); });
            })
            .then(function (data) {
                if (data && data.success) {
                    populateSummary(data);
                }
            })
            .catch(function (err) {
                console.warn('Gagal memuat data backend. Pastikan diakses via http://localhost (XAMPP):', err);
            });
    }

    // ==========================================
    // Initialize
    // ==========================================
    function init() {
        // Set current date
        if (currentDateEl) {
            currentDateEl.textContent = formatDate(new Date());
        }

        // Muat data dari backend (cek sesi + ringkasan)
        loadDashboard();

        // Jaring pengaman: tambahkan token saat link AKLAP diklik
        // bila belum sempat disisipkan saat halaman dimuat.
        document.addEventListener('click', function (e) {
            var a = e.target && e.target.closest ? e.target.closest('a[href*="peta.simtkd.com"]') : null;
            if (!a || !SESSION_API_TOKEN) return;
            if (a.href.indexOf('token=') !== -1) return;
            var sep = (a.href.indexOf('?') === -1) ? '?' : '&';
            a.href = a.href + sep + 'token=' + encodeURIComponent(SESSION_API_TOKEN);
        }, true);

        // Log
        console.log('SIM-TKD Dashboard - Ready');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
