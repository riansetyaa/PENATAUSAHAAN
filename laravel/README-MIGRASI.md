# Migrasi SIM-TKD ke Laravel

Dokumen ini menjelaskan arsitektur aplikasi Laravel yang menggantikan sistem
PHP vanilla (legacy) secara bertahap, tanpa mengubah skema database.

> **Status:** Fase 0 (penguatan sistem live), Fase 1 (fondasi Laravel),
> Fase 2 (differential test harness), Fase 3a (Penerimaan + Dashboard/Profil/
> Lupa Password), **Fase 3b (Belanja)**, **Fase 3c (AKLAP: `aklap.php` 8/8 +
> `akuntansi.php` 40/40)**, Fase 3d (Dokumen & TTD) dan **Fase 3e (STS/STBP)**
> selesai — Fase 3 tuntas.
> **Fase 4** dimulai: layout Blade + login + halaman pilot (Buku Kas
> Pengeluaran PPKD) sudah berjalan di Laravel, halaman lain masih dilayani
> legacy lewat menu bersama (§11).
> **Fase 5**: `verifikasi.php` LULUS semua (health, tiket SSO, paritas lewat
> proksi) — siap cutover bertahap (§12).

---

## 1. Prinsip utama

### 1.1 Skema database TIDAK berubah

40 tabel legacy **dipakai apa adanya**. Migration Laravel hanya *mencerminkan*
skema yang sudah ada:

```php
public function up(): void
{
    if (Schema::hasTable('akun_master')) {
        return;  // tabel sudah ada -> jangan sentuh
    }
    Schema::create('akun_master', function (Blueprint $table) { ... });
}
```

Konsekuensinya:

- **Nol migrasi data** — risiko tertinggi sebuah rewrite hilang total.
- **Rollback trivial**: kembalikan docroot cPanel + snapshot DB = sistem lama
  hidup lagi dalam 5 menit.
- `database/migrations/2026_01_01_000000_mirror_*.php` dibuat oleh
  `tools/generate-schema-artifacts.php` yang membaca `information_schema`
  dari database live. Jadi hasilnya dijamin identik dengan skema nyata.

### 1.2 Satu aplikasi, tiga subdomain

```
simtkd.com        /            modul utama (penerimaan, belanja, pengaturan)
peta.simtkd.com  /aklap       modul AKLAP (akuntansi)
doc.simtkd.com   /dokumen     modul dokumen & tanda tangan elektronik
```

Ketiganya dilayani satu aplikasi Laravel, dirouting berdasarkan `Host`.

---

## 2. Peta pemetaan legacy → Laravel

| Legacy | Laravel |
|---|---|
| `api/*.php` + `if ($action === 'x')` | Route + Controller + FormRequest |
| 367 PDO prepared statement | Eloquent Model / Query Builder |
| `includes/auth.php` (sesi manual) | Laravel Auth + `Auth` facade |
| `hashApiToken()` + `users.api_token` | **Sanctum** Personal Access Token (hash, expired 12 jam) |
| `requireInstansi()` + `WHERE skpd = ?` manual | **Global scope** `HasSkpdScope` + `Tenant` |
| `ensureUsersColumns()` (DDL runtime) | `php artisan migrate` |
| `jsonResponse()` | `ApiResponse::ok()` / `::fail()` (bentuk JSON identik) |
| `includes/mailer.php` (410 baris SMTP manual) | `Mail` facade + **Notification** + Queue |
| `docWaktu()` (format waktu Indonesia doc.simtkd.com) | `App\Support\Waktu::indo()` (string identik) |
| Rate limit berbasis file | **RateLimiter** (cache database) |
| Enum `peran` (6 nilai) | **Spatie laravel-permission** (6 role + 12 permission) |
| Kolom `otp_*` manual | `OtpService` + tabel `users.otp_*` (tetap sama) |

---

## 3. Kompatibilitas frontend

Frontend yang ada (62 halaman HTML + JS vanilla) **tidak diubah** pada tahap
awal. Bentuk JSON dipertahankan persis:

```json
{ "success": true, "message": "...", ... }
```

Kode status HTTP juga sama (200/201/401/403/404/409/422/429/500).
Ini penting karena:
1. Frontend tidak perlu diubah mendadak.
2. *Differential test harness* (Fase 2) dapat membandingkan API lama vs baru
   dan memperlakukan output lama sebagai *golden master*.

Header auth berubah dari query string ke header:

```js
// SEBELUM
fetch(url + '?token=' + token)

// SESUDAH
fetch(url, { headers: { 'Authorization': 'Bearer ' + token } })
```

---

## 4. Multi-tenancy

Semua tabel domain punya kolom `skpd`. Global scope menerapkannya otomatis:

```php
// Otomatis terfilter ke SKPD user yang sedang login
Sts::query()->latest()->get();

// Lintas tenant (konsolidator/admin)
Sts::withoutGlobalScope(HasSkpdScope::class)->get();
```

Sifatnya **fail-closed**: user tanpa instansi tidak melihat data apa pun
(diuji di `tests/Feature/TenantScopeTest.php`).

---

## 5. Otorisasi berbasis peran

