<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit;

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

    public function testEveryKeyLivesUnderThePluginNamespace(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach (self::keys($locale) as $key) {
                self::assertStringStartsWith('codeconjure.gls.', $key, \sprintf('messages.%s.yaml', $locale));
            }
        }
    }

    public function testBothLocalesDefineTheSameKeys(): void
    {
        self::assertSame(self::keys('hu'), self::keys('en'));
    }

    /** @return list<string> */
    private static function keys(string $locale): array
    {
        $parsed = Yaml::parseFile(\dirname(__DIR__, 2) . '/translations/messages.' . $locale . '.yaml');
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
