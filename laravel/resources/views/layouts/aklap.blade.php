<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="referrer" content="no-referrer">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    {{-- Token CSRF untuk panggilan fetch ke API (sesi Laravel + Sanctum stateful). --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="api-base" content="{{ url('/api/v1/aklap') }}">
    <title>@yield('title', 'AKLAP') - SIM-TKD</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="{{ asset('css/aklap.css') }}">
    @yield('styles')
</head>
<body>
@php
    $menu = config('aklap_menu', []);
    $user = auth()->user();
    $inisial = mb_strtoupper(mb_substr(trim((string) ($user->nama_lengkap ?? 'U')), 0, 1));

    // Halaman aktif ditandai lewat nama route (halaman Laravel) atau nama
    // berkas legacy — meniru deteksi `currentFile()` pada menu.js legacy.
    $aktif = trim((string) $__env->yieldContent('menu-aktif'));
    $berkasAktif = trim((string) $__env->yieldContent('menu-file'));

    // Halaman yang belum diporting tetap menautkan berkas legacy. Di produksi
    // (legacy_base kosong) berkas .html berada di host yang sama -> tautan
    // identik seperti web lama; di pengembangan arahkan ke server legacy.
    $legacyBase = (string) config('aklap.legacy_base', '');

    $tautanButir = static function (array $butir) use ($legacyBase): string {
        if (!empty($butir['route'])) {
            return route($butir['route']);
        }

        $berkas = (string) ($butir['legacy'] ?? '#');

        return $legacyBase !== '' ? $legacyBase . $berkas : url($berkas);
    };
@endphp

<header class="topbar">
    <div class="topbar-left">
        <div class="logo-badge"><img src="{{ asset('img/logo-polban.png') }}" alt="Logo POLBAN"></div>
        <div class="brand">
            <b>AKLAP · SIM-TKD</b>
            <span>Aplikasi Laporan Akuntansi Pemerintahan</span>
        </div>
    </div>
    <div class="topbar-right">
        <div class="region">
            <b>{{ $user->instansi ?? '-' }}</b>
            <span>{{ $user->kota ?? '' }}{{ !empty($user->provinsi) ? ' · ' . $user->provinsi : '' }}</span>
        </div>
        <div class="topbar-user">
            <div class="avatar">{{ $inisial }}</div>
            <div class="region">
                <b>{{ $user->nama_lengkap ?? 'Pengguna' }}</b>
                <span>{{ $user->peran ?? '' }}</span>
            </div>
            <form method="POST" action="{{ route('keluar') }}">
                @csrf
                <button type="submit" class="btn-logout" title="Keluar">
                    <i class="fas fa-right-from-bracket"></i> Keluar
                </button>
            </form>
        </div>
    </div>
</header>

<div class="body-wrap">
    <aside class="sidebar" id="sidebar">
        <div class="side-title">Menu AKLAP</div>

        @foreach ($menu as $butir)
            @if (!empty($butir['anak']))
                {{-- Dropdown — sama dengan grupHtml() pada peta.simtkd.com/js/menu.js --}}
                @php
                    $kunci = (string) ($butir['kunci'] ?? 'Grup' . $loop->index);
                    $adaAktif = false;

                    foreach ($butir['anak'] as $anak) {
                        if (!empty($anak['route']) && $aktif === $anak['route']) {
                            $adaAktif = true;
                        } elseif (!empty($anak['legacy']) && $berkasAktif === $anak['legacy']) {
                            $adaAktif = true;
                        }
                    }
                @endphp

                <div class="side-group {{ $adaAktif ? 'open' : '' }}" id="sideGroup{{ $kunci }}">
                    <a href="#" class="side-link" id="sideLink{{ $kunci }}">
                        <i class="fas {{ $butir['ikon'] ?? 'fa-circle' }}"></i>
                        {{ $butir['label'] }}
                        <i class="fas fa-chevron-right side-arrow"></i>
                    </a>
                    <div class="side-sub {{ $adaAktif ? 'open' : '' }}" id="sideSub{{ $kunci }}">
                        @foreach ($butir['anak'] as $anak)
                            @php
                                $punyaRoute = !empty($anak['route']);
                                $href = $tautanButir($anak);
                                $iniAktif = $punyaRoute
                                    ? $aktif === $anak['route']
                                    : ($berkasAktif !== '' && $berkasAktif === ($anak['legacy'] ?? null));
                            @endphp
                            <a class="side-link {{ $iniAktif ? 'active' : '' }}" href="{{ $href }}">
                                <i class="fas {{ $anak['ikon'] ?? 'fa-circle' }}"></i>
                                {{ $anak['label'] }}
                                @unless ($punyaRoute)
                                    <small title="Belum diporting">legacy</small>
                                @endunless
                            </a>
                        @endforeach
                    </div>
                </div>
            @else
                @php
                    $punyaRoute = !empty($butir['route']);
                    $href = $tautanButir($butir);
                    $iniAktif = $punyaRoute
                        ? $aktif === $butir['route']
                        : ($berkasAktif !== '' && $berkasAktif === ($butir['legacy'] ?? null));
                @endphp
                <a class="side-link {{ $iniAktif ? 'active' : '' }}" href="{{ $href }}">
                    <i class="fas {{ $butir['ikon'] ?? 'fa-circle' }}"></i>
                    {{ $butir['label'] }}
                    @unless ($punyaRoute)
                        <small title="Belum diporting">legacy</small>
                    @endunless
                </a>
            @endif
        @endforeach
    </aside>
    <div class="main">
        <div class="breadcrumb-bar">
            <div class="crumb">
                <i class="fas fa-house"></i> <a href="{{ route('aklap.beranda') }}">Home</a>
                @hasSection('crumb-grup')
                    <i class="fas fa-chevron-right"></i><span>@yield('crumb-grup')</span>
                @endif
                @hasSection('crumb')
                    <i class="fas fa-chevron-right"></i><span class="current">@yield('crumb')</span>
                @endif
            </div>
            <a class="btn-back" href="https://simtkd.com/dashboard.html" data-back-main>
                <i class="fas fa-arrow-left"></i> Kembali ke Penatausahaan
            </a>
        </div>

        <main class="content">
            @yield('content')
        </main>

        <footer class="footer">
            &copy; {{ date('Y') }} AKLAP - Modul Edukasi SIM-TKD | Politeknik Negeri Bandung (Jurusan Akuntansi)
        </footer>
    </div>
</div>

<div id="toast"></div>

{{-- Shim config.js: basis API Laravel + tautan halaman yang belum diporting. --}}
<script src="{{ asset('js/aklap/config.js') }}"></script>

{{-- Toggle sidebar — berkas ini SALINAN PERSIS peta.simtkd.com/js/sidebar.js:
     menyuntikkan tombol hamburger di .topbar-left & menyimpan status lipat. --}}
<script src="{{ asset('js/aklap/sidebar.js') }}"></script>

<script>
    // Toast sederhana (dipakai halaman untuk umpan balik aksi).
    window.toast = function (pesan, jenis) {
        const kotak = document.getElementById('toast');
        if (!kotak) { return; }

        const item = document.createElement('div');
        item.className = 'toast-item' + (jenis ? ' ' + jenis : '');
        item.textContent = pesan;
        kotak.appendChild(item);

        setTimeout(function () { item.remove(); }, 4200);
    };

    // Ringkas angka rupiah: 1234567 -> "1.234.567".
    window.rupiah = function (angka) {
        return new Intl.NumberFormat('id-ID').format(Math.round(Number(angka) || 0));
    };

    /**
     * Bantuan panggilan API AKLAP.
     *
     * Autentikasi memakai SESI Laravel (Sanctum stateful): cookie sesi
     * terkirim otomatis karena halaman ini same-origin. Header CSRF wajib
     * untuk POST.
     */
    window.aklap = {
        async minta(jalur, opsi) {
            opsi = opsi || {};

            const meta = document.querySelector('meta[name="csrf-token"]');
            const basis = document.querySelector('meta[name="api-base"]').content;
            const o = {
                method: opsi.method || 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': meta ? meta.content : ''
                },
                credentials: 'same-origin'
            };

            if (opsi.body) {
                o.headers['Content-Type'] = 'application/json';
                o.body = JSON.stringify(opsi.body);
            }

            const res = await fetch(basis + jalur, o);
            let data = null;

            try { data = await res.json(); } catch (e) { data = null; }

            if (!res.ok || !data || data.success !== true) {
                const pesan = (data && data.message) ? data.message : ('Permintaan gagal (HTTP ' + res.status + ').');
                throw new Error(pesan);
            }

            return data;
        }
    };

    // Buka/tutup dropdown sidebar — perilaku sama dengan menu.js legacy
    // (klik judul grup -> .side-group.open + .side-sub.open).
    document.querySelectorAll('.side-group').forEach(function (grup) {
        const kunci = grup.id.replace('sideGroup', '');
        const sub = document.getElementById('sideSub' + kunci);
        const tautan = document.getElementById('sideLink' + kunci);

        if (!sub || !tautan) { return; }

        tautan.addEventListener('click', function (e) {
            e.preventDefault();
            grup.classList.toggle('open');
            sub.classList.toggle('open');
        });
    });
</script>

@yield('scripts')
</body>
</html>

