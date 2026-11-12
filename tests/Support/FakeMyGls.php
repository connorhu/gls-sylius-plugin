<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Support;

use CodeConjure\SyliusGlsPlugin\Api\ApiAccess;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A MyGLS helyettese: a sorba tett JSON-válaszokat adja vissza, és
 * rögzíti, melyik metódust milyen törzzsel hívták.
 */
final class FakeMyGls
{
    /** @var list<array{method: string, body: array<mixed>}> */
    public array $calls = [];

    /** @var list<array<mixed>> */
    private array $responses = [];

    /** @param array<mixed> $response */
    public function queue(array $response): self
    {
        $this->responses[] = $response;

        return $this;
    }

    public function access(): ApiAccess
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode((string) ($options['body'] ?? '{}'), true);
            $this->calls[] = ['method' => (string) preg_replace('~^.*/json/~', '', $url), 'body' => \is_array($body) ? $body : []];
            $next = array_shift($this->responses) ?? throw new \LogicException('Váratlan MyGLS-hívás: ' . $url);

            return new MockResponse(json_encode($next, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });
        $factory = new Psr17Factory();

        return new ApiAccess(new Psr18Client($http, $factory, $factory), $factory, $factory, 'api@example.com', 'titok', '100000001', 'test', 'HU', 'Sylius');
    }

    /** A `$call`-adik kérés törzsének egy értéke, kulcsok mentén (vagy null). */
    public function value(int $call, string|int ...$path): mixed
    {
        $value = $this->calls[$call]['body'] ?? null;

        foreach ($path as $key) {
            $value = \is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_map(static fn (array $call): string => $call['method'], $this->calls);
    }
}
