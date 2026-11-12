<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit;

use CodeConjure\SyliusGlsPlugin\DependencyInjection\CodeConjureSyliusGlsExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Amit a hostnak kell megtennie, az legyen LEÍRVA a `docs/installation.md`-
 * ben — és az elvárás a plugin KÓDJÁBÓL jön, nem másolt listából: egy új
 * env-változó vagy konfigurációs szakasz addig piros, amíg nincs
 * dokumentálva (a FoxPost plugin #194-es szerződésének mintája).
 */
final class InstallationContractTest extends TestCase
{
    public function testEveryEnvironmentVariableTheConfigurationReadsIsDocumented(): void
    {
        preg_match_all('/%env\(default::([A-Z0-9_]+)\)%/', self::read('src/DependencyInjection/Configuration.php'), $matches);

        self::assertNotSame([], $matches[1]);

        foreach ($matches[1] as $name) {
            self::assertStringContainsString($name . '=', self::doc(), \sprintf('A `%s` env-változó nincs a telepítési dokumentáció .env-blokkjában.', $name));
        }
    }

    public function testEveryConfigurationSectionIsDocumented(): void
    {
        preg_match_all("/->arrayNode\\('([a-z_]+)'\\)/", self::read('src/DependencyInjection/Configuration.php'), $matches);

        foreach ($matches[1] as $section) {
            self::assertMatchesRegularExpression('/^ +' . preg_quote($section, '/') . ':/m', self::doc(), \sprintf('A `%s` konfigurációs szakasz nincs dokumentálva.', $section));
        }
    }

    public function testTheMonologProdHandlerIsDocumented(): void
    {
        $channel = CodeConjureSyliusGlsExtension::MONOLOG_CHANNEL;

        self::assertStringContainsString('channels: [' . $channel . ']', self::doc());
        self::assertStringContainsString('"!' . $channel . '"', self::doc());
    }

    public function testTheBundleRegistrationIsDocumentedInTheRightOrder(): void
    {
        $carrier = strpos(self::doc(), 'CodeConjureSyliusCarrierBundle::class');
        $gls = strpos(self::doc(), 'CodeConjureSyliusGlsPlugin::class');

        self::assertIsInt($carrier);
        self::assertIsInt($gls);
        self::assertLessThan($gls, $carrier, 'A közös szállító-csomagnak a GLS plugin ELŐTT kell állnia.');
    }

    /**
     * A plugin hookjai a host felületén jelennek meg; a host felülírhatja
     * (sablon, prioritás) vagy kikapcsolhatja őket — ehhez tudnia kell a
     * nevüket. A lista a plugin hooks.yaml-jából jön.
     */
    public function testEveryTwigHookThePluginRegistersIsDocumented(): void
    {
        $parsed = Yaml::parseFile(\dirname(__DIR__, 2) . '/src/Resources/config/app/hooks.yaml');
        self::assertIsArray($parsed);
        self::assertIsArray($parsed['sylius_twig_hooks'] ?? null);
        $hooks = $parsed['sylius_twig_hooks']['hooks'] ?? null;
        self::assertIsArray($hooks);

        foreach ($hooks as $hook => $hookables) {
            self::assertIsArray($hookables);

            foreach (array_keys($hookables) as $name) {
                self::assertStringContainsString((string) $hook, self::doc(), \sprintf('A `%s` hook nincs dokumentálva.', $hook));
                self::assertMatchesRegularExpression('/`' . preg_quote((string) $name, '/') . '`/', self::doc(), \sprintf('A `%s` hookable neve nincs dokumentálva.', $name));
            }
        }
    }

    /** A Stimulus controller a host build-jébe kerül — a lépésnek le kell írva lennie. */
    public function testTheStimulusControllerIsDocumented(): void
    {
        $package = json_decode(self::read('assets/package.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($package);
        self::assertIsArray($package['symfony'] ?? null);
        $controllers = $package['symfony']['controllers'] ?? null;
        self::assertIsArray($controllers);

        foreach (array_keys($controllers) as $name) {
            self::assertStringContainsString((string) $name, self::doc());
        }

        self::assertStringContainsString('@codeconjure/gls-sylius-plugin', self::doc());
    }

    private static function doc(): string
    {
        return self::read('docs/installation.md');
    }

    private static function read(string $relative): string
    {
        $contents = file_get_contents(\dirname(__DIR__, 2) . '/' . $relative);
        self::assertIsString($contents);

        return $contents;
    }
}
