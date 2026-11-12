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
