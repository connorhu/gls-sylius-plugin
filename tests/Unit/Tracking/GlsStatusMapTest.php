<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Tracking;

use CodeConjure\SyliusGlsPlugin\Tracking\GlsStatusMap;
use CodeConjure\SyliusGlsPlugin\Tracking\TrackingOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A MyGLS státuszkódjai (G függelék, ver. 25.12.11) a belső állapotokra (#258).
 */
final class GlsStatusMapTest extends TestCase
{
    /** @return iterable<string, array{string, TrackingOutcome}> */
    public static function codes(): iterable
    {
        foreach (['5', '54', '55', '58', '59', '92'] as $code) {
            yield 'kézbesítve ' . $code => [$code, TrackingOutcome::Delivered];
        }

        foreach (['23', '40'] as $code) {
            yield 'visszaküldve ' . $code => [$code, TrackingOutcome::Returned];
        }

        foreach (['11', '12', '16', '20', '33', '36', '68'] as $code) {
            yield 'sikertelen kézbesítés ' . $code => [$code, TrackingOutcome::Failed];
        }

        foreach (['1', '2', '3', '4', '22', '56', '57', '97'] as $code) {
            yield 'a GLS-nél, úton vagy ponton vár ' . $code => [$code, TrackingOutcome::InTransit];
        }

        yield 'adat rögzítve, még nincs a GLS-nél' => ['51', TrackingOutcome::NotYetAtGls];
        yield 'ismeretlen kód' => ['999', TrackingOutcome::Unknown];
        yield 'nem szám' => ['x', TrackingOutcome::Unknown];
        yield 'vezető nullával' => ['05', TrackingOutcome::Delivered];
    }

    #[DataProvider('codes')]
    public function testTheCodeMapsToAnOutcome(string $code, TrackingOutcome $outcome): void
    {
        self::assertSame($outcome, GlsStatusMap::outcome($code));
    }

    public function testEveryOutcomeHasItsTransition(): void
    {
        self::assertSame('tracking_delivered', TrackingOutcome::Delivered->transition());
        self::assertSame('tracking_returned', TrackingOutcome::Returned->transition());
        self::assertSame('tracking_failed', TrackingOutcome::Failed->transition());
        self::assertSame('tracking_in_transit', TrackingOutcome::InTransit->transition());
        self::assertNull(TrackingOutcome::NotYetAtGls->transition());
        self::assertNull(TrackingOutcome::Unknown->transition());
    }

    /** A „még nincs a GLS-nél" és az ismeretlen kód nem bizonyítja, hogy a csomagot átvették. */
    public function testOnlyRealScansProveTheHandOver(): void
    {
        self::assertTrue(TrackingOutcome::InTransit->provesHandOver());
        self::assertTrue(TrackingOutcome::Delivered->provesHandOver());
        self::assertFalse(TrackingOutcome::NotYetAtGls->provesHandOver());
        self::assertFalse(TrackingOutcome::Unknown->provesHandOver());
    }
}