Pada sistem legacy, kolom `peran` di-`SELECT` tapi **tidak pernah dicek** —
siapa pun dalam satu SKPD bisa menyetujui jurnal, menghapus akun, dan
mengirim dokumen tanda tangan.

Sekarang tersedia dua lapis:

1. **Role Spatie** — `User::PERAN_TO_ROLE` memetakan ENUM `peran` ke role.
   Akun legacy otomatis disinkronkan oleh `RoleSeeder`.
2. **Middleware `peran`** pada route:

```php
Route::post('aklap/jurnal/{id}/approve', ...)->middleware('peran:verifikator,kepala-dinas,admin-dinas');
```

---

## 6. Perintah penting

```bash
cd laravel

# Skema
php artisan migrate                 # idempoten, aman di DB berisi data
php artisan db:seed --class=RoleSeeder

# Pengembangan
php artisan serve
php artisan test

# Regenerasi artefak skema (hanya bila skema live berubah)
php tools/generate-schema-artifacts.php
```

### Variabel environment khusus

| Key | Fungsi |
|---|---|
| `SIMTKD_DEBUG_OTP` | `true` → OTP dikembalikan di respons (uji lokal saja) |
| `SIMTKD_TENANT_COLUMN` | Nama kolom tenant (default `skpd`) |
| `SIMTKD_CUTOVER_AKTIF` | `true` → tiket cutover dari proksi legacy diterima (lihat §10) |
| `SIMTKD_CUTOVER_SECRET` | Secret HMAC tiket cutover — **wajib sama** dengan `sso_secret` di `config/cutover.php` |
| `SIMTKD_CUTOVER_TTL` | Umur tiket cutover (detik, default 90) |
| `SIMTKD_CUTOVER_SKEW` | Toleransi selisih jam kedua server (detik) |
| `SIMTKD_SSO_TICKET_TTL` | Umur tiket SSO lintas-subdomain (detik) |
| `SIMTKD_TTD_SECRET` | Kunci enkripsi tanda tangan dokumen |
| `SESSION_DOMAIN` | Kosong di lokal; isi `.simtkd.com` di produksi untuk SSO |

Variabel **sisi legacy** (tanpa perlu deploy ulang, cukup `SetEnv` di Apache):

| Key | Fungsi |
|---|---|
| `SIMTKD_CUTOVER` | `0` = kill switch (semua pengalihan berhenti), `1` = mengikuti `config/cutover.php` |
| `SIMTKD_CUTOVER_ROLLOUT` | Persen canary (0–100, sticky per user) |
| `SIMTKD_CUTOVER_BASE` | Alamat aplikasi Laravel (mis. `https://next.simtkd.com`) |
| `SIMTKD_CUTOVER_SECRET` | Secret tiket (sama dengan Laravel) |

---

## 7. Database uji

Test berjalan pada database **terpisah** (`simtkd_test`), bukan `simtkd`:

```xml
<env name="DB_DATABASE" value="simtkd_test"/>
```

Skema tabel legacy dimuat oleh migration yang sama, sehingga environment uji
identik dengan produksi.

```bash
mysql -u root -p -e "CREATE DATABASE simtkd_test CHARACTER SET utf8mb4;"
php artisan test
```

---

## 8. Peta jalan

| Fase | Isi | Status |
|---|---|---|
| 0 | Penguatan sistem live (token bocor, XSS, kredensial, `db_credentials()`) | ✅ |
| 1 | Fondasi Laravel: model, migration, scope, middleware, auth | ✅ |
| 2 | Differential test harness (golden master) | ✅ |
| 3a | Porting modul **Penerimaan** (permohonan, akun, laporan, SKP) | ✅ |
| 3a | Porting **Dashboard, Profil, Lupa Password** | ✅ |
| 3b | Porting modul Belanja (38 aksi: 15 baca + 23 mutasi) | ✅ |
| 3c | Porting modul AKLAP: **aklap.php 8/8 ✅** + **akuntansi.php 40/40 ✅** (master akun, anggaran, jurnal umum + saran jurnal, laporan, posisi kas harian, rekonsiliasi bank, register + LPJ PPKD, jurnal khusus, kirim dokumen) | ✅ |
| 3d | Porting modul Dokumen & TTD (8 aksi + `kirim`) | ✅ |
| 3e | Porting **STS & STBP** (SKP/Profil/Dashboard/Lupa Password sudah di 3a) | ✅ |
| 4 | Integrasi frontend: **landing/Dashboard AKLAP (salinan persis legacy)** ✅ + layout Blade + login + halaman pilot (Buku Kas Pengeluaran PPKD) ✅; 18 halaman AKLAP lain masih dilayani legacy (menu menautkan keduanya) | 🟨 |
| 5 | Parallel run & verifikasi cutover: **`verifikasi.php` LULUS semua** (health, tiket SSO, 5 endpoint identik lewat proksi) ✅; cutover produksi tetap bertahap per modul | 🟨 |

Detail Fase 0 ada di commit `8b6d34f`.

### Status porting

```
TOTAL 219   WIRED 217   PENDING 2
Endpoint yang sudah dimigrasi: 217/219 (99%)
```

