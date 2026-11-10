# Migrasi SIM-TKD ke Laravel

Dokumen ini menjelaskan arsitektur aplikasi Laravel yang menggantikan sistem
PHP vanilla (legacy) secara bertahap, tanpa mengubah skema database.

> **Status:** Fase 0 (pengingkatan sistem live) & Fase 1 (fondasi Laravel) selesai.
> Fase 2–5 (porting modul) menyusul.

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
| `SIMTKD_SSO_TICKET_TTL` | Umur tiket SSO lintas-subdomain (detik) |
| `SIMTKD_TTD_SECRET` | Kunci enkripsi tanda tangan dokumen |
| `SESSION_DOMAIN` | Kosong di lokal; isi `.simtkd.com` di produksi untuk SSO |

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
| 3 | Porting modul: auth → penerimaan → belanja → AKLAP → dokumen | ⬜ |
| 4 | Integrasi frontend (62 halaman) | ⬜ |
| 5 | Parallel run, verifikasi, cutover | ⬜ |

Detail Fase 0 ada di commit `8b6d34f`.

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
TOTAL 95   PASS 18   FAIL 0   PENDING 77   ERROR 0
Endpoint yang sudah dimigrasi: 18/95 (19%)
```

Skenario `PENDING` adalah **checklist porting yang hidup** — setiap endpoint
legacy yang belum ada padanya API baru akan tercatat di sana.

### 9.4 Temuan yang sudah ditemukan harness

| Temuan | Dampak | Perbaikan |
|---|---|---|
| Respons validasi Laravel tidak punya kunci `success` | **Frontend lama rusak** — memeriksa `d.success` | Exception handler global menyeragamkan ke `{success, message, errors}` |
| `throttle` bawaan Laravel memakai kunci IP & JSON berbeda | 429 vs 422, pesan tak sama | Rate limit dipindah ke dalam controller (pola legacy: `username+IP`) |
| Bentuk respons `session` berbeda (pesan, `user.id`, `user.email`) | Frontend bergantung isi | Controller disesuaikan identik dengan legacy |
| `api_token` legacy tak ada padanya API baru | Noise | Di-*drop* per-skenario dengan alasan terdokumentasi |

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

Placeholder `{{user.aktif.username}}` diisi dari akun uji runtime. Akun uji
dibuat dan dibersihkan otomatis oleh harness.
