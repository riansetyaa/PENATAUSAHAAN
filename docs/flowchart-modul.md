# Flowchart Semua Modul — SIM-TKD (Modul Edukasi, POLBAN)

Dokumen berisi diagram alur (Mermaid) untuk seluruh modul aplikasi:
**Penerimaan** (perolehan/penerimaan kas) dan **Belanja** (pengeluaran/pembelian),
plus **Akuntansi (AKLAP)** sebagai penutup pencatatan.

---

## 1. Alur Masuk Aplikasi (Login)

```mermaid
flowchart TD
    A[Mulai] --> B[Buka login.html]
    B --> C[Isi username, password, captcha]
    C --> D{Login benar?}
    D -->|Tidak| E[Tampilkan pesan error]
    E --> C
    D -->|Ya| F[Session dibuat + api_token dirotasi]
    F --> G[Redirect ke dashboard]
    G --> H[Dashboard: ringkasan penerimaan & belanja]
    H --> I[Pilih modul di sidebar]
```

---

## 2. Modul Penerimaan (Penjualan / Pendapatan)

### 2.1 Rekening Permohonan (Dasar Rekening Kas)

```mermaid
flowchart TD
    A[Permohonan rekening diajukan] --> B[Simpan: status_terbit=0, disetujui=0]
    B --> C{Verifikasi oleh admin?}
    C -->|Tidak| B
    C -->|Ya| D[status_terbit=1, disetujui=1, aktif=1]
    D --> E[Rekening siap dipakai untuk STBP/STS]
    E --> F{Set aktif / nonaktif?}
    F -->|Aktif| G[Muncul di daftar pilihan STBP]
    F -->|Nonaktif| H[Tidak muncul di daftar pilihan]
```

### 2.2 STBP (Surat Tanda Bukti Pembayaran)

```mermaid
flowchart TD
    A[STBP dibuat oleh BP/penyetor] --> B[status: belum_diverifikasi]
    B --> C[Verifikasi oleh PPK/BUD]
    C -->|Setuju| D[status: sudah_diverifikasi]
    C -->|Tolak| Z[Dihapus]
    D --> E[Otorisasi oleh BUD]
    E --> F[status: sudah_diotorisasi]
    F --> G[Validasi akhir]
    G --> H[status: sudah_divalidasi = SIAP STS]
    H --> I[Lanjut ke pembuatan STS]
    Z --> H1[Selesai - tidak masuk STS]
```

### 2.3 STS (Surat Tanda Setoran)

```mermaid
flowchart TD
    A[Pilih STBP yang sudah_divalidasi / siap_sts] --> B[STBP terpilih difilter - tidak bisa dobel]
    B --> C[Isi penyetor, akun, jumlah]
    C --> D[Buat STS: status aktif]
    D --> E[STS tersimpan + total dihitung]
    E --> F{Cetak STS?}
    F -->|Ya| G[Cetak STS resmi]
    F -->|Tidak| H[Lanjut: STS siap masuk jurnal AKLAP]
    G --> H
```

### 2.4 BKU Penerimaan

```mermaid
flowchart TD
    A[STS aktif] --> B[BKU Penerimaan mencatat kas masuk]
    B --> C[Saldo kas penerimaan bertambah]
    C --> D[Buku pembantu bank / kas / pajak diperbarui]
```

---

## 3. Modul Belanja (Pembelian / Pengeluaran)

### 3.0 Data Pendukung

```mermaid
flowchart LR
    subgraph Referensi
        A[Rekanan: perusahaan/perseorangan]
        B[Rekening Bank SKPD: permohonan → pembuatan → aktif]
        C[Besaran UP per tahun]
        D[Kebijakan SPD: jenis terbitan & periode]
    end
```

### 3.1 Alur Utama Belanja (SPD → SPP → SPM → SP2D)

```mermaid
flowchart TD
    A[SPD dibuat - belum_otorisasi] --> B[Otorisasi SPD oleh BUD]
    B --> C[SPD sudah_otorisasi]
    C --> D[Buat SPP - pilih SPD terotorisasi yang belum terpakai]
    D --> E[SPP: belum_diverifikasi]
    E --> F{Verifikasi oleh PPK}
    F -->|Tolak| G[SPP ditolak - SPD bebas dipakai lagi]
    F -->|Setuju| H[SPP sudah_diverifikasi + SPM dibuat otomatis]
    G --> C
    H --> I[SPM: belum_disetujui]
    I --> J[Persetujuan PA - SPTJM]
    J --> K[SPM: belum_diverifikasi]
    K --> L[Verifikasi Kuasa BUD]
    L --> M[SPM: sudah_diverifikasi]
    M --> N[Buat SP2D - pilih SPM terverifikasi]
    N --> O[SP2D: belum_diverifikasi]
    O --> P[Verifikasi SP2D]
    P --> Q[SP2D: sudah_diverifikasi]
    Q --> R[Pencairan dana - transfer]
    R --> S[SP2D: sudah_dicairkan]
    S --> T[BKU Belanja + Buku Pembantu]
    S --> U[Jurnal Belanja AKLAP]
```

### 3.2 Percabangan Jenis SPP

