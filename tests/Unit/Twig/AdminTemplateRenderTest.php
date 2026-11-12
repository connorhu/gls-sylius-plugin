<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Twig;

use CodeConjure\SyliusGlsPlugin\Address\StreetSplitter;
use CodeConjure\SyliusGlsPlugin\Api\ApiAccess;
use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Cod\PickupPointCashCheck;
use CodeConjure\SyliusGlsPlugin\Controller\GlsParcelController;
use CodeConjure\SyliusGlsPlugin\Entity\GlsParcel;
use CodeConjure\SyliusGlsPlugin\Entity\GlsParcelStatus;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Parcel\GlsParcelPayloadFactory;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use CodeConjure\SyliusGlsPlugin\Repository\GlsParcelRepository;
use CodeConjure\SyliusGlsPlugin\Tests\Support\FakeMyGls;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsShipment;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsShippingMethod;
use CodeConjure\SyliusGlsPlugin\Tests\Support\OrderFactory;
use CodeConjure\SyliusGlsPlugin\Tests\Support\RecordingLogger;
use CodeConjure\SyliusGlsPlugin\Twig\GlsExtension;
use CodeConjure\SyliusGlsPlugin\Twig\GlsRuntime;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\Translation\IdentityTranslator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFunction;

/**
 * Az admin feladás-panel és a grid-akciók (#257) `strict_variables` mellett,
 * a Sylius-függvények helyetteseivel. A `path()` helyettese a route-nevet és
 * a paramétereket írja ki — a nevek létezését a controller attribútumaihoz
 * méri a `testEveryRouteATemplateUsesExists`.
 */
final class AdminTemplateRenderTest extends TestCase
{
    private const string SHIP = '@CodeConjureSyliusGlsPlugin/admin/shipment/ship.html.twig';

    private ?GlsParcel $parcel = null;

    public function testWithoutAParcelTheApiModeOffersRegistration(): void
    {
        $page = $this->ship($this->shipment());

        self::assertSame('route:codeconjure_gls_admin_parcel_register{"shipmentId":7}', $page->filter('form')->attr('action'));
        self::assertSame(1, $page->filter('input[name="weight"]')->count());
        self::assertSame(1, $page->filter('input[name="_csrf_token"][value="token:gls_parcel"]')->count());
        self::assertSame(0, $page->filter('[data-test-gls-manual]')->count());
    }

    public function testARegisteredParcelOffersTheLabelAndTheCancel(): void
    {
        $shipment = $this->shipment();
        $this->parcel = $this->parcelOf($shipment, GlsParcelStatus::Registered);
        $page = $this->ship($shipment);

        self::assertSame('registered', $page->filter('[data-test-gls-status]')->attr('data-test-gls-status'));
        self::assertSame(1, $page->filter('a[href^="route:codeconjure_gls_admin_parcel_label"]')->count());
        self::assertSame(1, $page->filter('form[action^="route:codeconjure_gls_admin_parcel_cancel"]')->count());
        self::assertSame(0, $page->filter('form[action^="route:codeconjure_gls_admin_parcel_hand_over"]')->count(), 'Címke nélkül nincs átadás.');
    }

    public function testAPrintedLabelOffersTheHandOver(): void
    {
        $shipment = $this->shipment();
        $this->parcel = $this->parcelOf($shipment, GlsParcelStatus::LabelReady);

        self::assertSame(1, $this->ship($shipment)->filter('form[action^="route:codeconjure_gls_admin_parcel_hand_over"]')->count());
    }

    public function testAFailedRegistrationShowsTheErrorAndOffersARetry(): void
    {
        $shipment = $this->shipment();
        $this->parcel = $this->parcelOf($shipment, GlsParcelStatus::RegistrationFailed);
        $this->parcel->recordError('[13] Parcel validation issue');
        $page = $this->ship($shipment);

        self::assertSame('[13] Parcel validation issue', $page->filter('[data-test-gls-error]')->text());
        self::assertStringStartsWith('route:codeconjure_gls_admin_parcel_register', (string) $page->filter('form')->attr('action'));
    }

    /** API nélkül (#253): a GLS felületére átviendő adatok és a nyomkövetési szám rögzítése. */
    public function testTheManualModeShowsTheDataToCopyAndTheTrackingForm(): void
    {
        $page = $this->ship($this->shipment(), apiEnabled: false);
        $data = $page->filter('[data-test-gls-manual]')->text();

        self::assertStringContainsString('000123', $data);
        self::assertStringContainsString('Anna Kiss', $data);
        self::assertStringContainsString('7621 Pécs Kossuth tér 5', $data);
        self::assertSame(1, $page->filter('[data-test-gls-tracking-number]')->count());
        self::assertStringStartsWith('route:codeconjure_gls_admin_parcel_manual_hand_over', (string) $page->filter('form')->attr('action'));
    }

