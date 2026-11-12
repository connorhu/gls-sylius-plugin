<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Api;

use CodeConjure\Gls\Country;
use CodeConjure\Gls\Environment;
use CodeConjure\SyliusGlsPlugin\Api\ApiAccess;
use CodeConjure\SyliusGlsPlugin\Api\ApiMode;
use CodeConjure\SyliusGlsPlugin\Exception\GlsApiNotConfiguredException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

/**
 * Az API nélküli mód (#253, tulajdonosi döntés 2026-11-12): hitelesítő adat
 * nélkül a plugin díjkalkulátor és pénztár-integráció; a csomagot kézzel
 * kell feladni. A módot EGY helyen dönti el a plugin, futásidőben, az
 * env-ből — így a hozzáférés megérkezésekor elég az env-et kitölteni.
 */
final class ApiAccessTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, ?string}> */
    public static function noCredentialsProvider(): iterable
    {
        yield 'null (az env-változó nincs megadva)' => [null, null, null];
        yield 'üres string' => ['', '', ''];
        yield 'csak szóköz' => [' ', ' ', ' '];
    }

    #[DataProvider('noCredentialsProvider')]
    public function testWithoutCredentialsThePluginRunsInManualMode(?string $username, ?string $password, ?string $clientNumber): void
    {
        $access = $this->access($username, $password, $clientNumber, null);

        self::assertSame(ApiMode::Manual, $access->mode());
        self::assertFalse($access->isEnabled());
        self::assertSame([], $access->missingSettings(), 'A kézi mód nem hibás konfiguráció.');
    }

    public function testManualModeRefusesToBuildAClientWithAClearMessage(): void
    {
        $access = $this->access(null, null, null, null);

        $this->expectException(GlsApiNotConfiguredException::class);
        $this->expectExceptionMessage('API nélküli');

        $access->client();
    }

    public function testWithAllCredentialsThePluginRunsInApiMode(): void
    {
        $access = $this->access('api@example.com', 'titok', '100000001', 'test');

        self::assertSame(ApiMode::Api, $access->mode());
        self::assertTrue($access->isEnabled());
        self::assertSame([], $access->missingSettings());
    }

    public function testTheClientIsBuiltFromTheSettings(): void
    {
        $client = $this->access('api@example.com', 'titok', '100000001', 'test', 'CZ', 'Sylius')->client();

        self::assertSame(Environment::Test, $client->environment());
        self::assertSame(Country::Czechia, $client->country());
        self::assertSame(100000001, $client->credentials()->clientNumber);
        self::assertSame('api@example.com', $client->credentials()->username);
        self::assertSame('Sylius', $client->credentials()->webshopEngine);
    }

    public function testTheClientIsBuiltOnce(): void
    {
        $access = $this->access('api@example.com', 'titok', '100000001', 'production');

        self::assertSame($access->client(), $access->client());
        self::assertSame(Environment::Production, $access->client()->environment());
    }

    /**
     * A részleges kitöltés HIBA, nem kézi mód: különben egy elgépelt
     * env-változó csendben API nélküli boltot adna.
     */
    public function testPartialCredentialsAreAMisconfigurationNotManualMode(): void
    {
        $access = $this->access('api@example.com', null, '', 'test');

        self::assertFalse($access->isEnabled());
        self::assertSame(ApiMode::Api, $access->mode(), 'Ha bármelyik adat meg van adva, a szándék az API-mód.');
        self::assertSame(['GLS_PASSWORD', 'GLS_CLIENT_NUMBER'], $access->missingSettings());

        $this->expectException(GlsApiNotConfiguredException::class);
        $this->expectExceptionMessage('GLS_PASSWORD, GLS_CLIENT_NUMBER');

        $access->client();
    }

    /**
     * A környezetnek nincs alapértéke: a téves „test" élesben érvénytelen
     * címkét, a téves „production" teszt közben valódi feladást adna.
     */
    public function testTheEnvironmentMustBeExplicitInApiMode(): void
    {
        $access = $this->access('api@example.com', 'titok', '100000001', null);

        self::assertSame(['GLS_ENVIRONMENT'], $access->missingSettings());

        $this->expectException(GlsApiNotConfiguredException::class);
        $this->expectExceptionMessage('GLS_ENVIRONMENT');

        $access->client();
    }

    public function testAnUnknownEnvironmentIsAMisconfiguration(): void
    {
        $access = $this->access('api@example.com', 'titok', '100000001', 'sandbox');

        self::assertSame(['GLS_ENVIRONMENT'], $access->missingSettings());

        $this->expectException(GlsApiNotConfiguredException::class);
        $this->expectExceptionMessage('"sandbox"');

        $access->client();
    }

    public function testANonNumericClientNumberIsAMisconfiguration(): void
    {
        $access = $this->access('api@example.com', 'titok', 'ABC123', 'test');

        self::assertSame(['GLS_CLIENT_NUMBER'], $access->missingSettings());

        $this->expectException(GlsApiNotConfiguredException::class);
        $this->expectExceptionMessage('GLS_CLIENT_NUMBER');

        $access->client();
    }

    public function testTheMisconfigurationNeverEchoesThePassword(): void
    {
        $access = $this->access('api@example.com', 'nagyon-titkos', null, 'test');

        try {
            $access->client();
            self::fail('Kivételt vártunk.');
        } catch (GlsApiNotConfiguredException $e) {
            self::assertStringNotContainsString('nagyon-titkos', $e->getMessage());
        }
    }

    private function access(
        ?string $username,
        ?string $password,
        ?string $clientNumber,
        ?string $environment,
        string $country = 'HU',
        string $webshopEngine = 'Sylius',
    ): ApiAccess {
        $factory = new Psr17Factory();

        return new ApiAccess(
            $this->createStub(ClientInterface::class),
            $factory,
            $factory,
            $username,
            $password,
            $clientNumber,
            $environment,
            $country,
            $webshopEngine,
        );
    }
}
