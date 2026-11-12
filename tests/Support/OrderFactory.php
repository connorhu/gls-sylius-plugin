<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Support;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\OrderPaymentStates;

/**
 * Egy GLS-szállítmányos rendelés, a tesztek által változtatott részekkel
 * paraméterezve. A `total` fillérben (a Sylius-szokás szerint).
 */
final class OrderFactory
{
    public static function order(
        GlsDeliveryKind $kind = GlsDeliveryKind::ParcelLocker,
        ?string $pickupPointId = '2351-CSOMAGPONT',
        ?string $phone = '+36701234567',
        string $countryCode = 'HU',
        ?string $street = 'Kossuth tér 5.',
        string $paymentMethodCode = 'simplepay_card',
        string $paymentState = OrderPaymentStates::STATE_PAID,
        int $total = 1_299_000,
        ?string $email = 'anna@example.com',
        string $state = OrderInterface::STATE_NEW,
    ): Order {
        $order = new class($total) extends Order {
            public function __construct(private readonly int $fixedTotal)
            {
                parent::__construct();
            }

            public function getTotal(): int
            {
                return $this->fixedTotal;
            }
        };

        $order->setState($state);
        $order->setPaymentState($paymentState);
        $order->setCurrencyCode('HUF');

        $customer = new Customer();
        $customer->setEmail($email);
        $order->setCustomer($customer);

        $address = new Address();
        $address->setFirstName('Anna');
        $address->setLastName('Kiss');
        $address->setCountryCode($countryCode);
        $address->setPostcode('7621');
        $address->setCity('Pécs');
        $address->setStreet($street);
        $order->setShippingAddress($address);

        $method = new PaymentMethod();
        $method->setCode($paymentMethodCode);
        $payment = new Payment();
        $payment->setMethod($method);
        $payment->setCurrencyCode('HUF');
        $order->addPayment($payment);

        $order->addShipment(new GlsShipment(new GlsShippingMethod($kind->value), $kind->isPickupPoint() ? $pickupPointId : null, $phone));

        return $order;
    }
}
