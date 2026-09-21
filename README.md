# SIM-TKD — Sistem Informasi Manajemen Tata Kelola Daerah (Modul Edukasi)

Aplikasi **modul edukasi** dari **Politeknik Negeri Bandung (Jurusan Akuntansi)**.
Frontend menggunakan **HTML, CSS, dan JavaScript murni (vanilla)**. Backend menggunakan **PHP & MySQL**.

---

## ✨ Fitur

- **Login** menggunakan username atau email + validasi captcha
- **Pendaftaran (Daftar)** dengan validasi lengkap + indikator kekuatan kata sandi
- **Dashboard** ringkasan APBD yang datanya diambil langsung dari database
- Perlindungan sesi (halaman dashboard hanya bisa diakses setelah login)
- Password di-hash dengan `password_hash()` (bcrypt)
- Query memakai **PDO prepared statements** (aman dari SQL Injection)

---

## 🖥️ Arsitektur

```
PENATAUSAHAAN/
├── login.html          # Frontend login (vanilla)
├── daftar.html         # Frontend pendaftaran (vanilla)
├── dashboard.html      # Frontend dashboard (vanilla)
├── css/                # Stylesheet
├── js/                 # JavaScript frontend (fetch ke API)
├── img/                # Gambar / logo
│
├── api/                # ⚙ BACKEND (PHP) - endpoint API JSON
│   ├── login.php       #   POST: autentikasi login
│   ├── register.php    #   POST: pendaftaran akun
│   ├── logout.php      #   GET : hapus sesi (logout)
│   ├── session.php     #   GET : cek status login
│   ├── summary.php     #   GET : data ringkasan dashboard
│   ├── dokumen.php     #   POST: simpan dokumen ke antrean TTD (doc.simtkd.com)
│   ├── dokumen_go.php  #   GET : SSO redirect ke doc.simtkd.com (?token=)
│   └── aklap_go.php    #   GET : SSO redirect ke peta.simtkd.com (?token=)
├── config/
│   └── db.php          # Koneksi database (PDO)
├── includes/
│   ├── functions.php   # Helper (validasi, respons JSON)
│   └── auth.php        # Sesi & proteksi halaman
├── database/
│   └── simtkd.sql      # Skema database (referensi)
│   └── dokumen_ttd.sql # Tabel dokumen & tanda tangan elektronik
├── peta.simtkd.com/    # Subdomain modul AKLAP (SSO via ?token=)
├── doc.simtkd.com/     # Subdomain dokumen & tanda tangan elektronik
├── setup.php           # Installer otomatis (DB + admin)
└── README.md
```

> **Konsep:** Halaman frontend (`.html`) memanggil API PHP (`.php` di folder `api/`)
> menggunakan `fetch()`. Backend PHP menangani database, validasi, dan sesi.
> Alur: `login.html` → `api/login.php` → `dashboard.html` → `api/summary.php`.

---

## 📋 Kebutuhan

| Software | Keterangan |
|----------|------------|
| **XAMPP** (Apache + PHP 7.4+ / 8.x + MySQL) | Disarankan |
| Browser modern | Chrome / Firefox / Edge |

---

## 🚀 Cara Instalasi (XAMPP)

1. **Salin folder proyek** ke direktori `htdocs` XAMPP:
   ```bash
   # macOS (XAMPP)
   cp -r PENATAUSAHAAN /Applications/XAMPP/htdocs/
   # Windows (XAMPP)
   # salin ke C:\xampp\htdocs\PENATAUSAHAAN
   ```

2. **Nyalakan Apache & MySQL** melalui XAMPP Control Panel.

