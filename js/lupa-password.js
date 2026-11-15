/**
 * SIM-TKD - Lupa Password (Reset via Kode OTP Email)
 * Menghubungkan lupa-password.html dengan api/lupa_password.php:
 *   1. Langkah 1 : masukkan email terdaftar -> action=cek
 *   2. Langkah 2 : periksa nama & instansi, klik Konfirmasi -> action=kirim_otp
 *   3. Langkah 3 : masukkan OTP + kata sandi baru -> action=reset
 */
(function () {
    'use strict';

    var stepBar = document.getElementById('stepBar');
    var step1 = document.getElementById('step1');
    var step2 = document.getElementById('step2');
    var step3 = document.getElementById('step3');
    var stepSelesai = document.getElementById('stepSelesai');

    var formEmail = document.getElementById('formEmail');
    var emailInput = document.getElementById('emailReset');
    var errEmail = document.getElementById('errEmailReset');
    var btnCekEmail = document.getElementById('btnCekEmail');
    var loadingCek = document.getElementById('loadingCek');

    var infoNama = document.getElementById('infoNama');
    var infoInstansi = document.getElementById('infoInstansi');
    var infoUsername = document.getElementById('infoUsername');
    var infoEmail = document.getElementById('infoEmail');
    var infoWilayah = document.getElementById('infoWilayah');
    var infoPeran = document.getElementById('infoPeran');
    var btnKonfirmasi = document.getElementById('btnKonfirmasi');
    var loadingKirim = document.getElementById('loadingKirim');

    var formReset = document.getElementById('formReset');
    var otpInput = document.getElementById('otpInput');
    var errOtp = document.getElementById('errOtp');
    var otpEmail = document.getElementById('otpEmail');
    var otpTimer = document.getElementById('otpTimer');
    var passBaru = document.getElementById('passBaru');
    var passUlangi = document.getElementById('passUlangi');
    var errBaru = document.getElementById('errBaru');
    var errUlangi = document.getElementById('errUlangi');
    var btnReset = document.getElementById('btnReset');
    var loadingReset = document.getElementById('loadingReset');
    var btnKirimUlang = document.getElementById('btnKirimUlang');
    var lblKirimUlang = document.getElementById('lblKirimUlang');
    var btnGantiEmail = document.getElementById('btnGantiEmail');
    var btnUbahEmail = document.getElementById('btnUbahEmail');
    var pesanSelesai = document.getElementById('pesanSelesai');
    var toastContainer = document.getElementById('toastContainer');
    var aktivasiBox = document.getElementById('aktivasiBox');
    var linkAktivasi = document.getElementById('linkAktivasi');

    var emailTerkonfirmasi = '';
    var timerBerlaku = null, timerKirimUlang = null;
    var sisaBerlaku = 0, sisaKirimUlang = 0;
    function showToast(message, type) {
        var toast = document.createElement('div');
        toast.className = 'toast toast-' + (type || 'success');
        var icons = { success: 'fa-check-circle', error: 'fa-times-circle', warning: 'fa-exclamation-triangle' };
        var span = document.createElement('span');
        span.textContent = message;
        var icon = document.createElement('i');
        icon.className = 'fas ' + (icons[type] || icons.success);
        toast.appendChild(icon);
        toast.appendChild(span);
        toastContainer.appendChild(toast);
        setTimeout(function () { if (toast.parentNode) toast.remove(); }, 3600);
    }

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

    function tampilStep(nomor) {
        var peta = { '1': step1, '2': step2, '3': step3, 'selesai': stepSelesai };
        Object.keys(peta).forEach(function (key) {
            peta[key].style.display = key === String(nomor) ? 'block' : 'none';
        });
        var nomorAktif = nomor === 'selesai' ? 4 : parseInt(nomor, 10);
        stepBar.querySelectorAll('.step-item').forEach(function (item) {
            var step = parseInt(item.getAttribute('data-step'), 10);
            item.classList.toggle('active', step === nomorAktif);
            item.classList.toggle('done', step < nomorAktif);
            item.querySelector('.step-num').textContent = step < nomorAktif ? '\u2713' : String(step);
        });
        stepBar.style.display = nomor === 'selesai' ? 'none' : 'flex';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function formatDurasi(detik) {
        var m = Math.floor(detik / 60);
        var s = detik % 60;
        return m + ':' + (s < 10 ? '0' + s : s);
    }

    function mulaiTimerBerlaku(detik) {
        hentikanTimerBerlaku();
        sisaBerlaku = Math.max(0, parseInt(detik, 10) || 0);
        renderTimerBerlaku();
        timerBerlaku = setInterval(function () {
            sisaBerlaku -= 1;
            renderTimerBerlaku();
            if (sisaBerlaku <= 0) {
                hentikanTimerBerlaku();
                otpTimer.innerHTML = '<i class="fas fa-clock"></i> Kode OTP kedaluwarsa. Klik "Kirim ulang kode".';
                hentikanTimerKirimUlang();
                sisaKirimUlang = 0;
                renderTimerKirimUlang();
                btnKirimUlang.disabled = false;
            }
        }, 1000);
    }

    function renderTimerBerlaku() {
        if (sisaBerlaku <= 0) return;
        otpTimer.innerHTML = '<i class="fas fa-clock"></i> Kode berlaku <strong>' + formatDurasi(sisaBerlaku) + '</strong> lagi.';
    }

    function hentikanTimerBerlaku() {
        if (timerBerlaku) { clearInterval(timerBerlaku); timerBerlaku = null; }
    }

    function mulaiTimerKirimUlang(detik) {
        hentikanTimerKirimUlang();
        sisaKirimUlang = Math.max(0, parseInt(detik, 10) || 0);
        renderTimerKirimUlang();
        btnKirimUlang.disabled = sisaKirimUlang > 0;
        if (sisaKirimUlang <= 0) return;
        timerKirimUlang = setInterval(function () {
            sisaKirimUlang -= 1;
            renderTimerKirimUlang();
            if (sisaKirimUlang <= 0) { hentikanTimerKirimUlang(); btnKirimUlang.disabled = false; }
        }, 1000);
    }

    function renderTimerKirimUlang() {
        lblKirimUlang.textContent = sisaKirimUlang > 0 ? 'Kirim ulang kode (' + sisaKirimUlang + ')' : 'Kirim ulang kode';
    }

    function hentikanTimerKirimUlang() {
        if (timerKirimUlang) { clearInterval(timerKirimUlang); timerKirimUlang = null; }
    }

    function panggilReset(action, params) {
        var body = new URLSearchParams(Object.assign({ action: action }, params || {}));
        return fetch('api/lupa_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (res) { return res.json(); });
    }

    function isiInfoAkun(user) {
        user = user || {};
        infoNama.textContent = user.nama_lengkap || '-';
        infoInstansi.textContent = user.instansi && user.instansi !== '' ? user.instansi : '-';
        var wilayah = [user.kota, user.provinsi].filter(function (v) { return v && v !== ''; }).join(', ');
        infoUsername.textContent = user.username || '-';
        infoEmail.textContent = user.email_samar || '-';
        infoWilayah.textContent = wilayah !== '' ? wilayah : '-';
        infoPeran.textContent = user.peran || '-';
    }

    function kembaliKeEmail() {
        hentikanTimerBerlaku();
        hentikanTimerKirimUlang();
        otpInput.value = '';
        clearError(otpInput, errOtp);
        tampilStep(1);
        emailInput.focus();
    }

    function tampilkanKotakAktivasi(url) {
        if (linkAktivasi && url) linkAktivasi.href = url;
        if (aktivasiBox) aktivasiBox.style.display = 'block';
    }

    function sembunyikanKotakAktivasi() {
        if (aktivasiBox) aktivasiBox.style.display = 'none';
    }

    formEmail.addEventListener('submit', function (e) {
        e.preventDefault();
        var email = emailInput.value.trim().toLowerCase();
        clearError(emailInput, errEmail);
        sembunyikanKotakAktivasi();
        if (!email) {
            setError(emailInput, errEmail, 'Email wajib diisi.');
            showToast('Email wajib diisi.', 'warning');
            return;
        }
        if (!isValidEmail(email)) {
            setError(emailInput, errEmail, 'Format email tidak valid.');
            showToast('Format email tidak valid.', 'error');
            return;
        }
        btnCekEmail.disabled = true;
        btnCekEmail.style.display = 'none';
        loadingCek.style.display = 'flex';
        panggilReset('cek', { email: email })
            .then(function (data) {
                loadingCek.style.display = 'none';
                btnCekEmail.style.display = 'flex';
                btnCekEmail.disabled = false;
                if (!data.success) {
                    showToast(data.message || 'Akun tidak ditemukan.', 'error');
                    setError(emailInput, errEmail, data.message || 'Akun tidak ditemukan.');
                    if (data.need_activation) tampilkanKotakAktivasi(data.aktivasi || 'aktivasi.html');
                    return;
                }
                emailTerkonfirmasi = email;
                isiInfoAkun(data.user);
                tampilStep(2);
                showToast('Data akun ditemukan. Periksa lalu klik Konfirmasi.', 'success');
            })
            .catch(function () {
                loadingCek.style.display = 'none';
                btnCekEmail.style.display = 'flex';
                btnCekEmail.disabled = false;
                showToast('Koneksi ke server gagal. Pastikan MySQL aktif.', 'error');
            });
    });

    function kirimOtp() {
        btnKonfirmasi.disabled = true;
        btnKonfirmasi.style.display = 'none';
        loadingKirim.style.display = 'flex';
        panggilReset('kirim_otp', { email: emailTerkonfirmasi })
            .then(function (data) {
                loadingKirim.style.display = 'none';
                btnKonfirmasi.style.display = 'flex';
                btnKonfirmasi.disabled = false;
                if (!data.success) {
                    showToast(data.message || 'Gagal mengirim kode OTP.', 'error');
                    if (data.tunggu_detik) mulaiTimerKirimUlang(data.tunggu_detik);
                    if (data.dev_otp) showToast('Mode uji — kode OTP: ' + data.dev_otp, 'warning');
                    return;
                }
                otpEmail.textContent = data.email_samar || emailTerkonfirmasi;
                tampilStep(3);
                var berlaku = 600;
                if (data.berlaku_menit) berlaku = parseInt(data.berlaku_menit, 10) * 60;
                mulaiTimerBerlaku(berlaku);
                mulaiTimerKirimUlang(60);
                showToast(data.message || 'Kode OTP dikirim.', 'success');
                if (data.dev_otp) showToast('Mode uji — kode OTP: ' + data.dev_otp, 'warning');
                otpInput.focus();
            })
            .catch(function () {
                loadingKirim.style.display = 'none';
                btnKonfirmasi.style.display = 'flex';
                btnKonfirmasi.disabled = false;
                showToast('Koneksi ke server gagal.', 'error');
            });
    }

    btnKonfirmasi.addEventListener('click', kirimOtp);

    btnKirimUlang.addEventListener('click', function () {
        if (btnKirimUlang.disabled) return;
        btnKirimUlang.disabled = true;
        panggilReset('kirim_otp', { email: emailTerkonfirmasi })
            .then(function (data) {
                if (!data.success) {
                    showToast(data.message || 'Gagal mengirim ulang.', 'error');
                    if (data.tunggu_detik) mulaiTimerKirimUlang(data.tunggu_detik);
                    else btnKirimUlang.disabled = false;
                    if (data.dev_otp) showToast('Mode uji — kode OTP: ' + data.dev_otp, 'warning');
                    return;
                }
                mulaiTimerBerlaku(data.berlaku_menit ? parseInt(data.berlaku_menit, 10) * 60 : 600);
                mulaiTimerKirimUlang(60);
                showToast('Kode OTP baru dikirim.', 'success');
                if (data.dev_otp) showToast('Mode uji — kode OTP: ' + data.dev_otp, 'warning');
            })
            .catch(function () {
                btnKirimUlang.disabled = false;
                showToast('Koneksi ke server gagal.', 'error');
            });
    });

    formReset.addEventListener('submit', function (e) {
        e.preventDefault();
        var otp = otpInput.value.replace(/\D/g, '').slice(0, 6);
        otpInput.value = otp;
        var baru = passBaru.value;
        var ulangi = passUlangi.value;
        clearError(otpInput, errOtp);
        clearError(passBaru, errBaru);
        clearError(passUlangi, errUlangi);
        if (otp.length !== 6) {
            setError(otpInput, errOtp, 'Kode OTP terdiri dari 6 angka.');
            showToast('Kode OTP terdiri dari 6 angka.', 'error');
            otpInput.focus();
            return;
        }
        if (baru.length < 8) {
            setError(passBaru, errBaru, 'Kata sandi baru minimal 8 karakter.');
            showToast('Kata sandi baru minimal 8 karakter.', 'error');
            passBaru.focus();
            return;
        }
        if (baru !== ulangi) {
            setError(passUlangi, errUlangi, 'Konfirmasi tidak sama dengan kata sandi baru.');
            showToast('Konfirmasi kata sandi tidak sama.', 'error');
            passUlangi.focus();
            return;
        }
        btnReset.disabled = true;
        btnReset.style.display = 'none';
        loadingReset.style.display = 'flex';
        panggilReset('reset', { email: emailTerkonfirmasi, otp: otp, password_baru: baru, password_ulangi: ulangi })
            .then(function (data) {
                loadingReset.style.display = 'none';
                btnReset.style.display = 'flex';
                btnReset.disabled = false;
                if (!data.success) {
                    showToast(data.message || 'Gagal menyimpan kata sandi.', 'error');
                    if (data.field === 'otp') {
                        setError(otpInput, errOtp, data.message || 'Kode OTP tidak valid.');
                        otpInput.value = '';
                        otpInput.focus();
                    } else if (data.field === 'password_baru') {
                        setError(passBaru, errBaru, data.message);
                        passBaru.focus();
                    } else if (data.field === 'password_ulangi') {
                        setError(passUlangi, errUlangi, data.message);
                        passUlangi.focus();
                    }
                    if (data.kedaluwarsa || data.harus_kirim_ulang) {
                        hentikanTimerBerlaku();
                        hentikanTimerKirimUlang();
                        sisaKirimUlang = 0;
                        renderTimerKirimUlang();
                        btnKirimUlang.disabled = false;
                    }
                    if (data.need_activation) tampilkanKotakAktivasi(data.aktivasi || 'aktivasi.html');
                    return;
                }
                hentikanTimerBerlaku();
                hentikanTimerKirimUlang();
                tampilStep('selesai');
                pesanSelesai.textContent = data.message || 'Kata sandi berhasil diubah. Silakan login.';
                showToast('Berhasil! Mengalihkan ke halaman login...', 'success');
                setTimeout(function () { window.location.href = data.redirect || 'login.html'; }, 2200);
            })
            .catch(function () {
                loadingReset.style.display = 'none';
                btnReset.style.display = 'flex';
                btnReset.disabled = false;
                showToast('Koneksi ke server gagal.', 'error');
            });
    });

    otpInput.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
    });
    otpInput.addEventListener('focus', function () { clearError(otpInput, errOtp); });
    emailInput.addEventListener('focus', function () { clearError(emailInput, errEmail); });
    passBaru.addEventListener('focus', function () { clearError(passBaru, errBaru); });
    passUlangi.addEventListener('focus', function () { clearError(passUlangi, errUlangi); });

    function pasangToggle(input, tombol) {
        if (!input || !tombol) return;
        tombol.addEventListener('click', function () {
            var tampil = input.type === 'password';
            input.type = tampil ? 'text' : 'password';
            var ikon = tombol.querySelector('i');
            if (ikon) ikon.className = tampil ? 'fas fa-eye-slash' : 'fas fa-eye';
        });
    }
    pasangToggle(passBaru, document.getElementById('toggleBaru'));
    pasangToggle(passUlangi, document.getElementById('toggleUlangi'));

    btnGantiEmail.addEventListener('click', kembaliKeEmail);
    btnUbahEmail.addEventListener('click', kembaliKeEmail);

    emailInput.focus();
    console.log('SIM-TKD - Lupa Password (Reset OTP) Ready');
})();

