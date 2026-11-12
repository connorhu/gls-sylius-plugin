<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Twig;

use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsShipment;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsShippingMethod;
use CodeConjure\SyliusGlsPlugin\Twig\GlsExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class GlsExtensionTest extends TestCase
{
    public function testGlsHandlesDeliveryAnswersForTheThreeGlsKinds(): void
    {
        $twig = new Environment(new ArrayLoader(['t' => '{{ gls_handles_delivery(method) ? "igen" : "nem" }}|{{ gls_delivery_kind(method) }}']));
        $twig->addExtension(new GlsExtension());

        self::assertSame('igen|gls_parcel_shop', $twig->render('t', ['method' => new GlsShippingMethod('gls_parcel_shop')]));
        self::assertSame('nem|', $twig->render('t', ['method' => new GlsShippingMethod('foxpost_parcel_locker')]));
        self::assertSame('nem|', $twig->render('t', ['method' => null]));
    }

    /** A leadott rendelés összegzője a szállítmányt kérdezi, nem a módot. */
    public function testTheShipmentAnswersToo(): void
    {
        $twig = new Environment(new ArrayLoader(['t' => '{{ gls_handles_delivery(shipment) ? "igen" : "nem" }}|{{ gls_delivery_kind(shipment) }}']));
        $twig->addExtension(new GlsExtension());

        self::assertSame('igen|gls_parcel_locker', $twig->render('t', ['shipment' => new GlsShipment(new GlsShippingMethod('gls_parcel_locker'))]));
        self::assertSame('nem|', $twig->render('t', ['shipment' => new GlsShipment(new GlsShippingMethod('foxpost_parcel_locker'))]));
    }
}
