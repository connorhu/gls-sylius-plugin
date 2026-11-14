<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Twig;

use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Cod\PickupPointCashCheck;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Packaging\ParcelEstimator;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use CodeConjure\SyliusGlsPlugin\Shipping\GlsShippingCalculator;
use CodeConjure\SyliusGlsPlugin\Tests\Support\ShipmentBuilder;
use CodeConjure\SyliusGlsPlugin\Twig\GlsRuntime;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * A díjszámítás felületi kérdései (#260): mennyi utánvét-díj kerül a
 * szállítási díjba (a 3. lépés utánvét-kártyája), és mekkora csomag(ok)
 * mennek ki (az admin feladó-űrlapjának előtöltése).
 */
final class GlsRuntimeShippingTest extends TestCase
{
    private const array CONFIGURATION = ['MET_HUF' => [
        'base' => ['XS' => 159_000, 'S' => 159_000, 'M' => 159_000, 'L' => 259_000, 'XL' => 259_000],
        'cod_fee' => 60_000,
        'cod_fee_high' => 119_000,
        'cod_threshold' => 3_000_000,
    ]];

    public function testTheCodFeeOfAGlsShipment(): void
    {
        self::assertSame(60_000, self::runtime()->codFee(self::order(itemsTotal: 2_781_000)));
        self::assertSame(119_000, self::runtime()->codFee(self::order(itemsTotal: 2_781_100)));
    }

    /**
     * Ha az utánvét már ki van választva, a szállítási díj tartalmazza a
     * díjat — a sáv mégis a díj NÉLKÜLI összegből jön, nem ugrik át.
     */
    public function testTheCodFeeDoesNotCountItself(): void
    {
        self::assertSame(60_000, self::runtime()->codFee(self::order(itemsTotal: 2_781_000, paymentMethod: 'cash_on_delivery')));
    }

    public function testNoCodFeeWithoutTheGlsCalculator(): void
    {
        self::assertNull(self::runtime()->codFee(self::order(itemsTotal: 1_000, calculator: 'flat_rate')));
        self::assertNull(self::runtime()->codFee(null));
    }

    public function testNoCodFeeWithoutTheChannelConfiguration(): void
    {
        self::assertNull(self::runtime()->codFee(self::order(itemsTotal: 1_000, configuration: [])));
    }

    public function testTheParcelEstimateForTheAdminForm(): void
    {
        $shipment = ShipmentBuilder::shipment()->kind(GlsDeliveryKind::HomeDelivery)->item(200, 50, 300, 15_000, quantity: 3)->build();

        self::assertSame(['count' => 2, 'weight' => 45.0], self::runtime()->parcelEstimate($shipment));
    }

    public function testNoParcelEstimateForAShipmentThatFitsNoParcel(): void
    {
        $shipment = ShipmentBuilder::shipment()->item(100, 100, 800, 1000)->build();

        self::assertNull(self::runtime()->parcelEstimate($shipment));
        self::assertNull(self::runtime()->parcelEstimate(null));
    }

    /** @param array<string, mixed>|null $configuration */
    private static function order(int $itemsTotal, string $calculator = GlsShippingCalculator::TYPE, ?array $configuration = null, ?string $paymentMethod = null): OrderInterface
    {
        $builder = ShipmentBuilder::shipment()->item(10, 100, 100, 500, unitPrice: $itemsTotal);

        if ($paymentMethod !== null) {
            $builder->paidWith($paymentMethod);
        }

        $shipment = $builder->build();
        $method = $shipment->getMethod();
        self::assertNotNull($method);
        $method->setCalculator($calculator);
        $method->setConfiguration($configuration ?? self::CONFIGURATION);

        $order = $shipment->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);

        // A Sylius ShippingChargesProcessor korrekciója — utánvétnél a díjjal.
        $adjustment = new \Sylius\Component\Core\Model\Adjustment();
        $adjustment->setType(\Sylius\Component\Core\Model\AdjustmentInterface::SHIPPING_ADJUSTMENT);
        $adjustment->setAmount(new GlsShippingCalculator(new ParcelEstimator(), new CodPolicy(), false, false)->calculate($shipment, $method->getConfiguration() === [] ? self::CONFIGURATION : $method->getConfiguration()));
        $shipment->addAdjustment($adjustment);

        return $order;
    }

    private static function runtime(): GlsRuntime
    {
        $catalog = new DeliveryPointCatalog(new MockHttpClient(), new ArrayAdapter());
        $estimator = new ParcelEstimator();

        return new GlsRuntime(
            $catalog,
            new PickupPointCashCheck($catalog),
            new CodPolicy(),
            'HU',
            estimator: $estimator,
            calculator: new GlsShippingCalculator($estimator, new CodPolicy(), false, false),
        );
    }
}