Angka di atas dihitung dari katalog `tools/difftest/scenarios.php`
(`WIRED` = skenario yang endpoint barunya sudah ada; katalog kini bebas
duplikat). Hasil run nyata per modul dicatat pada §9.3.

---

## 9. Differential Test Harness

### 9.1 Konsep

API legacy (PHP vanilla) adalah **golden master**. Apa pun yang dikembalikan
legacy dianggap benar; API baru wajib mengulang hasil yang sama.

Karena kedua API membaca basis data yang sama (`simtkd`), perbandingan nilai
nyata — total anggaran, saldo akun, nomor dokumen — benar-benar bermakna,
bukan sekadar membandingkan bentuk JSON.

```
laravel/tools/difftest/
├── Client.php        Klien HTTP (cURL + cookie jar + Bearer token)
├── Response.php      Hasil request
├── Normalizer.php    Normalisasi & diff JSON
├── Runner.php        Orkestrasi + pelaporan
├── scenarios.php     Katalog skenario (CHECKLIST porting)
└── run.php           CLI
```

### 9.2 Menjalankan

```bash
# Terminal 1 — server legacy
php -S 127.0.0.1:8899 -t /path/ke/PENATAUSAHAAN

# Terminal 2 — server Laravel
cd laravel && php artisan serve --host=127.0.0.1 --port=8291

# Terminal 3 — harness
php laravel/tools/difftest/run.php
php laravel/tools/difftest/run.php --filter=auth      # hanya skenario auth
php laravel/tools/difftest/run.php --pending-only      # hanya yang belum dimigrasi
php laravel/tools/difftest/run.php --verbose
```

Exit code `0` = tidak ada kegagalan, `1` = ada FAIL/ERROR.
Cocok untuk dipakai sebagai langkah wajib CI.

### 9.3 Status saat ini

```
TOTAL 219   WIRED 217   PENDING 2
Endpoint yang sudah dimigrasi: 217/219 (99%)
```

Hasil run nyata (legacy 8899 vs Laravel 8291, basis data yang sama):

```
php laravel/tools/difftest/run.php --filter=dokumen
TOTAL 14   PASS 13   FAIL 0   PENDING 0   ERROR 0   KNOWN 1

php laravel/tools/difftest/run.php --filter=belanja
TOTAL 44   PASS 44   FAIL 0   PENDING 0   ERROR 0   KNOWN 0

php laravel/tools/difftest/run.php --filter=aklap
TOTAL 93   PASS 90   FAIL 0   PENDING 2   ERROR 0   KNOWN 1
           (2 PENDING = aksi mutasi akun_seed_coa & anggaran_sinkron
            yang sudah diporting tetapi diuji lewat PHPUnit)

php laravel/tools/difftest/run.php --filter=sts
TOTAL 8    PASS 8    FAIL 0   PENDING 0   ERROR 0   KNOWN 0

php laravel/tools/difftest/run.php --filter=stbp
TOTAL 7    PASS 7    FAIL 0   PENDING 0   ERROR 0   KNOWN 0

php artisan test
Tests: 193 passed (1727 assertions)

php laravel/tools/difftest/run.php --filter=lupa_password
TOTAL 7    PASS 7    FAIL 0   PENDING 0   ERROR 0   KNOWN 0

php laravel/tools/difftest/run.php --filter=profil
TOTAL 8    PASS 8    FAIL 0   PENDING 0   ERROR 0   KNOWN 0

php laravel/tools/difftest/run.php --filter=penerimaan
TOTAL 6    PASS 6    FAIL 0   PENDING 0   ERROR 0   KNOWN 0

php laravel/tools/difftest/run.php --filter=skp
TOTAL 13   PASS 13   FAIL 0   PENDING 0   ERROR 0   KNOWN 0
```

#### E2E dengan data nyata (`tools/difftest/e2e-belanja.php`)

Harness difftest memakai akun ujinya sendiri, sehingga daftar pada instansi
itu kosong — perbedaan pada *bentuk baris* tidak akan terdeteksi. Untuk itu
tersedia alat E2E yang menyemai data lengkap (rekanan → SPD → SPP → SPM →
SP2D, LPJ, pengajuan TU, rekening, besaran UP, kebijakan SPD, NPD) pada
instansi sementara `Dinas Uji Belanja`, membandingkan respons legacy langsung
vs proksi cutover, lalu **menghapus kembali** seluruh data uji:

```
php laravel/tools/difftest/e2e-belanja.php
TOTAL 20   LULUS 20   GAGAL 0
```

Cakupan: 15 aksi baca + variasi filter status, periode BKU, kolom turunan
(`sisa` SPD, `spd_sisa`), lampiran `details`/`potongan`/`pajak`, kasus
`besaran_up` kosong (null), dan escape karakter `&` pada nama rekanan.
Setiap respons juga diverifikasi **benar-benar dilayani Laravel** lewat
penanda `X-SIMTKD-Served-By: cutover`.

Sejak fase 3b-mutasi, alat yang sama juga menjalankan **seluruh alur mutasi
lewat proksi** (URL legacy -> Laravel), termasuk substitusi `{id}` pada rute:

