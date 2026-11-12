<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Parcel;

use CodeConjure\SyliusGlsPlugin\Address\StreetSplitter;
use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Entity\GlsParcel;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Model\GlsShipmentInterface;
use CodeConjure\SyliusGlsPlugin\Parcel\GlsParcelPayloadFactory;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use CodeConjure\SyliusGlsPlugin\Tests\Support\OrderFactory;
use CodeConjure\SyliusGlsPlugin\Tests\Support\RecordingLogger;
use CodeConjure\SyliusGlsPlugin\Tests\Unit\Pickup\DeliveryPointTest;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Sylius-rendelés → GLS-csomag (#257): előbb a feladott adatok PILLANATKÉPE
 * a `GlsParcel`-en (az admin ezt látja, és ez megy újra egy újrapróbánál),
 * aztán abból a MyGLS `Parcel`.
 */
final class GlsParcelPayloadFactoryTest extends TestCase
{
    private const array PICKUP = [
        'name' => 'Bolt Kft.', 'street' => 'Fő utca', 'house_number' => '1', 'house_number_info' => null,
        'city' => 'Budapest', 'zip_code' => '1011', 'country_iso_code' => 'HU',
        'contact_name' => 'Raktár', 'contact_phone' => '+3612345678', 'contact_email' => 'raktar@example.com',
    ];

    public function testAHomeDeliverySplitsTheStreet(): void
    {
        $parcel = $this->snapshot(OrderFactory::order(GlsDeliveryKind::HomeDelivery, street: 'Petőfi tér 5. 2. em. 4.'));

        self::assertSame(['Petőfi tér', '5', '2. em. 4.'], [$parcel->getStreet(), $parcel->getHouseNumber(), $parcel->getHouseNumberInfo()]);
        self::assertSame(['7621', 'Pécs', 'HU'], [$parcel->getZipCode(), $parcel->getCity(), $parcel->getCountryCode()]);
        self::assertSame('Anna Kiss', $parcel->getRecipientName());
        self::assertNull($parcel->getPickupPointId());

        $api = $this->factory()->build($parcel, 100000001);
        self::assertSame('Petőfi tér', $api->deliveryAddress->street);
        self::assertSame('5', $api->deliveryAddress->houseNumber);
        self::assertSame([], $api->services);
        self::assertSame('000123', $api->clientReference);
        self::assertSame(100000001, $api->clientNumber);
        self::assertSame('Bolt Kft.', $api->pickupAddress->name);
    }

    public function testAPickupPointGetsThePsdServiceAndTheFullContact(): void
    {
        $parcel = $this->snapshot(OrderFactory::order(GlsDeliveryKind::ParcelLocker, 'HU1012-PLOCKER001'));
        $api = $this->factory()->build($parcel, 1);

        self::assertTrue($api->hasService('PSD'));
        self::assertSame(['StringValue' => 'HU1012-PLOCKER001'], $api->services[0]->toArray()['PSDParameter'] ?? null);
        self::assertTrue($api->deliveryAddress->hasFullContact());
        self::assertSame('anna@example.com', $api->deliveryAddress->contactEmail);
        self::assertSame('+36701234567', $api->deliveryAddress->contactPhone);
    }

    /**
     * Átvételi pontos módnál a bolt nem kér szállítási címet (#241); a
     * MyGLS kézbesítési címe ilyenkor a PONT címe.
     */
    public function testWithoutAShippingAddressThePickupPointsAddressIsUsed(): void
    {
        $order = OrderFactory::order(GlsDeliveryKind::ParcelShop, '1027-CSOMAGPONT05');
        $billing = $order->getShippingAddress();
        self::assertNotNull($billing);
        $order->setBillingAddress($billing);
        $order->setShippingAddress(null);

        $parcel = $this->snapshot($order);

        self::assertSame(['1027', 'BUDAPEST II. KER.', 'Tölgyfa utca', '9'], [$parcel->getZipCode(), $parcel->getCity(), $parcel->getStreet(), $parcel->getHouseNumber()]);
        self::assertSame('Anna Kiss', $parcel->getRecipientName(), 'A címzett neve a számlázási címről jön.');
    }

    /** Utánvét: forintban, egészre kerekítve, a rendelésszámmal. */
    public function testCashOnDeliveryCarriesTheAmountInForints(): void
    {
        $parcel = $this->snapshot(OrderFactory::order(GlsDeliveryKind::HomeDelivery, paymentMethodCode: 'cash_on_delivery', total: 1_299_050));
        $api = $this->factory()->build($parcel, 1);

        self::assertSame(12_991, $parcel->getCodAmount());
        self::assertSame(12_991, $api->codAmount);
        self::assertSame('000123', $api->codReference);
        self::assertSame('HUF', $api->codCurrency);
    }

    public function testACardPaymentHasNoCod(): void
    {
        $api = $this->factory()->build($this->snapshot(OrderFactory::order(GlsDeliveryKind::HomeDelivery)), 1);

        self::assertNull($api->codAmount);
        self::assertNull($api->codReference);
    }

    /** Az értesítések alapból ki (#260); bekapcsolva FDS, és FSS csak FDS-sel. */
    public function testTheNotificationsFollowTheConfiguration(): void
    {
        $parcel = $this->snapshot(OrderFactory::order(GlsDeliveryKind::HomeDelivery));

        self::assertFalse($this->factory()->build($parcel, 1)->hasService('FDS'));

        $both = $this->factory(email: true, sms: true)->build($parcel, 1);
        self::assertTrue($both->hasService('FDS'));
        self::assertTrue($both->hasService('FSS'));

        $smsOnly = $this->factory(sms: true)->build($parcel, 1);
        self::assertFalse($smsOnly->hasService('FSS'), 'FSS FDS nélkül a 30-as hiba.');
    }

    public function testTheWeightAndCountGoIntoTheParcel(): void
    {
        $parcel = $this->snapshot(OrderFactory::order(GlsDeliveryKind::HomeDelivery), 2.5, 2);
        $api = $this->factory()->build($parcel, 1);

        self::assertSame(2, $api->count);
        self::assertSame(2.5, $api->properties[0]->weight ?? null);
    }

    public function testAnIncompletePickupAddressIsAConfigurationError(): void
    {
        $factory = new GlsParcelPayloadFactory(new StreetSplitter(), $this->catalog(), new CodPolicy(), [...self::PICKUP, 'city' => null], false, false);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('pickup_address.city');

        $factory->build($this->snapshot(OrderFactory::order(GlsDeliveryKind::HomeDelivery)), 1);
    }

    private function snapshot(Order $order, ?float $weight = null, int $count = 1): GlsParcel
    {
        $order->setNumber('000123');
        $shipment = $order->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);
        self::assertInstanceOf(GlsShipmentInterface::class, $shipment);

        $kind = GlsDeliveryKind::fromShipment($shipment);
        self::assertNotNull($kind);
        $parcel = new GlsParcel($shipment, '000123', $kind);
        $this->factory()->snapshot($parcel, $shipment, $weight, $count);

        return $parcel;
    }

    private function factory(bool $email = false, bool $sms = false): GlsParcelPayloadFactory
    {
        return new GlsParcelPayloadFactory(new StreetSplitter(), $this->catalog(), new CodPolicy(), self::PICKUP, $email, $sms);
    }

    private function catalog(): DeliveryPointCatalog
    {
        $json = json_encode(['items' => [DeliveryPointTest::SHOP, DeliveryPointTest::LOCKER]], \JSON_THROW_ON_ERROR);

        return new DeliveryPointCatalog(new MockHttpClient(new MockResponse($json)), new ArrayAdapter(), new RecordingLogger());
    }
}
