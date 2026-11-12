<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Aklap\Concerns;

/**
 * Template blanko Buku Jurnal Khusus (Modul-3E-LK).
 *
 * Data disalin dari legacy `jk_template()` pada
 * peta.simtkd.com/api/akuntansi.php dan disimpan sebagai satu sumber di
 * `resources/data/jurnal_khusus.json` — berkas itu dihasilkan langsung dari
 * respons `action=jurnal_khusus_template` sistem legacy sehingga bentuk dan
 * nilai kolomnya identik (termasuk kolom kosong di ujung tiap grup yang
 * kode akunnya boleh diisi manual).
 */
trait JurnalKhususTemplate
{
    /** Batas jumlah kolom per grup (legacy: konstanta JK_MAX_SLOT). */
    public const MAKS_SLOT = 10;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $jkTemplate = null;

    /**
     * Template per jenis jurnal, berindeks `key` (mis. `pendapatan_lo`).
     *
     * @return array<string, array<string, mixed>>
     */
    protected function jkTemplate(): array
    {
        if (self::$jkTemplate !== null) {
            return self::$jkTemplate;
        }

        $berkas = resource_path('data/jurnal_khusus.json');
        $data   = is_readable($berkas) ? json_decode((string) file_get_contents($berkas), true) : null;

        $tpl = [];

        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $t) {
                $key = trim((string) ($t['key'] ?? ''));

                if ($key === '') {
                    continue;
                }

                $tpl[$key] = [
                    'key'          => $key,
                    'judul'        => (string) ($t['judul'] ?? ''),
                    'satuan'       => (string) ($t['satuan'] ?? ''),
                    'nomor_label'  => array_values((array) ($t['nomor_label'] ?? ['Nomor'])),
                    'label_id'     => (string) ($t['label_id'] ?? 'NIP'),
                    'grup'         => (string) ($t['grup'] ?? ''),
                    'uraian_label' => (string) ($t['uraian_label'] ?? 'Uraian'),
                    'maks_slot'    => self::MAKS_SLOT,
                    'debet'        => [
                        'label' => (string) ($t['debet']['label'] ?? 'Debet'),
                        'kode'  => array_values((array) ($t['debet']['kode'] ?? [])),
                        'nama'  => array_values((array) ($t['debet']['nama'] ?? [])),
                    ],
                    'kredit'       => [
                        'label' => (string) ($t['kredit']['label'] ?? 'Kredit'),
                        'kode'  => array_values((array) ($t['kredit']['kode'] ?? [])),
                        'nama'  => array_values((array) ($t['kredit']['nama'] ?? [])),
                    ],
                    'contoh'       => array_values((array) ($t['contoh'] ?? [])),
                ];
            }
        }

        return self::$jkTemplate = $tpl;
    }

    /** Data template siap kirim ke frontend (daftar blanko untuk dropdown). */
    protected function jkTemplatePublik(): array
    {
        return array_values($this->jkTemplate());
    }

    /** Kunci jenis jurnal pertama (fallback bila jenis tidak dikenal). */
    protected function jkJenisDefault(): string
    {
        $kunci = array_keys($this->jkTemplate());

        return (string) ($kunci[0] ?? 'pendapatan_lo');
    }

    /**
     * Jenis jurnal yang valid; selain itu dikembalikan ke jenis pertama.
     */
    protected function jkJenisValid(string $jenis, bool $fallback = true): string
    {
        if (isset($this->jkTemplate()[$jenis])) {
            return $jenis;
        }

        return $fallback ? $this->jkJenisDefault() : '';
    }

    /**
     * Daftar kolom uang pada tabel baris (debet_1, kredit_1, ...).
     *
     * @return array<int, string>
     */
    protected function jkKolomUang(): array
    {
        $kolom = [];

        for ($i = 1; $i <= self::MAKS_SLOT; $i++) {
            $kolom[] = "debet_{$i}";
            $kolom[] = "kredit_{$i}";
        }

        return $kolom;
    }
}
