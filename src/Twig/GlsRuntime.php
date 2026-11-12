<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Twig;

use CodeConjure\SyliusGlsPlugin\Api\ApiAccess;
use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Cod\PickupPointCashCheck;
use CodeConjure\SyliusGlsPlugin\Entity\GlsParcel;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Model\GlsShipmentInterface;
use CodeConjure\SyliusGlsPlugin\Parcel\GlsParcelPayloadFactory;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPoint;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use CodeConjure\SyliusGlsPlugin\Repository\GlsParcelRepository;
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
    ) {
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
