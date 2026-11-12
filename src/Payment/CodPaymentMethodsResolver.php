<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Payment;

use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use Sylius\Component\Core\Model\PaymentInterface as CorePaymentInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;

/**
 * Kiveszi az utánvétet a választható fizetési módok közül, ha a rendelés
 * GLS-szállítmánya nem engedi (500 000 Ft fölött, vagy külföldre) — így a
 * vevő a 3. lépésen nem is látja, nem a leadáskor bukik el.
 *
 * A `sylius.resolver.payment_methods` dekorátora; a host saját dekorátoraival
 * (pl. szállítási módhoz kötött fizetési módok) láncba fűzhető, mert csak
 * elvesz a listából, nem rendez és nem ad hozzá.
 */
final readonly class CodPaymentMethodsResolver implements PaymentMethodsResolverInterface
{
    public function __construct(
        private PaymentMethodsResolverInterface $inner,
        private CodPolicy $policy,
    ) {
    }

    /** @return array<array-key, PaymentMethodInterface> */
    public function getSupportedMethods(PaymentInterface $subject): array
    {
        $methods = $this->inner->getSupportedMethods($subject);

        $order = $subject instanceof CorePaymentInterface ? $subject->getOrder() : null;

        if ($order === null || $this->policy->refusalReason($order) === null) {
            return $methods;
        }

        return array_filter($methods, fn (PaymentMethodInterface $method): bool => !$this->policy->isCashOnDelivery($method));
    }

    public function supports(PaymentInterface $subject): bool
    {
        return $this->inner->supports($subject);
    }
}
