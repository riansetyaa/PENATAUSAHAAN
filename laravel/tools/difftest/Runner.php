<?php

declare(strict_types=1);

namespace Simtkd\Difftest;

/**
 * Runner differential test: API legacy (golden master) vs API baru (Laravel).
 *
 * Konsep:
 *   - API legacy (PHP vanilla) adalah BENCHMARK. Apa pun yang dikembalikan
 *     legacy dianggap benar.
 *   - API baru (Laravel) harus mengembalikan hasil yang setara.
 *
 * Karena kedua API membaca basis data yang sama, perbandingan nilai
 * (mis. total anggaran, saldo akun, nomor dokumen) benar-benar bermakna.
 *
 * Skenario yang belum ada padanya API baru ditandai PENDING dan menjadi
 * CHECKLIST porting.
 */
class Runner
{
    /** @var array<int, ScenarioResult> */
    private array $results = [];

    /**
     * @param  array<string, mixed>  $vars  Variabel runtime untuk placeholder {{...}}
     */
    public function __construct(
        private readonly Client $legacy,
        private readonly Client $baru,
        private readonly bool $verbose = false,
        private readonly array $vars = [],
        /** Callback opsional sebelum setiap skenario (mis. reset rate limiter uji). */
        private readonly ?\Closure $sebelum = null,
    ) {
    }

    /**
     * Jalankan seluruh skenario.
     *
     * @param  array<int, array<string, mixed>>  $scenarios
     * @return array<int, ScenarioResult>
     */
    public function run(array $scenarios): array
    {
        foreach ($scenarios as $sc) {
            if ($this->sebelum !== null) {
                ($this->sebelum)($sc);
            }

            $this->results[] = $this->runOne($sc);
        }

        return $this->results;
    }

