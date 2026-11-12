# Migrasi SIM-TKD ke Laravel — Tanpa Downtime

Panduan operasional (runbook) untuk memindahkan sistem legacy (PHP vanilla)
ke Laravel **tanpa mematikan layanan** dan **tanpa logout massal** pengguna.

> **Prinsip:** kedua sistem memakai **database yang sama** dan **tidak ada
> migrasi data**. Karena itu "cutover" di sini = memindahkan *lalu lintas
> request*, bukan data. Setiap langkah bisa dibalik dalam hitungan detik.

---

## 1. Ringkasan mekanisme

```
                 Browser pengguna
                        │
                        ▼
        simtkd.com  ┌──────────────────────────────┐
                    │  PHP legacy (docroot lama)   │
                    │                              │
                    │  includes/cutover.php        │
                    │   ├─ endpoint MATI?  ────────┼──► dilayani legacy (seperti biasa)
                    │   └─ endpoint AKTIF? ────────┼──► proxy + TIKET SSO
                    └──────────────────────────────┘          │
                                                              ▼
                                          next.simtkd.com ──► Laravel (public/)
```

* **Frontend 62 halaman tidak diubah.** Halaman tetap memanggil
  `/api/*.php` seperti biasa; yang berpindah adalah *di mana* request itu
  dilayani.
* **Legacy tetap menjadi sumber tunggal otentikasi** selama migrasi
  (login, session, logout, aktivasi tidak dialihkan). Laravel menerima
  identitas pengguna lewat **tiket SSO ber-HMAC** yang dibuat dari sesi legacy
  → pengguna tidak pernah diminta login ulang.
* **Per endpoint, bukan sekaligus.** Satu endpoint bisa dinyalakan/dimatikan
  sendiri (`config/cutover.php`).
* **Canary** (`rollout` 0–100%) dengan sifat *sticky per user*, supaya satu
  pengguna tidak berpindah-pindah sistem dalam satu sesi.
* **Fail-safe:** bila Laravel tidak terjangkau, endpoint baca-saja langsung
  dilayani legacy lagi; endpoint tulis membalas 503 (tidak pernah ditulis
  dua kali).

---

## 2. Prasyarat

| # | Hal | Cara memastikan |
|---|---|---|
| 1 | Host Laravel: **PHP ≥ 8.2** + ekstensi `pdo_mysql`, `mbstring`, `curl`, `openssl`, `json`, `tokenizer`, `xml`, `ctype`, `bcmath` | `php -v`, `php -m` |
| 2 | Kredensial DB legacy **sama** untuk kedua aplikasi | bandingkan `config/credentials.php` (legacy) dengan `.env` (Laravel) |
| 3 | `public/` Laravel menjadi **document root** subdomain baru (mis. `next.simtkd.com`), **bukan** folder induk | cPanel → Subdomains → Document Root = `…/simtkd-app/public` |
| 4 | `php artisan migrate --force` dijalankan (idempoten, non-destruktif) | `GET /api/v1/health` → `status: ok`, `tabel_hilang: []` |
| 5 | `SIMTKD_CUTOVER_AKTIF=true` + `SIMTKD_CUTOVER_SECRET=<32+ karakter>` di `.env` Laravel | `GET /api/v1/health` → `cutover.aktif: true` |
| 6 | Sekret yang sama di `config/cutover.php` sisi legacy (`sso_secret`) | jalankan `tools/cutover/verifikasi.php` |
| 7 | Jam kedua server sinkron (NTP/datacenter) | `date -u` pada kedua server |

> Jika prasyarat 6 belum lulus, **jangan** menyalakan pengalihan: pengguna
> akan ter-logout dan menerima 401 dari endpoint yang dipindahkan.

### Menyiapkan subdomain aplikasi baru (cPanel)

1. Upload/`git pull` repo ke server, lalu salin aplikasi ke luar webroot:
   `~simtkdco/simtkd-app/` (lihat `.cpanel.laravel.yml`).
