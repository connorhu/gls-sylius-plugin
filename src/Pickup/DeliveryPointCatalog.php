<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Pickup;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A GLS nyilvános pontlistája (hitelesítés nélkül, országonként egy JSON),
 * gyorsítótárazva.
 *
 * A térkép-widget a vevő böngészőjében fut: amit a `pickupPointId` mezőbe
 * kapunk, abban a szerver nem bízhat. Ebből a listából jön a választott
 * pont neve és címe, az, hogy a pont a mód fajtájához tartozik-e, és hogy
 * fogad-e készpénzt.
 *
 * A GLS kiesése nem állíthatja le a pénztárt: elérhetetlen listánál a
 * `find()` null, az `isAvailable()` false, és a hívó dönt. A hibát a
 * katalógus rövid ideig megjegyzi, hogy a kiesés alatt ne minden
 * oldalbetöltés a GLS időtúllépésére várjon.
 */
final class DeliveryPointCatalog
{
    public const string URL = 'https://map.gls-hungary.com/data/deliveryPoints/%s.json';

    /** @var array<string, DeliveryPoint>|false|null */
    private array|false|null $points = null;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        ?LoggerInterface $logger = null,
        private readonly string $country = 'HU',
        private readonly int $ttl = 86_400,
        private readonly int $failureTtl = 300,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function find(string $id): ?DeliveryPoint
    {
        $points = $this->points();

        return $points === false ? null : ($points[$id] ?? null);
    }

    public function isAvailable(): bool
    {
        return $this->points() !== false;
    }

    /** @return array<string, DeliveryPoint>|false */
    private function points(): array|false
    {
        $country = strtolower($this->country);

        /** @var array<string, DeliveryPoint>|false $points */
        $points = $this->points ??= $this->cache->get(
            'codeconjure_gls.delivery_points.' . $country,
            function (ItemInterface $item) use ($country): array|false {
                $points = $this->fetch($country);
                $item->expiresAfter($points === false ? $this->failureTtl : $this->ttl);

                return $points;
            },
        );

        return $points;
    }

    /** @return array<string, DeliveryPoint>|false */
    private function fetch(string $country): array|false
    {
        $url = \sprintf(self::URL, $country);

        try {
            $data = $this->httpClient->request('GET', $url, ['timeout' => 10])->toArray();
        } catch (\Throwable $exception) {
            $this->logger->warning('A GLS pontlista nem érhető el: {url} ({error})', ['url' => $url, 'error' => $exception->getMessage()]);

            return false;
        }

        $items = $data['items'] ?? null;

        if (!\is_array($items)) {
            $this->logger->warning('A GLS pontlista váratlan alakú: {url}', ['url' => $url]);

            return false;
        }

        $points = [];

        foreach ($items as $row) {
            $point = \is_array($row) ? DeliveryPoint::fromArray($row) : null;

            if ($point !== null) {
                $points[$point->id] = $point;
            }
        }

        return $points;
    }
}