    /**
     * @param  array<string, mixed>  $sc
     */
    private function runOne(array $sc): ScenarioResult
    {
        $name = (string) ($sc['name'] ?? 'tanpa-nama');
        $drop = (array) ($sc['drop'] ?? []);

        // Skenario belum diimplementasikan di API baru -> PENDING.
        if (! isset($sc['baru']) || $sc['baru'] === null) {
            return new ScenarioResult(
                $name,
                ScenarioResult::PENDING,
                note: (string) ($sc['desc'] ?? 'Belum ada di API baru')
            );
        }

        // Variabel runtime digabung dengan variabel per-skenario (per-skenario
        // mengoverride yang global).
        $vars = array_replace_recursive($this->vars, (array) ($sc['vars'] ?? []));

        // ---- API legacy (golden master) ----
        $legacySpec = $sc['legacy'];
        $legacyResp = $this->legacy->request(
            (string) ($legacySpec['method'] ?? 'GET'),
            (string) $legacySpec['path'],
            $this->interpolate((array) ($legacySpec['params'] ?? []), $vars),
            [],
            $this->interpolate((array) ($legacySpec['query'] ?? []), $vars)
        );

        if (! $legacyResp->isJson()) {
            return new ScenarioResult(
                $name,
                ScenarioResult::ERROR,
                note: 'API legacy tidak mengembalikan JSON (HTTP ' . $legacyResp->status . '): '
                    . substr($legacyResp->raw, 0, 200),
                legacyStatus: $legacyResp->status,
            );
        }

        // ---- API baru ----
        // Skenario boleh menimpa token Bearer (mis. menguji 401); token
        // semula dipulihkan kembali setelah request.
        $baruSpec = $sc['baru'];
        $gantiToken = array_key_exists('token', $baruSpec);
        $tokenSemula = $this->baru->token();

        if ($gantiToken) {
            $this->baru->withToken($baruSpec['token'] === null ? null : (string) $baruSpec['token']);
        }

        try {
            $baruResp = $this->baru->request(
                (string) ($baruSpec['method'] ?? 'GET'),
                (string) $baruSpec['path'],
                $this->interpolate((array) ($baruSpec['params'] ?? []), $vars),
                [],
                $this->interpolate((array) ($baruSpec['query'] ?? []), $vars)
            );
        } finally {
            if ($gantiToken) {
                $this->baru->withToken($tokenSemula);
            }
        }

        if (! $baruResp->isJson()) {
            return new ScenarioResult(
                $name,
                ScenarioResult::ERROR,
                note: 'API baru tidak mengembalikan JSON (HTTP ' . $baruResp->status . '): '
                    . substr($baruResp->raw, 0, 200),
                legacyStatus: $legacyResp->status,
                newStatus: $baruResp->status,
            );
        }

        // ---- Perbandingan status HTTP ----
        $diffs = [];

        $expectedStatus = (int) ($legacySpec['expect_status'] ?? $legacyResp->status);
        if ($baruResp->status !== $expectedStatus) {
            $diffs[] = "HTTP status: legacy {$legacyResp->status} vs baru {$baruResp->status}";
        }

        // ---- Perbandingan body ----
        $expected = Normalizer::normalize($legacyResp->data, $drop);
        $actual   = Normalizer::normalize($baruResp->data, $drop);

        $diffs = array_merge($diffs, Normalizer::diff($expected, $actual));

        // ---- Ekspektasi tambahan per-skenario ----
        // Nilai ekspektasi juga boleh memakai placeholder {{...}}.
        foreach ((array) ($sc['expect'] ?? []) as $path => $value) {
            if (is_string($value)) {
                $value = $this->interpolate(['v' => $value], $vars)['v'];
            }

            $actualValue = $this->pick($baruResp->data, (string) $path);

            if ($actualValue !== $value) {
                $diffs[] = "ekspektasi {$path}: " . json_encode($value, JSON_UNESCAPED_UNICODE)
                    . ' vs ' . json_encode($actualValue, JSON_UNESCAPED_UNICODE);
            }
        }

        // ---- Perbedaan yang disengaja ----
        // Bila skenario mendeklarasikan `known_divergence`, perbedaan di atas
        // diperlakukan sebagai hal yang KNOWN (bukan kegagalan) asalkan
        // alasannya diisi. Ini dipakai ketika porting sengaja memperbaiki
        // perilaku legacy yang memang rusak.
        $known = $sc['known_divergence'] ?? null;

        if ($diffs !== [] && is_array($known) && ! empty($known['reason'])) {
            return new ScenarioResult(
                $name,
                ScenarioResult::KNOWN,
                $diffs,
                $legacyResp->status,
                $baruResp->status,
                note: (string) ($known['note'] ?? ''),
                knownReason: (string) $known['reason'],
            );
        }

        return new ScenarioResult(
            $name,
            $diffs === [] ? ScenarioResult::PASS : ScenarioResult::FAIL,
            $diffs,
            $legacyResp->status,
            $baruResp->status,
        );
    }

    /**
     * Ambil nilai dari array dengan path "a.b.0.c".
     */
    private function pick(?array $data, string $path): mixed
    {
        $cur = $data;

        foreach (explode('.', $path) as $seg) {
            if (! is_array($cur) || ! array_key_exists($seg, $cur)) {
                return null;
            }
            $cur = $cur[$seg];
        }

        return $cur;
    }

    /**
     * Substitusi placeholder {{nama}} pada parameter.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $vars
     * @return array<string, mixed>
     */
    private function interpolate(array $params, array $vars): array
    {
        $out = [];

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->interpolate($value, $vars);
                continue;
            }

            if (! is_string($value)) {
                $out[$key] = $value;
                continue;
            }

            $out[$key] = preg_replace_callback(
                '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
                function (array $m) use ($vars): string {
                    $cur = $vars;
                    foreach (explode('.', $m[1]) as $seg) {
                        if (! is_array($cur) || ! array_key_exists($seg, $cur)) {
                            return '';
                        }
                        $cur = $cur[$seg];
                    }

                    return is_scalar($cur) ? (string) $cur : '';
                },
                $value
            );
        }

        return $out;
    }
}
