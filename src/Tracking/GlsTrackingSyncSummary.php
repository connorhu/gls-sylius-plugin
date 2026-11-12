<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tracking;

/**
 * Egy nyomkövetés-szinkron futás eredménye (#258) — a FoxPost plugin #173-as
 * tanulsága: a záró napló a TÉNYLEGESEN feldolgozott csomagokat számolja, nem
 * a lekérdezés találatát.
 *
 * - `found`: a nyomon követendő csomagok;
 * - `synced`: a GLS válaszolt rá, a nyers státusz eltéve;
 * - `transitions`: ahány csomag tényleg állapotot váltott;
 * - `skipped`: nem kérdezhető (nem szám nyomkövetési szám, vagy API nélküli mód);
 * - `failed`: a kérés elbukott, vagy a csomag hiányzott a válaszból.
 *
 * `found === synced + skipped + failed`; a `transitions` a `synced` része.
 */
final readonly class GlsTrackingSyncSummary
{
    public function __construct(
        public int $found = 0,
        public int $synced = 0,
        public int $transitions = 0,
        public int $skipped = 0,
        public int $failed = 0,
        public bool $apiDisabled = false,
    ) {
    }

    /** @return array{found: int, synced: int, transitions: int, skipped: int, failed: int} */
    public function toLogContext(): array
    {
        return [
            'found' => $this->found,
            'synced' => $this->synced,
            'transitions' => $this->transitions,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
        ];
    }
}