2. `cd ~/simtkdco/simtkd-app && composer install --no-dev --optimize-autoloader`.
3. Salin `.env.production.example` → `.env`, isi kredensial DB + secret cutover,
   jalankan `php artisan key:generate`.
4. `php artisan migrate --force && php artisan config:cache && php artisan route:cache`.
5. Buat subdomain di cPanel dengan Document Root = `…/simtkd-app/public`.
6. Pastikan `storage/` dan `bootstrap/cache/` dapat ditulis oleh user web
   (`chmod -R 775 storage bootstrap/cache`).
7. Buka `https://next.simtkd.com/api/v1/health` → harus `status: ok`.

---

## 3. Prosedur cutover (bertahap)

### Langkah 0 — Verifikasi prasyarat

```bash
php laravel/tools/cutover/verifikasi.php \
    --laravel=https://next.simtkd.com \
    --legacy=https://simtkd.com \
    --uid=1                      # id user uji yang aman dipakai
```

Semua pemeriksaan harus **LULUS** (exit code 0). Perintah ini membuktikan
health aplikasi baru, keaktifan tiket cutover, dan bahwa tiket yang dibuat
dengan secret sisi legacy **diterima** Laravel.

### Langkah 1 — Nyalakan tiket di sisi Laravel (belum ada pengalihan)

Pada `.env` Laravel:

```env
SIMTKD_CUTOVER_AKTIF=true
SIMTKD_CUTOVER_SECRET=<hasil: php -r "echo bin2hex(random_bytes(24));">
SIMTKD_CUTOVER_TTL=90
```

Lalu `php artisan config:cache`. Pada tahap ini **belum ada** satu pun request
yang dialihkan (sisi legacy masih `enabled => false`), jadi risiko nol.

### Langkah 2 — Canary 1 endpoint baca-saja, 10%

Di `config/cutover.php` (sisi legacy) setel **hanya** secret dan saklar:

```php
'enabled'    => true,
'sso_secret' => '<secret yang sama>',
'rollout'    => 10,          // 10% pengguna (sticky) memakai sistem baru
'base_url'   => 'https://next.simtkd.com',
// peta `routes` bawaan sudah berisi endpoint yang modulnya SELESAI diporting
```

Unggah/deploy file tersebut, lalu pantau 15–30 menit:

```bash
tail -f logs/cutover.log                     # ALIHKAN / fallback per request
tail -f laravel/storage/logs/laravel.log     # [CUTOVER] request via tiket SSO
curl -I https://simtkd.com/api/summary.php   # X-Simtkd-Served-By: cutover
```

### Langkah 3 — Naikkan bertahap

| Tahap | `rollout` | Amati |
|---|---|---|
| Kanari | 10 | error rate, keluhan pengguna, log fallback |
| Setengah | 50 | bandingkan data (`verifikasi.php --proxy`) |
| Penuh | 100 | semua endpoint baca-saja |

Setiap kenaikan: ubah **satu angka** lalu deploy perubahan
`config/cutover.php` (atau setel `SIMTKD_CUTOVER_ROLLOUT` di server tanpa
deploy).

### Langkah 4 — Endpoint tulis

Setelah endpoint baca-saja stabil ≥ 1 hari kerja, endpoint tulis
(`profil:simpan`, `skp:create/update/delete`, `dokumen:kirim`, dst.) ikut
dialihkan karena sudah ada di peta `routes` (tanpa fallback). Uji dengan akun
internal, lalu pantau log.

### Langkah 5 — Frontend (Fase 4, menyusul)

Setelah **semua** endpoint dilayani Laravel, halaman HTML/JS dipindahkan agar
berkomunikasi langsung ke `/api/v1/*` (Sanctum/sesi Laravel). Pada tahap ini
login/logout/aktivasi dipindahkan ke Laravel (menggantikan sesi legacy), lalu
proksi legacy dihapus.

---

## 4. Rollback (kapan pun, < 1 menit)

| Ingin | Lakukan |
|---|---|
| Semua kembali ke legacy | `setenv SIMTKD_CUTOVER 0` (atau `'enabled' => false`) |
| Turunkan persen | `setenv SIMTKD_CUTOVER_ROLLOUT 0` |
| Endpoint tertentu saja | hapus/comment entri di `routes` lalu deploy |
| Laravel bermasalah total | biarkan fail-safe (`fallback`) bekerja, atau matikan subdomain |

