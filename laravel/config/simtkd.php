<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Konfigurasi khusus SIM-TKD
|--------------------------------------------------------------------------
|
| Dipakai saat migrasi bertahap (cutover) dari sistem legacy (PHP vanilla)
| ke Laravel: legacy mem-proxy sebagian endpoint ke aplikasi ini sambil
| membawa TIKET SSO ber-HMAC agar user tidak perlu login ulang.
|
*/

return [

    /*
    | Cutover aktif? Bila false, header tiket cutover DIABAIKAN sama sekali
    | (perilaku normal: Sanctum/session). Biarkan false sampai subdomain
    | aplikasi ini siap menerima lalu lintas dari sistem legacy.
    */
    'cutover_aktif' => (bool) env('SIMTKD_CUTOVER_AKTIF', false),

    /*
    | Secret HMAC untuk tiket cutover. WAJIB sama dengan yang dipakai sisi
    | legacy (`config/cutover.php` -> sso_secret). Kosong = fitur tiket mati;
    | request yang menandai dirinya sebagai proksi akan ditolak 503 agar
    | tidak pernah ada akses tanpa autentikasi.
    */
    'cutover_secret' => (string) env('SIMTKD_CUTOVER_SECRET', ''),

    // Umur tiket (detik). Dibuat baru pada setiap request oleh proksi legacy,
    // jadi cukup pendek (batas: toleransi jam kedua server).
    'cutover_ttl' => (int) env('SIMTKD_CUTOVER_TTL', 90),

    // Toleransi selisih jam server legacy vs server Laravel (detik).
    'cutover_skew' => (int) env('SIMTKD_CUTOVER_SKEW', 15),

    // Catat setiap request yang masuk lewat tiket cutover (audit trail).
    'cutover_log' => (bool) env('SIMTKD_CUTOVER_LOG', true),

    /*
    | Nama tabel yang WAJIB ada (dipakai health check) — bukti database
    | legacy benar-benar sudah dimigrasikan/dimirror dengan benar.
    */
    'tabel_wajib' => [
        'users',
        'dokumen',
        'dokumen_ttd',
        'user_ttd',
        'personal_access_tokens',
        'sessions',
    ],
];
