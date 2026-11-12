<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Support;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Model\GlsShippingMethodInterface;
use Sylius\Component\Core\Model\ShippingMethod;

/** A host szállítási módjának helyettese: a Sylius módja + a GLS szerződés. */
final class GlsShippingMethod extends ShippingMethod implements GlsShippingMethodInterface
{
    public function __construct(
        private readonly ?string $deliveryKindSlug = GlsDeliveryKind::ParcelLocker->value,
        private readonly bool $requiresRecipientPhone = true,
        string $code = 'gls_locker',
    ) {
        parent::__construct();

        $this->setCode($code);
    }

    public function getDeliveryKindSlug(): ?string
    {
        return $this->deliveryKindSlug;
    }

    public function requiresRecipientPhone(): bool
    {
        return $this->requiresRecipientPhone;
    }

    public function deliversToPickupPoint(): bool
    {
        return GlsDeliveryKind::tryFrom($this->deliveryKindSlug ?? '')?->isPickupPoint() ?? false;
    }
}
