<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Packaging;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Packaging\ParcelDimensions;
use CodeConjure\SyliusGlsPlugin\Packaging\SizeClass;
use CodeConjure\SyliusGlsPlugin\Packaging\SizeLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A méretosztályok a GLS `parcelLimits.hu` mért értékeiből (#260): automatánál
 * dobozméret, csomagpontnál és házhoz a leghosszabb + legrövidebb oldal; a
 * határ maga még belefér, egy mm-rel fölötte már nem.
 */
final class SizeLimitsTest extends TestCase
{
    /** @return iterable<string, array{GlsDeliveryKind, ParcelDimensions, ?SizeClass}> */
    public static function cases(): iterable
    {
        // Automata: XS 75×180×600, S 75×430×600, M 170×430×600, L 360×430×600, XL 430×600×740 mm.
        yield 'automata XS, pont a határon' => [GlsDeliveryKind::ParcelLocker, ParcelDimensions::of(75, 180, 600, 500), SizeClass::XS];
        yield 'automata: 1 mm-rel szélesebb az XS-nél → S' => [GlsDeliveryKind::ParcelLocker, ParcelDimensions::of(75, 181, 600, 500), SizeClass::S];
        yield 'automata: forgatva is befér' => [GlsDeliveryKind::ParcelLocker, ParcelDimensions::of(600, 75, 180, 500), SizeClass::XS];
        yield 'automata: 76 mm vastag → M' => [GlsDeliveryKind::ParcelLocker, ParcelDimensions::of(76, 300, 400, 500), SizeClass::M];
        yield 'automata: L' => [GlsDeliveryKind::ParcelLocker, ParcelDimensions::of(360, 430, 600, 500), SizeClass::L];
        yield 'automata: XL' => [GlsDeliveryKind::ParcelLocker, ParcelDimensions::of(430, 600, 740, 500), SizeClass::XL];
        yield 'automata: 1 mm-rel hosszabb az XL-nél' => [GlsDeliveryKind::ParcelLocker, ParcelDimensions::of(430, 600, 741, 500), null];
        yield 'automata: 40 kg még megy' => [GlsDeliveryKind::ParcelLocker, ParcelDimensions::of(10, 100, 100, 40_000), SizeClass::XS];
        yield 'automata: túlsúly' => [GlsDeliveryKind::ParcelLocker, ParcelDimensions::of(10, 100, 100, 40_001), null];

        // Csomagpont / házhoz: leghosszabb + legrövidebb ≤ 350 / 500 / 650 / 800 mm.
        yield 'csomagpont XS, pont a határon' => [GlsDeliveryKind::ParcelShop, ParcelDimensions::of(50, 200, 300, 500), SizeClass::XS];
        yield 'csomagpont: 1 mm-rel fölötte → S' => [GlsDeliveryKind::ParcelShop, ParcelDimensions::of(51, 200, 300, 500), SizeClass::S];
        yield 'csomagpont: M' => [GlsDeliveryKind::ParcelShop, ParcelDimensions::of(150, 300, 500, 500), SizeClass::M];
        yield 'házhoz: L, pont a határon' => [GlsDeliveryKind::HomeDelivery, ParcelDimensions::of(200, 400, 600, 500), SizeClass::L];
        yield 'házhoz: L fölött → XL' => [GlsDeliveryKind::HomeDelivery, ParcelDimensions::of(200, 400, 601, 500), SizeClass::XL];
        yield 'házhoz: XL a 300 cm-es körméretig' => [GlsDeliveryKind::HomeDelivery, ParcelDimensions::of(300, 300, 1800, 500), SizeClass::XL];
        yield 'házhoz: a körméret 1 mm-rel több' => [GlsDeliveryKind::HomeDelivery, ParcelDimensions::of(300, 301, 1800, 500), null];
        yield 'házhoz: 200 cm fölött nem' => [GlsDeliveryKind::HomeDelivery, ParcelDimensions::of(10, 10, 2001, 500), null];
        yield 'házhoz: a középső oldal 80 cm fölött nem' => [GlsDeliveryKind::HomeDelivery, ParcelDimensions::of(10, 801, 900, 500), null];
        yield 'csomagpont: túlsúly' => [GlsDeliveryKind::ParcelShop, ParcelDimensions::of(10, 100, 100, 40_001), null];
    }

    #[DataProvider('cases')]
    public function testItClassifiesTheParcel(GlsDeliveryKind $kind, ParcelDimensions $parcel, ?SizeClass $expected): void
    {
        self::assertSame($expected, SizeLimits::classify($kind, $parcel));
    }
}
