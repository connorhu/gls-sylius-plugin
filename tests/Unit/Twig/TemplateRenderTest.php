<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Twig;

use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Cod\PickupPointCashCheck;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsShipment;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsShippingMethod;
use CodeConjure\SyliusGlsPlugin\Tests\Support\OrderFactory;
use CodeConjure\SyliusGlsPlugin\Tests\Support\RecordingLogger;
use CodeConjure\SyliusGlsPlugin\Tests\Unit\Pickup\DeliveryPointTest;
use CodeConjure\SyliusGlsPlugin\Twig\GlsExtension;
use CodeConjure\SyliusGlsPlugin\Twig\GlsRuntime;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\PaymentMethod;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\IdentityTranslator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * A plugin három sablonja `strict_variables` mellett, a host-kontextus
 * helyettesével (a `hookable_metadata.context` tömbként; a FormView helyén
 * a `vars.value`-t hordozó tömb). A host valódi formjával a befogadó bolt
 * tesztjei mérnek.
 */
final class TemplateRenderTest extends TestCase
{
    private const string WIDGET = '@CodeConjureSyliusGlsPlugin/shop/checkout/select_shipping/pickup_point_widget.html.twig';

    private const string SUMMARY = '@CodeConjureSyliusGlsPlugin/shop/order/summary_pickup_point.html.twig';

    private const string COD_NOTICE = '@CodeConjureSyliusGlsPlugin/shop/checkout/select_payment/cod_notice.html.twig';

    public function testTheWidgetHasOneBlockPerPickupKindWithTheMapFilteredToIt(): void
    {
        $page = $this->widget(null);

        self::assertSame(['gls_parcel_shop', 'gls_parcel_locker'], $page->filter('[data-carrier-delivery-kinds]')->each(static fn (Crawler $n): string => (string) $n->attr('data-carrier-delivery-kinds')));
        self::assertSame(['parcel-shop', 'parcel-locker'], $page->filter('gls-dpm-dialog')->each(static fn (Crawler $n): string => (string) $n->attr('filter-type')));

        foreach ($page->filter('gls-dpm-dialog') as $dialog) {
            \assert($dialog instanceof \DOMElement);
            // A típus-szűrő NINCS a felületen: a vevő ne kapcsolhassa vissza a másik fajtát.
            self::assertNotContains('type', explode(',', $dialog->getAttribute('ui-filters')));
            self::assertSame('hu', $dialog->getAttribute('country'));
            self::assertSame('change->gls-pickup-point#select', $dialog->getAttribute('data-action'));
        }
    }

    public function testWithoutAStoredPointNothingIsShownAndTheButtonsSaySelect(): void
    {
        $page = $this->widget(null);

        self::assertSame(2, $page->filter('[data-gls-pickup-point-target="display"][hidden]')->count());
        self::assertSame(['codeconjure.gls.pickup_point.shop.select', 'codeconjure.gls.pickup_point.locker.select'], $page->filter('button')->each(static fn (Crawler $n): string => $n->text()));
    }

    /** A tárolt pont csak a SAJÁT fajtája blokkjában látszik. */
    public function testAStoredLockerShowsInTheLockerBlockOnly(): void
    {
        $page = $this->widget('HU1012-PLOCKER001');
        $locker = $page->filter('[data-carrier-delivery-kinds="gls_parcel_locker"]');
        $shop = $page->filter('[data-carrier-delivery-kinds="gls_parcel_shop"]');

        self::assertNull($locker->filter('[data-gls-pickup-point-target="display"]')->attr('hidden'));
        self::assertSame('GLS Automata SHELL Budapest I. Ker.', $locker->filter('[data-gls-pickup-point-target="pointName"]')->text());
        self::assertSame('HU1012-PLOCKER001', $locker->attr('data-gls-pickup-point-point-id-value'));
        self::assertNotNull($shop->filter('[data-gls-pickup-point-target="display"]')->attr('hidden'));
        self::assertSame('', $shop->attr('data-gls-pickup-point-point-id-value'));
    }

    /** Utánvét: az automata-blokk mindig figyelmeztet, a csomagpont-blokk a pont szerint. */
    public function testTheCodNoticeInTheWidget(): void
    {
        $locker = $this->widget(null)->filter('[data-carrier-delivery-kinds="gls_parcel_locker"]');
        self::assertStringContainsString('codeconjure.gls.pickup_point.cod_card_only', $locker->text());

        $cashShop = $this->widget('1027-CSOMAGPONT05')->filter('[data-gls-pickup-point-target="cashNotice"]');
        self::assertNotNull($cashShop->attr('hidden'));

        $cardOnlyShop = $this->widget('2000-KARTYAS')->filter('[data-gls-pickup-point-target="cashNotice"]');
        self::assertNull($cardOnlyShop->attr('hidden'));
    }

