<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Pickup;

use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use CodeConjure\SyliusGlsPlugin\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A GLS nyilvános pontlistája a szerveroldalon: ebből jön a választott pont
 * neve és címe (összegző), a fajta-ellenőrzés (pénztár) és az, hogy a pont
 * fogad-e készpénzt (utánvét-figyelmeztetés). A widget a vevő böngészőjében
 * fut — a szerver nem bízhat abban, amit a `pickupPointId` mezőbe kap.
 */
final class DeliveryPointCatalogTest extends TestCase
{
    private RecordingLogger $logger;

    /** @var list<string> */
    private array $requestedUrls = [];

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testItFindsAPointByItsId(): void
    {
        $catalog = $this->catalog([new MockResponse(self::json())]);

        self::assertSame('Tölgyfa ABC', $catalog->find('1027-CSOMAGPONT05')?->name);
        self::assertSame('parcel-locker', $catalog->find('HU1012-PLOCKER001')?->type);
        self::assertSame(['https://map.gls-hungary.com/data/deliveryPoints/hu.json'], $this->requestedUrls);
    }

    public function testAnUnknownIdIsNull(): void
    {
        $catalog = $this->catalog([new MockResponse(self::json())]);

        self::assertNull($catalog->find('NINCS-ILYEN'));
        self::assertTrue($catalog->isAvailable());
    }

    public function testTheListIsFetchedOnceAndCached(): void
    {
        $catalog = $this->catalog([new MockResponse(self::json())]);

        $catalog->find('1027-CSOMAGPONT05');
        $catalog->find('HU1012-PLOCKER001');
        $catalog->isAvailable();

        self::assertCount(1, $this->requestedUrls);
    }

    public function testTheCountryPicksTheList(): void
    {
        $catalog = $this->catalog([new MockResponse(self::json())], 'SK');

        $catalog->find('X');

        self::assertSame(['https://map.gls-hungary.com/data/deliveryPoints/sk.json'], $this->requestedUrls);
    }

    /**
     * A GLS kiesése nem állíthatja le a pénztárt: a katalógus „nem elérhető",
     * a hívó dönt (a fajta-ellenőrzés ilyenkor átenged). A hibát a `gls`
     * csatornára naplózza, és rövid ideig nem próbálja újra — különben a
     * kiesés alatt minden oldalbetöltés a GLS időtúllépésére várna.
     */
    public function testAnUnavailableListIsLoggedAndBrieflyRemembered(): void
    {
        $catalog = $this->catalog([new MockResponse('', ['http_code' => 502]), new MockResponse(self::json())]);

        self::assertNull($catalog->find('1027-CSOMAGPONT05'));
        self::assertFalse($catalog->isAvailable());
        self::assertCount(1, $this->requestedUrls);
        self::assertCount(1, $this->logger->records);
        self::assertSame('warning', $this->logger->records[0]['level']);
    }

    public function testABrokenBodyIsUnavailable(): void
    {
        $catalog = $this->catalog([new MockResponse('{"items": "nem lista"')]);

        self::assertFalse($catalog->isAvailable());
        self::assertCount(1, $this->logger->records);
    }

    public function testMalformedRowsAreSkipped(): void
    {
        $catalog = $this->catalog([new MockResponse(json_encode(['items' => [['id' => 'rossz'], DeliveryPointTest::SHOP]], \JSON_THROW_ON_ERROR))]);

        self::assertNotNull($catalog->find('1027-CSOMAGPONT05'));
        self::assertNull($catalog->find('rossz'));
    }

    /** @param list<MockResponse> $responses */
    private function catalog(array $responses, string $country = 'HU'): DeliveryPointCatalog
    {
        $http = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requestedUrls[] = $url;

            return array_shift($responses) ?? throw new \LogicException('Váratlan kérés: ' . $url);
        });

        return new DeliveryPointCatalog($http, new ArrayAdapter(), $this->logger, $country);
    }

    private static function json(): string
    {
        return json_encode(['items' => [DeliveryPointTest::SHOP, DeliveryPointTest::LOCKER]], \JSON_THROW_ON_ERROR);
    }
}