3. **Buka installer** di browser:
   ```
   http://localhost/PENATAUSAHAAN/setup.php
   ```
   Isi form instalasi (seperti installer pada umumnya):
   - **Database Host** → `localhost`
   - **Database User** → `root`
   - **Database Password** → kosongkan jika tanpa password (isi jika MySQL Anda berpassword)
   - **Nama Database** → `simtkd` (dibuat otomatis)
   - **Username Admin** → `admin`
   - **Password Admin** → `admin123`

   Klik **🚀 Mulai Instalasi**. Installer akan otomatis:
   - Koneksi ke MySQL dengan kredensial yang Anda isi
   - Membuat database `simtkd`
   - Membuat tabel `users` dan `kegiatan`
   - Mengisi data contoh kegiatan
   - Membuat akun admin
   - **Menulis kredensial ke `config/db.php`** (otomatis)

4. **Hapus `setup.php`** setelah instalasi selesai (untuk keamanan).

5. **Login** di:
   ```
   http://localhost/PENATAUSAHAAN/login.html
   ```
   - Username: `admin`
   - Password: `admin123`

6. Setelah login berhasil, Anda diarahkan ke **dashboard**.

---

## ⚙️ Konfigurasi Database

Kredensial database **ditulis otomatis** oleh `setup.php` ke `config/db.php` saat
Anda menjalankan installer. Anda tidak perlu mengedit manual.

Jika ingin mengubah manual, buka `config/db.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'simtkd');
define('DB_USER', 'root');
define('DB_PASS', '');   // isi dengan password MySQL jika ada
```

---

## 🔌 API Endpoint

| Endpoint | Method | Deskripsi |
|----------|--------|-----------|
| `api/login.php` | POST | Login (`username`, `password`, `tahun_anggaran`) |
| `api/register.php` | POST | Daftar (`nama_lengkap`, `username`, `email`, `password`, `instansi`, `peran`) |
| `api/logout.php` | GET | Logout |
| `api/session.php` | GET | Cek sesi login (dipakai dashboard) |
| `api/summary.php` | GET | Data ringkasan APBD (wajib login) |
| `api/permohonan.php` | GET / POST | Daftar & simpan permohonan rekening (`?q=` untuk pencarian) |
| `api/akun_penerimaan.php` | GET / POST | Daftar & simpan akun penerimaan (Pengaturan) (`?q=` untuk pencarian) |
| `api/stbp.php` | GET / POST | Daftar STBP per status (`?status=` & `?q=`) & buat STBP baru |

Semua endpoint mengembalikan JSON:
```json
{ "success": true, "message": "...", ... }
```

---

## 📁 Modul

