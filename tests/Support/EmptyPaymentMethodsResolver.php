<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Support;

use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;

/** A host Sylius-resolverének helyettese a konténer-tesztben (dekorálható). */
final class EmptyPaymentMethodsResolver implements PaymentMethodsResolverInterface
{
    public function getSupportedMethods(PaymentInterface $subject): array
    {
        return [];
    }

    public function supports(PaymentInterface $subject): bool
    {
        return true;
    }
}
