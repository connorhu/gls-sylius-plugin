<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Shipping;

use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Packaging\EstimatedParcel;
use CodeConjure\SyliusGlsPlugin\Packaging\ParcelDimensions;
use CodeConjure\SyliusGlsPlugin\Packaging\ParcelEstimator;
use CodeConjure\SyliusGlsPlugin\Packaging\SizeClass;
use Sylius\Component\Core\Exception\MissingChannelConfigurationException;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Model\ShipmentInterface as BaseShipmentInterface;
use Webmozart\Assert\Assert;

/**
 * A GLS díja a GLS saját kalkulátorának képlete szerint (bolt-issue #260),
 * csomagonként:
 *
 *     alapár[méret]            — külföldön az ország táblája; belföldön GLS
 *                                GO-val a GO-keret egységára
 *   + kézbesítési felár[méret] — a mód táblája (automata / csomagpont / házhoz)
 *   + feladási felár[méret]    — automatából / csomagponton / futárral
 *   + értesítések              — ha a plugin küldi őket (FSS csak FDS-sel)
 *
 * és szállítmányonként egyszer az utánvét díja, ha a rendelés fizetési módja
 * utánvét: a sáv a beszedendő összegből (tételek + szállítás + az alacsonyabb
 * díj) jön. A fizetési mód választása a Sylius `select_payment` átmeneténél
 * újrafuttatja az order processor-láncot, és vele ezt a számítást.
 *
 * Minden ár a mód calculator-konfigurációjából jön, csatornánként, fillérben
 * (`GlsRateTable`) — a kódban nincs ár.
 */
final readonly class GlsShippingCalculator implements CalculatorInterface
{
    public const string TYPE = 'gls';

    public function __construct(
        private ParcelEstimator $estimator,
        private CodPolicy $codPolicy,
        private bool $notifyByEmail,
        private bool $notifyBySms,
    ) {
    }

    /** @param array<string, mixed> $configuration */
    public function calculate(BaseShipmentInterface $subject, array $configuration): int
    {
        [$order, $rates, $parcels] = $this->parcels($subject, $configuration);

        if ($this->codPolicy->isCashOnDelivery($order->getLastPayment()?->getMethod())) {
            $parcels += $rates->cashOnDeliveryFee($order->getItemsTotal() + $parcels);
        }

        return $parcels;
    }

    /**
     * Mennyi utánvét-díj kerülne a szállítási díjba, ha a vevő utánvétet
     * választ — a mostani fizetési módtól függetlenül (a 3. lépés kártyája).
     *
     * @param array<string, mixed> $configuration
     */
    public function cashOnDeliveryFee(BaseShipmentInterface $subject, array $configuration): int
    {
        [$order, $rates, $parcels] = $this->parcels($subject, $configuration);

        return $rates->cashOnDeliveryFee($order->getItemsTotal() + $parcels);
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    /**
     * A csomagok díja, utánvét nélkül.
     *
     * @param array<string, mixed> $configuration
     *
     * @return array{OrderInterface, GlsRateTable, int}
     */
    private function parcels(BaseShipmentInterface $subject, array $configuration): array
    {
        Assert::isInstanceOf($subject, ShipmentInterface::class);
        $order = $subject->getOrder();
        Assert::isInstanceOf($order, OrderInterface::class);

        $rates = self::rates($subject, $order, $configuration);
        $kind = GlsDeliveryKind::fromShippingMethod($subject->getMethod()) ?? GlsDeliveryKind::HomeDelivery;
        $country = strtoupper((string) $order->getShippingAddress()?->getCountryCode());

        // Ami egy csomagba sem fér, az a mód nem választható (eligibility);
        // ha mégis ide jut (pl. az adminban), egy XL csomag ára, nem nulla.
        $parcels = $this->estimator->estimate($subject, $kind)
            ?? [new EstimatedParcel(ParcelDimensions::empty(), SizeClass::XL)];

        $total = 0;

        foreach ($parcels as $parcel) {
            $total += $rates->parcelPrice($parcel->sizeClass, $country, $this->notifyByEmail, $this->notifyBySms);
        }

        return [$order, $rates, $total];
    }

    /** @param array<string, mixed> $configuration */
    private static function rates(ShipmentInterface $shipment, OrderInterface $order, array $configuration): GlsRateTable
    {
        $channelCode = (string) $order->getChannel()?->getCode();
        $channelConfiguration = $configuration[$channelCode] ?? null;

        if (!\is_array($channelConfiguration) || $channelConfiguration === []) {
            throw new MissingChannelConfigurationException(\sprintf(
                'Channel %s has no GLS rates defined for shipping method %s',
                (string) $order->getChannel()?->getName(),
                (string) $shipment->getMethod()?->getCode(),
            ));
        }

        return GlsRateTable::fromArray($channelConfiguration);
    }
}
