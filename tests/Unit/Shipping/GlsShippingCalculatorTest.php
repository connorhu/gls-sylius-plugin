<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Shipping;

use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Packaging\ParcelEstimator;
use CodeConjure\SyliusGlsPlugin\Packaging\SizeClass;
use CodeConjure\SyliusGlsPlugin\Shipping\GlsShippingCalculator;
use CodeConjure\SyliusGlsPlugin\Tests\Support\ShipmentBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Exception\MissingChannelConfigurationException;

/**
 * A GLS díja a GLS kalkulátorának képlete szerint (bolt-issue #260):
 *
 *     alapár[méret] (GO-val a GO-keret egységára)
 *   + kézbesítési felár[méret] (a mód saját táblája: dtl / dts / dta)
 *   + feladási felár[méret] (ptl / pts / pta)
 *   + értesítések + utánvét
 *
 * Az árak az ecsomag mért (bejelentkezett, bruttó) árai, fillérben.
 */
final class GlsShippingCalculatorTest extends TestCase
{
    private const array BASE = ['XS' => 159_000, 'S' => 159_000, 'M' => 159_000, 'L' => 259_000, 'XL' => 259_000];

    private const array DELIVERY = [
        'dtl' => ['XS' => 0, 'S' => 0, 'M' => 0, 'L' => 0, 'XL' => 0],
        'dts' => ['XS' => 15_000, 'S' => 15_000, 'M' => 15_000, 'L' => 55_000, 'XL' => 55_000],
        'dta' => ['XS' => 50_000, 'S' => 50_000, 'M' => 50_000, 'L' => 70_000, 'XL' => 70_000],
    ];

    private const array DISPATCH = [
        'ptl' => ['XS' => 0, 'S' => 0, 'M' => 0, 'L' => 0, 'XL' => 0],
        'pts' => ['XS' => 50_000, 'S' => 50_000, 'M' => 50_000, 'L' => 80_000, 'XL' => 80_000],
        'pta' => ['XS' => 65_000, 'S' => 65_000, 'M' => 65_000, 'L' => 90_000, 'XL' => 90_000],
    ];

    private const array DELIVERY_CODE = ['gls_parcel_locker' => 'dtl', 'gls_parcel_shop' => 'dts', 'gls_home_delivery' => 'dta'];

    /** Egy-egy tétel, ami pontosan az adott osztályba esik — fajtánként. */
    private const array ITEM = [
        'locker' => [
            'XS' => [10, 100, 100], 'S' => [50, 300, 500], 'M' => [100, 300, 500], 'L' => [300, 400, 500], 'XL' => [400, 500, 700],
        ],
        'counter' => [
            'XS' => [10, 100, 300], 'S' => [10, 200, 450], 'M' => [10, 300, 600], 'L' => [10, 400, 750], 'XL' => [100, 500, 900],
        ],
    ];

    /** @return iterable<string, array{GlsDeliveryKind, SizeClass, string, ?int}> */
    public static function formulaCases(): iterable
    {
        foreach (GlsDeliveryKind::cases() as $kind) {
            foreach (SizeClass::cases() as $size) {
                foreach (array_keys(self::DISPATCH) as $dispatch) {
                    foreach ([null, 129_000] as $go) {
                        yield \sprintf('%s %s %s%s', $kind->value, $size->value, $dispatch, $go === null ? '' : ' GO') => [$kind, $size, $dispatch, $go];
                    }
                }
            }
        }
    }

    #[DataProvider('formulaCases')]
    public function testTheFormula(GlsDeliveryKind $kind, SizeClass $size, string $dispatch, ?int $goUnitPrice): void
    {
        $delivery = self::DELIVERY[self::DELIVERY_CODE[$kind->value]];
        $shipment = self::shipmentOf($kind, $size);

        $expected = ($goUnitPrice ?? self::BASE[$size->value]) + $delivery[$size->value] + self::DISPATCH[$dispatch][$size->value];

        self::assertSame($expected, self::calculator()->calculate($shipment, self::configuration(
            delivery: $delivery,
            dispatch: self::DISPATCH[$dispatch],
            goUnitPrice: $goUnitPrice,
        )));
    }