    /** Elérhetetlen pontlistánál a tárolt azonosító mindkét blokkban látszik — a fajtát nem tudjuk. */
    public function testAnUnavailableListShowsTheStoredId(): void
    {
        $page = $this->widget('HU1012-PLOCKER001', available: false);

        self::assertSame(['HU1012-PLOCKER001', 'HU1012-PLOCKER001'], $page->filter('[data-gls-pickup-point-target="pointName"]')->each(static fn (Crawler $n): string => $n->text()));
    }

    /** A host elhagyhatja a mezőt: a választó akkor nem jelenik meg, és nem dob. */
    public function testWithoutThePickupPointFieldTheWidgetIsEmpty(): void
    {
        self::assertSame('', trim($this->twig()->render(self::WIDGET, ['hookable_metadata' => ['context' => ['form' => []]]])));
    }

    public function testTheSummaryShowsTheNameAndAddress(): void
    {
        $html = $this->summary(new GlsShipment(new GlsShippingMethod('gls_parcel_locker'), 'HU1012-PLOCKER001'));

        self::assertStringContainsString('codeconjure.gls.pickup_point.locker.title', $html);
        self::assertStringContainsString('GLS Automata SHELL Budapest I. Ker.', $html);
        self::assertStringContainsString('1012 BUDAPEST I. KER., Alkotás utca 20-24.', $html);
    }

    public function testTheSummaryFallsBackToTheIdWithoutTheList(): void
    {
        $html = $this->summary(new GlsShipment(new GlsShippingMethod('gls_parcel_shop'), '1027-CSOMAGPONT05'), available: false);

        self::assertStringContainsString('codeconjure.gls.pickup_point.shop.title', $html);
        self::assertStringContainsString('1027-CSOMAGPONT05', $html);
    }

    /** Más szállító pontja ugyanerre a hookra ül — nem a GLS dolga. */
    public function testTheSummaryIgnoresOtherCarriers(): void
    {
        self::assertSame('', trim($this->summary(new GlsShipment(new GlsShippingMethod('foxpost_parcel_locker'), 'HU1234'))));
        self::assertSame('', trim($this->summary(new GlsShipment(new GlsShippingMethod('gls_parcel_locker'), null))));
    }

    public function testTheCodNoticeOnThePaymentCard(): void
    {
        $locker = OrderFactory::order(GlsDeliveryKind::ParcelLocker, 'HU1012-PLOCKER001');
        $cashShop = OrderFactory::order(GlsDeliveryKind::ParcelShop, '1027-CSOMAGPONT05');

        self::assertStringContainsString('codeconjure.gls.pickup_point.cod_card_only', $this->codNotice('cash_on_delivery', $locker));
        self::assertSame('', trim($this->codNotice('simplepay_card', $locker)));
        self::assertSame('', trim($this->codNotice('cash_on_delivery', $cashShop)));
        self::assertSame('', trim($this->codNotice('cash_on_delivery', null)));
    }

    private function widget(?string $storedId, bool $available = true): Crawler
    {
        $html = $this->twig($available)->render(self::WIDGET, [
            'hookable_metadata' => ['context' => ['form' => ['pickupPointId' => ['vars' => ['value' => $storedId]]]]],
        ]);

        return new Crawler($html);
    }

    private function summary(GlsShipment $shipment, bool $available = true): string
    {
        return $this->twig($available)->render(self::SUMMARY, ['hookable_metadata' => ['context' => ['shipment' => $shipment]]]);
    }

    private function codNotice(string $code, ?object $order): string
    {
        $method = new PaymentMethod();
        $method->setCode($code);
        $context = ['method' => $method];

        if ($order !== null) {
            $context['order'] = $order;
        }

        return $this->twig()->render(self::COD_NOTICE, ['hookable_metadata' => ['context' => $context]]);
    }

    private function twig(bool $available = true): Environment
    {
        $cardOnlyShop = [...DeliveryPointTest::SHOP, 'id' => '2000-KARTYAS', 'features' => ['acceptsCard', 'pickup']];
        $response = $available
            ? new MockResponse(json_encode(['items' => [DeliveryPointTest::SHOP, DeliveryPointTest::LOCKER, $cardOnlyShop]], \JSON_THROW_ON_ERROR))
            : new MockResponse('', ['http_code' => 503]);
        $catalog = new DeliveryPointCatalog(new MockHttpClient($response), new ArrayAdapter(), new RecordingLogger());
        $runtime = new GlsRuntime($catalog, new PickupPointCashCheck($catalog), new CodPolicy(), 'HU');

        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 3) . '/templates', 'CodeConjureSyliusGlsPlugin');

        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new GlsExtension());
        $twig->addExtension(new TranslationExtension(new IdentityTranslator()));
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([GlsRuntime::class => static fn (): GlsRuntime => $runtime]));

        return $twig;
    }
}