| Modul | Halaman | API | Tabel |
|-------|---------|-----|-------|
| Dashboard | `dashboard.html` | `api/summary.php` | `kegiatan` |
| Penerimaan → Rekening → Permohonan | `permohonan.html` | `api/permohonan.php` | `permohonan` |
| Pengaturan → Akun Penerimaan | `akun-penerimaan.html` | `api/akun_penerimaan.php` | `akun_penerimaan` |
| Penerimaan → STBP (Pembuatan) | `stbp-pembuatan.html` · `stbp-tambah.html` | `api/stbp.php` | `stbp`, `stbp_pembayaran`, `stbp_pendapatan` |
| Penerimaan → Buku Pembantu Penerimaan | `buku-pembantu-penerimaan.html` | `api/laporan.php` (`action=buku_pembantu`, `action=obyek_penerimaan`) | `sts_detail` + `sts` + `anggaran_lra` (read-only) |
| AKLAP → Jurnal Umum (Manual) | `peta.simtkd.com/jurnal-umum.html` | `peta.simtkd.com/api/akuntansi.php` | `jurnal_umum`, `jurnal_umum_detail`, `akun_master` |
| AKLAP → Master Akun (COA LRA) | `peta.simtkd.com/master-akun.html` | `peta.simtkd.com/api/akuntansi.php` (`akun_list`, `akun_simpan`, `akun_hapus`, `akun_seed_coa`) | `akun_master` + `config/coa_lra.json` |
| AKLAP → Anggaran (Pagu) | `peta.simtkd.com/anggaran.html` | `peta.simtkd.com/api/akuntansi.php` (`anggaran_list`, `anggaran_simpan`, `anggaran_hapus`, `anggaran_sinkron_master`) | `anggaran_lra` (JOIN `akun_master`) |
| AKLAP → Buku Kas (Penerimaan/Pengeluaran PPKD) | `peta.simtkd.com/buku-penerimaan-ppkd.html` · `buku-pengeluaran-ppkd.html` | `peta.simtkd.com/api/akuntansi.php` (`buku_kas_list`, `buku_kas_simpan`, `buku_kas_hapus`) | `buku_kas` |
| AKLAP → Buku Kas Umum (BUD) | `peta.simtkd.com/buku-kas-bud.html` | `peta.simtkd.com/api/akuntansi.php` (`buku_kas_umum`) | `buku_kas` |
| AKLAP → Laporan Kas Harian | `peta.simtkd.com/laporan-kas-harian.html` | `peta.simtkd.com/api/akuntansi.php` (`kas_harian*`) | `kas_harian`, `kas_harian_item` |
| AKLAP → Rekonsiliasi Bank | `peta.simtkd.com/rekonsiliasi-bank.html` | `peta.simtkd.com/api/akuntansi.php` (`rekonsiliasi_bank*`) | `rekonsiliasi_bank`, `rekonsiliasi_bank_item` |
| AKLAP → Register SPP/SPM/SP2D | `peta.simtkd.com/register-ppkd.html` | `peta.simtkd.com/api/akuntansi.php` (`register_ppkd_list`, `register_ppkd_simpan`) | `register_ppkd` + `buku_kas` |
| AKLAP → LPJ Bendahara PPKD | `peta.simtkd.com/lpj-ppkd.html` | `peta.simtkd.com/api/akuntansi.php` (`lpj_ppkd`) | `buku_kas` + `anggaran_lra` + `akun_master` |
| AKLAP → Buku Pembantu Objek | `peta.simtkd.com/buku-pembantu.html` | `peta.simtkd.com/api/akuntansi.php` (`buku_pembantu_list`) | `buku_kas` + `anggaran_lra` |
| AKLAP → Buku Jurnal Khusus | `peta.simtkd.com/jurnal-khusus.html` | `peta.simtkd.com/api/akuntansi.php` (`jurnal_khusus*`) | `jurnal_khusus`, `jurnal_khusus_baris` |
| AKLAP → Jurnal Khusus Konsolidator | `peta.simtkd.com/jurnal-khusus-konsolidator.html` | `peta.simtkd.com/api/akuntansi.php` (`jurnal_khusus*`, jenis `penerimaan_konsolidator`) | `jurnal_khusus` (kolom `skp_label`), `jurnal_khusus_baris` |
| AKLAP → Buku Besar | `peta.simtkd.com/buku-besar.html` | `peta.simtkd.com/api/akuntansi.php` | `jurnal_umum_detail` (posting on-the-fly) |
| AKLAP → Neraca Saldo | `peta.simtkd.com/neraca-saldo.html` | `peta.simtkd.com/api/akuntansi.php` | `jurnal_umum_detail`, `akun_master` |

### 🧾 Model Akuntansi Manual (Double-Entry)

Menu **AKLAP** di `peta.simtkd.com` menyediakan pencatatan akuntansi manual
dua sisi (Debet/Kredit) sesuai lembar kerja **Modul 3 AKLAP SKPKD**:

1. **Jurnal Umum** (`jurnal-umum.html`) — input manual minimal 2 baris dengan
   validasi **Total Debet = Total Kredit** (divalidasi ulang di server), plus
   tombol **"Isi dari Dokumen"** yang menarik template jurnal dari dokumen modul
   yang sudah ada:
   - **STS aktif** → D *Kas di Bendahara Penerimaan* / K *Pendapatan* (per kode rekening `sts_detail`)
   - **SP2D LS Gaji/LS B&J cair** → D *Belanja* (bruto) / K *Utang Pajak & Potongan* (dari `spp_potongan_pajak`) + K *Kas di BUD* (neto)
   - **SP2D UP/GU/TU cair** → D *Kas di Bendahara Pengeluaran* / K *Kas di BUD*
   Dokumen yang sudah dibuatkan jurnal ditandai `sudah_dijurnal` agar tidak dobel.
