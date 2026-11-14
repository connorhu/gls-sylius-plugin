<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Shipping;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Packaging\ParcelEstimator;
use CodeConjure\SyliusGlsPlugin\Shipping\GlsShippingMethodEligibilityChecker;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsShippingMethod;
use CodeConjure\SyliusGlsPlugin\Tests\Support\ShipmentBuilder;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ShippingMethod;

/**
 * Ami nem fér be, az a mód nem választható (#260): a vevő a 2. lépésen ne
 * is lássa a GLS automatát, ha a kosár egy XL rekeszbe sem fér — ne a
 * feladáskor derüljön ki.
 */
final class GlsShippingMethodEligibilityCheckerTest extends TestCase
{
    public function testALockerIsNotOfferedForAnItemThatFitsNoBox(): void
    {
        $shipment = ShipmentBuilder::shipment()->item(100, 100, 800, 1000)->build();
        $checker = new GlsShippingMethodEligibilityChecker(new ParcelEstimator());

        self::assertFalse($checker->isEligible($shipment, new GlsShippingMethod(GlsDeliveryKind::ParcelLocker->value)));
        self::assertTrue($checker->isEligible($shipment, new GlsShippingMethod(GlsDeliveryKind::ParcelShop->value)));
        self::assertTrue($checker->isEligible($shipment, new GlsShippingMethod(GlsDeliveryKind::HomeDelivery->value)));
    }

    public function testNoGlsMethodIsOfferedForAnOverweightItem(): void
    {
        $shipment = ShipmentBuilder::shipment()->item(100, 100, 100, 40_001)->build();
        $checker = new GlsShippingMethodEligibilityChecker(new ParcelEstimator());

        foreach (GlsDeliveryKind::cases() as $kind) {
            self::assertFalse($checker->isEligible($shipment, new GlsShippingMethod($kind->value)), $kind->value);
        }
    }

    public function testACartThatSplitsIntoParcelsIsStillEligible(): void
    {
        $shipment = ShipmentBuilder::shipment()->item(200, 50, 300, 15_000, quantity: 3)->build();

        self::assertTrue(new GlsShippingMethodEligibilityChecker(new ParcelEstimator())->isEligible($shipment, new GlsShippingMethod(GlsDeliveryKind::HomeDelivery->value)));
    }

    public function testOtherCarriersAreNotItsBusiness(): void
    {
        $shipment = ShipmentBuilder::shipment()->item(100, 100, 100, 90_000)->build();
        $checker = new GlsShippingMethodEligibilityChecker(new ParcelEstimator());

        self::assertTrue($checker->isEligible($shipment, new ShippingMethod()));
        self::assertTrue($checker->isEligible($shipment, new GlsShippingMethod('foxpost_parcel_locker')));
    }
}
