<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Payment;

use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Payment\CodPaymentMethodsResolver;
use CodeConjure\SyliusGlsPlugin\Tests\Support\OrderFactory;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Model\Payment as BasePayment;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;

/**
 * A pénztár 3. lépése: a GLS utánvét-szabálya a fizetési mód VÁLASZTÉKÁT
 * szűri (#260: „ilyen kosárnál az utánvét fizetési mód ne legyen
 * választható"), nem a rendelés leadásakor bukik el.
 */
final class CodPaymentMethodsResolverTest extends TestCase
{
    public function testCodStaysWhenThePolicyAllowsIt(): void
    {
        self::assertSame(['simplepay_card', 'cash_on_delivery'], $this->codes(OrderFactory::order()->getLastPayment()));
    }

    public function testCodIsRemovedAboveTheLimit(): void
    {
        self::assertSame(['simplepay_card'], $this->codes(OrderFactory::order(total: 60_000_000)->getLastPayment()));
    }

    public function testCodIsRemovedAbroad(): void
    {
        self::assertSame(['simplepay_card'], $this->codes(OrderFactory::order(countryCode: 'DE')->getLastPayment()));
    }

    /** A rendeléshez nem kötött (nem Core) fizetést nem szűri. */
    public function testANonOrderPaymentIsLeftAlone(): void
    {
        self::assertSame(['simplepay_card', 'cash_on_delivery'], $this->codes(new BasePayment()));
    }

    public function testSupportsIsDelegated(): void
    {
        $inner = $this->createMock(PaymentMethodsResolverInterface::class);
        $inner->expects(self::once())->method('supports')->willReturn(false);

        self::assertFalse((new CodPaymentMethodsResolver($inner, new CodPolicy()))->supports(new Payment()));
    }

    /** @return list<string|null> */
    private function codes(?PaymentInterface $payment): array
    {
        self::assertNotNull($payment);

        $inner = $this->createStub(PaymentMethodsResolverInterface::class);
        $inner->method('getSupportedMethods')->willReturn([self::method('simplepay_card'), self::method('cash_on_delivery')]);

        return array_map(
            static fn (PaymentMethodInterface $method): ?string => $method->getCode(),
            array_values((new CodPaymentMethodsResolver($inner, new CodPolicy()))->getSupportedMethods($payment)),
        );
    }

    private static function method(string $code): PaymentMethod
    {
        $method = new PaymentMethod();
        $method->setCode($code);

        return $method;
    }
}
