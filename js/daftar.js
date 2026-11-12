/**
 * SIM-TKD - Register Page Scripts
 * ============================================
 */

(function () {
    'use strict';

    // ==========================================
    // DOM Elements
    // ==========================================
    const registerForm = document.getElementById('registerForm');
    const btnRegister = document.getElementById('btnRegister');
    const loadingEl = document.getElementById('loading');
    const refreshCaptchaBtn = document.getElementById('refreshCaptcha');
    const captchaCanvas = document.getElementById('captchaCanvas');
    const captchaInput = document.getElementById('captchaInput');
    const toastContainer = document.getElementById('toastContainer');

    const namaLengkap = document.getElementById('namaLengkap');
    const username = document.getElementById('username');
    const email = document.getElementById('email');
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirmPassword');
    const instansi = document.getElementById('instansi');
    const kota = document.getElementById('kota');
    const provinsi = document.getElementById('provinsi');
    const kotaInput = document.getElementById('kotaInput');
    const terms = document.getElementById('terms');

    let captchaCode = '';

    // ==========================================
    // Captcha Generator
    // ==========================================
    function rand(min, max) {
        return Math.floor(Math.random() * (max - min + 1)) + min;
    }

    function generateCaptcha() {
        const canvas = captchaCanvas;
        const ctx = canvas.getContext('2d');

        canvas.width = 160;
        canvas.height = 50;

        // Background
        const bgGradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
        bgGradient.addColorStop(0, '#f0f3f7');
        bgGradient.addColorStop(1, '#e2e8f0');
        ctx.fillStyle = bgGradient;
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // Random code
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        captchaCode = '';
        for (let i = 0; i < 5; i++) {
            captchaCode += chars.charAt(Math.floor(Math.random() * chars.length));
        }

        // Noise lines
        for (let i = 0; i < 6; i++) {
            ctx.beginPath();
            ctx.strokeStyle = `rgba(${rand(100, 180)}, ${rand(100, 180)}, ${rand(150, 200)}, ${rand(3, 6) / 10})`;
            ctx.lineWidth = rand(1, 2);
            ctx.moveTo(rand(0, canvas.width), rand(0, canvas.height));
            ctx.lineTo(rand(0, canvas.width), rand(0, canvas.height));
            ctx.stroke();
        }

        // Noise dots
        for (let i = 0; i < 80; i++) {
            ctx.fillStyle = `rgba(${rand(100, 200)}, ${rand(100, 200)}, ${rand(150, 220)}, ${rand(3, 7) / 10})`;
            ctx.beginPath();
            ctx.arc(rand(0, canvas.width), rand(0, canvas.height), rand(1, 2), 0, Math.PI * 2);
            ctx.fill();
        }

        // Characters
        const charWidth = canvas.width / 5;
        for (let i = 0; i < captchaCode.length; i++) {
            const x = charWidth * i + charWidth / 2;
            const y = canvas.height / 2;

            ctx.save();
            ctx.translate(x, y);
            ctx.rotate((Math.random() - 0.5) * 0.5);

            const fontSize = rand(26, 34);
            const fontWeight = rand(0, 1) ? 'bold' : 'normal';
            const fontFamily = ['Arial', 'Georgia', 'Courier New', 'Impact'][rand(0, 3)];

            ctx.font = `${fontWeight} ${fontSize}px ${fontFamily}`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = `rgb(${rand(20, 80)}, ${rand(20, 80)}, ${rand(40, 100)})`;
            ctx.shadowColor = 'rgba(0,0,0,0.15)';
            ctx.shadowBlur = 2;
            ctx.shadowOffsetX = 1;
            ctx.shadowOffsetY = 1;
            ctx.fillText(captchaCode[i], 0, rand(-3, 3));
            ctx.shadowColor = 'transparent';
            ctx.shadowBlur = 0;
            ctx.shadowOffsetX = 0;
            ctx.shadowOffsetY = 0;
            ctx.restore();
        }

        // Border
        ctx.strokeStyle = '#cbd5e0';
        ctx.lineWidth = 1;
        ctx.strokeRect(0, 0, canvas.width, canvas.height);
    }

    // ==========================================
    // Toggle Password Visibility
    // ==========================================
    document.querySelectorAll('.toggle-password').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);
            const icon = this.querySelector('i');

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                this.setAttribute('aria-label', 'Sembunyikan kata sandi');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                this.setAttribute('aria-label', 'Tampilkan kata sandi');
            }
        });
    });

    // ==========================================
    // Refresh Captcha
    // ==========================================
    refreshCaptchaBtn.addEventListener('click', function () {
        generateCaptcha();
        captchaInput.value = '';
        captchaInput.focus();

        const icon = this.querySelector('i');
        icon.style.transition = 'transform 0.4s ease';
        icon.style.transform = 'rotate(360deg)';
        setTimeout(() => {
            icon.style.transition = 'none';
            icon.style.transform = 'none';
        }, 400);
    });

    captchaCanvas.addEventListener('click', function () {
        refreshCaptchaBtn.click();
    });

    // Auto-capitalize captcha
    captchaInput.addEventListener('input', function () {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    });

    // ==========================================
    // Password Strength
    // ==========================================
    function evaluateStrength(pw) {
        let score = 0;

        if (!pw) return { level: 0, label: '-' };

        if (pw.length >= 8) score++;
        if (pw.length >= 12) score++;
        if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
        if (/\d/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;

        if (score <= 1) return { level: 1, label: 'Lemah' };
        if (score <= 3) return { level: 2, label: 'Sedang' };
        return { level: 3, label: 'Kuat' };
    }

    const pwStrength = document.getElementById('pwStrength');

    password.addEventListener('input', function () {
        const result = evaluateStrength(this.value);

        pwStrength.classList.remove('weak', 'medium', 'strong');
        if (result.level === 1) pwStrength.classList.add('weak');
        if (result.level === 2) pwStrength.classList.add('medium');
        if (result.level === 3) pwStrength.classList.add('strong');

        pwStrength.querySelector('.strength-text').textContent = 'Kekuatan: ' + result.label;
    });

    // ==========================================
    // Toast Notifications
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
        toastContainer.appendChild(toast);

        setTimeout(function () {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 3200);
    }

    // ==========================================
    // Helper - set field error
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

    // Pendaftaran hanya diizinkan via email domain POLBAN
    const ALLOWED_EMAIL_DOMAIN = 'polban.ac.id';

    function isAllowedEmailDomain(value) {
        const at = value.lastIndexOf('@');
        return at !== -1 && value.slice(at + 1).toLowerCase() === ALLOWED_EMAIL_DOMAIN;
    }

    // ==========================================
    // Form Submission
    // ==========================================
    registerForm.addEventListener('submit', function (e) {
        e.preventDefault();

        // Values
        const nama = namaLengkap.value.trim();
        const user = username.value.trim();
        const mail = email.value.trim();
        const pw = password.value;
        const confirm = confirmPassword.value;
        const inst = instansi.value.trim();
        const kt = kota.value;
        const prov = provinsi.value.trim();
        const cap = captchaInput.value.trim().toUpperCase();

        // Reset errors
        clearError(namaLengkap, document.getElementById('errNamaLengkap'));
        clearError(username, document.getElementById('errUsername'));
        clearError(email, document.getElementById('errEmail'));
        clearError(password, document.getElementById('errPassword'));
        clearError(confirmPassword, document.getElementById('errConfirm'));
        clearError(instansi, document.getElementById('errInstansi'));
        document.getElementById('errTerms').textContent = '';

        let hasError = false;

        // Validate nama
        if (!nama) {
            setError(namaLengkap, document.getElementById('errNamaLengkap'), 'Nama lengkap wajib diisi.');
            hasError = true;
        } else if (nama.length < 3) {
            setError(namaLengkap, document.getElementById('errNamaLengkap'), 'Nama lengkap minimal 3 karakter.');
            hasError = true;
        }

        // Validate username
        if (!user) {
            setError(username, document.getElementById('errUsername'), 'Nama pengguna wajib diisi.');
            hasError = true;
        } else if (!/^[a-zA-Z0-9._-]{3,20}$/.test(user)) {
            setError(username, document.getElementById('errUsername'), 'Username 3-20 karakter (huruf, angka, . _ -).');
            hasError = true;
        }

        // Validate email
        if (!mail) {
            setError(email, document.getElementById('errEmail'), 'Email wajib diisi.');
            hasError = true;
        } else if (!isValidEmail(mail)) {
            setError(email, document.getElementById('errEmail'), 'Format email tidak valid.');
            hasError = true;
        } else if (!isAllowedEmailDomain(mail)) {
            setError(email, document.getElementById('errEmail'), 'Pendaftaran hanya menggunakan email @polban.ac.id.');
            hasError = true;
        }

        // Validate instansi (kunci pemisahan data multi-dinas)
        if (!inst) {
            setError(instansi, document.getElementById('errInstansi'), 'Nama instansi wajib diisi.');
            hasError = true;
        } else if (inst.length < 3) {
            setError(instansi, document.getElementById('errInstansi'), 'Nama instansi minimal 3 karakter.');
            hasError = true;
        }

        // Validate password
        if (!pw) {
            setError(password, document.getElementById('errPassword'), 'Kata sandi wajib diisi.');
            hasError = true;
        } else if (pw.length < 8) {
            setError(password, document.getElementById('errPassword'), 'Kata sandi minimal 8 karakter.');
            hasError = true;
        }

        // Validate confirm
        if (!confirm) {
            setError(confirmPassword, document.getElementById('errConfirm'), 'Konfirmasi kata sandi wajib diisi.');
            hasError = true;
        } else if (pw !== confirm) {
            setError(confirmPassword, document.getElementById('errConfirm'), 'Konfirmasi kata sandi tidak cocok.');
            hasError = true;
        }

        // Validate provinsi
        if (!prov) {
            showToast('Silakan pilih Provinsi.', 'warning');
            provinsi.style.borderColor = '#e74c3c';
            hasError = true;
        }

        // Validate kota
        if (!kt) {
            showToast('Silakan pilih Kota / Kabupaten.', 'warning');
            kotaInput.style.borderColor = '#e74c3c';
            hasError = true;
        }

        // Validate captcha
        if (!cap) {
            captchaInput.style.borderColor = '#e74c3c';
            showToast('Kode Keamanan (Captcha) wajib diisi.', 'warning');
            hasError = true;
        } else if (cap !== captchaCode) {
            captchaInput.style.borderColor = '#e74c3c';
            showToast('Kode Captcha tidak sesuai. Silakan coba lagi.', 'error');
            generateCaptcha();
            captchaInput.value = '';
            hasError = true;
        }

        // Validate terms
        if (!terms.checked) {
            document.getElementById('errTerms').textContent = 'Anda harus menyetujui Syarat & Ketentuan terlebih dahulu.';
            hasError = true;
        }

        if (hasError) {
            return;
        }

        // Kirim ke backend (API register)
        btnRegister.disabled = true;
        btnRegister.style.display = 'none';
        loadingEl.style.display = 'flex';

        fetch('api/register.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                nama_lengkap: nama,
                username: user,
                email: mail,
                password: pw,
                instansi: inst,
                kota: kt,
                provinsi: prov
            })
        })
        .then(function (res) {
            return res.json();
        })
        .then(function (data) {
            loadingEl.style.display = 'none';
            btnRegister.style.display = 'flex';
            btnRegister.disabled = false;

            if (data.success) {
                showToast(data.message || 'Pendaftaran berhasil! Silakan verifikasi kode OTP.', 'success');

                // Akun berstatus PENDING -> arahkan ke halaman Aktivasi Akun
                // (email dibawa lewat query string agar tidak perlu diketik ulang)
                const tujuan = data.aktivasi
                    || ('aktivasi.html?email=' + encodeURIComponent(mail));
                setTimeout(function () {
                    window.location.href = tujuan;
                }, 2000);
            } else {
                showToast(data.message || 'Pendaftaran gagal. Silakan coba lagi.', 'error');

                // Tandai field yang bermasalah dari backend
                if (data.field) {
                    const target = document.getElementById(data.field);
                    if (target) target.style.borderColor = '#e74c3c';
                }

                generateCaptcha();
                captchaInput.value = '';
            }
        })
        .catch(function () {
            loadingEl.style.display = 'none';
            btnRegister.style.display = 'flex';
            btnRegister.disabled = false;
            showToast('Terjadi kesalahan koneksi ke server. Pastikan MySQL aktif.', 'error');
        });
    });

    // ==========================================
    // Clear error on focus
    // ==========================================
    const fieldMap = {
        namaLengkap: 'errNamaLengkap',
        username: 'errUsername',
        email: 'errEmail',
        password: 'errPassword',
        confirmPassword: 'errConfirm'
    };

    Object.keys(fieldMap).forEach(function (id) {
        const el = document.getElementById(id);
        const errId = fieldMap[id];
        el.addEventListener('focus', function () {
            clearError(this, document.getElementById(errId));
        });
    });

    captchaInput.addEventListener('focus', function () {
        this.style.borderColor = '';
    });

    terms.addEventListener('change', function () {
        document.getElementById('errTerms').textContent = '';
    });

    // ==========================================
    // Initialize
    // ==========================================
    function init() {
        generateCaptcha();
        // Wilayah: Provinsi -> Kota (cascading + searchable)
        if (typeof initPilihWilayah === 'function') {
            initPilihWilayah({
                provinsiId: 'provinsi',
                kotaId: 'kota',
                kotaInputId: 'kotaInput',
                kotaListId: 'kotaList',
                onKotaChange: function () {
                    clearError(kotaInput, document.getElementById('errKota'));
                    clearError(provinsi, document.getElementById('errProvinsi'));
                }
            });
        }
        console.log('SIM-TKD - Register Page Ready');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
