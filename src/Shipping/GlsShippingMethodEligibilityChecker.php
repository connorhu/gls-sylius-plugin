<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Shipping;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Packaging\ParcelEstimator;
use Sylius\Component\Shipping\Checker\Eligibility\ShippingMethodEligibilityCheckerInterface;
use Sylius\Component\Shipping\Model\ShippingMethodInterface;
use Sylius\Component\Shipping\Model\ShippingSubjectInterface;

/**
 * Egy GLS-mód csak akkor választható, ha a szállítmány minden tétele
 * önmagában befér a mód legnagyobb méretosztályába (#260). Több tétel, ami
 * együtt nem fér, több csomag — az választható, a díj csomagonként jár.
 *
 * A Sylius összetett eligibility-ellenőrzőjének tagja
 * (`sylius.shipping_method_eligibility_checker`); más szállító módjába nem
 * szól bele.
 */
final readonly class GlsShippingMethodEligibilityChecker implements ShippingMethodEligibilityCheckerInterface
{
    public function __construct(private ParcelEstimator $estimator)
    {
    }

    public function isEligible(ShippingSubjectInterface $shippingSubject, ShippingMethodInterface $shippingMethod): bool
    {
        $kind = GlsDeliveryKind::fromShippingMethod($shippingMethod);

        return $kind === null || $this->estimator->estimate($shippingSubject, $kind) !== null;
    }
}
