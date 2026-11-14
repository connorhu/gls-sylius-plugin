<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Packaging;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;

/**
 * A belföldi GLS méretosztályok — az ecsomag `parcelLimits.hu` mért értékei
 * (bolt-issue #260, 2026-11-12), mm-ben és grammban.
 *
 * - Automatába a csomag a rekesz DOBOZÁBA kell férjen (forgatva is).
 * - Csomagpontba és házhoz a leghosszabb + legrövidebb oldal összege dönt;
 *   XL a 300 cm-es körméretig (leghosszabb + 2 × a másik kettő), legfeljebb
 *   200 × 80 × 60 cm.
 * - Mindenhol legfeljebb 40 kg.
 *
 * A határ maga még belefér. Külföldön a korlátok országonként mások (pl. CZ
 * automata: 20 kg, csak S/M/L) — azokat ez az osztály még nem ismeri.
 */
final class SizeLimits
{
    public const float MAX_WEIGHT = 40_000;

    /** Automata-rekeszek, oldalak növekvő sorrendben. */
    private const array LOCKER_BOXES = [
        'XS' => [75, 180, 600],
        'S' => [75, 430, 600],
        'M' => [170, 430, 600],
        'L' => [360, 430, 600],
        'XL' => [430, 600, 740],
    ];

    /** Csomagpont / házhoz: a leghosszabb + legrövidebb oldal felső határa. */
    private const array LONGEST_PLUS_SHORTEST = [
        'XS' => 350,
        'S' => 500,
        'M' => 650,
        'L' => 800,
    ];

    /** A legnagyobb csomag oldalai (növekvő) és körmérete. */
    private const array MAX_SIDES = [600, 800, 2000];

    private const float MAX_GIRTH = 3000;

    /** A legkisebb osztály, amibe a csomag belefér, vagy null, ha egyikbe sem. */
    public static function classify(GlsDeliveryKind $kind, ParcelDimensions $parcel): ?SizeClass
    {
        if ($parcel->weight > self::MAX_WEIGHT) {
            return null;
        }

        return $kind === GlsDeliveryKind::ParcelLocker ? self::lockerClass($parcel) : self::counterClass($parcel);
    }

    /** A mód legnagyobb osztályába is befér-e. */
    public static function fits(GlsDeliveryKind $kind, ParcelDimensions $parcel): bool
    {
        return self::classify($kind, $parcel) !== null;
    }

    private static function lockerClass(ParcelDimensions $parcel): ?SizeClass
    {
        foreach (self::LOCKER_BOXES as $class => $box) {
            if (self::within($parcel, $box)) {
                return SizeClass::from($class);
            }
        }

        return null;
    }

    private static function counterClass(ParcelDimensions $parcel): ?SizeClass
    {
        $girth = $parcel->longest + 2 * ($parcel->middle + $parcel->shortest);

        if (!self::within($parcel, self::MAX_SIDES) || $girth > self::MAX_GIRTH) {
            return null;
        }

        foreach (self::LONGEST_PLUS_SHORTEST as $class => $limit) {
            if ($parcel->longest + $parcel->shortest <= $limit) {
                return SizeClass::from($class);
            }
        }

        return SizeClass::XL;
    }

    /** @param array{int, int, int} $box */
    private static function within(ParcelDimensions $parcel, array $box): bool
    {
        return $parcel->shortest <= $box[0] && $parcel->middle <= $box[1] && $parcel->longest <= $box[2];
    }
}