```
spd_create -> spd_otorisasi -> spp_create -> spp_verifikasi (SPM otomatis)
  -> spm_persetujuan -> spm_verifikasi -> sp2d_create -> sp2d_verifikasi
  -> sp2d_pencairan
rekanan_create, lpj_create, pengajuan_tu_create/otorisasi/validasi,
rekening_skpd_create/ajukan/nomor/validasi, besaran_up_set,
kebijakan_spd_create, npd_create/validasi_pa/validasi_bp,
spp_create GU (LPJ) & TU (pengajuan TU)
```

Hasil terakhir:

```
BACA   TOTAL 20   LULUS 20   GAGAL 0
MUTASI TOTAL 33   LULUS 33   GAGAL 0
```

Termasuk pemeriksaan keadaan basis data: SPM otomatis bernilai netto
(400.000 - 11% pajak = 356.000), SP2D dicairkan memakai tanggal pencairan,
rekening aktif dengan nomor hasil validasi, NPD sampai `divalidasi_bp`, dan
total BKU periode.

#### E2E AKLAP dengan token (`tools/difftest/e2e-aklap.php`)

Subdomain `peta.simtkd.com` memakai autentikasi TOKEN API (`?token=` /
Bearer), bukan cookie sesi. Alat E2E ini membuktikan rantai tersebut bekerja
utuh lewat proksi: **token legacy → tiket SSO (HMAC) → Sanctum di Laravel**,
sekaligus membandingkan respons legacy langsung vs proksi dengan data nyata.

```
php laravel/tools/difftest/e2e-aklap.php
BACA        : 14/14 LULUS
AKUNTANSI   : 12/12 LULUS   (profil, akun_list, anggaran_simpan/hapus,
                             akun_simpan/hapus/seed-coa, sinkron-master)
JURNAL      : 13/13 LULUS   (saran jurnal -> simpan -> approve -> hapus,
                             termasuk penanda sudah_dijurnal)
LAPORAN     : 28/28 LULUS   (buku besar, neraca saldo, buku kas, buku kas
                             umum, buku pembantu, pemilih objek) — 18
                             pembandingan legacy vs proksi + 10 pemeriksaan
                             isi (saldo awal dari neraca_awal, hanya jurnal
                             approved yang diposting, saldo berjalan buku
                             kas, total s.d. bulan pada buku pembantu)
KAS         : 20/20 LULUS   (buku kas simpan/ubah/hapus, posisi kas harian
                             simpan -> baca ulang -> saran H-1 -> hapus)
REKONSILIASI: 12/12 LULUS   (blanko Modul-3E-LK: saran saldo buku dari buku
                             kas, simpan 3 baris A/B/C/D, ganti rincian,
                             hapus)
REGISTER/LPJ: 13/13 LULUS   (register SPP/SPM/SP2D dari Buku Pengeluaran,
                             upsert, LPJ: pagu kode induk, bucket
                             rekonsiliasi, saldo kas, sd_lalu vs bulan_ini)
JURNAL KHUSUS: 12/12 LULUS   (blanko 6 jenis, simpan 2 baris + catatan
                             timpang, kode_extra kolom bebas, SKPKD
                             dipertahankan, hapus)
DOKUMEN KIRIM: 4/4 LULUS     (antrean TTD: kode verifikasi TTD-, perbarui
                             dokumen menunggu, penandatangan diklem)
TOTAL 128  LULUS 128  GAGAL 0
```

Cakupan: 8 aksi baca `aklap.php` (`jurnal`, `jurnal_biasa`, `akun_list`,
`dokumen_list`, `lra_rekap`, `neraca_rekap`, `profil`, `rekap`) + 40 aksi
`akuntansi.php` (Master Akun, Anggaran, Jurnal Umum, Saran Jurnal, laporan,
mutasi buku kas, posisi kas harian, rekonsiliasi bank, register & LPJ PPKD,
jurnal khusus, kirim dokumen) — termasuk pemeriksaan isi (penghitung rekap,
LRA hanya jurnal `sudah_approve`, total belanja SP2D, urutan baris jurnal) dan
bukti bahwa **data yang ditulis Laravel dibaca identik oleh legacy** (pagu,
jurnal, saldo awal, entri buku kas, dokumen kas harian, rekonsiliasi bank,
register PPKD, dan 20 kolom jurnal khusus ditulis lewat proksi lalu dibaca
ulang lewat kedua jalur).

Skenario `PENDING` adalah **checklist porting yang hidup** — setiap endpoint
legacy yang belum ada padanya API baru akan tercatat di sana. Skenario
`KNOWN` adalah perbedaan yang disengaja dan sudah diberi alasan
(`known_divergence`).

Modul Dokumen & TTD sengaja memakai jalur negatif/validasi agar dapat
dibandingkan tanpa fixture dokumen (mutasi seperti *kirim* → *tanda tangan*
memerlukan fixture per-sisi); alur positifnya diuji di
`tests/Feature/Api/DokumenTest.php` (22 tes).

### 9.4 Temuan yang sudah ditemukan harness

