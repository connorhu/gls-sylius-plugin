<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Eligibility;

use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Eligibility\EligibilityChecker;
use CodeConjure\SyliusGlsPlugin\Eligibility\EligibilityResult;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsShipment;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsShippingMethod;
use CodeConjure\SyliusGlsPlugin\Tests\Support\OrderFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderPaymentStates;

/**
 * Feladható-e a szállítmány GLS-csomagként (a #257 feladása előtt fut).
 *
 * A szabályok a MyGLS dokumentációból jönnek: a PSD (csomagpont /
 * automata) kötelezővé teszi a címzett nevét, telefonját és e-mailjét (B
 * függelék), a házszám csak szám és nem 0 lehet (23-as hiba), az utánvét
 * a CodPolicy szerint.
 */
final class EligibilityCheckerTest extends TestCase
{
    /** @return iterable<string, array{GlsDeliveryKind}> */
    public static function kindProvider(): iterable
    {
        foreach (GlsDeliveryKind::cases() as $kind) {
            yield $kind->value => [$kind];
        }
    }

    #[DataProvider('kindProvider')]
    public function testACompletePaidOrderIsEligible(GlsDeliveryKind $kind): void
    {
        $result = $this->check(OrderFactory::order(kind: $kind));

        self::assertTrue($result->eligible, json_encode($result->errors, \JSON_THROW_ON_ERROR));
    }

    public function testANonGlsShipmentIsIneligible(): void
    {
        $order = OrderFactory::order();
        $shipment = new GlsShipment(new GlsShippingMethod('foxpost_parcel_locker'));
        $order->addShipment($shipment);

        self::assertSame(['no_delivery_kind'], self::codes((new EligibilityChecker(new CodPolicy()))->check($shipment)));
    }

    public function testACancelledOrderIsIneligible(): void
    {
        self::assertSame(['order_cancelled'], self::codes($this->check(OrderFactory::order(state: OrderInterface::STATE_CANCELLED))));
    }

    public function testAnUnpaidOrderIsIneligible(): void
    {
        self::assertSame(['order_not_paid'], self::codes($this->check(OrderFactory::order(paymentState: OrderPaymentStates::STATE_AWAITING_PAYMENT))));
    }

    /** Utánvétnél a fizetés a kézbesítéskor történik: a feladás nem várhat rá. */
    public function testAnUnpaidCashOnDeliveryOrderIsEligible(): void
    {
        $result = $this->check(OrderFactory::order(paymentMethodCode: 'cash_on_delivery', paymentState: OrderPaymentStates::STATE_AWAITING_PAYMENT));

        self::assertTrue($result->eligible);
    }

    public function testCashOnDeliveryAboveTheLimitIsIneligible(): void
    {
        self::assertSame(['cod_amount_too_high'], self::codes($this->check(OrderFactory::order(paymentMethodCode: 'cash_on_delivery', paymentState: OrderPaymentStates::STATE_AWAITING_PAYMENT, total: 50_000_001))));
    }

    public function testCashOnDeliveryAbroadIsIneligible(): void
    {
        self::assertSame(['cod_abroad'], self::codes($this->check(OrderFactory::order(kind: GlsDeliveryKind::HomeDelivery, countryCode: 'AT', paymentMethodCode: 'cash_on_delivery', paymentState: OrderPaymentStates::STATE_AWAITING_PAYMENT))));
    }

    /** A COD-limit csak utánvétnél számít — egy drága, kártyával fizetett rendelés feladható. */
    public function testAnExpensivePrepaidOrderIsEligible(): void
    {
        self::assertTrue($this->check(OrderFactory::order(total: 90_000_000))->eligible);
    }

    /** @return iterable<string, array{GlsDeliveryKind}> */
    public static function pickupKindProvider(): iterable
    {
        yield 'csomagpont' => [GlsDeliveryKind::ParcelShop];
        yield 'automata' => [GlsDeliveryKind::ParcelLocker];
    }

