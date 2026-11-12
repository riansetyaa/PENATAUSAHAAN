<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Aklap\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Helper bersama modul AKLAP — akuntansi manual (api/akuntansi.php).
 *
 * Berisi COA LRA standar, pemetaan tipe akun dari kode, dan seed COA ke
 * tabel akun_master. Semuanya disalin setia dari legacy agar hasil seed
 * dan daftar akun identik.
 */
trait CoaLra
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $coa = null;

    /**
     * COA LRA standar.
     *
     * Sumber utama: `peta.simtkd.com/config/coa_lra.json` (berkas legacy —
     * satu sumber kode+nama akun). Bila tidak terjangkau (mis. aplikasi
     * Laravel berdiri sendiri), dipakai salinan bawaan
     * `resources/data/coa_lra.json` yang isinya identik.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function coaLra(): array
    {
        if (self::$coa !== null) {
            return self::$coa;
        }

        $kandidat = [
            base_path('../peta.simtkd.com/config/coa_lra.json'),
            resource_path('data/coa_lra.json'),
        ];

        $coa = [];

        foreach ($kandidat as $berkas) {
            if (! is_readable($berkas)) {
                continue;
            }

            $data = json_decode((string) file_get_contents($berkas), true);

            if (! is_array($data) || ! isset($data['akun']) || ! is_array($data['akun'])) {
                continue;
            }

            foreach ($data['akun'] as $a) {
                $kode = trim((string) ($a['kode_akun'] ?? ''));
                $nama = trim((string) ($a['nama_akun'] ?? ''));

                if ($kode === '' || $nama === '') {
                    continue;
                }

                [$tipeKode, $normalKode] = $this->tipeDariKode($kode);
                $tipe   = trim((string) ($a['tipe'] ?? ''));
                $normal = trim((string) ($a['saldo_normal'] ?? ''));

                $coa[$kode] = [
                    'kode_akun'    => $kode,
                    'nama_akun'    => $nama,
                    'tipe'         => $tipe !== '' ? $tipe : $tipeKode,
                    'saldo_normal' => $normal !== '' ? $normal : $normalKode,
                    'level'        => (int) ($a['level'] ?? (substr_count($kode, '.') + 1)),
                ];
            }

            break; // pakai berkas pertama yang valid
        }

        return self::$coa = $coa;
    }

    /**
     * Tipe & saldo normal menurut digit pertama kode akun (fallback COA).
     *
     * @return array{0: string, 1: string}
     */
    protected function tipeDariKode(string $kode): array
    {
        $map = [
            '1' => ['aset', 'debet'],
            '2' => ['liabilitas', 'kredit'],
            '3' => ['ekuitas', 'kredit'],
            '4' => ['pendapatan', 'kredit'],
            '5' => ['belanja', 'debet'],
            '6' => ['belanja', 'debet'],
            '7' => ['ekuitas', 'kredit'],
        ];

        return $map[substr($kode, 0, 1)] ?? ['aset', 'debet'];
    }

    /**
     * Lengkapi COA LRA standar ke akun_master satu instansi (idempoten).
     * Akun yang sudah ada TIDAK diubah namanya. Mengembalikan jumlah baris
     * yang benar-benar ditambahkan.
     */
    protected function seedCoa(string $skpd): int
    {
        $coa = $this->coaLra();

        if ($coa === []) {
            return 0;
        }

        $kodes = array_keys($coa);

        $sudah = (int) DB::table('akun_master')
            ->where('skpd', $skpd)
            ->whereIn('kode_akun', $kodes)
            ->distinct()
            ->count('kode_akun');

        if ($sudah >= count($kodes)) {
            return 0;
        }

        $ditambah = 0;

        foreach (array_chunk($kodes, 60) as $potong) {
            $baris = [];
            $nilai = [];

            foreach ($potong as $kode) {
                $a = $coa[$kode];
                $baris[] = '(?, ?, ?, ?, ?)';
                $nilai[] = $skpd;
                $nilai[] = $a['kode_akun'];
                $nilai[] = $a['nama_akun'];
                $nilai[] = $a['tipe'];
                $nilai[] = $a['saldo_normal'];
            }

            // INSERT IGNORE: aman terhadap UNIQUE (skpd, kode_akun).
            // affectingStatement mengembalikan jumlah baris yang BENAR-BENAR
            // dimasukkan (padanan rowCount() pada legacy).
            $ditambah += DB::affectingStatement(
                'INSERT IGNORE INTO akun_master (skpd, kode_akun, nama_akun, tipe, saldo_normal) VALUES ' . implode(',', $baris),
                $nilai
            );
        }

        return $ditambah;
    }

    /**
     * Seed COA ringkas (BSA, Perwali 112/2018) — hanya bila akun_master
     * instansi masih kosong. Disalin identik dari legacy `akun_seed_default()`.
     */
    protected function seedDefault(string $skpd): void
    {
        $ada = (int) DB::table('akun_master')->where('skpd', $skpd)->count();

        if ($ada > 0) {
            return;
        }

        $defaults = [
            ['1.1.1.01', 'Kas di Bendahara Penerimaan', 'aset', 'debet'],
            ['1.1.1.02', 'Kas di Bendahara Pengeluaran', 'aset', 'debet'],
            ['1.1.1.03', 'Uang Persediaan', 'aset', 'debet'],
            ['1.1.2.01', 'Rekening Bank SKPD', 'aset', 'debet'],
            ['1.1.3.01', 'Kas di Bendahara Umum Daerah', 'aset', 'debet'],
            ['2.1.1.01', 'Utang Pajak', 'liabilitas', 'kredit'],
            ['2.1.1.02', 'Utang Potongan', 'liabilitas', 'kredit'],
            ['4.1.1.01', 'Pendapatan Pajak Daerah', 'pendapatan', 'kredit'],
            ['4.1.2.01', 'Pendapatan Retribusi Daerah', 'pendapatan', 'kredit'],
            ['4.1.4.01', 'Pendapatan Lain-lain PAD yang Sah', 'pendapatan', 'kredit'],
            ['5.1.1.01', 'Belanja Gaji dan Tunjangan', 'belanja', 'debet'],
            ['5.1.2.01', 'Belanja Barang dan Jasa', 'belanja', 'debet'],
            ['5.1.3.01', 'Belanja Modal', 'belanja', 'debet'],
        ];

        $baris = [];
        $nilai = [];

        foreach ($defaults as $d) {
            $baris[] = '(?, ?, ?, ?, ?)';
            $nilai[] = $skpd;
            $nilai[] = $d[0];
            $nilai[] = $d[1];
            $nilai[] = $d[2];
            $nilai[] = $d[3];
        }

        DB::affectingStatement(
            'INSERT IGNORE INTO akun_master (skpd, kode_akun, nama_akun, tipe, saldo_normal) VALUES ' . implode(',', $baris),
            $nilai
        );
    }

    /** Nama akun dari kode: akun_master (instansi) -> akun_penerimaan -> ''. */
    protected function akunNamaOf(string $skpd, string $kode): string
    {
        if ($kode === '') {
            return '';
        }

        $nama = (string) (DB::table('akun_master')
            ->where('skpd', $skpd)
            ->where('kode_akun', $kode)
            ->value('nama_akun') ?? '');

        if ($nama !== '') {
            return $nama;
        }

        return (string) (DB::table('akun_penerimaan')
            ->where('kode_akun', $kode)
            ->orderByDesc('id')
            ->value('nama_akun') ?? '');
    }
}
