<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Twig;

use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Cod\PickupPointCashCheck;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPoint;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use Sylius\Component\Core\Model\OrderInterface;
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
    public function __construct(
        private DeliveryPointCatalog $catalog,
        private PickupPointCashCheck $cashCheck,
        private CodPolicy $codPolicy,
        private string $country,
    ) {
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
