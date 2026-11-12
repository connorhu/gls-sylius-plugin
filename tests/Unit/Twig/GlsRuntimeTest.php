<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Twig;

use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Cod\PickupPointCashCheck;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use CodeConjure\SyliusGlsPlugin\Tests\Support\OrderFactory;
use CodeConjure\SyliusGlsPlugin\Tests\Support\RecordingLogger;
use CodeConjure\SyliusGlsPlugin\Tests\Unit\Pickup\DeliveryPointTest;
use CodeConjure\SyliusGlsPlugin\Twig\GlsExtension;
use CodeConjure\SyliusGlsPlugin\Twig\GlsRuntime;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

final class GlsRuntimeTest extends TestCase
{
    public function testThePickupPointIsLookedUpById(): void
    {
        self::assertSame(
            'Tölgyfa ABC|1027 BUDAPEST II. KER., Tölgyfa utca 9',
            $this->render('{% set p = gls_pickup_point(id) %}{{ p.name }}|{{ p.addressLine }}', ['id' => '1027-CSOMAGPONT05']),
        );
    }

    public function testAnUnknownOrEmptyIdIsNull(): void
    {
        self::assertSame('null|null', $this->render('{{ gls_pickup_point("X") is null ? "null" }}|{{ gls_pickup_point(null) is null ? "null" }}'));
    }

    public function testTheCodNoticeQuestion(): void
    {
        $template = '{{ gls_cod_card_only(order) ? "kártya" : "-" }}';

        self::assertSame('kártya', $this->render($template, ['order' => OrderFactory::order(GlsDeliveryKind::ParcelLocker, 'HU1012-PLOCKER001')]));
        self::assertSame('-', $this->render($template, ['order' => OrderFactory::order(GlsDeliveryKind::ParcelShop, '1027-CSOMAGPONT05')]));
        self::assertSame('-', $this->render($template, ['order' => null]));
    }

    public function testTheCashOnDeliveryMethodIsRecognised(): void
    {
        $cod = new PaymentMethod();
        $cod->setCode('cash_on_delivery');
        $card = new PaymentMethod();
        $card->setCode('simplepay_card');

        $template = '{{ gls_is_cash_on_delivery(method) ? "igen" : "nem" }}';
        self::assertSame('igen', $this->render($template, ['method' => $cod]));
        self::assertSame('nem', $this->render($template, ['method' => $card]));
        self::assertSame('nem', $this->render($template, ['method' => null]));
    }

    /** A widget országa a MyGLS-ország, kisbetűvel (a pontlista fájlneve is ez). */
    public function testTheMapWidgetSettings(): void
    {
        self::assertSame(
            'https://map.gls-hungary.com/widget/gls-dpm.js|hu',
            $this->render('{{ gls_map_widget_url() }}|{{ gls_map_country() }}'),
        );
    }

    /** @param array<string, mixed> $context */
    private function render(string $template, array $context = []): string
    {
        $json = json_encode(['items' => [DeliveryPointTest::SHOP, DeliveryPointTest::LOCKER]], \JSON_THROW_ON_ERROR);
        $catalog = new DeliveryPointCatalog(new MockHttpClient(new MockResponse($json)), new ArrayAdapter(), new RecordingLogger());
        $runtime = new GlsRuntime($catalog, new PickupPointCashCheck($catalog), new CodPolicy(), 'HU');

        $twig = new Environment(new ArrayLoader(['t' => $template]), ['strict_variables' => true]);
        $twig->addExtension(new GlsExtension());
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([GlsRuntime::class => static fn (): GlsRuntime => $runtime]));

        return $twig->render('t', $context);
    }
}
