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
| 2 | Differential test harness (golden master) | ⬜ |
| 3 | Porting modul: auth → penerimaan → belanja → AKLAP → dokumen | ⬜ |
| 4 | Integrasi frontend (62 halaman) | ⬜ |
| 5 | Parallel run, verifikasi, cutover | ⬜ |

Detail Fase 0 ada di commit `8b6d34f`.
