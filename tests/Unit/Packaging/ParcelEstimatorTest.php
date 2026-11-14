<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Packaging;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Packaging\EstimatedParcel;
use CodeConjure\SyliusGlsPlugin\Packaging\ParcelEstimator;
use CodeConjure\SyliusGlsPlugin\Packaging\SizeClass;
use CodeConjure\SyliusGlsPlugin\Tests\Support\ShipmentBuilder;
use PHPUnit\Framework\TestCase;

/**
 * A kosár csomagmérete (#260): a tételek egymásra rakva — a legkisebb méretük
 * összeadódik, a másik kettőből a legnagyobb számít —, plusz a ráhagyás; ami
 * egy csomagba nem fér, az szétbomlik (first-fit decreasing).
 */
final class ParcelEstimatorTest extends TestCase
{
    public function testASingleScoreIsOneXsParcel(): void
    {
        $shipment = ShipmentBuilder::shipment()->item(160, 10, 230, 250)->build();

        $parcels = new ParcelEstimator()->estimate($shipment, GlsDeliveryKind::ParcelLocker);

        self::assertNotNull($parcels);
        self::assertCount(1, $parcels);
        self::assertSame(SizeClass::XS, $parcels[0]->sizeClass);
        self::assertSame([10.0, 160.0, 230.0], $parcels[0]->dimensions->sorted());
        self::assertSame(250.0, $parcels[0]->dimensions->weight);
    }

    public function testItemsAreStackedOnTheirThinnestSide(): void
    {
        // 3 db 10 mm vastag kotta + egy 30 mm-es könyv: 60 mm vastag, a
        // szélesség és a hosszúság a legnagyobb tételé.
        $shipment = ShipmentBuilder::shipment()
            ->item(160, 10, 230, 250, quantity: 3)
            ->item(170, 240, 30, 600)
            ->build();

        $parcels = new ParcelEstimator()->estimate($shipment, GlsDeliveryKind::ParcelLocker);

        self::assertNotNull($parcels);
        self::assertCount(1, $parcels);
        self::assertSame([60.0, 170.0, 240.0], $parcels[0]->dimensions->sorted());
        self::assertSame(1350.0, $parcels[0]->dimensions->weight);
    }

    public function testTheAllowanceIsAddedToEverySideAndOncePerParcel(): void
    {
        $shipment = ShipmentBuilder::shipment()->item(160, 10, 230, 250, quantity: 2)->build();

        $parcels = new ParcelEstimator(allowanceMm: 5, allowanceG: 100)->estimate($shipment, GlsDeliveryKind::ParcelLocker);

        self::assertNotNull($parcels);
        self::assertSame([25.0, 165.0, 235.0], $parcels[0]->dimensions->sorted());
        self::assertSame(600.0, $parcels[0]->dimensions->weight);
    }

    public function testTheAllowanceCanPushTheParcelIntoTheNextClass(): void
    {
        $shipment = ShipmentBuilder::shipment()->item(75, 180, 600, 250)->build();

        self::assertSame(SizeClass::XS, self::single(new ParcelEstimator()->estimate($shipment, GlsDeliveryKind::ParcelLocker))->sizeClass);
        self::assertSame(SizeClass::XL, self::single(new ParcelEstimator(allowanceMm: 1)->estimate($shipment, GlsDeliveryKind::ParcelLocker))->sizeClass);
    }

    public function testASingleItemTooBigForTheLockerMakesTheShipmentImpossible(): void
    {
        $shipment = ShipmentBuilder::shipment()->item(160, 10, 230, 250)->item(100, 100, 800, 1000)->build();

        self::assertNull(new ParcelEstimator()->estimate($shipment, GlsDeliveryKind::ParcelLocker));
        self::assertNotNull(new ParcelEstimator()->estimate($shipment, GlsDeliveryKind::HomeDelivery));
    }

    public function testASingleOverweightItemMakesTheShipmentImpossible(): void
    {
        $shipment = ShipmentBuilder::shipment()->item(100, 100, 100, 40_001)->build();

        self::assertNull(new ParcelEstimator()->estimate($shipment, GlsDeliveryKind::HomeDelivery));
    }

    public function testItemsThatDoNotFitTogetherAreSplitIntoParcels(): void
    {
        // 3 × 15 kg: kettő együtt 30 kg (befér), a harmadik már 45 kg lenne.
        $shipment = ShipmentBuilder::shipment()->item(200, 50, 300, 15_000, quantity: 3)->build();

        $parcels = new ParcelEstimator()->estimate($shipment, GlsDeliveryKind::HomeDelivery);

        self::assertNotNull($parcels);
        self::assertSame([30_000.0, 15_000.0], array_map(static fn (EstimatedParcel $parcel): float => $parcel->dimensions->weight, $parcels));
    }

    public function testALockerCartIsSplitWhenTheStackOutgrowsTheXlBox(): void
    {
        // 5 db 100 mm vastag, 500 × 700 mm-es doboz: négy együtt 400 mm
        // (befér az XL-be: 430 × 600 × 740), öt már 500 mm — kettébomlik.
        $shipment = ShipmentBuilder::shipment()->item(100, 500, 700, 1000, quantity: 5)->build();

        $parcels = new ParcelEstimator()->estimate($shipment, GlsDeliveryKind::ParcelLocker);

        self::assertNotNull($parcels);
        self::assertCount(2, $parcels);
        self::assertSame([400.0, 500.0, 700.0], $parcels[0]->dimensions->sorted());
        self::assertSame(SizeClass::XL, $parcels[0]->sizeClass);
        self::assertSame([100.0, 500.0, 700.0], $parcels[1]->dimensions->sorted());
        self::assertSame(4000.0, $parcels[0]->dimensions->weight);
    }

    public function testTheLargestItemsArePackedFirst(): void
    {
        // A kis tétel érkezik elsőnek, de a nagy kerül az első csomagba.
        $shipment = ShipmentBuilder::shipment()->item(10, 100, 100, 100)->item(50, 300, 400, 39_950)->build();

        $parcels = new ParcelEstimator()->estimate($shipment, GlsDeliveryKind::HomeDelivery);

        self::assertNotNull($parcels);
        self::assertSame([39_950.0, 100.0], array_map(static fn (EstimatedParcel $parcel): float => $parcel->dimensions->weight, $parcels));
    }

    public function testMissingDimensionsCountAsZero(): void
    {
        // A #261 indulási kapuja gondoskodik a teljes adatról; addig a hiány
        // nem teszi a módot választhatatlanná.
        $shipment = ShipmentBuilder::shipment()->item(null, null, null, null)->item(160, 10, 230, 250)->build();

        $parcel = self::single(new ParcelEstimator()->estimate($shipment, GlsDeliveryKind::ParcelLocker));

        self::assertSame([10.0, 160.0, 230.0], $parcel->dimensions->sorted());
        self::assertSame(250.0, $parcel->dimensions->weight);
    }

    public function testAnEmptyShipmentIsOneEmptyXsParcel(): void
    {
        $parcel = self::single(new ParcelEstimator()->estimate(ShipmentBuilder::shipment()->build(), GlsDeliveryKind::ParcelShop));

        self::assertSame(SizeClass::XS, $parcel->sizeClass);
    }

    /** @param list<EstimatedParcel>|null $parcels */
    private static function single(?array $parcels): EstimatedParcel
    {
        self::assertNotNull($parcels);
        self::assertCount(1, $parcels);

        return $parcels[0];
    }
}
