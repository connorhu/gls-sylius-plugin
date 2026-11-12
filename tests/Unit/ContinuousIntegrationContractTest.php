<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A csomag HORDOZZA a kapuit, és futtatja is őket — a `codeconjure/foxpost`
 * #196-os szerződésének átvétele.
 *
 * A lezárt néma hibamód: egy kapukonfiguráció ott van a repóban, de a
 * munkafolyamat nem hívja meg. Az elvárás a gyökérben TÉNYLEGESEN jelen lévő
 * konfigurációs fájlokból jön, így egy új kapu addig pirosat ad, amíg a CI
 * nem hívja. A YAML-t szándékosan stringként olvassuk: a futásidejű
 * függőség a PSR-interfészeken túl semmi, egy `symfony/yaml` dev-függőséget
 * ez nem ér meg.
 */
final class ContinuousIntegrationContractTest extends TestCase
{
    private const string WORKFLOW = '.github/workflows/ci.yaml';

    /**
     * Kapukonfiguráció → az a parancsrészlet, aminek a munkafolyamatban
     * meg kell jelennie, ha a konfiguráció létezik.
     *
     * @var array<string, string>
     */
    private const array GATES = [
        'phpunit.xml.dist' => 'vendor/bin/phpunit',
        'phpstan.dist.neon' => 'vendor/bin/phpstan',
        'ecs.php' => 'vendor/bin/ecs',
    ];

    public function testTheWorkflowFileExists(): void
    {
        self::assertFileExists(
            self::path(self::WORKFLOW),
            'A csomagnak nincs CI-munkafolyamata. Enélkül a `phpunit.xml.dist`, a ' .
            '`phpstan.dist.neon` és az `ecs.php` csak díszlet: egy PR-on semmi nem fut.',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function gateProvider(): iterable
    {
        foreach (self::GATES as $config => $command) {
            if (!file_exists(self::path($config))) {
                continue;
            }

            yield $config => [$config, $command];
        }
    }

    #[DataProvider('gateProvider')]
    public function testEveryGateTheRepositoryShipsIsInvokedByTheWorkflow(string $config, string $command): void
    {
        self::assertStringContainsString(
            $command,
            self::workflow(),
            \sprintf(
                'A `%s` ott van a repó gyökerében, de a `%s` nem hívja meg a `%s`-t. ' .
                'Ez a néma hibamód: a kapu létezik, a CI zöld, és senki nem méri.',
                $config,
                self::WORKFLOW,
                $command,
            ),
        );
    }

    /**
     * A `push` és a `pull_request` trigger együtt kell: `push` nélkül a `main`
     * állapotáról nincs mérés, `pull_request` nélkül pedig egy KÜLSŐ
     * közreműködő forkjáról indított PR-on nem fut semmi.
     */
    public function testTheWorkflowRunsOnBothPushAndPullRequest(): void
    {
        $workflow = self::workflow();

        self::assertMatchesRegularExpression('/^\s{4}push:/m', $workflow, 'Hiányzik a `push` trigger.');
        self::assertMatchesRegularExpression('/^\s{4}pull_request:/m', $workflow, 'Hiányzik a `pull_request` trigger.');
    }

    /**
     * A mátrixnak le kell fednie a `composer.json`-ban DEKLARÁLT PHP alsó
     * határt. Ha a floor `^8.4`-ről feljebb megy, de a CI a 8.4-et méri
     * tovább, a csomag olyan verzión bizonyított, amit már nem is támogat —
     * és fordítva: ha a floor marad, de a mátrixból kiesik, a legalacsonyabb
     * támogatott PHP-n semmi nem fut.
     */
    public function testThePhpMatrixCoversTheDeclaredMinimumPhpVersion(): void
    {
        $require = self::decode('composer.json')['require'] ?? null;
        $constraint = \is_array($require) ? ($require['php'] ?? null) : null;
        self::assertIsString($constraint, 'A `composer.json` nem deklarál `require.php` constraintet.');

        if (preg_match('/(\d+\.\d+)/', $constraint, $matches) !== 1) {
            self::fail(\sprintf('A `require.php` constraintből (`%s`) nem olvasható ki a minimális PHP verzió.', $constraint));
        }

        $minimum = $matches[1];

        self::assertStringContainsString(
            "'" . $minimum . "'",
            self::workflow(),
            \sprintf(
                'A `composer.json` `php: %s`-t deklarál, de a CI mátrixa nem méri a %s-et. ' .
                'A legalacsonyabb támogatott PHP-n így semmi nem fut.',
                $constraint,
                $minimum,
            ),
        );
    }

    /**
     * A build-badge nem dekoráció: ez az egyetlen hely, ahol a csomag
     * nyitólapjára ránéző ember látja, hogy a `main` zöld-e. Ha a
     * munkafolyamat fájlneve változik, a badge némán örökre „no status"
     * marad — ezért a badge URL-jét a munkafolyamat ÚTVONALÁHOZ mérjük.
     */
    public function testTheReadmeCarriesTheBadgeOfThisWorkflow(): void
    {
        self::assertStringContainsString(
            'actions/workflows/' . basename(self::WORKFLOW) . '/badge.svg',
            self::read('README.md'),
            'A README-ben nincs a CI-munkafolyamathoz tartozó build-badge.',
        );
    }

    private static function workflow(): string
    {
        return self::read(self::WORKFLOW);
    }

    /** @return array<mixed> */
    private static function decode(string $relative): array
    {
        $decoded = json_decode(self::read($relative), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }

    private static function read(string $relative): string
    {
        $contents = file_get_contents(self::path($relative));
        \assert(\is_string($contents));

        return $contents;
    }

    private static function path(string $relative): string
    {
        return \dirname(__DIR__, 2) . '/' . $relative;
    }
}
