<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit;

use CodeConjure\SyliusGlsPlugin\Pickup\PickupPointValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A plugin fordításai a saját `codeconjure.gls.*` névterükben élnek — a
 * host és a többi szállító-plugin kulcsaival így nem ütközhetnek —, és a
 * két nyelv kulcskészlete azonos.
 */
final class TranslationNamespaceTest extends TestCase
{
    private const array LOCALES = ['hu', 'en'];

    private const array DOMAINS = ['messages', 'validators'];

    public function testEveryKeyLivesUnderThePluginNamespace(): void
    {
        foreach (self::DOMAINS as $domain) {
            foreach (self::LOCALES as $locale) {
                foreach (self::keys($domain, $locale) as $key) {
                    self::assertStringStartsWith('codeconjure.gls.', $key, \sprintf('%s.%s.yaml', $domain, $locale));
                }
            }
        }
    }

    public function testBothLocalesDefineTheSameKeys(): void
    {
        foreach (self::DOMAINS as $domain) {
            self::assertSame(self::keys($domain, 'hu'), self::keys($domain, 'en'), $domain);
        }
    }

    /**
     * A pénztár-ellenőrzés hibakulcsai a `validators` domainben vannak —
     * enélkül a vevő a nyers kulcsot látná a panelnél.
     */
    public function testTheValidatorMessagesAreTranslated(): void
    {
        foreach ([PickupPointValidator::UNKNOWN, PickupPointValidator::WRONG_TYPE] as $key) {
            self::assertContains($key, self::keys('validators', 'hu'));
        }
    }

    /**
     * A widget és az összegző a fajtából képzi a kulcsot
     * (`codeconjure.gls.pickup_point.{shop|locker}.{select|change|title}`).
     */
    public function testThePickupPointKeysExistForBothKinds(): void
    {
        foreach (['shop', 'locker'] as $kind) {
            foreach (['select', 'change', 'title'] as $leaf) {
                self::assertContains(\sprintf('codeconjure.gls.pickup_point.%s.%s', $kind, $leaf), self::keys('messages', 'hu'));
            }
        }
    }

    /**
     * Minden sablon szó szerinti kulcsa létezik (a fajtából vagy állapotból
     * képzett kulcsokat a két külön teszt méri).
     */
    /** A `gls` calculator címkéje és az admin-űrlap mezői (#260). */
    public function testTheCalculatorFormIsTranslated(): void
    {
        $keys = self::keys('messages', 'hu');
        $fields = ['label', 'base', 'delivery', 'dispatch', 'go_unit_price', 'go_unit_price_help', 'email_fee', 'sms_fee', 'cod_fee', 'cod_fee_high', 'cod_threshold', 'country_base', 'country_base_help', 'country_base_invalid'];

        foreach ($fields as $field) {
            self::assertContains('codeconjure.gls.form.calculator.' . $field, $keys);
        }
    }

    public function testEveryLiteralKeyInTheTemplatesExists(): void
    {
        $keys = self::keys('messages', 'hu');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/templates', \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            preg_match_all("/'(codeconjure\\.gls\\.[a-z_.]+[a-z_])'\\s*\\|\\s*trans/", (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $key) {
                self::assertContains($key, $keys, $file->getFilename());
            }
        }
    }

    /** A csomaglista címkéi és szűrő-választékai fordítottak. */
    public function testEveryGridLabelIsTranslated(): void
    {
        $keys = self::keys('messages', 'hu');
        preg_match_all('/\b(codeconjure\.gls\.[a-z_.]+[a-z_])\b/', (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Resources/config/app/grid.yaml'), $matches);

        self::assertNotSame([], $matches[1]);

        foreach (array_unique($matches[1]) as $key) {
            self::assertContains($key, $keys);
        }
    }

    /** A grid a fajtából és az állapotból képzi a kulcsot. */
    public function testEveryKindAndStatusIsTranslated(): void
    {
        $keys = self::keys('messages', 'hu');

        foreach (\CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind::cases() as $kind) {
            self::assertContains('codeconjure.gls.delivery_kind.' . $kind->value, $keys);
        }

        foreach (\CodeConjure\SyliusGlsPlugin\Entity\GlsParcelStatus::cases() as $status) {
            self::assertContains('codeconjure.gls.ui.status.' . $status->value, $keys);
        }
    }

    /** @return list<string> */
    private static function keys(string $domain, string $locale): array
    {
        $parsed = Yaml::parseFile(\dirname(__DIR__, 2) . '/translations/' . $domain . '.' . $locale . '.yaml');
        self::assertIsArray($parsed);

        $keys = self::flatten($parsed, '');
        sort($keys);

        return $keys;
    }

    /**
     * @param array<mixed> $tree
     *
     * @return list<string>
     */
    private static function flatten(array $tree, string $prefix): array
    {
        $keys = [];

        foreach ($tree as $key => $value) {
            $path = $prefix . $key;
            $keys = \is_array($value) ? [...$keys, ...self::flatten($value, $path . '.')] : [...$keys, $path];
        }

        return $keys;
    }
}