    /**
     * Az issue három példája szó szerint: M méret, automatából feladva,
     * e-mail értesítéssel.
     *
     * @return iterable<string, array{GlsDeliveryKind, ?int, int}>
     */
    public static function issueExamples(): iterable
    {
        yield 'automatába: 1 590 + 0 + 0 + 60' => [GlsDeliveryKind::ParcelLocker, null, 165_000];
        yield 'automatába GO 50-nel: 1 290 + 60' => [GlsDeliveryKind::ParcelLocker, 129_000, 135_000];
        yield 'csomagpontba: 1 590 + 150 + 0 + 60' => [GlsDeliveryKind::ParcelShop, null, 180_000];
        yield 'házhoz: 1 590 + 500 + 0 + 60' => [GlsDeliveryKind::HomeDelivery, null, 215_000];
    }

    #[DataProvider('issueExamples')]
    public function testTheIssueExamples(GlsDeliveryKind $kind, ?int $goUnitPrice, int $expected): void
    {
        $shipment = self::shipmentOf($kind, SizeClass::M);

        self::assertSame($expected, self::calculator(notifyByEmail: true)->calculate($shipment, self::configuration(
            delivery: self::DELIVERY[self::DELIVERY_CODE[$kind->value]],
            goUnitPrice: $goUnitPrice,
        )));
    }

    public function testTheSmsFeeIsChargedOnlyWithTheEmailNotification(): void
    {
        $shipment = self::shipmentOf(GlsDeliveryKind::ParcelLocker, SizeClass::XS);

        self::assertSame(159_000, self::calculator(notifyBySms: true)->calculate($shipment, self::configuration()));
        self::assertSame(159_000 + 6_000 + 12_000, self::calculator(notifyByEmail: true, notifyBySms: true)->calculate($shipment, self::configuration()));
    }

    public function testEveryParcelIsCharged(): void
    {
        // 3 × 15 kg házhoz: két csomag (30 + 15 kg), mindkettő M.
        $shipment = ShipmentBuilder::shipment()->kind(GlsDeliveryKind::HomeDelivery)->item(200, 50, 300, 15_000, quantity: 3)->build();

        self::assertSame(2 * (159_000 + 50_000), self::calculator()->calculate($shipment, self::configuration(delivery: self::DELIVERY['dta'])));
    }

    public function testAShipmentThatFitsNoParcelIsPricedAsOneXlParcel(): void
    {
        // A mód ilyenkor nem választható (eligibility); ha mégis ide jut
        // (pl. az adminban), a díj ne legyen nulla.
        $shipment = ShipmentBuilder::shipment()->item(100, 100, 800, 1000)->build();

        self::assertSame(259_000, self::calculator()->calculate($shipment, self::configuration()));
    }

    public function testTheCountryTableReplacesTheBasePriceAbroad(): void
    {
        $germany = ['XS' => 779_000, 'S' => 1_189_000, 'M' => 1_519_000, 'L' => 1_889_000, 'XL' => 2_329_000];
        $configuration = self::configuration(base: ['XS' => 649_000, 'S' => 1_019_000, 'M' => 1_299_000, 'L' => 1_579_000, 'XL' => 1_989_000], countryBase: ['DE' => $germany]);

        $austria = ShipmentBuilder::shipment()->kind(GlsDeliveryKind::HomeDelivery)->country('AT')->item(10, 300, 600, 500)->build();
        $berlin = ShipmentBuilder::shipment()->kind(GlsDeliveryKind::HomeDelivery)->country('DE')->item(10, 300, 600, 500)->build();

        self::assertSame(1_299_000, self::calculator()->calculate($austria, $configuration));
        self::assertSame(1_519_000, self::calculator()->calculate($berlin, $configuration));
    }

    public function testTheGoPriceDoesNotReplaceACountryTable(): void
    {
        $shipment = ShipmentBuilder::shipment()->country('DE')->item(10, 100, 100, 500)->build();

        self::assertSame(779_000, self::calculator()->calculate($shipment, self::configuration(goUnitPrice: 129_000, countryBase: ['DE' => ['XS' => 779_000, 'S' => 1, 'M' => 1, 'L' => 1, 'XL' => 1]])));
    }

