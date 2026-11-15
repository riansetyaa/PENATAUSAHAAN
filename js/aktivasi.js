/**
 * SIM-TKD - Aktivasi Akun (Verifikasi Email via Kode OTP)
 * ============================================
 * Menghubungkan aktivasi.html dengan api/aktivasi.php:
 *   1. Langkah 1 : masukkan email terdaftar         -> action=cek
 *   2. Langkah 2 : periksa nama akun & instansi,
 *                  klik Konfirmasi                  -> action=kirim_otp
 *   3. Langkah 3 : masukkan kode OTP 6 angka        -> action=verifikasi
 * Setelah kode benar, akun berstatus 'aktif' dan pengguna bisa login.
 */

(function () {
    'use strict';

    // ==========================================
    // DOM Elements
    // ==========================================
    const stepBar = document.getElementById('stepBar');
    const step1 = document.getElementById('step1');
    const step2 = document.getElementById('step2');
    const step3 = document.getElementById('step3');
    const stepSelesai = document.getElementById('stepSelesai');

    const formEmail = document.getElementById('formEmail');
    const emailInput = document.getElementById('emailAktivasi');
    const errEmailAktivasi = document.getElementById('errEmailAktivasi');
    const btnCekEmail = document.getElementById('btnCekEmail');
    const loadingCek = document.getElementById('loadingCek');

    const infoNama = document.getElementById('infoNama');
    const infoInstansi = document.getElementById('infoInstansi');
    const infoUsername = document.getElementById('infoUsername');
    const infoEmail = document.getElementById('infoEmail');
    const infoWilayah = document.getElementById('infoWilayah');
    const infoPeran = document.getElementById('infoPeran');
    const btnKonfirmasi = document.getElementById('btnKonfirmasi');
    const loadingKirim = document.getElementById('loadingKirim');

    const formOtp = document.getElementById('formOtp');
    const otpInput = document.getElementById('otpInput');
    const errOtp = document.getElementById('errOtp');
    const otpEmail = document.getElementById('otpEmail');
    const otpTimer = document.getElementById('otpTimer');
    const btnVerifikasi = document.getElementById('btnVerifikasi');
    const loadingVerifikasi = document.getElementById('loadingVerifikasi');
    const btnKirimUlang = document.getElementById('btnKirimUlang');
    const lblKirimUlang = document.getElementById('lblKirimUlang');
    const btnGantiEmail = document.getElementById('btnGantiEmail');
    const btnUbahEmail = document.getElementById('btnUbahEmail');
    const pesanSelesai = document.getElementById('pesanSelesai');

    const toastContainer = document.getElementById('toastContainer');

    // State
    let emailTerkonfirmasi = '';
    let timerBerlaku = null;
    let timerKirimUlang = null;
    let sisaBerlaku = 0;
    let sisaKirimUlang = 0;

    // ==========================================
    // Toast Notifikasi
    // ==========================================
    function showToast(message, type) {
        const toast = document.createElement('div');
        toast.className = 'toast toast-' + (type || 'success');

        const icons = {
            success: 'fa-check-circle',
            error: 'fa-times-circle',
            warning: 'fa-exclamation-triangle'
        };

        // textContent (bukan innerHTML) agar pesan dari API tidak bisa menyuntik HTML (XSS)
        const span = document.createElement('span');
        span.textContent = message;
        const icon = document.createElement('i');
        icon.className = 'fas ' + (icons[type] || icons.success);
        toast.appendChild(icon);
        toast.appendChild(span);
        toastContainer.appendChild(toast);

        setTimeout(function () {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 3600);
    }

    // ==========================================
    // Helper - pesan kesalahan pada field
    // ==========================================
    function setError(input, errEl, message) {
        input.style.borderColor = '#e74c3c';
        if (errEl) errEl.textContent = message;
    }

    function clearError(input, errEl) {
        input.style.borderColor = '';
        if (errEl) errEl.textContent = '';
    }

    function isValidEmail(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    }

    // ==========================================
    // Navigasi antar langkah
    // ==========================================
    function tampilStep(nomor) {
        const peta = { '1': step1, '2': step2, '3': step3, 'selesai': stepSelesai };

        Object.keys(peta).forEach(function (key) {
            peta[key].style.display = key === String(nomor) ? 'block' : 'none';
        });

        // Indikator langkah (langkah yang sudah dilalui ditandai centang)
        const nomorAktif = nomor === 'selesai' ? 4 : parseInt(nomor, 10);
        stepBar.querySelectorAll('.step-item').forEach(function (item) {
            const step = parseInt(item.getAttribute('data-step'), 10);
            item.classList.toggle('active', step === nomorAktif);
            item.classList.toggle('done', step < nomorAktif);
            item.querySelector('.step-num').textContent = step < nomorAktif ? '\u2713' : String(step);
        });

        stepBar.style.display = nomor === 'selesai' ? 'none' : 'flex';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function formatDurasi(detik) {
        const m = Math.floor(detik / 60);
        const s = detik % 60;
        return m + ':' + (s < 10 ? '0' + s : s);
    }

    // ==========================================
    // Timer masa berlaku kode OTP
    // ==========================================
    function mulaiTimerBerlaku(detik) {
        hentikanTimerBerlaku();
        sisaBerlaku = Math.max(0, parseInt(detik, 10) || 0);
        renderTimerBerlaku();

        timerBerlaku = setInterval(function () {
            sisaBerlaku -= 1;
            renderTimerBerlaku();
            if (sisaBerlaku <= 0) {
                hentikanTimerBerlaku();
                otpTimer.innerHTML = '<i class="fas fa-clock"></i> Kode OTP sudah kedaluwarsa. Klik "Kirim ulang kode".';
                hentikanTimerKirimUlang();
                sisaKirimUlang = 0;
                renderTimerKirimUlang();
                btnKirimUlang.disabled = false;
            }
        }, 1000);
    }

    function renderTimerBerlaku() {
        if (sisaBerlaku <= 0) return;
        otpTimer.innerHTML = '<i class="fas fa-clock"></i> Kode berlaku <strong>'
            + formatDurasi(sisaBerlaku) + '</strong> lagi.';
    }

    function hentikanTimerBerlaku() {
        if (timerBerlaku) {
            clearInterval(timerBerlaku);
            timerBerlaku = null;
        }
    }

    // ==========================================
    // Timer jeda kirim ulang (60 detik, sama dengan API)
    // ==========================================
    function mulaiTimerKirimUlang(detik) {
        hentikanTimerKirimUlang();
        sisaKirimUlang = Math.max(0, parseInt(detik, 10) || 0);
        btnKirimUlang.disabled = true;
        renderTimerKirimUlang();

        timerKirimUlang = setInterval(function () {
            sisaKirimUlang -= 1;
            renderTimerKirimUlang();
            if (sisaKirimUlang <= 0) {
                hentikanTimerKirimUlang();
                btnKirimUlang.disabled = false;
            }
        }, 1000);
    }

    function renderTimerKirimUlang() {
        lblKirimUlang.textContent = sisaKirimUlang > 0
            ? 'Kirim ulang kode (' + sisaKirimUlang + ')'
            : 'Kirim ulang kode';
    }

    function hentikanTimerKirimUlang() {
        if (timerKirimUlang) {
            clearInterval(timerKirimUlang);
            timerKirimUlang = null;
        }
    }

    // ==========================================
    // Panggil API aktivasi
    // ==========================================
    function panggilAktivasi(action, params) {
        const body = new URLSearchParams(Object.assign({ action: action }, params || {}));

        return fetch('api/aktivasi.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body
        }).then(function (res) {
            return res.json();
        });
    }

    // ==========================================
    // Tampilkan data akun (nama & instansi) di langkah 2
    // ==========================================
    function isiInfoAkun(user) {
        user = user || {};

        infoNama.textContent = user.nama_lengkap || '-';
        infoInstansi.textContent = user.instansi && user.instansi !== '' ? user.instansi : '-';

        const wilayah = [user.kota, user.provinsi].filter(function (v) {
            return v && v !== '';
        }).join(', ');

        infoUsername.textContent = user.username || '-';
        infoEmail.textContent = user.email_samar || '-';
        infoWilayah.textContent = wilayah !== '' ? wilayah : '-';
        infoPeran.textContent = user.peran || '-';
    }

    /** Kembali ke langkah 1 (ganti/ubah email). */
    function kembaliKeEmail() {
        hentikanTimerBerlaku();
        hentikanTimerKirimUlang();
        otpInput.value = '';
        clearError(otpInput, errOtp);
        tampilStep(1);
        emailInput.focus();
    }

    // ==========================================
    // Langkah 1 - Cek akun berdasarkan email
    // ==========================================
    formEmail.addEventListener('submit', function (e) {
        e.preventDefault();

        const email = emailInput.value.trim().toLowerCase();
        clearError(emailInput, errEmailAktivasi);

        if (!email) {
            setError(emailInput, errEmailAktivasi, 'Email wajib diisi.');
            showToast('Email wajib diisi.', 'warning');
            return;
        }

        if (!isValidEmail(email)) {
            setError(emailInput, errEmailAktivasi, 'Format email tidak valid.');
            showToast('Format email tidak valid.', 'error');
            return;
        }

        btnCekEmail.disabled = true;
        btnCekEmail.style.display = 'none';
        loadingCek.style.display = 'flex';

        panggilAktivasi('cek', { email: email })
            .then(function (data) {
                loadingCek.style.display = 'none';
                btnCekEmail.style.display = 'flex';
                btnCekEmail.disabled = false;

                if (!data.success) {
                    showToast(data.message || 'Data akun tidak ditemukan.', 'error');
                    if (data.field === 'email') {
                        setError(emailInput, errEmailAktivasi, data.message || 'Data akun tidak ditemukan.');
                    }
                    return;
                }

                // Akun sudah aktif -> langsung diarahkan ke halaman login
                if (data.status === 'aktif') {
                    showToast(data.message || 'Akun Anda sudah aktif. Silakan login.', 'success');
                    setTimeout(function () {
                        window.location.href = data.redirect || 'login.html';
                    }, 1600);
                    return;
                }

                emailTerkonfirmasi = email;
                isiInfoAkun(data.user);
                tampilStep(2);

                if (data.otp_aktif && data.otp_sisa_detik > 0) {
                    showToast('Kode OTP sebelumnya masih berlaku. Cek email Anda atau klik Konfirmasi untuk kode baru.', 'warning');
                }
            })
            .catch(function () {
                loadingCek.style.display = 'none';
                btnCekEmail.style.display = 'flex';
                btnCekEmail.disabled = false;
                showToast('Terjadi kesalahan koneksi ke server. Pastikan MySQL aktif.', 'error');
            });
    });

    // ==========================================
    // Langkah 2 & Kirim Ulang - minta kode OTP
    // ==========================================
    function mintaKodeOtp(kirimUlang) {
        if (emailTerkonfirmasi === '') {
            kembaliKeEmail();
            return;
        }

        const tombol = kirimUlang ? btnKirimUlang : btnKonfirmasi;
        tombol.disabled = true;

        if (!kirimUlang) {
            btnKonfirmasi.style.display = 'none';
            loadingKirim.style.display = 'flex';
        }

        panggilAktivasi('kirim_otp', { email: emailTerkonfirmasi })
            .then(function (data) {
                if (!kirimUlang) {
                    loadingKirim.style.display = 'none';
                    btnKonfirmasi.style.display = 'flex';
                    btnKonfirmasi.disabled = false;
                }

                if (!data.success) {
                    showToast(data.message || 'Kode OTP gagal dikirim.', 'error');

                    if (data.status === 'aktif' && data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }

                    // Server masih menerapkan jeda kirim ulang -> lanjut ke langkah OTP
                    if (data.tunggu_detik > 0) {
                        if (data.email_samar) otpEmail.textContent = data.email_samar;
                        tampilStep(3);
                        mulaiTimerKirimUlang(data.tunggu_detik);
                    }
                    return;
                }

                if (data.status === 'aktif') {
                    showToast(data.message || 'Akun Anda sudah aktif. Silakan login.', 'success');
                    setTimeout(function () {
                        window.location.href = data.redirect || 'login.html';
                    }, 1600);
                    return;
                }

                otpEmail.textContent = data.email_samar || 'email Anda';
                otpInput.value = '';
                clearError(otpInput, errOtp);
                tampilStep(3);
                mulaiTimerBerlaku((data.berlaku_menit || 10) * 60);
                mulaiTimerKirimUlang(60);
                otpInput.focus();
                showToast(data.message || 'Kode OTP telah dikirim ke email Anda.', 'success');

                // Mode uji lokal (config/mail.php: 'debug_otp' => true)
                if (data.dev_otp) {
                    showToast('Mode uji: kode OTP Anda ' + data.dev_otp, 'warning');
                }
            })
            .catch(function () {
                if (!kirimUlang) {
                    loadingKirim.style.display = 'none';
                    btnKonfirmasi.style.display = 'flex';
                    btnKonfirmasi.disabled = false;
                } else {
                    btnKirimUlang.disabled = false;
                }
                showToast('Terjadi kesalahan koneksi ke server. Pastikan MySQL aktif.', 'error');
            });
    }

    btnKonfirmasi.addEventListener('click', function () {
        mintaKodeOtp(false);
    });

    btnKirimUlang.addEventListener('click', function () {
        mintaKodeOtp(true);
    });

    btnGantiEmail.addEventListener('click', kembaliKeEmail);
    btnUbahEmail.addEventListener('click', kembaliKeEmail);

    // ==========================================
    // Langkah 3 - Verifikasi kode OTP
    // ==========================================
    formOtp.addEventListener('submit', function (e) {
        e.preventDefault();

        const otp = otpInput.value.replace(/\D/g, '');
        clearError(otpInput, errOtp);

        if (otp === '') {
            setError(otpInput, errOtp, 'Kode OTP wajib diisi.');
            showToast('Kode OTP wajib diisi.', 'warning');
            return;
        }

        if (otp.length !== 6) {
            setError(otpInput, errOtp, 'Kode OTP terdiri dari 6 angka.');
            showToast('Kode OTP terdiri dari 6 angka.', 'warning');
            return;
        }

        btnVerifikasi.disabled = true;
        btnVerifikasi.style.display = 'none';
        loadingVerifikasi.style.display = 'flex';

        panggilAktivasi('verifikasi', { email: emailTerkonfirmasi, otp: otp })
            .then(function (data) {
                loadingVerifikasi.style.display = 'none';
                btnVerifikasi.style.display = 'flex';
                btnVerifikasi.disabled = false;

                if (!data.success) {
                    showToast(data.message || 'Kode OTP tidak valid.', 'error');
                    setError(otpInput, errOtp, data.message || 'Kode OTP tidak valid.');

                    // Kode kedaluwarsa / habis percobaan -> boleh minta kode baru
                    if (data.kedaluwarsa || data.harus_kirim_ulang) {
                        hentikanTimerBerlaku();
                        hentikanTimerKirimUlang();
                        sisaKirimUlang = 0;
                        renderTimerKirimUlang();
                        btnKirimUlang.disabled = false;
                    }

                    otpInput.value = '';
                    otpInput.focus();
                    return;
                }

                // Kode benar -> akun aktif
                hentikanTimerBerlaku();
                hentikanTimerKirimUlang();
                tampilStep('selesai');

                const nama = data.user && data.user.nama_lengkap ? ', ' + data.user.nama_lengkap : '';
                pesanSelesai.textContent = data.message
                    || ('Akun' + nama + ' sudah aktif. Silakan login menggunakan username atau email Anda.');
                showToast('Akun berhasil diaktifkan! Mengalihkan ke halaman login...', 'success');

                setTimeout(function () {
                    window.location.href = data.redirect || 'login.html';
                }, 2200);
            })
            .catch(function () {
                loadingVerifikasi.style.display = 'none';
                btnVerifikasi.style.display = 'flex';
                btnVerifikasi.disabled = false;
                showToast('Terjadi kesalahan koneksi ke server. Pastikan MySQL aktif.', 'error');
            });
    });

    // ==========================================
    // Input OTP: hanya angka, maksimal 6 digit
    // ==========================================
    otpInput.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
    });

    otpInput.addEventListener('focus', function () {
        clearError(otpInput, errOtp);
    });

    emailInput.addEventListener('focus', function () {
        clearError(emailInput, errEmailAktivasi);
    });

    // ==========================================
    // Inisialisasi — prefill email dari ?email= (mis. setelah mendaftar)
    // ==========================================
    function init() {
        const params = new URLSearchParams(window.location.search);
        const emailAwal = (params.get('email') || '').trim();

        if (emailAwal !== '') {
            emailInput.value = emailAwal;
            // Langsung periksa akun agar pengguna tidak mengetik ulang
            formEmail.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        } else {
            emailInput.focus();
        }

        console.log('SIM-TKD - Aktivasi Akun (Verifikasi OTP) Ready');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
