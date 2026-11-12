<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Api;

use CodeConjure\Gls\Client;
use CodeConjure\Gls\Country;
use CodeConjure\Gls\Credentials;
use CodeConjure\Gls\Environment;
use CodeConjure\SyliusGlsPlugin\Exception\GlsApiNotConfiguredException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Az EGYETLEN hely, ami eldönti, fut-e a plugin MyGLS API-val.
 *
 * A döntés futásidejű, a beállított értékekből (alapból env-ből): ha egyik
 * hitelesítő adat sincs megadva, a mód `Manual`, és ez érvényes állapot. Ha
 * bármelyik meg van adva, a szándék az API — ilyenkor a hiányzó vagy hibás
 * érték konfigurációs HIBA (`missingSettings()`), nem csendes kézi mód.
 *
 * Az API-t hívó service-ek a `client()`-et kérik; kézi módban az egy
 * egyértelmű `GlsApiNotConfiguredException`-t dob, nem hálózati hibát.
 */
final class ApiAccess
{
    private const array ENVIRONMENTS = [
        'test' => Environment::Test,
        'production' => Environment::Production,
    ];

    private ?Client $client = null;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?string $username,
        #[\SensitiveParameter]
        private readonly ?string $password,
        private readonly ?string $clientNumber,
        private readonly ?string $environment,
        private readonly string $country = 'HU',
        private readonly string $webshopEngine = 'Sylius',
    ) {
    }

    public function mode(): ApiMode
    {
        foreach ([$this->username, $this->password, $this->clientNumber] as $value) {
            if (self::filled($value)) {
                return ApiMode::Api;
            }
        }

        return ApiMode::Manual;
    }

    /** Minden adat megvan és érvényes: a `client()` nem fog dobni. */
    public function isEnabled(): bool
    {
        return $this->mode() === ApiMode::Api && $this->missingSettings() === [];
    }

    /**
     * API-módban a hiányzó vagy érvénytelen beállítások env-változó-neve.
     * Kézi módban mindig üres.
     *
     * @return list<string>
     */
    public function missingSettings(): array
    {
        if ($this->mode() === ApiMode::Manual) {
            return [];
        }

        $missing = [];

        if (!self::filled($this->username)) {
            $missing[] = 'GLS_USERNAME';
        }

        if (!self::filled($this->password)) {
            $missing[] = 'GLS_PASSWORD';
        }

        if ($this->parsedClientNumber() === null) {
            $missing[] = 'GLS_CLIENT_NUMBER';
        }

        if ($this->parsedEnvironment() === null) {
            $missing[] = 'GLS_ENVIRONMENT';
        }

        return $missing;
    }

    /**
     * @throws GlsApiNotConfiguredException
     */
    public function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if ($this->mode() === ApiMode::Manual) {
            throw new GlsApiNotConfiguredException(
                'A GLS plugin API nélküli módban fut (nincs GLS_USERNAME / GLS_PASSWORD / GLS_CLIENT_NUMBER): ' .
                'a csomagot a GLS felületén kell feladni.',
            );
        }

        $missing = $this->missingSettings();
        $clientNumber = $this->parsedClientNumber();
        $environment = $this->parsedEnvironment();

        if ($missing !== [] || $clientNumber === null || $environment === null || $this->username === null || $this->password === null) {
            $message = \sprintf('A MyGLS API beállítása hiányos vagy hibás: %s.', implode(', ', $missing));

            if (\in_array('GLS_ENVIRONMENT', $missing, true) && self::filled($this->environment)) {
                $message .= \sprintf(' A GLS_ENVIRONMENT értéke "%s", de csak "test" vagy "production" lehet.', $this->environment);
            }

            throw new GlsApiNotConfiguredException($message);
        }

        return $this->client = new Client(
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            new Credentials(trim($this->username), $this->password, $clientNumber, $this->webshopEngine),
            Country::from($this->country),
            $environment,
        );
    }

    private function parsedClientNumber(): ?int
    {
        if ($this->clientNumber === null || preg_match('/^\s*0*([1-9]\d{0,17})\s*$/', $this->clientNumber, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function parsedEnvironment(): ?Environment
    {
        return self::ENVIRONMENTS[strtolower(trim($this->environment ?? ''))] ?? null;
    }

    private static function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