    /**
     * Az utánvét díja a szállítási díj része, ha a fizetési mód utánvét. A
     * sáv a beszedendő összegből jön (tételek + szállítás + a 600 Ft-os díj):
     * 30 000 Ft-ig 600, fölötte 1 190 Ft.
     *
     * @return iterable<string, array{int, int}>
     */
    public static function codBands(): iterable
    {
        // XS automatába: 1 590 Ft szállítás. 27 810 + 1 590 = 29 400 Ft.
        yield 'kosár + szállítás 29 400 Ft: a végösszeg 30 000 → 600 Ft' => [2_781_000, 60_000];
        yield 'kosár + szállítás 29 401 Ft: a végösszeg 30 001 → 1 190 Ft' => [2_781_100, 119_000];
    }

    #[DataProvider('codBands')]
    public function testTheCashOnDeliveryFeeBand(int $itemsTotal, int $fee): void
    {
        $shipment = ShipmentBuilder::shipment()->item(10, 100, 100, 500, unitPrice: $itemsTotal)->paidWith('cash_on_delivery')->build();

        self::assertSame(159_000 + $fee, self::calculator()->calculate($shipment, self::configuration()));
    }

    public function testNoCashOnDeliveryFeeForOtherPaymentMethods(): void
    {
        $shipment = ShipmentBuilder::shipment()->item(10, 100, 100, 500, unitPrice: 1_000_000)->paidWith('simplepay_card')->build();

        self::assertSame(159_000, self::calculator()->calculate($shipment, self::configuration()));
    }

    public function testTheCashOnDeliveryFeeIsChargedOncePerShipment(): void
    {
        $shipment = ShipmentBuilder::shipment()->kind(GlsDeliveryKind::HomeDelivery)->item(200, 50, 300, 15_000, quantity: 3)->paidWith('cash_on_delivery')->build();

        self::assertSame(2 * 159_000 + 60_000, self::calculator()->calculate($shipment, self::configuration()));
    }

    public function testAMissingChannelConfigurationIsReported(): void
    {
        $this->expectException(MissingChannelConfigurationException::class);

        self::calculator()->calculate(self::shipmentOf(GlsDeliveryKind::ParcelLocker, SizeClass::XS), ['OTHER' => []]);
    }

    public function testItIsTheGlsCalculator(): void
    {
        self::assertSame('gls', self::calculator()->getType());
        self::assertSame(GlsShippingCalculator::TYPE, self::calculator()->getType());
    }

    private static function calculator(bool $notifyByEmail = false, bool $notifyBySms = false): GlsShippingCalculator
    {
        return new GlsShippingCalculator(new ParcelEstimator(), new CodPolicy(), $notifyByEmail, $notifyBySms);
    }

    private static function shipmentOf(GlsDeliveryKind $kind, SizeClass $size): \Sylius\Component\Core\Model\ShipmentInterface
    {
        [$a, $b, $c] = self::ITEM[$kind === GlsDeliveryKind::ParcelLocker ? 'locker' : 'counter'][$size->value];

        return ShipmentBuilder::shipment()->kind($kind)->item($a, $b, $c, 500)->build();
    }

    /**
     * @param array<string, int>                $base
     * @param array<string, int>                $delivery
     * @param array<string, int>                $dispatch
     * @param array<string, array<string, int>> $countryBase
     *
     * @return array<string, array<string, mixed>>
     */
    private static function configuration(
        array $base = self::BASE,
        array $delivery = self::DELIVERY['dtl'],
        array $dispatch = self::DISPATCH['ptl'],
        ?int $goUnitPrice = null,
        array $countryBase = [],
    ): array {
        return ['MET_HUF' => [
            'base' => $base,
            'delivery' => $delivery,
            'dispatch' => $dispatch,
            'go_unit_price' => $goUnitPrice,
            'email_fee' => 6_000,
            'sms_fee' => 12_000,
            'cod_fee' => 60_000,
            'cod_fee_high' => 119_000,
            'cod_threshold' => 3_000_000,
            'country_base' => $countryBase,
        ]];
    }
}
