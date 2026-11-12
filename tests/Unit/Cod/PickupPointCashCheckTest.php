<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Cod;

use CodeConjure\SyliusGlsPlugin\Cod\PickupPointCashCheck;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use CodeConjure\SyliusGlsPlugin\Tests\Support\OrderFactory;
use CodeConjure\SyliusGlsPlugin\Tests\Support\RecordingLogger;
use CodeConjure\SyliusGlsPlugin\Tests\Unit\Pickup\DeliveryPointTest;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Order;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Utánvét átvételi ponton (#255): a pont a 2. lépésben dől el, a fizetési
 * mód csak a 3.-ban, ezért a widget nem szűrhet fizetési mód szerint. A 3.
 * lépés a figyelmeztetést ebből a kérdésből rajzolja: a rendelés GLS-pontján
 * utánvétnél csak kártyával lehet-e fizetni.
 *
 * Mérve (2026-11-12): a 3225 automatából egy sem fogad készpénzt; a 944
 * csomagpontból 777 igen.
 */
final class PickupPointCashCheckTest extends TestCase
{
    public function testALockerIsAlwaysCardOnly(): void
    {
        self::assertTrue($this->check()->isCardOnly(OrderFactory::order(GlsDeliveryKind::ParcelLocker, 'HU1012-PLOCKER001')));
    }

    /** Az automatáról a pontlista nélkül is tudjuk: a GLS kiesése alatt is figyelmeztet. */
    public function testALockerIsCardOnlyEvenWithoutThePointList(): void
    {
        $catalog = new DeliveryPointCatalog(new MockHttpClient(new MockResponse('', ['http_code' => 500])), new ArrayAdapter(), new RecordingLogger());

        self::assertTrue((new PickupPointCashCheck($catalog))->isCardOnly(OrderFactory::order(GlsDeliveryKind::ParcelLocker, null)));
    }

    public function testAShopThatTakesCashIsNotCardOnly(): void
    {
        self::assertFalse($this->check()->isCardOnly(OrderFactory::order(GlsDeliveryKind::ParcelShop, '1027-CSOMAGPONT05')));
    }

    public function testAShopWithoutCashIsCardOnly(): void
    {
        self::assertTrue($this->check()->isCardOnly(OrderFactory::order(GlsDeliveryKind::ParcelShop, '2000-KARTYAS')));
    }

    /** Ismeretlen pontról nem állítunk semmit (azt a pénztár ellenőrzése fogja meg). */
    public function testAnUnknownShopIsNotCardOnly(): void
    {
        self::assertFalse($this->check()->isCardOnly(OrderFactory::order(GlsDeliveryKind::ParcelShop, 'NINCS-ILYEN')));
    }

    public function testHomeDeliveryIsNotCardOnly(): void
    {
        self::assertFalse($this->check()->isCardOnly(OrderFactory::order(GlsDeliveryKind::HomeDelivery)));
    }

    public function testAnOrderWithoutShipmentsIsNotCardOnly(): void
    {
        self::assertFalse($this->check()->isCardOnly(new Order()));
    }

    private function check(): PickupPointCashCheck
    {
        $cardOnlyShop = [...DeliveryPointTest::SHOP, 'id' => '2000-KARTYAS', 'features' => ['acceptsCard', 'pickup']];
        $json = json_encode(['items' => [DeliveryPointTest::SHOP, DeliveryPointTest::LOCKER, $cardOnlyShop]], \JSON_THROW_ON_ERROR);

        return new PickupPointCashCheck(new DeliveryPointCatalog(new MockHttpClient(new MockResponse($json)), new ArrayAdapter(), new RecordingLogger()));
    }
}
