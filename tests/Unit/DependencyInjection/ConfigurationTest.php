<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\DependencyInjection;

use CodeConjure\SyliusGlsPlugin\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    /**
     * Üres host-konfigurációval is érvényes: ez az API nélküli mód alapja
     * (#253). A hitelesítő adatok env-ből jönnek, `default::`-tal — hiányzó
     * env-változóra null, nem EnvNotFoundException.
     */
    public function testAnEmptyConfigurationIsValid(): void
    {
        $config = $this->process([]);

        self::assertSame('%env(default::GLS_USERNAME)%', $config['api']['username']);
        self::assertSame('%env(default::GLS_PASSWORD)%', $config['api']['password']);
        self::assertSame('%env(default::GLS_CLIENT_NUMBER)%', $config['api']['client_number']);
        self::assertSame('%env(default::GLS_ENVIRONMENT)%', $config['api']['environment']);
        self::assertSame('HU', $config['api']['country']);
        self::assertSame('Sylius', $config['api']['webshop_engine']);
    }

    /** A csomagolási ráhagyás helye és alapértéke (#260, tulajdonosi döntés: 0). */
    public function testThePackagingAllowanceDefaultsToZero(): void
    {
        $config = $this->process([]);

        self::assertSame(0, $config['packaging']['allowance_mm']);
        self::assertSame(0, $config['packaging']['allowance_g']);
    }

    public function testThePackagingAllowanceIsNotNegative(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['packaging' => ['allowance_mm' => -1]]);
    }

    public function testThePickupAddressIsOptionalAndDefaultsToHungary(): void
    {
        $config = $this->process([]);

        self::assertNull($config['pickup_address']['name']);
        self::assertNull($config['pickup_address']['street']);
        self::assertSame('HU', $config['pickup_address']['country_iso_code']);
    }

    public function testThePickupAddressIsPassedThrough(): void
    {
        $config = $this->process(['pickup_address' => [
            'name' => 'Bolt Kft.',
            'street' => 'Fő utca',
            'house_number' => '1',
            'city' => 'Budapest',
            'zip_code' => '1011',
            'contact_phone' => '+3611234567',
        ]]);

        self::assertSame('Bolt Kft.', $config['pickup_address']['name']);
        self::assertSame('1', $config['pickup_address']['house_number']);
        self::assertSame('+3611234567', $config['pickup_address']['contact_phone']);
    }

    public function testAnUnknownCountryIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['api' => ['country' => 'DE']]);
    }

    public function testAnUnknownKeyIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['api' => ['api_key' => 'x']]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{api: array<string, mixed>, pickup_address: array<string, mixed>, packaging: array<string, mixed>}
     */
    private function process(array $config): array
    {
        $processed = (new Processor())->processConfiguration(new Configuration(), [$config]);
        self::assertIsArray($processed['api'] ?? null);
        self::assertIsArray($processed['pickup_address'] ?? null);
        self::assertIsArray($processed['packaging'] ?? null);

        return ['api' => $processed['api'], 'pickup_address' => $processed['pickup_address'], 'packaging' => $processed['packaging']];
    }
}
