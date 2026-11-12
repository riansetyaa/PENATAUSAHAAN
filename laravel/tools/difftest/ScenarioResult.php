<?php

declare(strict_types=1);

namespace Simtkd\Difftest;

/**
 * Hasil eksekusi satu skenario.
 */
class ScenarioResult
{
    public const PASS    = 'PASS';
    public const FAIL    = 'FAIL';
    public const PENDING = 'PENDING';
    public const ERROR   = 'ERROR';

    /**
     * Perbedaan yang disengaja (sudah didokumentasikan & disetujui).
     */
    public const KNOWN   = 'KNOWN';

    /**
     * @param  array<int, string>  $diffs
     */
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly array $diffs = [],
        public readonly ?int $legacyStatus = null,
        public readonly ?int $newStatus = null,
        public readonly string $note = '',
        public readonly ?string $knownReason = null,
    ) {
    }
}
