<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Pickup;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPoint;
use PHPUnit\Framework\TestCase;

/**
 * Egy pont a GLS nyilvános pontlistájából
 * (`map.gls-hungary.com/data/deliveryPoints/hu.json`, mérve 2026-11-12) —
 * a két minta a valódi adat két sora, a `hours`/`location` nélkül.
 */
final class DeliveryPointTest extends TestCase
{
    public const array SHOP = [
        'id' => '1027-CSOMAGPONT05',
        'name' => 'Tölgyfa ABC',
        'contact' => ['countryCode' => 'HU', 'postalCode' => '1027', 'city' => 'BUDAPEST II. KER.', 'address' => 'Tölgyfa utca 9'],
        'features' => ['acceptsCash', 'acceptsCard', 'pickup', 'delivery'],
        'type' => 'parcel-shop',
    ];

    public const array LOCKER = [
        'id' => 'HU1012-PLOCKER001',
        'name' => 'GLS Automata SHELL Budapest I. Ker.',
        'contact' => ['countryCode' => 'HU', 'postalCode' => '1012', 'city' => 'BUDAPEST I. KER.', 'address' => 'Alkotás utca 20-24.'],
        'features' => ['acceptsCard', 'pickup', 'delivery', 'milkrun'],
        'type' => 'parcel-locker',
    ];

    public function testItReadsAParcelShop(): void
    {
        $point = DeliveryPoint::fromArray(self::SHOP);

        self::assertNotNull($point);
        self::assertSame('1027-CSOMAGPONT05', $point->id);
        self::assertSame('Tölgyfa ABC', $point->name);
        self::assertSame('parcel-shop', $point->type);
        self::assertSame('HU', $point->countryCode);
        self::assertSame('1027 BUDAPEST II. KER., Tölgyfa utca 9', $point->addressLine());
        self::assertTrue($point->acceptsCash);
    }

    /** Mérve: a 3225 automatából egyik sem fogad készpénzt. */
    public function testALockerDoesNotAcceptCash(): void
    {
        $point = DeliveryPoint::fromArray(self::LOCKER);

        self::assertNotNull($point);
        self::assertFalse($point->acceptsCash);
    }

    public function testThePointMatchesOnlyItsOwnKind(): void
    {
        $shop = DeliveryPoint::fromArray(self::SHOP);
        $locker = DeliveryPoint::fromArray(self::LOCKER);
        self::assertNotNull($shop);
        self::assertNotNull($locker);

        self::assertTrue($shop->matches(GlsDeliveryKind::ParcelShop));
        self::assertFalse($shop->matches(GlsDeliveryKind::ParcelLocker));
        self::assertTrue($locker->matches(GlsDeliveryKind::ParcelLocker));
        self::assertFalse($locker->matches(GlsDeliveryKind::HomeDelivery));
    }

    /** A külső adat alakja nem a miénk: a hibás sor kimarad, nem dob. */
    public function testAMalformedRowIsRejected(): void
    {
        self::assertNull(DeliveryPoint::fromArray(['id' => 'X']));
        self::assertNull(DeliveryPoint::fromArray([...self::SHOP, 'id' => 12]));
        self::assertNull(DeliveryPoint::fromArray([...self::SHOP, 'type' => 'post-office']));
        self::assertNull(DeliveryPoint::fromArray([...self::SHOP, 'contact' => 'Budapest']));
    }

    public function testMissingFeaturesMeanNoCash(): void
    {
        $row = self::SHOP;
        unset($row['features']);

        self::assertFalse(DeliveryPoint::fromArray($row)?->acceptsCash);
    }
}
