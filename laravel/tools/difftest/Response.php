<?php

declare(strict_types=1);

namespace Simtkd\Difftest;

/**
 * Hasil satu request HTTP.
 */
class Response
{
    /**
     * @param  array<string, mixed>|null  $data  Body JSON yang sudah di-decode
     * @param  string  $raw  Body mentah (untuk pesan error non-JSON)
     */
    public function __construct(
        public readonly int $status,
        public readonly ?array $data,
        public readonly string $raw,
    ) {
    }

    /** Body JSON berupa objek? */
    public function isJson(): bool
    {
        return $this->data !== null;
    }

    /**
     * Ambil nilai dari body JSON dengan path sederhana "a.b.c".
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $cur = $this->data;

        foreach (explode('.', $path) as $seg) {
            if (! is_array($cur) || ! array_key_exists($seg, $cur)) {
                return $default;
            }
            $cur = $cur[$seg];
        }

        return $cur;
    }
}
