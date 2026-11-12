<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Support;

use CodeConjure\SyliusGlsPlugin\Model\GlsShipmentInterface;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Shipping\Model\ShippingMethodInterface;

/**
 * A host szállítmányának helyettese: a Sylius `Shipment`-je + a GLS
 * host-szerződés. A fajta-slug a MÓDBÓL jön (a bolt a mód választásakor
 * pillanatképet tesz el; a teszthez ez a kettő ugyanaz).
 */
final class GlsShipment extends Shipment implements GlsShipmentInterface
{
    private ?string $pickupPointId = null;

    private ?string $phoneNumber = null;

    public function __construct(?ShippingMethodInterface $method = null, ?string $pickupPointId = null, ?string $phoneNumber = null)
    {
        parent::__construct();

        $this->setMethod($method);
        $this->pickupPointId = $pickupPointId;
        $this->phoneNumber = $phoneNumber;
    }

    public function getPickupPointId(): ?string
    {
        return $this->pickupPointId;
    }

    public function setPickupPointId(?string $pickupPointId): void
    {
        $this->pickupPointId = $pickupPointId;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): void
    {
        $this->phoneNumber = $phoneNumber;
    }

    public function getDeliveryKindSlug(): ?string
    {
        $method = $this->getMethod();

        return $method instanceof GlsShippingMethod ? $method->getDeliveryKindSlug() : null;
    }
}
