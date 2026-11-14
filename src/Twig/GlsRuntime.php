<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Twig;

use CodeConjure\Gls\LabelFormat;
use CodeConjure\SyliusGlsPlugin\Api\ApiAccess;
use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Cod\PickupPointCashCheck;
use CodeConjure\SyliusGlsPlugin\Entity\GlsParcel;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Model\GlsShipmentInterface;
use CodeConjure\SyliusGlsPlugin\Packaging\ParcelEstimator;
use CodeConjure\SyliusGlsPlugin\Parcel\GlsParcelPayloadFactory;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPoint;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use CodeConjure\SyliusGlsPlugin\Repository\GlsParcelRepository;
use CodeConjure\SyliusGlsPlugin\Shipping\GlsShippingCalculator;
use Sylius\Component\Core\Exception\MissingChannelConfigurationException;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * A sablonok GLS-kérdései, amikhez szolgáltatás kell (a pontlista, az
 * utánvét-szabály). A paraméterek szándékosan lazák (`mixed`): egy bolt,
 * amelyik a modelljére nem tette rá a host-interfészt, nem render-hibát
 * kap, hanem nem látja a GLS-blokkokat.
 */
final readonly class GlsRuntime implements RuntimeExtensionInterface
{
    public const string TRACKING_URL = 'https://gls-group.com/HU/hu/csomagkovetes?match=%s';

    public function __construct(
        private DeliveryPointCatalog $catalog,
        private PickupPointCashCheck $cashCheck,
        private CodPolicy $codPolicy,
        private string $country,
        private ?ApiAccess $apiAccess = null,
        private ?GlsParcelRepository $parcelRepository = null,
        private ?GlsParcelPayloadFactory $payloadFactory = null,
        private ?ParcelEstimator $estimator = null,
        private ?GlsShippingCalculator $calculator = null,
        private string $labelFormat = LabelFormat::A4_2x2->value,
    ) {
    }

    /** A címke formátuma (`codeconjure_gls.label.format`, a MyGLS `TypeOfPrinter`-e). */
    public function labelFormat(): string
    {
        return $this->labelFormat;
    }

    /**
     * A választható kezdőpozíciók a címkés lapon (#263): A4-es formátumnál
     * 1–4 (a GLS `PrintPosition`-je), hőnyomtatónál nincs lap, tehát üres.
     *
     * @return list<int>
     */
    public function labelPrintPositions(): array
    {
        return LabelFormat::tryFrom($this->labelFormat)?->isA4() === true ? [1, 2, 3, 4] : [];
    }

    /** API-módban fut-e a plugin (a hiányos beállítás NEM az). */
    public function apiEnabled(): bool
    {
        return $this->apiAccess?->isEnabled() ?? false;
    }

    public function parcelForShipment(mixed $shipment): ?GlsParcel
    {
        return $shipment instanceof ShipmentInterface ? $this->parcelRepository?->findOneByShipment($shipment) : null;
    }

    /**
     * A feladandó adatok előnézete mentés nélkül — a kézi (API nélküli)
     * feladás-panel ezt mutatja másolható formában (#257).
     */
    public function parcelPreview(mixed $shipment): ?GlsParcel
    {
        if (!$shipment instanceof ShipmentInterface || !$shipment instanceof GlsShipmentInterface || $this->payloadFactory === null) {
            return null;
        }

        $kind = GlsDeliveryKind::fromShipment($shipment);
        $order = $shipment->getOrder();

        if ($kind === null || !$order instanceof OrderInterface) {
            return null;
        }

        $parcel = new GlsParcel($shipment, (string) $order->getNumber(), $kind);
        $this->payloadFactory->snapshot($parcel, $shipment);

        return $parcel;
    }

    public static function trackingUrl(?string $parcelNumber): ?string
    {
        return $parcelNumber === null || trim($parcelNumber) === '' ? null : \sprintf(self::TRACKING_URL, rawurlencode(trim($parcelNumber)));
    }

    /**
     * Mennyi utánvét-díj kerül a szállítási díjba, ha a vevő utánvétet
     * választ (#260) — a rendelés `gls` calculatoros szállítmányából; null,
     * ha nincs ilyen (más szállító, vagy a mód nem a GLS díjképletével
     * számol, és akkor az utánvét díja a fizetési mód kezelési díja marad).
     */
    public function codFee(mixed $order): ?int
    {
        if (!$order instanceof OrderInterface || $this->calculator === null) {
            return null;
        }

        foreach ($order->getShipments() as $shipment) {
            $method = $shipment->getMethod();

            if ($method === null || $method->getCalculator() !== GlsShippingCalculator::TYPE) {
                continue;
            }

            try {
                return $this->calculator->cashOnDeliveryFee($shipment, $method->getConfiguration());
            } catch (MissingChannelConfigurationException) {
                return null;
            }
        }

        return null;
    }

    /**
     * A szállítmány becsült csomagjai az admin feladó-űrlapjának (#260):
     * darabszám és az össztömeg kg-ban; null, ha a szállítmány nem GLS-é,
     * vagy a mód egyik méretosztályába sem fér.
     *
     * @return array{count: int, weight: float}|null
     */
    public function parcelEstimate(mixed $shipment): ?array
    {
        if (!$shipment instanceof ShipmentInterface || $this->estimator === null) {
            return null;
        }

        $kind = GlsDeliveryKind::of($shipment);

        if ($kind === null) {
            return null;
        }

        $parcels = $this->estimator->estimate($shipment, $kind);

        if ($parcels === null) {
            return null;
        }

        $grams = array_sum(array_map(static fn ($parcel): float => $parcel->dimensions->weight, $parcels));

        return ['count' => \count($parcels), 'weight' => round($grams / 1000, 3)];
    }

    public function pickupPoint(?string $id): ?DeliveryPoint
    {
        return $id === null || $id === '' ? null : $this->catalog->find($id);
    }

    public function codCardOnly(mixed $order): bool
    {
        return $order instanceof OrderInterface && $this->cashCheck->isCardOnly($order);
    }

    public function isCashOnDelivery(mixed $method): bool
    {
        return $method instanceof PaymentMethodInterface && $this->codPolicy->isCashOnDelivery($method);
    }

    public function mapCountry(): string
    {
        return strtolower($this->country);
    }
}