| Temuan | Dampak | Perbaikan |
|---|---|---|
| Respons validasi Laravel tidak punya kunci `success` | **Frontend lama rusak** — memeriksa `d.success` | Exception handler global menyeragamkan ke `{success, message, errors}` |
| `throttle` bawaan Laravel memakai kunci IP & JSON berbeda | 429 vs 422, pesan tak sama | Rate limit dipindah ke dalam controller (pola legacy: `username+IP`) |
| Bentuk respons `session` berbeda (pesan, `user.id`, `user.email`) | Frontend bergantung isi | Controller disesuaikan identik dengan legacy |
| `api_token` legacy tak ada padanya API baru | Noise | Di-*drop* per-skenario dengan alasan terdokumentasi |
| **Modul Dokumen:** `ttd()` menghitung status dokumen SEBELUM slot `dokumen_ttd` ditandai | Dokumen tidak pernah berstatus `ditandatangani` → verifikasi keaslian selalu gagal, viewer menampilkan "Menunggu" | Urutan operasi dibalik: tandai slot → baru hitung sisa (`status = 'menunggu' AND user_id IS NOT NULL`) |
| Bentuk respons `detail` berbeda (field `ttd_*`, `hash_*` di luar objek `dokumen`; tanpa `signed_at_fmt`) | `doc.simtkd.com/dokumen.html` menampilkan `-` dan status tombol TTD salah; `index.html` kehilangan `signed_at_fmt` | Bentuk respons disamakan dengan legacy + helper `App\Support\Waktu` (padanan `docWaktu()`) |
| Slot tanda tangan tidak menyimpan `nip`, `nama`, `jabatan` penandatangan | Blok tanda tangan pada dokumen tanpa NIP | Slot diisi dari profil penandatangan (`users.nip`) |
| Blok barcode verifikasi disisipkan pada setiap penandatanganan | Barcode/QR ganda di dokumen | Disisipkan hanya bila `data-ttd-qr="1"` belum ada (sama dengan legacy) |
| Verifikasi mengambil penandatangan dari slot berstatus `ditandatangani` | Penandatangan bisa salah bila urutan 1 belum menandatangani | Diambil dari JOIN `urutan = 1`, persis legacy |
| `kode_verifikasi` pada respons `ttd` | Legacy mengembalikan `null` (variabel tak terdefinisi) | API baru mengembalikan kode yang benar — perbedaan disengaja, didokumentasikan sebagai `KNOWN` bila diuji |
| **Lupa password:** field konfirmasi dibaca `password_konfirmasi`, padahal frontend (`js/lupa-password.js`) mengirim `password_ulangi` | **Seluruh permintaan reset password ditolak** ("Konfirmasi tidak cocok") begitu endpoint dialihkan ke Laravel | Dibaca sebagai `password_ulangi` (fallback `password_konfirmasi`), `field` respons dikembalikan `password_ulangi` |
| Pesan & kunci respons reset lupa password berbeda dari legacy ("Password baru minimal…" vs "Kata sandi baru minimal…", tanpa `status`/`redirect`) | Teks & alur di halaman pengguna berbeda dari sistem lama | Seluruh pesan, `field`, `kedaluwarsa`, `need_activation`, `status`, dan `redirect` disamakan dengan `api/lupa_password.php` |
| Token/sesi lama tidak dicabut saat password direset | Token tetap berlaku (legacy mengosongkan `users.api_token`) | `$user->tokens()->delete()` — semua token Sanctum dicabut |
| Katalog skenario difftest memuat **entri duplikat** (sts/stbp/skp/laporan/summary/profil) sehingga endpoint yang sudah diporting tetap muncul sebagai `PENDING` | Status porting terlihat lebih rendah dari kenyataan & sulit dipercaya | Duplikat dihapus; katalog kini bebas nama ganda dan seluruh endpoint porting ter-*wire* |
| `id` pada respons create STS/STBP | Legacy mengembalikan string (`lastInsertId()` PDO) | API baru mengembalikan angka (int). Nilai id memang selalu berbeda antar-baris baru, sehingga skenario create diuji lewat jalur validasi/negatif (bukan perbandingan nilai) |
| Daftar Belanja mengembalikan baris tabel **apa adanya** (`SELECT s.*`) | Eloquent akan mengubah tipe (DECIMAL→float, tanggal→objek) sehingga JSON berbeda dari legacy | Seluruh endpoint baca Belanja memakai **Query Builder** + `Tenant::requireSkpd()`, dan syarat tenant selalu berkualifikasi (`s.skpd`) karena `spd`/`skp_daerah` juga punya kolom `skpd` |
| Respons sukses modul AKLAP tidak memuat kunci `message` (mis. `{success, data}`) | Helper `ok()` standar selalu menyertakan `message` sehingga bentuk JSON berbeda dari legacy | Ditambah `ApiResponse::okTanpaPesan()` / `Controller::okData()` khusus modul AKLAP |
| Pesan saat akun tanpa instansi: legacy (AKLAP) *"Instansi akun belum diatur. Hubungi administrator."* vs middleware `tenant` *"Instansi belum diatur pada akun Anda. Hubungi administrator."* | Sama-sama 403 dan fail-closed; hanya kata-katanya berbeda | Dibiarkan konsisten dengan middleware modul lain (satu pesan untuk seluruh API baru). Tidak diuji karena harness selalu punya instansi |
| Legacy AKLAP (`akuntansi.php`, `aklap.php`) membaca `action`/`token` dari **QUERY STRING** meski request-nya POST | Harness differential test semula mengirim `action` di body → legacy membalas 400 "Aksi tidak dikenal." sehingga 8 skenario FAIL | `Client::request()` + `Runner` diberi dukungan `query` (parameter query terpisah dari body); skenario AKLAP POST memakai `query` |