    #[DataProvider('pickupKindProvider')]
    public function testAPickupPointDeliveryNeedsThePoint(GlsDeliveryKind $kind): void
    {
        self::assertSame(['missing_pickup_point'], self::codes($this->check(OrderFactory::order(kind: $kind, pickupPointId: null))));
    }

    #[DataProvider('pickupKindProvider')]
    public function testAPickupPointDeliveryNeedsThePhone(GlsDeliveryKind $kind): void
    {
        self::assertSame(['missing_phone'], self::codes($this->check(OrderFactory::order(kind: $kind, phone: ''))));
    }

    #[DataProvider('pickupKindProvider')]
    public function testAPickupPointDeliveryNeedsTheEmail(GlsDeliveryKind $kind): void
    {
        self::assertSame(['missing_email'], self::codes($this->check(OrderFactory::order(kind: $kind, email: null))));
    }

    /** Csomagpontnál a cím nem számít (a pont címe a cél) — utca nélkül is feladható. */
    public function testAPickupPointDeliveryDoesNotNeedAStreet(): void
    {
        self::assertTrue($this->check(OrderFactory::order(kind: GlsDeliveryKind::ParcelLocker, street: null))->eligible);
    }

    public function testHomeDeliveryNeedsAStreet(): void
    {
        self::assertSame(['missing_address'], self::codes($this->check(OrderFactory::order(kind: GlsDeliveryKind::HomeDelivery, street: null))));
    }

    /**
     * A GLS házszáma csak szám és nem 0 lehet (23-as hiba); a bolt egy
     * sorban tárolja az utcát és a házszámot. Ha a sorban nincs nem nulla
     * szám, a bontás (#257) sem tud házszámot adni.
     *
     * @return iterable<string, array{string}>
     */
    public static function streetWithoutHouseNumberProvider(): iterable
    {
        yield 'szám nélkül' => ['Kossuth tér'];
        yield 'nulla' => ['Fő utca 0'];
    }

    #[DataProvider('streetWithoutHouseNumberProvider')]
    public function testHomeDeliveryNeedsAHouseNumber(string $street): void
    {
        self::assertSame(['missing_house_number'], self::codes($this->check(OrderFactory::order(kind: GlsDeliveryKind::HomeDelivery, street: $street))));
    }

    public function testHomeDeliveryWithAHouseNumberAndExtrasIsEligible(): void
    {
        self::assertTrue($this->check(OrderFactory::order(kind: GlsDeliveryKind::HomeDelivery, street: 'Fő utca 12/A. 3. em. 4.'))->eligible);
    }

    /** A házhoz szállítás telefonját a mód flagje dönti el, mint a pénztárban. */
    public function testHomeDeliveryNeedsThePhoneWhenTheMethodAsksForIt(): void
    {
        self::assertSame(['missing_phone'], self::codes($this->check(OrderFactory::order(kind: GlsDeliveryKind::HomeDelivery, phone: null))));
    }

    public function testEveryProblemIsReportedAtOnce(): void
    {
        $codes = self::codes($this->check(OrderFactory::order(pickupPointId: null, phone: null, email: null, paymentState: OrderPaymentStates::STATE_AWAITING_PAYMENT)));

        self::assertSame(['order_not_paid', 'missing_pickup_point', 'missing_phone', 'missing_email'], $codes);
    }

    public function testEveryErrorCarriesAHungarianMessage(): void
    {
        foreach ($this->check(OrderFactory::order(pickupPointId: null, phone: null))->errors as $error) {
            self::assertNotSame('', $error['message']);
        }
    }

    private function check(Order $order): EligibilityResult
    {
        $shipment = $order->getShipments()->first();
        self::assertInstanceOf(GlsShipment::class, $shipment);

        return (new EligibilityChecker(new CodPolicy()))->check($shipment);
    }

    /** @return list<string> */
    private static function codes(EligibilityResult $result): array
    {
        return array_map(static fn (array $error): string => $error['code'], $result->errors);
    }
}
