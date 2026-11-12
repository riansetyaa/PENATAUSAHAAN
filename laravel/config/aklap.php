<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Konfigurasi frontend AKLAP (Fase 4)
|--------------------------------------------------------------------------
|
| `legacy_base` dipakai halaman Blade untuk menautkan halaman yang BELUM
| diporting (masih .html di server legacy).
|
|  - Produksi: biarkan kosong (''), karena halaman legacy berada di host yang
|    sama -> tautan persis seperti web sebelumnya.
|  - Pengembangan: isi alamat server legacy, mis.
|    AKLAP_LEGACY_BASE=http://127.0.0.1:8898/peta.simtkd.com/
|
*/

return [
    'legacy_base' => rtrim((string) env('AKLAP_LEGACY_BASE', ''), '/') . (env('AKLAP_LEGACY_BASE', '') !== '' ? '/' : ''),

    /*
    |----------------------------------------------------------------------
    | Folder sumber legacy (PENATAUSAHAAN)
    |----------------------------------------------------------------------
    |
    | Dipakai untuk membandingkan aset hasil porting dengan aslinya —
    | mis. memastikan `public/js/aklap/menu.js` benar-benar salinan persis
    | `peta.simtkd.com/js/menu.js`. Bisa diarahkan lewat AKLAP_LEGACY_ROOT.
    |
    */
    'legacy_root' => rtrim((string) env('AKLAP_LEGACY_ROOT', dirname(base_path()) . '/PENATAUSAHAAN'), '/'),
];
