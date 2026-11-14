<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Support;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\ProductVariant;

/**
 * Egy valódi Sylius-szállítmány valódi tételekkel (változat → tétel → egység
 * → szállítmány-egység), a méretbecslés és a díjszámítás tesztjeihez.
 * Méret mm-ben, súly grammban, összeg fillérben.
 */
final class ShipmentBuilder
{
    /** @var list<array{?float, ?float, ?float, ?float, int, int}> */
    private array $items = [];

    private GlsDeliveryKind $kind = GlsDeliveryKind::ParcelLocker;

    private string $countryCode = 'HU';

    private ?string $paymentMethodCode = null;

    public static function shipment(): self
    {
        return new self();
    }

    public function item(?float $width, ?float $height, ?float $depth, ?float $weight, int $quantity = 1, int $unitPrice = 0): self
    {
        $this->items[] = [$width, $height, $depth, $weight, $quantity, $unitPrice];

        return $this;
    }

    public function kind(GlsDeliveryKind $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function country(string $countryCode): self
    {
        $this->countryCode = $countryCode;

        return $this;
    }

    public function paidWith(string $paymentMethodCode): self
    {
        $this->paymentMethodCode = $paymentMethodCode;

        return $this;
    }

    public function build(): GlsShipment
    {
        $order = new Order();
        $order->setCurrencyCode('HUF');

        $channel = new Channel();
        $channel->setCode('MET_HUF');
        $channel->setName('Bolt');
        $order->setChannel($channel);

        $address = new Address();
        $address->setCountryCode($this->countryCode);
        $order->setShippingAddress($address);

        $shipment = new GlsShipment(new GlsShippingMethod($this->kind->value));
        $order->addShipment($shipment);

        foreach ($this->items as [$width, $height, $depth, $weight, $quantity, $unitPrice]) {
            $variant = new ProductVariant();
            $variant->setWidth($width);
            $variant->setHeight($height);
            $variant->setDepth($depth);
            $variant->setWeight($weight);

            $item = new OrderItem();
            $item->setVariant($variant);
            $item->setUnitPrice($unitPrice);
            $order->addItem($item);

            for ($i = 0; $i < $quantity; ++$i) {
                $shipment->addUnit(new OrderItemUnit($item));
            }
        }

        if ($this->paymentMethodCode !== null) {
            $method = new PaymentMethod();
            $method->setCode($this->paymentMethodCode);
            $payment = new Payment();
            $payment->setMethod($method);
            $payment->setCurrencyCode('HUF');
            $order->addPayment($payment);
        }

        $order->recalculateItemsTotal();

        return $shipment;
    }
}
