<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Cod;

use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Tests\Support\OrderFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\PaymentMethod;

/**
 * Az utánvét szabályai GLS-szállítmánynál (#251, #254, #260):
 *
 * - mindhárom belföldi GLS módon van utánvét;
 * - 500 000 Ft fölött nincs (a GLS `maxCodAmount`-ja);
 * - külföldre csak akkor, ha a szerződés ad (az ecsomag nem ad) —
 *   alapból tiltva.
 */
final class CodPolicyTest extends TestCase
{
    public function testTheConfiguredCodesAreCashOnDelivery(): void
    {
        $policy = new CodPolicy(['cash_on_delivery', 'utanvet']);

        self::assertTrue($policy->isCashOnDelivery(self::method('cash_on_delivery')));
        self::assertTrue($policy->isCashOnDelivery(self::method('utanvet')));
        self::assertFalse($policy->isCashOnDelivery(self::method('bank_transfer')));
        self::assertFalse($policy->isCashOnDelivery(null));
    }

    /** @return iterable<string, array{GlsDeliveryKind}> */
    public static function domesticKindProvider(): iterable
    {
        foreach (GlsDeliveryKind::cases() as $kind) {
            yield $kind->value => [$kind];
        }
    }

    #[DataProvider('domesticKindProvider')]
    public function testCodIsAvailableOnEveryDomesticGlsMode(GlsDeliveryKind $kind): void
    {
        self::assertNull((new CodPolicy())->refusalReason(OrderFactory::order(kind: $kind)));
    }

    public function testFiveHundredThousandForintsIsStillAllowed(): void
    {
        self::assertNull((new CodPolicy())->refusalReason(OrderFactory::order(total: 50_000_000)));
    }

    public function testAboveFiveHundredThousandForintsThereIsNoCod(): void
    {
        self::assertSame(CodPolicy::REASON_AMOUNT, (new CodPolicy())->refusalReason(OrderFactory::order(total: 50_000_001)));
    }

    public function testTheLimitIsConfigurable(): void
    {
        self::assertSame(CodPolicy::REASON_AMOUNT, (new CodPolicy(maxAmount: 10_000_000))->refusalReason(OrderFactory::order(total: 10_000_001)));
    }

    public function testThereIsNoCodAbroadByDefault(): void
    {
        self::assertSame(CodPolicy::REASON_ABROAD, (new CodPolicy())->refusalReason(OrderFactory::order(kind: GlsDeliveryKind::HomeDelivery, countryCode: 'AT')));
    }

    public function testCodAbroadCanBeEnabledByContract(): void
    {
        self::assertNull((new CodPolicy(international: true))->refusalReason(OrderFactory::order(kind: GlsDeliveryKind::HomeDelivery, countryCode: 'AT')));
    }

    /** A szabály CSAK GLS-szállítmányra vonatkozik: a FoxPost-rendelést nem szűri. */
    public function testANonGlsOrderIsNotTheGlsPolicysBusiness(): void
    {
        $order = new Order();

        self::assertNull((new CodPolicy())->refusalReason($order));
        self::assertFalse((new CodPolicy())->appliesTo($order));
        self::assertTrue((new CodPolicy())->appliesTo(OrderFactory::order()));
    }

    private static function method(string $code): PaymentMethod
    {
        $method = new PaymentMethod();
        $method->setCode($code);

        return $method;
    }
}