    public function testTheManualModeShowsTheCodAmount(): void
    {
        $order = OrderFactory::order(GlsDeliveryKind::ParcelLocker, 'HU1012-PLOCKER001', paymentMethodCode: 'cash_on_delivery', total: 1_299_000);

        self::assertStringContainsString('12990 Ft', $this->ship($this->shipment($order), apiEnabled: false)->filter('[data-test-gls-manual]')->text());
    }

    public function testOtherCarriersAndShippedShipmentsGetNothing(): void
    {
        self::assertSame('', trim($this->render(new GlsShipment(new GlsShippingMethod('foxpost_parcel_locker')))));

        $shipped = $this->shipment();
        $shipped->setState(ShipmentInterface::STATE_SHIPPED);
        self::assertSame('', trim($this->render($shipped)));
    }

    public function testEveryRouteATemplateUsesExists(): void
    {
        $controller = (string) file_get_contents(\dirname(__DIR__, 3) . '/src/Controller/GlsParcelController.php');
        preg_match_all("/name: '([a-z_]+)', methods/", $controller, $matches);
        $routes = [...array_map(static fn (string $name): string => 'codeconjure_gls_admin_parcel_' . $name, $matches[1]), GlsParcelController::INDEX_ROUTE];

        foreach (glob(\dirname(__DIR__, 3) . '/templates/admin/*/*.twig') ?: [] as $template) {
            preg_match_all("/path\\('([a-z_]+)'/", (string) file_get_contents($template), $used);

            foreach ($used[1] as $route) {
                self::assertContains($route, $routes, basename($template));
            }
        }
    }

    private function ship(GlsShipment $shipment, bool $apiEnabled = true): Crawler
    {
        return new Crawler($this->render($shipment, $apiEnabled));
    }

    private function render(GlsShipment $shipment, bool $apiEnabled = true): string
    {
        return $this->twig($apiEnabled)->render(self::SHIP, ['hookable_metadata' => ['context' => ['resource' => $shipment, 'attributes' => '']]]);
    }

    private function shipment(?\Sylius\Component\Core\Model\Order $order = null): GlsShipment
    {
        $order ??= OrderFactory::order(GlsDeliveryKind::HomeDelivery);
        $order->setNumber('000123');
        $shipment = $order->getShipments()->first();
        self::assertInstanceOf(GlsShipment::class, $shipment);
        $shipment->setState(ShipmentInterface::STATE_READY);
        (new \ReflectionProperty(\Sylius\Component\Shipping\Model\Shipment::class, 'id'))->setValue($shipment, 7);

        return $shipment;
    }

    private function parcelOf(GlsShipment $shipment, GlsParcelStatus $status): GlsParcel
    {
        $parcel = new GlsParcel($shipment, '000123', GlsDeliveryKind::HomeDelivery);
        $parcel->setInternalStatus($status);
        $parcel->setParcelId(42);
        (new \ReflectionProperty(GlsParcel::class, 'id'))->setValue($parcel, 3);

        return $parcel;
    }

    private function twig(bool $apiEnabled): Environment
    {
        $catalog = new DeliveryPointCatalog(new MockHttpClient(), new ArrayAdapter(), new RecordingLogger());
        $factory = new Psr17Factory();
        $access = $apiEnabled
            ? (new FakeMyGls())->access()
            : new ApiAccess(new Psr18Client(new MockHttpClient(), $factory, $factory), $factory, $factory, null, null, null, null, 'HU', 'Sylius');
        $repository = $this->createStub(GlsParcelRepository::class);
        $repository->method('findOneByShipment')->willReturnCallback(fn (): ?GlsParcel => $this->parcel);
        $payload = new GlsParcelPayloadFactory(new StreetSplitter(), $catalog, new CodPolicy(), [], false, false);
        $runtime = new GlsRuntime($catalog, new PickupPointCashCheck($catalog), new CodPolicy(), 'HU', $access, $repository, $payload);

        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 3) . '/templates', 'CodeConjureSyliusGlsPlugin');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new GlsExtension());
        $twig->addExtension(new TranslationExtension(new IdentityTranslator()));
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([GlsRuntime::class => static fn (): GlsRuntime => $runtime]));
        $twig->addFunction(new TwigFunction('path', static fn (string $name, array $parameters = []): string => 'route:' . $name . json_encode($parameters, \JSON_THROW_ON_ERROR)));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'token:' . $id));
        $twig->addFunction(new TwigFunction('ux_icon', static fn (): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('sylius_test_html_attribute', static fn (string $name): string => 'data-test-' . $name, ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('sylius_sm_can', static fn (object $subject, string $graph, string $transition): bool => $subject instanceof ShipmentInterface && $subject->getState() === ShipmentInterface::STATE_READY));

        return $twig;
    }
}