Harness juga menemukan **dua bug pada dirinya sendiri** (variabel runtime
tak diteruskan; ekspektasi tak di-interpolasi) — keduanya diperbaiki.

### 9.5 Cara menambah skenario

```php
[
    'name'  => 'aklap.buku_besar',
    'desc'  => 'Buku besar',
    'legacy' => ['method' => 'GET', 'path' => '/api/akuntansi.php', 'params' => ['action' => 'buku_besar']],
    'baru'   => ['method' => 'GET', 'path' => '/api/v1/aklap/buku-besar'],
    // Kunci yang boleh berbeda (opsional)
    'drop'   => ['user.api_token'],
    // Ekspektasi tambahan pada API baru (opsional)
    'expect' => ['success' => true],
],
```

Kunci opsional yang dikenali `Runner`:

| Kunci | Fungsi |
|---|---|
| `drop` | Daftar kunci JSON yang boleh berbeda (`user.api_token`, `message`, …) |
| `expect` | Nilai yang WAJIB ada pada respons API baru (mendukung placeholder) |
| `vars` | Variabel runtime tambahan untuk placeholder skenario ini |
| `known_divergence` | `['reason' => ..., 'note' => ...]` — perbedaan disengaja → status `KNOWN`, bukan `FAIL` |
| `token` (di dalam `baru`) | Menimpa Bearer token sesaat (mis. menguji 401), lalu dipulihkan |

Placeholder `{{user.aktif.username}}` diisi dari akun uji runtime. Akun uji
dibuat dan dibersihkan otomatis oleh harness.

Untuk modul yang masih memakai `?token=` (doc.simtkd.com), harness menyiapkan
`{{user.aktif.token}}` di `run.php` — token legacy ditulis ke
`users.api_token` (hash SHA-256) SETELAH login, karena login legacy merotasi
token tersebut tanpa mengembalikannya. API baru tidak memakai placeholder ini
(Sanctum Bearer dipasang otomatis pada klien `baru`).

---

## 10. Migrasi tanpa downtime (cutover)

Runbook lengkap: **[docs/CUTOVER-TANPA-DOWNTIME.md](docs/CUTOVER-TANPA-DOWNTIME.md)**.

Ringkasnya, karena kedua sistem memakai database yang sama dan **tidak ada
migrasi data**, perpindahan dilakukan pada lapisan *lalu lintas request*:

```
simtkd.com/api/*.php  ──(includes/cutover.php)──►  next.simtkd.com/api/v1/*
        legacy                     + tiket SSO              Laravel
```

| Komponen | Berkas |
|---|---|
| Saklar, canary, peta endpoint | `config/cutover.php` (sisi legacy) |
| Proksi + pembuat tiket | `includes/cutover.php` |
| Verifikasi tiket & autentikasi | `app/Support/CutoverTicket.php`, `app/Http/Middleware/TerimaTiketCutover.php` |
| Readiness probe | `GET /api/v1/health` (`HealthController`) |
| Alat verifikasi pra/pasca cutover | `tools/cutover/verifikasi.php` |

Sifat penting:

* **Mati secara bawaan** — mengunggah berkas ini tidak mengubah perilaku apa pun.
* **Per endpoint** dan **per persen** (sticky per user) — bisa naik/turun bertahap.
* **Fail-safe** — endpoint baca-saja kembali dilayani legacy bila Laravel
  tidak terjangkau; endpoint tulis membalas 503 (tidak pernah ditulis dua kali).
* **Rollback < 1 menit** — `SIMTKD_CUTOVER=0` atau `'enabled' => false`.
* **Tanpa logout massal** — sesi legacy diteruskan sebagai tiket SSO
  (`X-SIMTKD-Ticket`, HMAC-SHA256, umur 90 detik, dibuat ulang per request).

Syarat wajib sebelum menyalakan pengalihan (dijaga oleh alat verifikasi):

```bash
php laravel/tools/cutover/verifikasi.php \
    --laravel=https://next.simtkd.com --legacy=https://simtkd.com --uid=1
```

1. `GET /api/v1/health` = 200 dan `cutover.aktif: true`.
2. Tiket yang dibuat dengan secret sisi legacy **diterima** Laravel
   (membuktikan secret sama dan jam server sinkron).
3. Setelah `rollout` menyala: respons endpoint baca-saja identik dengan legacy.


---

## 11. Fase 4 — frontend Laravel (pilot)

Fase 3 memindahkan **API**; Fase 4 memindahkan **tampilan**. Pola yang dipakai
adalah strangler fig halaman-per-halaman: satu layout Blade bersama, satu menu
yang menautkan campuran halaman Laravel & legacy, lalu halaman dipindahkan satu
per satu tanpa pernah memutus akses pengguna.