```mermaid
flowchart TD
    A[Mulai buat SPP] --> B{Pilih jenis SPP}
    B -->|UP| C[SPP-UP: tanpa ref tambahan]
    B -->|GU| D[SPP-GU: WAJIB pilih LPJ]
    B -->|TU| E[SPP-TU: WAJIB pilih Pengajuan TU]
    B -->|LS Gaji| F[SPP-LS Gaji: pilih rekanan]
    B -->|LS Barang dan Jasa| G[SPP-LS B&J: pilih rekanan + potongan dan pajak]
    C --> H[Isi SPD + jumlah]
    D --> I[LPJ dibuat lebih dulu di modul LPJ]
    E --> J[Pengajuan TU: otorisasi BUD + validasi PA]
    F --> H
    G --> H
    H --> K[Simpan SPP]
    I --> D
    J --> E
```

### 3.3 LPJ (referensi SPP-GU)

```mermaid
flowchart TD
    A[Bendahara catat LPJ - nomor otomatis LPJ] --> B[LPJ tersimpan]
    B --> C[Dipakai sebagai dasar SPP-GU]
    C --> D[Setelah SP2D GU cair, LPJ terlampiri]
```

### 3.4 Pengajuan TU (referensi SPP-TU)

```mermaid
flowchart TD
    A[Pengajuan TU dibuat - belum_otorisasi] --> B[Otorisasi BUD]
    B --> C[sudah_otorisasi]
    C --> D[Validasi PA/KPA]
    D --> E[sudah_divalidasi]
    E --> F[Dipakai sebagai dasar SPP-TU]
```

### 3.5 NPD (Nota Pencairan Dana)

```mermaid
flowchart TD
    A[NPD diajukan - status diajukan] --> B{Validasi PA?}
    B -->|Setuju| C[divalidasi_pa]
    C --> D{Validasi BP?}
    D -->|Setuju| E[divalidasi_bp]
    D -->|Tolak| F[ditolak]
    B -->|Tolak| F
```

---

## 4. Modul Akuntansi (AKLAP)

```mermaid
flowchart TD
    A[Sumber jurnal Penerimaan: STBP sudah_divalidasi + STS aktif] --> J
    B[Sumber jurnal Belanja: SP2D sudah_dicairkan] --> J
    J[Baris jurnal terdaftar - jurnal_status belum_approve] --> K{Approve / Reject}
    K -->|Approve| L[jurnal_status sudah_approve]
    K -->|Reject| M[jurnal_status ditolak]
    L --> N[Pembukuan jurnal final]
    N --> O[Laporan Realisasi Anggaran LRA]
    M --> J
    J --> P[Profil instansi - token API]
```

---

## 4b. Model Akuntansi Manual — Jurnal Umum Double-Entry (peta.simtkd.com)

```mermaid
flowchart TD
    A[Jurnal Umum - input manual 2 baris D/K] --> V{Debet = Kredit?}
    B[Isi dari Dokumen: STS aktif / SP2D sudah_dicairkan] --> T[Template baris D/K dari dokumen - diedit manual] --> A
    A --> S{Simpan via jurnal_simpan}
    V -->|Tidak| X[Ditolak: jurnal tidak seimbang]
    V -->|Ya| S
    S --> Y[sudah_dijurnal = 1 utk dokumen sumber - anti dobel]
    Y --> J[jurnal_umum status: draft]
    J --> K{Approve / Reject}
    K -->|Approve| L[jurnal_umum status: sudah_approve]
    K -->|Reject| M[status: ditolak]
    L --> N[Buku Besar - posting per akun + saldo berjalan]
    N --> O[Neraca Saldo - mutasi D/K per akun]
    O --> P[LRA & Neraca - hanya dari jurnal approved]
    J --> H[Hapus jurnal: sudah_dijurnal kembali 0 - dokumen bisa dijurnal ulang]
```

Sumber template jurnal:
- **STS aktif** → D Kas di Bendahara Penerimaan / K Pendapatan (per `sts_detail`)
- **SP2D LS** (Gaji/B&J) → D Belanja (bruto) / K Utang Pajak-Potongan (`spp_potongan_pajak`) + K Kas di BUD (neto)
- **SP2D UP/GU/TU** → D Kas di Bendahara Pengeluaran / K Kas di BUD

---

## 5. Peta Ringkas Alur (End-to-End)

```mermaid
flowchart LR
    P1[Permohonan Rekening] --> P2[STBP] --> P3[STS] --> P4[BKU Penerimaan]
    P4 --> A[AKLAP Jurnal Penerimaan]
    B1[SPD] --> B2[SPP UP/GU/TU/LS] --> B3[SPM] --> B4[SP2D] --> B5[Pencairan]
    B5 --> B6[BKU Belanja]
    B5 --> C[AKLAP Jurnal Belanja]
    A --> L[LRA]
    C --> L
```

---

## 6. Tanda Tangan Elektronik Dokumen (doc.simtkd.com)

```mermaid
flowchart TD
    A[Cetak dokumen di SIM-TKD] --> B[Kirim ke Tanda Tangan: simpan HTML + hash ke tabel dokumen]
    B --> C[Menu Tanda Tangan Dokumen: SSO ke doc.simtkd.com ?token=]
    C --> D{Token valid?}
    D -->|Tidak| E[Peringatan: tidak terhubung akun]
    D -->|Ya| F[Daftar dokumen menunggu TTD]
    F --> G[Buka dokumen: viewer + gambar TTD di kanvas]
    G --> H[Konfirmasi: simpan gambar TTD user + QR verifikasi]
    H --> I{Integritas hash + status menunggu_ttd?}
    I -->|Ya| J[Sematkan TTD + blok verifikasi ke dokumen]
    J --> K[status: ditandatangani + hash_signed]
    I -->|Tidak| L[Tolak: kirim ulang dokumen]
    K --> M[Cetak / simpan PDF final]
    K --> N[Verifikasi publik via kode / QR]
```

---