2. **Approve/Reject** — hanya jurnal berstatus `sudah_approve` yang di-posting.
3. **Buku Besar** (`buku-besar.html`) — posting per akun dengan saldo berjalan
   (dapat dicetak), termasuk saldo awal dari `neraca_awal`.
4. **Neraca Saldo** (`neraca-saldo.html`) — rekap saldo awal, mutasi D/K, dan
   saldo akhir semua akun + indikator keseimbangan.

Skema database: `database/akuntansi_manual.sql` (idempoten, kompatibel
MySQL & MariaDB; tabel juga dibuat otomatis oleh API).

#### 🆕 AKLAP v2.0 — tambahan basis data (modul Kas, Register, LPJ, Jurnal Khusus)

| Tabel baru | Dipakai oleh | Keterangan |
|------------|--------------|------------|
| `register_ppkd` | Register SPP/SPM/SP2D | 1 baris per transaksi Buku Kas Pengeluaran (unik per SKPD + `buku_kas_id`) |
| `kas_harian`, `kas_harian_item` | Laporan Posisi Kas Harian | Dokumen per tanggal + baris transaksi |
| `rekonsiliasi_bank`, `rekonsiliasi_bank_item` | Rekonsiliasi Bank | Dokumen per bulan + baris keterangan selisih A/B/C/D |
| `jurnal_khusus`, `jurnal_khusus_baris` | Buku Jurnal Khusus & Konsolidator | Dokumen per jenis/periode + nilai per posisi kolom |

| Kolom baru | Tabel | Keterangan |
|------------|-------|------------|
| `skp_label` | `jurnal_khusus` | Isian SKPKD manual (blanko Konsolidator) |
| `debet_7..debet_10`, `kredit_7..kredit_10` | `jurnal_khusus_baris` | Slot kolom 7–10 (blanko Belanja/Beban Pemda berkolom 8 debet) |

Tabel pada baris di atas **dibuat otomatis oleh API** (`CREATE TABLE IF NOT EXISTS`
+ `pastikan_kolom()` di `peta.simtkd.com/api/akuntansi.php`), sehingga tidak ada
impor SQL manual yang wajib dilakukan. Untuk menyiapkan/memeriksa skema lebih dulu
(mis. deploy ke produksi), gunakan berkas idempoten
`database/akuntansi_v2.sql` — sudah diuji pada DB kosong **dan** DB versi lama
(kolom ditambahkan tanpa menghapus data).

Modul **Master Akun**, **Anggaran (Pagu)**, **Buku Kas**, **Buku Kas Umum (BUD)**,
**LPJ Bendahara PPKD**, dan **Buku Pembantu Objek** **tidak menambah tabel** —
mereka memakai tabel yang sudah ada (`akun_master`, `anggaran_lra`, `buku_kas`,
`jurnal_umum`, `neraca_awal`) sehingga data & laporan lama tetap utuh.

#### 📚 Manual book

| Dokumen | Isi |
|---------|-----|
| `docs/manual-book.html` | Manual lengkap (mahasiswa + dosen/TTE), versi 2.0 |
| `docs/manual-book-mahasiswa.html` | Edisi mahasiswa (Bagian 5 memuat seluruh modul AKLAP terbaru) |
| `docs/manual-book-pembaruan.html` | **Manual Book Pembaruan v2.0** — daftar modul baru, perbaikan bug, catatan teknis (endpoint/tabel baru), alur kerja, dan langkah verifikasi |
| `docs/screenshots/opt/baru-*.webp` | 15 tangkapan layar modul baru (Master Akun, Anggaran, Buku Kas, BUD, Kas Harian, Rekonsiliasi, Register, LPJ, Jurnal Khusus, Konsolidator, cetak per jurnal) |


---

## ✍️ Dokumen & Tanda Tangan Elektronik (doc.simtkd.com)

Dokumen hasil laman cetak (belanja & penerimaan) dapat dikirim ke antrean tanda
tangan lalu ditandatangani elektronik dengan **tanda tangan tangan** (ala Privy):