### 11.1 Berkas yang ditambahkan

| Berkas | Isi |
|---|---|
| `resources/views/aklap/beranda.blade.php` | **Landing/Dashboard AKLAP** — salinan persis `peta.simtkd.com/index.html` (markup + CSS identik) |
| `public/js/aklap/menu.js`, `sidebar.js` | **Salinan persis** berkas legacy yang sama (sidebar & tombol hamburger tidak berubah) |
| `public/js/aklap/config.js` | Pengganti `js/config.js` legacy: `SIMTKD_API.endpoint` → `/api/v1/aklap`, tanpa token di URL (sesi), plus peta tautan halaman → route Laravel |
| `public/img/logo-polban.png` | Logo POLBAN (gambar sama dengan `img/Logo Polban.png` legacy) |
| `config/aklap.php` | `legacy_base` — alamat server legacy untuk tautan halaman yang belum diporting (kosong di produksi) |
| `public/css/aklap.css` | Gaya bersama (palet navy, topbar, sidebar, breadcrumb, kartu, tabel, tombol, badge, toast) — dipindahkan dari CSS inline halaman legacy agar tampilan tidak berubah |
| `resources/views/layouts/aklap.blade.php` | Kerangka halaman: topbar (instansi + pengguna + tombol keluar), sidebar dari menu, breadcrumb, footer, toast, dan helper JS `aklap.minta()`/`rupiah()` |
| `config/aklap_menu.php` | Menu AKLAP: butir ber-`route` = halaman Laravel, butir ber-`legacy` = halaman vanilla (ditandai `legacy` di sidebar) |
| `resources/views/aklap/buku-pengeluaran.blade.php` | **Halaman pilot**: Buku Kas Pengeluaran PPKD (daftar + filter periode + ringkasan + tambah/ubah/hapus) |
| `resources/views/auth/masuk.blade.php` | Formulir masuk memakai sesi Laravel |
| `app/Http/Controllers/Web/AklapWebController.php` | `masuk`, `keluar`, dan halaman pilot |
| `routes/web.php` | `/` → `/aklap` (landing), `/masuk`, `/keluar`, `/aklap` (landing), `/aklap/buku-pengeluaran` (pilot) |

### 11.1a Bukti landing page "persis" seperti web sebelumnya

Landing page dibuat **otomatis** dari berkas legacy (bukan ditulis ulang),
lalu hasil render Laravel (`GET /aklap`) dibandingkan dengan
`peta.simtkd.com/index.html`:

```
1) Blok <style> identik byte-per-byte : True  (11.611 karakter)
2) Baris markup <body>                : legacy 214 | laravel 217
3) Baris berbeda setelah normalisasi aset: 17 — semuanya DISENGAJA:
   - <img src>             : img/Logo Polban.png -> asset('img/logo-polban.png')
   - 3 tag <script>        : js/config.js|menu.js|sidebar.js -> /js/aklap/*
   - 1 baris tambahan      : window.SIMTKD_LEGACY_BASE (alamat halaman legacy)
   - profil/rekap/jurnal   : '?token=..&action=..' -> endpoint Laravel
```

Artinya topbar, sidebar, breadcrumb, enam kartu statistik, dua kartu akses
cepat, tabel "Jurnal Terbaru", footer, dan toast **tampil identik** — yang
berubah hanya sumber datanya (Laravel, lewat sesi).

`menu.js` dan `sidebar.js` **tidak disunting sama sekali**; tes PHPUnit
memverifikasinya dengan `assertFileEquals()` terhadap berkas legacy.

### 11.2 Prinsip penting

* **Satu sumber aturan bisnis.** Halaman pilot TIDAK menyalin logika: tombol
  simpan/hapus memanggil endpoint yang sama dengan API
  (`POST /api/v1/aklap/buku-kas`, `.../buku-kas/hapus`) — endpoint yang sudah
  lolos uji paritas di Fase 3. Perubahan aturan cukup di satu tempat.
* **Autentikasi sesi, bukan token tempelan.** Halaman memakai sesi Laravel;
  panggilan `fetch` memanfaatkan Sanctum stateful (`statefulApi()`) sehingga
  tidak perlu menyimpan Bearer token di browser. Header `X-CSRF-TOKEN` diambil
  dari `<meta name="csrf-token">` pada layout.
* **Menu tidak pernah mati.** Butir yang belum diporting tetap menunjuk ke
  halaman legacy (bertanda `legacy`), jadi migrasi bisa berhenti kapan pun
  tanpa merusak navigasi.
* **Tamu diarahkan ke `/masuk`** untuk halaman `/aklap/*`, sementara halaman
  legacy lain tetap memakai `login.html` (lihat `bootstrap/app.php`).

### 11.3 Cara memindahkan halaman berikutnya

1. Salin markup halaman legacy → `resources/views/aklap/<nama>.blade.php`
   dengan `@extends('layouts.aklap')`.
2. Ganti panggilan `api/akuntansi.php?action=...` menjadi `aklap.minta('/...')`
   (endpoint Laravel yang setara).
3. Tambahkan `Route::get(...)` di `routes/web.php` + `route` pada
   `config/aklap_menu.php` (ganti `legacy` milik butir menu tersebut).
