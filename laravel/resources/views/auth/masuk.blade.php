<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="referrer" content="no-referrer">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Masuk - SIM-TKD</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="{{ asset('css/aklap.css') }}">
    <style>
        body { align-items: center; justify-content: center; padding: 24px; }
        .masuk-wrap { width: 100%; max-width: 400px; }
        .masuk-brand { text-align: center; color: var(--navy); margin-bottom: 18px; }
        .masuk-brand i { font-size: 30px; }
        .masuk-brand b { display: block; font-size: 19px; margin-top: 8px; }
        .masuk-brand span { font-size: 12.5px; color: var(--muted); }
        .masuk-card { background: #fff; border: 1px solid var(--border); border-radius: 10px; box-shadow: 0 6px 18px rgba(16,24,40,.08); padding: 24px; }
        .masuk-card h2 { font-size: 15px; color: var(--navy); margin-bottom: 16px; }
        .masuk-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
        .masuk-field label { font-size: 12px; font-weight: 600; color: var(--muted); }
        .masuk-field input {
            height: 38px; border: 1px solid var(--border); border-radius: 6px; padding: 0 11px;
            font-size: 13.5px; width: 100%;
        }
        .masuk-field input:focus { outline: 2px solid #bfdbfe; border-color: var(--blue); }
        .masuk-card .btn { width: 100%; justify-content: center; }
        .galat {
            background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 6px;
            padding: 10px 12px; font-size: 12.5px; margin-bottom: 14px;
        }
        .masuk-note { text-align: center; font-size: 11.5px; color: var(--muted); margin-top: 16px; }
    </style>
</head>
<body>
<div class="masuk-wrap">
    <div class="masuk-brand">
        <i class="fas fa-city"></i>
        <b>SIM-TKD</b>
        <span>Akuntansi &amp; Pelaporan Keuangan Daerah</span>
    </div>

    <div class="masuk-card">
        <h2><i class="fas fa-right-to-bracket"></i> Masuk ke aplikasi</h2>

        @if ($errors->any())
            <div class="galat">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('masuk.simpan') }}">
            @csrf

            <div class="masuk-field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="{{ old('username') }}"
                       autocomplete="username" required autofocus>
            </div>

            <div class="masuk-field">
                <label for="password">Kata sandi</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-arrow-right-to-bracket"></i> Masuk
            </button>
        </form>
    </div>

    <p class="masuk-note">
        Halaman ini bagian dari aplikasi Laravel (Fase 4 migrasi).<br>
        Akun yang sama dengan sistem lama dapat dipakai.
    </p>
</div>
</body>
</html>
