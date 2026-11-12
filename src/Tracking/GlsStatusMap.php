<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tracking;

/**
 * A MyGLS státuszkódok (G függelék, ver. 25.12.11) leképezése (#258).
 *
 * Szándékosan zárt listák: ami egyikben sincs (vám, megsemmisítés, kárügy,
 * automata-hiba, pickup-szolgáltatás…), az `Unknown` — a nyers kód eltárolva,
 * átmenet nélkül, és a szinkron naplózza. Egy rossz „úton" jelzés rosszabb,
 * mint a változatlan állapot.
 */
final class GlsStatusMap
{
    private const array DELIVERED = [5, 54, 55, 58, 59, 92];

    private const array RETURNED = [23, 40];

    private const array FAILED = [11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 33, 34, 35, 36, 68];

    /** A GLS-nél: átvéve, depóban, úton, kiszállításon, ponton / automatában vár. */
    private const array IN_TRANSIT = [1, 2, 3, 4, 6, 7, 8, 9, 10, 21, 22, 24, 25, 26, 27, 32, 37, 41, 46, 47, 53, 56, 57, 80, 86, 97, 99];

    private const array NOT_YET_AT_GLS = [51, 52];

    public static function outcome(string $code): TrackingOutcome
    {
        if (preg_match('/^\d+$/', trim($code)) !== 1) {
            return TrackingOutcome::Unknown;
        }

        $number = (int) $code;

        return match (true) {
            \in_array($number, self::DELIVERED, true) => TrackingOutcome::Delivered,
            \in_array($number, self::RETURNED, true) => TrackingOutcome::Returned,
            \in_array($number, self::FAILED, true) => TrackingOutcome::Failed,
            \in_array($number, self::IN_TRANSIT, true) => TrackingOutcome::InTransit,
            \in_array($number, self::NOT_YET_AT_GLS, true) => TrackingOutcome::NotYetAtGls,
            default => TrackingOutcome::Unknown,
        };
    }
}
