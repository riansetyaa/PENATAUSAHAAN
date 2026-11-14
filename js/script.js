/**
 * SIPD Penatausahaan - Login Page Scripts
 * ============================================
 */

(function () {
    'use strict';

    // ==========================================
    // DOM Elements
    // ==========================================
    const loginForm = document.getElementById('loginForm');
    const btnLogin = document.getElementById('btnLogin');
    const loadingEl = document.getElementById('loading');
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const refreshCaptchaBtn = document.getElementById('refreshCaptcha');
    const captchaCanvas = document.getElementById('captchaCanvas');
    const captchaInput = document.getElementById('captchaInput');
    const usernameInput = document.getElementById('username');
    const toastContainer = document.getElementById('toastContainer');
    const aktivasiBox = document.getElementById('aktivasiBox');
    const linkAktivasi = document.getElementById('linkAktivasi');

    let captchaCode = '';

    // ==========================================
    // Captcha Generator
    // ==========================================
    function generateCaptcha() {
        const canvas = captchaCanvas;
        const ctx = canvas.getContext('2d');

        // Canvas size
        canvas.width = 160;
        canvas.height = 50;

        // Background gradient
        const bgGradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
        bgGradient.addColorStop(0, '#f0f3f7');
        bgGradient.addColorStop(1, '#e2e8f0');
        ctx.fillStyle = bgGradient;
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // Generate random code (5 characters)
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        captchaCode = '';
        for (let i = 0; i < 5; i++) {
            captchaCode += chars.charAt(Math.floor(Math.random() * chars.length));
        }

        // Draw noise lines
        for (let i = 0; i < 6; i++) {
            ctx.beginPath();
            ctx.strokeStyle = `rgba(${rand(100, 180)}, ${rand(100, 180)}, ${rand(150, 200)}, ${rand(3, 6) / 10})`;
            ctx.lineWidth = rand(1, 2);
            ctx.moveTo(rand(0, canvas.width), rand(0, canvas.height));
            ctx.lineTo(rand(0, canvas.width), rand(0, canvas.height));
            ctx.stroke();
        }

        // Draw noise dots
        for (let i = 0; i < 80; i++) {
            ctx.fillStyle = `rgba(${rand(100, 200)}, ${rand(100, 200)}, ${rand(150, 220)}, ${rand(3, 7) / 10})`;
            ctx.beginPath();
            ctx.arc(rand(0, canvas.width), rand(0, canvas.height), rand(1, 2), 0, Math.PI * 2);
            ctx.fill();
        }

        // Draw each character with styling
        const charWidth = canvas.width / 5;
        for (let i = 0; i < captchaCode.length; i++) {
            const x = charWidth * i + charWidth / 2;
            const y = canvas.height / 2;

            ctx.save();
            ctx.translate(x, y);

            // Random rotation
            const rotation = (Math.random() - 0.5) * 0.5;
            ctx.rotate(rotation);

            // Random font styling
            const fontSize = rand(26, 34);
            const fontWeight = rand(0, 1) ? 'bold' : 'normal';
            const fontFamily = ['Arial', 'Georgia', 'Courier New', 'Impact'][rand(0, 3)];

            ctx.font = `${fontWeight} ${fontSize}px ${fontFamily}`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            // Text color (dark)
            const r = rand(20, 80);
            const g = rand(20, 80);
            const b = rand(40, 100);
            ctx.fillStyle = `rgb(${r}, ${g}, ${b})`;

            // Subtle shadow
            ctx.shadowColor = 'rgba(0,0,0,0.15)';
            ctx.shadowBlur = 2;
            ctx.shadowOffsetX = 1;
            ctx.shadowOffsetY = 1;

            ctx.fillText(captchaCode[i], 0, rand(-3, 3));

            // Clear shadow for next char
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

    function rand(min, max) {
        return Math.floor(Math.random() * (max - min + 1)) + min;
    }

    // ==========================================
    // Toggle Password Visibility
    // ==========================================
    togglePassword.addEventListener('click', function () {
        const icon = this.querySelector('i');
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
            this.setAttribute('aria-label', 'Sembunyikan password');
        } else {
            passwordInput.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
            this.setAttribute('aria-label', 'Tampilkan password');
        }
    });

    // ==========================================
    // Refresh Captcha
    // ==========================================
    refreshCaptchaBtn.addEventListener('click', function () {
        generateCaptcha();
        captchaInput.value = '';
        captchaInput.focus();

        // Rotation animation
        const icon = this.querySelector('i');
        icon.style.transition = 'transform 0.4s ease';
        icon.style.transform = 'rotate(360deg)';
        setTimeout(() => {
            icon.style.transition = 'none';
            icon.style.transform = 'none';
        }, 400);
    });

    // Click on canvas to refresh
    captchaCanvas.addEventListener('click', function () {
        refreshCaptchaBtn.click();
    });

    // ==========================================
    // Toast Notifications
    // ==========================================
    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

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

        // Auto remove after animation
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 3200);
    }

    // ==========================================
    // Kotak Aktivasi Akun (akun berstatus pending)
    // ==========================================
    function tampilkanKotakAktivasi(tujuan) {
        if (!aktivasiBox || !linkAktivasi) return;
        linkAktivasi.href = tujuan || 'aktivasi.html';
        aktivasiBox.style.display = 'flex';
    }

    function sembunyikanKotakAktivasi() {
        if (!aktivasiBox) return;
        aktivasiBox.style.display = 'none';
    }

    // ==========================================
    // Form Submission
    // ==========================================
    loginForm.addEventListener('submit', function (e) {
        e.preventDefault();

        // Reset previous error styles
        const allInputs = loginForm.querySelectorAll('.form-control');
        allInputs.forEach(input => {
            input.style.borderColor = '';
        });

        // Get values
        const username = usernameInput.value.trim();
        const password = passwordInput.value;
        const captcha = captchaInput.value.trim().toUpperCase();

        // Validation
        let hasError = false;

        if (!username) {
            if (!hasError) {
                usernameInput.style.borderColor = '#e74c3c';
                showToast('Username atau Email wajib diisi.', 'warning');
                hasError = true;
            }
        }

        if (!password && !hasError) {
            passwordInput.style.borderColor = '#e74c3c';
            showToast('Kata Sandi (Password) wajib diisi.', 'warning');
            hasError = true;
        }

        if (!captcha && !hasError) {
            captchaInput.style.borderColor = '#e74c3c';
            showToast('Kode Keamanan (Captcha) wajib diisi.', 'warning');
            hasError = true;
        }

        if (hasError) {
            return;
        }

        // Validate captcha
        if (captcha !== captchaCode) {
            captchaInput.style.borderColor = '#e74c3c';
            showToast('Kode Captcha tidak sesuai. Silakan coba lagi.', 'error');
            generateCaptcha();
            captchaInput.value = '';
            captchaInput.focus();
            return;
        }

        // Kirim ke backend (API login)
        btnLogin.disabled = true;
        btnLogin.style.display = 'none';
        loadingEl.style.display = 'flex';

        fetch('api/login.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                username: username,
                password: password
            })
        })
        .then(function (res) {
            return res.json();
        })
        .then(function (data) {
            loadingEl.style.display = 'none';
            btnLogin.style.display = 'flex';
            btnLogin.disabled = false;

            if (data.success) {
                sembunyikanKotakAktivasi();
                showToast(data.message || 'Login berhasil!', 'success');
                // Redirect ke dashboard (frontend vanilla HTML)
                setTimeout(function () {
                    window.location.href = data.redirect || 'dashboard/dashboard.html';
                }, 1200);
            } else {
                showToast(data.message || 'Login gagal. Silakan coba lagi.', 'error');
                generateCaptcha();
                captchaInput.value = '';
                // Tandai field yang bermasalah dari backend
                if (data.field) {
                    var target = document.getElementById(data.field);
                    if (target) target.style.borderColor = '#e74c3c';
                }
                // Akun belum diverifikasi -> tawarkan tombol menuju halaman Aktivasi Akun
                if (data.need_activation) {
                    tampilkanKotakAktivasi(data.aktivasi || 'aktivasi.html');
                }
            }
        })
        .catch(function () {
            loadingEl.style.display = 'none';
            btnLogin.style.display = 'flex';
            btnLogin.disabled = false;
            showToast('Terjadi kesalahan koneksi ke server. Pastikan MySQL aktif.', 'error');
        });
    });

    // ==========================================
    // Input Field - Clear error on focus
    // ==========================================
    const formInputs = loginForm.querySelectorAll('.form-control');
    formInputs.forEach(function (input) {
        input.addEventListener('focus', function () {
            this.style.borderColor = '';
        });
    });

    // ==========================================
    // Keyboard shortcut: Enter to submit
    // ==========================================
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && document.activeElement && document.activeElement.closest('#loginForm')) {
            // Let the form handle it naturally
        }
    });

    // ==========================================
    // Auto-capitalize captcha input
    // ==========================================
    captchaInput.addEventListener('input', function () {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    });

    // ==========================================
    // Initialize
    // ==========================================
    function init() {
        generateCaptcha();

        console.log('SIM-TKD - Sistem Informasi Manajemen Tata Kelola Daerah (Modul Edukasi) - Login Page Ready');
    }

    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