1. Saat mencetak dokumen, klik **"✎ Kirim ke Tanda Tangan"** pada panel
   pengaturan cetak (modul Belanja: SPD, SPP, SPM, SP2D, LPJ, NPD, Pengajuan TU
   lewat `CetakBelanja`; modul Penerimaan: STBP, STS, BKU, LPJ, Register STS,
   Rekap Penerimaan Harian & Bulanan, Buku Pembantu Penerimaan, SKP Daerah lewat
   tombol di laman cetaknya masing-masing)
   → dokumen tersimpan di tabel `dokumen` (database sama) beserta orientasi
   kertasnya (aturan `@page` mengikuti pilihan di panel cetak).
2. Buka **menu "Tanda Tangan Dokumen"** → SSO otomatis ke doc.simtkd.com
   (membawa token API user, pola sama dengan modul Akuntansi/AKLAP).
3. Di doc.simtkd.com: pilih dokumen → pratinjau dirender selebar kertas A4
   asli (sama seperti tampilan di SIM-TKD); orientasi kertas dapat dipilih
   di atas pratinjau: **Otomatis** (mengikuti aturan `@page` dokumen),
   **Potret**, atau **Lanskap** — pilihan ini juga dipakai saat mencetak.
4. Gambar tanda tangan di kanvas (mouse/sentuh) → **Tanda Tangan Dokumen**.
   Sistem menyematkan gambar TTD pada slot tanda tangan serta satu
   **barcode (QR) verifikasi** di akhir dokumen (HTML final bersifat mandiri &
   tidak dapat diubah; nama, jabatan, waktu, kode verifikasi, dan hash
   SHA-256 tercatat pada database dan dapat dilihat melalui laman verifikasi
   publik).
5. Dokumen final dapat dicetak/simpan PDF; keaslian dapat dicek publik di
   `doc.simtkd.com/verify.html?kode=TTD-XXXXXXXX`.

Endpoint doc.simtkd.com (`api/dokumen.php`): `list`, `me`, `detail`, `konten`,
`ttd_gambar`, `ttd` — dilindungi token; `verify` publik (rate-limited).
Skema database: `database/dokumen_ttd.sql` (tabel `dokumen`, `dokumen_ttd`,
`user_ttd`). Deployment: `.cpanel.doc.yml`.

---

## 🔐 Keamanan

- Password disimpan dengan `password_hash()` (bcrypt)
- Query menggunakan **PDO prepared statements**
- Folder `config/`, `includes/`, `database/` dilindungi dari akses browser (`.htaccess`)
- Sesi menggunakan `session_regenerate_id()` untuk mencegah *session fixation*
- Validasi dilakukan dua lapis: **client-side** (JS) dan **server-side** (PHP)

---

## 📝 Catatan

- Aplikasi harus diakses melalui **server** (`http://localhost/...`), bukan langsung
  dari file (`file://`) — karena backend PHP & sesi membutuhkan server.
- Akun baru yang mendaftar **langsung aktif** (tanpa verifikasi admin) dan bisa
  login menggunakan **username atau email**. Untuk database yang sudah berjalan,
  jalankan `database/users_status_aktif.sql` agar akun lama yang masih `pending`
  ikut teraktifkan.

---

## ⚖️ Lisensi

Hak cipta © 2026 **Politeknik Negeri Bandung — Jurusan Akuntansi**. Seluruh hak cipta dilindungi.

Aplikasi ini dilindungi oleh **lisensi perangkat lunak proprietari** dan **DILARANG untuk disebarluaskan**, disalin, dijual, atau digunakan kembali tanpa izin tertulis dari pemegang hak cipta. Lihat file [`LICENSE`](./LICENSE) untuk ketentuan lengkap.

> ⛔ **TIDAK BOLEH DI-SEBAR.** Dengan menggunakan aplikasi ini, Anda menyetujui seluruh
> ketentuan lisensi yang berlaku. Setiap pelanggaran dapat ditindaklanjuti sesuai
> hukum yang berlaku di Republik Indonesia.