4. Tambahkan tes halaman (lihat `tests/Feature/Web/AklapWebTest.php`) lalu
   jalankan `php artisan test`.

### 11.4 Menjalankan secara lokal

```bash
cd laravel
php artisan serve --port=8292
```

Pakai `php artisan serve` (atau web server sungguhan). Mode `php -S -t public
public/index.php` **tidak** menyajikan berkas statis (`/css`, `/js`, `/img`)
sehingga halaman tampil tanpa gaya.

Tautan ke halaman yang belum diporting diarahkan ke server legacy; isi
`AKLAP_LEGACY_BASE` di `.env` (lihat `.env.example`) bila server legacy tidak
berada di host yang sama. Di produksi nilainya **kosong** sehingga tautan
persis seperti web sebelumnya (`buku-besar.html`, dst.).

### 11.5 Verifikasi Fase 4

```
php artisan test --filter=AklapWebTest
✓ tamu diarahkan ke halaman masuk
✓ landing page aklap identik dengan halaman legacy
✓ angka landing cocok dengan api rekap dan jurnal
✓ login berhasil menuju halaman pilot dan logout mengakhiri sesi
✓ login menolak kata sandi salah dan akun nonaktif
✓ halaman pilot merender layout menu dan identitas
✓ api aklap dapat dipakai dengan sesi browser
Tests: 9 passed (101 assertions)
```

Uji asap HTTP nyata (server `php -S`, cookie jar):

```
GET  /                               -> 302 /aklap
GET  /aklap (tamu)                   -> 302 /masuk
GET  /masuk                          -> 200 (form + CSRF)
POST /masuk (kredensial benar)       -> 302 /aklap/buku-pengeluaran
GET  /aklap                          -> 200 (Dashboard, CSS identik legacy)
GET  /aklap/buku-pengeluaran         -> 200 (layout, menu, token CSRF)
```

---

## 12. Fase 5 — hasil verifikasi cutover

Dijalankan pada tiga server lokal: legacy langsung (`:8898`), proksi cutover
(`:8899`, `SIMTKD_CUTOVER_ROLLOUT=100`), dan Laravel (`:8291`,
`SIMTKD_CUTOVER_AKTIF=true`).

```
php laravel/tools/cutover/verifikasi.php \
    --laravel=http://127.0.0.1:8291 --legacy=http://127.0.0.1:8898 \
    --proxy=http://127.0.0.1:8899 --uid=<id user uji> --secret=<secret> \
    --user=<akun> --pass=<sandi>

Verifikasi CUTOVER SIM-TKD
1) Health check aplikasi baru
  LULUS Aplikasi baru sehat (database ok, tabel lengkap)
  LULUS Tiket cutover aktif (SIMTKD_CUTOVER_AKTIF=true)
2) Rantai tiket SSO (sisi legacy -> Laravel)
  LULUS Tiket dari sisi legacy DITERIMA Laravel (/api/v1/dokumen/list)
3) Perbandingan respons legacy vs proksi
  LULUS dashboard / profil / permohonan / skp / laporan bku — identik
SEMUA PEMERIKSAAN LULUS — aman melanjutkan cutover.
```

Bukti **parallel run** dengan proksi menyala (seluruh katalog, 0 FAIL):

```
belanja 44/44 · dokumen 15/15 (+1 KNOWN) · aklap 90/90 (+2 PENDING uji-PHPUnit)
sts 8/8 · stbp 7/7 · lupa_password 7/7 · profil 8/8 · penerimaan 6/6 · skp 13/13
E2E token→proksi AKLAP: 128/128
```

### 12.1 Dua perbaikan harness dari Fase 5

1. **Data domain instansi uji dibersihkan saat setup** (`run.php`), sehingga
   skenario "belum tersimpan" (jurnal khusus, rekonsiliasi, kas harian,
   register PPKD) deterministik lintas-run.
2. **Rate limiter akun uji direset sebelum setiap skenario.** Bila server
   "legacy" pada pengujian adalah PROKSI, kedua sisi memanggil backend Laravel
   yang sama sehingga hitungan `profil_pw_<uid>` bertambah dua kali; tanpa
   reset, skenario `profil.ganti_password.*` bisa 429 pada satu sisi saja —
   artefak topologi uji, bukan perbedaan perilaku. Reset dibatasi pada kunci
   milik akun uji sehingga pengguna lain tidak terpengaruh.

### 12.2 Langkah cutover produksi (ringkas)

1. Terapkan `.env` produksi: `SIMTKD_CUTOVER_AKTIF=true`,
   `SIMTKD_CUTOVER_SECRET` (sama dengan `sso_secret` sisi legacy).
2. Jalankan `verifikasi.php` → wajib **SEMUA LULUS**.
3. Nyalakan pengalihan per modul dengan `rollout` kecil (mis. 10%), pantau
   `logs/cutover.log` (baris `ALIHKAN` vs `fallback`).
4. Naikkan `rollout` bertahap; rollback = set `SIMTKD_CUTOVER=0` (legacy) atau
   `'enabled' => false` (Laravel) — di bawah 1 menit, tanpa logout massal.