**Tidak ada data yang perlu dikembalikan** — kedua sistem menulis ke tabel
yang sama. Tidak ada migrasi skema, tidak ada sinkronisasi dua arah.

---

## 5. Pemantauan & indikator

| Indikator | Di mana | Arti |
|---|---|---|
| `ALIHKAN -> /api/v1/...` | `logs/cutover.log` | request dilayani sistem baru |
| `fallback` | `logs/cutover.log` | Laravel tak terjangkau → legacy ambil alih (baca-saja) |
| `[CUTOVER] request via tiket SSO` | `storage/logs/laravel.log` | tiket diterima (audit trail) |
| `X-Simtkd-Served-By: cutover` | header respons | bukti sistem baru melayani request |
| `Tiket cutover ditolak` | `storage/logs/laravel.log` | **berhentikan cutover**: secret/jam tidak sinkron |
| `/api/v1/health` ≠ 200 | monitor eksternal | Laravel tidak siap → turunkan `rollout` ke 0 |

Saran alarm: `health` (interval 1 menit), serta jumlah baris `fallback` dan
`ditolak` per 5 menit (notifikasi bila > 0).

---

## 6. Uji dan bukti (dijalankan di repo ini)

| Uji | Perintah | Hasil |
|---|---|---|
| Unit/feature Laravel (tiket, health, isolasi tenant) | `cd laravel && php artisan test` | 84 lulus (11 khusus cutover) |
| Kesetaraan legacy vs Laravel per modul | `php laravel/tools/difftest/run.php --filter=dokumen` | 11 lulus, 1 disengaja, 0 gagal |
| Rantai cutover E2E (legacy → tiket → Laravel) | 9 endpoint baca + 1 tulis + kanari + sticky + token doc | semua identik & terbukti dilayani Laravel |

---

## 7. Dekomisioning legacy (setelah stabil)

1. Pastikan peta `routes` mencakup **seluruh** endpoint (status porting 100%).
2. Pindahkan frontend (Fase 4) dan login ke Laravel.
3. `rollout => 100`, amati 1–2 minggu.
4. Arsipkan docroot legacy (jangan hapus: jalan rollback terakhir) dan
   pertahankan snapshot DB.
5. Hapus `includes/cutover.php`, pemanggilan `cutover_maybe_proxy()` di endpoint
   legacy, serta `config/cutover.php` setelah tidak dipakai.

---

## 8. Berkas yang terlibat

| Berkas | Peran |
|---|---|
| `config/cutover.php` (legacy) | saklar, secret, peta endpoint, canary |
| `includes/cutover.php` (legacy) | proksi + pembuat tiket SSO |
| `laravel/config/simtkd.php` | keaktifan tiket, TTL, tabel wajib (health) |
| `laravel/app/Support/CutoverTicket.php` | format & verifikasi tiket (HMAC) |
| `laravel/app/Http/Middleware/TerimaTiketCutover.php` | autentikasi request dari proksi |
| `laravel/app/Http/Controllers/Api/V1/HealthController.php` | readiness probe |
| `laravel/tools/cutover/verifikasi.php` | alat verifikasi pra/pasca cutover |
| `logs/cutover.log` | jejak pengalihan (server legacy) |

> **Catatan subdomain dokumen:** `doc.simtkd.com/api/dokumen.php` memuat
> pustaka proksi dari `__DIR__ . '/../../includes/cutover.php'`, yaitu
> `includes/` milik aplikasi utama (parent docroot). Bila subdomain di-deploy
> ke document root yang **bukan** subfolder `public_html` (mis. domain
> terpisah), salin `includes/cutover.php` + `config/cutover.php` ke lokasi
> yang setara agar endpoint dokumen juga bisa dialihkan. Bila berkas tidak
> ditemukan, pengalihan dilewati dan legacy melayani seperti biasa (aman).
