<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Pickup;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use CodeConjure\SyliusGlsPlugin\Pickup\PickupPointValidator;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsShipment;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsShippingMethod;
use CodeConjure\SyliusGlsPlugin\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A választott pont szerveroldali ellenőrzése. A widget `filter-type`-ja a
 * vevő böngészőjében hat; a mezőbe bármi érkezhet. A KÖTELEZŐSÉG nem ennek
 * a dolga (az a közös szállító-csomag `pickup_point_required` csoportja).
 */
final class PickupPointValidatorTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, ?string}> */
    public static function cases(): iterable
    {
        yield 'csomagpont a csomagpont-módon' => ['gls_parcel_shop', '1027-CSOMAGPONT05', null];
        yield 'automata az automata-módon' => ['gls_parcel_locker', 'HU1012-PLOCKER001', null];
        yield 'automata a csomagpont-módon' => ['gls_parcel_shop', 'HU1012-PLOCKER001', PickupPointValidator::WRONG_TYPE];
        yield 'csomagpont az automata-módon' => ['gls_parcel_locker', '1027-CSOMAGPONT05', PickupPointValidator::WRONG_TYPE];
        yield 'ismeretlen pont' => ['gls_parcel_locker', 'FOXPOST-123', PickupPointValidator::UNKNOWN];
        yield 'üres pont: a kötelezőség nem ide tartozik' => ['gls_parcel_locker', null, null];
        yield 'házhoz szállítás' => ['gls_home_delivery', 'bármi', null];
        yield 'más szállító módja' => ['foxpost_parcel_locker', 'HU1234', null];
    }

    #[DataProvider('cases')]
    public function testThePointIsCheckedAgainstTheKind(string $slug, ?string $pointId, ?string $expected): void
    {
        self::assertSame($expected, $this->validator()->validate(new GlsShipment(new GlsShippingMethod($slug), $pointId)));
    }

    public function testAShipmentWithoutAMethodPasses(): void
    {
        self::assertNull($this->validator()->validate(new GlsShipment(null, 'X')));
    }

    /** A GLS kiesése nem állíthatja le a pénztárt. */
    public function testAnUnavailableCatalogLetsThePointThrough(): void
    {
        $catalog = new DeliveryPointCatalog(new MockHttpClient(new MockResponse('', ['http_code' => 500])), new ArrayAdapter(), new RecordingLogger());

        self::assertNull((new PickupPointValidator($catalog))->validate(new GlsShipment(new GlsShippingMethod(GlsDeliveryKind::ParcelLocker->value), 'FOXPOST-123')));
    }

    private function validator(): PickupPointValidator
    {
        $json = json_encode(['items' => [DeliveryPointTest::SHOP, DeliveryPointTest::LOCKER]], \JSON_THROW_ON_ERROR);

        return new PickupPointValidator(new DeliveryPointCatalog(new MockHttpClient(new MockResponse($json)), new ArrayAdapter(), new RecordingLogger()));
    }
}
