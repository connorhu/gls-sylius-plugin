<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Model;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsShipment;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsShippingMethod;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShippingMethod;

/**
 * A három GLS mód (#251: három KÜLÖN szállítási mód, hogy a vevő egyszer,
 * választás előtt döntsön). A backing érték a HOST slugja, `gls_`
 * előtaggal — így nem ütközik a FoxPost `foxpost_*` slugjaival egy közös
 * host-enumban sem.
 */
final class GlsDeliveryKindTest extends TestCase
{
    public function testTheSlugsArePrefixed(): void
    {
        self::assertSame(
            ['gls_home_delivery', 'gls_parcel_shop', 'gls_parcel_locker'],
            array_map(static fn (GlsDeliveryKind $kind): string => $kind->value, GlsDeliveryKind::cases()),
        );
    }

    public function testExactlyOneOfTheTwoPredicatesHolds(): void
    {
        foreach (GlsDeliveryKind::cases() as $kind) {
            self::assertNotSame($kind->isPickupPoint(), $kind->isHomeDelivery(), $kind->name);
        }
    }

    public function testTheShopAndTheLockerArePickupPoints(): void
    {
        self::assertTrue(GlsDeliveryKind::ParcelShop->isPickupPoint());
        self::assertTrue(GlsDeliveryKind::ParcelLocker->isPickupPoint());
        self::assertTrue(GlsDeliveryKind::HomeDelivery->isHomeDelivery());
    }

    /**
     * A GLS csomagpont-adat `DeliveryPointType`-ja (MasterDataService):
     * 1 = ParcelShop, 2 = ParcelLocker — a térkép-widget szűrője is erre ül.
     */
    public function testTheDeliveryPointTypeMatchesTheGlsMasterData(): void
    {
        self::assertNull(GlsDeliveryKind::HomeDelivery->deliveryPointType());
        self::assertSame(1, GlsDeliveryKind::ParcelShop->deliveryPointType());
        self::assertSame(2, GlsDeliveryKind::ParcelLocker->deliveryPointType());
    }

    /**
     * A térkép-widget (`<gls-dpm-dialog filter-type="…">`) és a nyilvános
     * pontlista `type` mezője ugyanezt a két értéket beszéli.
     */
    public function testTheMapFilterTypeMatchesTheWidgetAndThePointList(): void
    {
        self::assertNull(GlsDeliveryKind::HomeDelivery->mapFilterType());
        self::assertSame('parcel-shop', GlsDeliveryKind::ParcelShop->mapFilterType());
        self::assertSame('parcel-locker', GlsDeliveryKind::ParcelLocker->mapFilterType());
    }

    public function testItResolvesFromAGlsShippingMethod(): void
    {
        self::assertSame(GlsDeliveryKind::ParcelShop, GlsDeliveryKind::fromShippingMethod(new GlsShippingMethod('gls_parcel_shop')));
    }

    public function testANonGlsMethodHasNoGlsKind(): void
    {
        self::assertNull(GlsDeliveryKind::fromShippingMethod(new GlsShippingMethod('foxpost_parcel_locker')));
        self::assertNull(GlsDeliveryKind::fromShippingMethod(new GlsShippingMethod(null)));
        self::assertNull(GlsDeliveryKind::fromShippingMethod(new ShippingMethod()));
        self::assertNull(GlsDeliveryKind::fromShippingMethod(null));
    }

    /**
     * Egy leadott rendelés szállítmánya a SAJÁT fajtáját mondja (a host a
     * mód választásakor pillanatképet tesz el) — az összegző ebből dolgozik,
     * nem a mód mostani beállításából.
     */
    public function testItResolvesFromAGlsShipment(): void
    {
        self::assertSame(GlsDeliveryKind::ParcelLocker, GlsDeliveryKind::fromShipment(new GlsShipment(new GlsShippingMethod('gls_parcel_locker'))));
        self::assertNull(GlsDeliveryKind::fromShipment(new GlsShipment(new GlsShippingMethod('foxpost_parcel_locker'))));
        self::assertNull(GlsDeliveryKind::fromShipment(new Shipment()));
        self::assertNull(GlsDeliveryKind::fromShipment(null));
    }
}
