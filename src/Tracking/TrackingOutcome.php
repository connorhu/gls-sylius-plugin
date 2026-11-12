<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tracking;

/** Egy GLS státuszkód jelentése a bolt szemével (#258). */
enum TrackingOutcome
{
    /** A GLS-nél: úton, depóban, kézbesítésre kiadva, ponton vagy automatában vár. */
    case InTransit;

    case Delivered;
    case Failed;
    case Returned;

    /** Az adat rögzítve (51), a csomag még nincs a GLS-nél. */
    case NotYetAtGls;

    /** A leképezés nem ismeri — a nyers kód megmarad, átmenet nincs. */
    case Unknown;

    /** A `gls_parcel` workflow átmenete, vagy null. */
    public function transition(): ?string
    {
        return match ($this) {
            self::InTransit => 'tracking_in_transit',
            self::Delivered => 'tracking_delivered',
            self::Failed => 'tracking_failed',
            self::Returned => 'tracking_returned',
            self::NotYetAtGls, self::Unknown => null,
        };
    }

    /** A kód a GLS saját szkennelése — a csomagot a GLS átvette. */
    public function provesHandOver(): bool
    {
        return $this !== self::NotYetAtGls && $this !== self::Unknown;
    }
}
