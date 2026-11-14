<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Controller;

use CodeConjure\SyliusGlsPlugin\Address\StreetSplitter;
use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Controller\GlsParcelController;
use CodeConjure\SyliusGlsPlugin\Eligibility\EligibilityChecker;
use CodeConjure\SyliusGlsPlugin\Entity\GlsParcel;
use CodeConjure\SyliusGlsPlugin\Entity\GlsParcelStatus;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Parcel\GlsParcelPayloadFactory;
use CodeConjure\SyliusGlsPlugin\Parcel\GlsParcelService;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use CodeConjure\SyliusGlsPlugin\Repository\GlsParcelRepository;
use CodeConjure\SyliusGlsPlugin\Tests\Support\FakeMyGls;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsParcelWorkflow;
use CodeConjure\SyliusGlsPlugin\Tests\Support\GlsShipment;
use CodeConjure\SyliusGlsPlugin\Tests\Support\OrderFactory;
use CodeConjure\SyliusGlsPlugin\Tests\Support\RecordingLogger;
use CodeConjure\SyliusGlsPlugin\Tests\Support\RecordingStateMachine;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Az admin műveletek (#257) kernel nélkül: a controller a valódi
 * szolgáltatással, hamis MyGLS-szel és a valódi állapotgéppel.
 */
final class GlsParcelControllerTest extends TestCase
{
    private const string REFERER = 'https://bolt.hu/admin/orders/1';

    private FakeMyGls $gls;

    /** @var array<int, GlsParcel> */
    private array $parcels = [];

    private ?GlsParcel $stored = null;

    private GlsShipment $shipment;

    protected function setUp(): void
    {
        $this->gls = new FakeMyGls();
        $order = OrderFactory::order(GlsDeliveryKind::HomeDelivery);
        $order->setNumber('000123');
        $shipment = $order->getShipments()->first();
        self::assertInstanceOf(GlsShipment::class, $shipment);
        $shipment->setState(ShipmentInterface::STATE_READY);
        $this->shipment = $shipment;
    }

    public function testAWrongCsrfTokenChangesNothing(): void
    {
        [$response, $session] = $this->call('register', ['_csrf_token' => 'rossz'], 7);

        self::assertSame(self::REFERER, $response->headers->get('Location'));
        self::assertSame([], $this->gls->calls);
        self::assertStringContainsString('token', $session->getFlashBag()->get('error')[0] ?? '');
    }

    public function testRegisteringRedirectsBackWithTheResult(): void
    {
        $this->gls->queue(['ParcelInfoList' => [['ClientReference' => '000123', 'ParcelId' => 42]], 'PrepareLabelsError' => []]);

        [, $session] = $this->call('register', ['_csrf_token' => 'jo', 'weight' => '1,5', 'count' => '2'], 7);

        self::assertStringContainsString('42', $session->getFlashBag()->get('success')[0] ?? '');
        self::assertNotNull($this->stored);
        self::assertSame(1.5, $this->stored->getWeight());
        self::assertSame(2, $this->stored->getCount());
    }

    public function testAnUnknownShipmentIsAnError(): void
    {
        [, $session] = $this->call('register', ['_csrf_token' => 'jo'], 999);

        self::assertSame(['A szállítmány nem található.'], $session->getFlashBag()->get('error'));
    }

    public function testTheLabelIsAPdfDownload(): void
    {
        $parcel = $this->parcel(1, GlsParcelStatus::Registered, 42);
        $this->gls->queue(['Labels' => array_values(unpack('C*', '%PDF') ?: []), 'PrintDataInfoList' => [['ParcelId' => 42, 'ParcelNumber' => 5001]], 'GetPrintedLabelsErrorList' => []]);

        $response = $this->controller()->label(Request::create('/x', 'GET', ['print_position' => '3']), 1);

        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('%PDF', $response->getContent());
        self::assertSame(3, $this->gls->value(0, 'PrintPosition'));
        self::assertSame('5001', $parcel->getParcelNumber());
    }

    /** A tömeges címke a kezdőpozíciót az űrlap-törzsből kapja (#263). */
    public function testTheBulkLabelStartsAtTheChosenPosition(): void
    {
        $this->parcel(1, GlsParcelStatus::Registered, 42);
        $this->parcel(2, GlsParcelStatus::Registered, 43);
        $this->gls->queue(['Labels' => array_values(unpack('C*', '%PDF') ?: []), 'PrintDataInfoList' => [], 'GetPrintedLabelsErrorList' => []]);

        $response = $this->controller()->bulkLabel(Request::create('/x', 'POST', ['_csrf_token' => 'jo', 'ids' => ['1', '2'], 'print_position' => '2']));

        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame(2, $this->gls->value(0, 'PrintPosition'));
    }

    /**
     * A tömeges sztornó a kijelölt SOROK (csomagok) azonosítóit kapja; a nem
     * sztornózható csomag kihagyott, nem hiba, és nem megy rá API-hívás.
     */
    public function testBulkCancelSkipsWhatCannotBeCancelled(): void
    {
        $this->parcel(1, GlsParcelStatus::Registered, 42);
        $this->parcel(2, GlsParcelStatus::HandedOver, 43);
        $this->gls->queue(['SuccessfullyDeletedList' => [['ParcelId' => 42, 'SubParcelIdList' => []]], 'DeleteLabelsErrorList' => []]);

        [, $session] = $this->call('bulkCancel', ['_csrf_token' => 'jo', 'ids' => ['1', '2', 'x']]);

        self::assertSame(['Tömeges sztornó: 1 sztornózva, 1 kihagyva, 0 hibás.'], $session->getFlashBag()->get('success'));
        self::assertCount(1, $this->gls->calls);
        self::assertSame(GlsParcelStatus::Cancelled, $this->parcels[1]->getInternalStatus());
    }

    public function testWithoutARefererItGoesToTheParcelList(): void
    {
        $request = Request::create('/x', 'POST', ['_csrf_token' => 'rossz']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $response = $this->controller()->cancel($request, 1);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/gls-parcels', $response->getTargetUrl());
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{RedirectResponse, Session}
     */
    private function call(string $action, array $body, ?int $id = null): array
    {
        $request = Request::create('/x', 'POST', $body);
        $request->headers->set('referer', self::REFERER);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $controller = $this->controller();
        $response = $id === null ? $controller->{$action}($request) : $controller->{$action}($request, $id);
        self::assertInstanceOf(RedirectResponse::class, $response);

        return [$response, $session];
    }

    private function parcel(int $id, GlsParcelStatus $status, int $parcelId): GlsParcel
    {
        $parcel = new GlsParcel($this->shipment, '000123', GlsDeliveryKind::HomeDelivery);
        $parcel->setInternalStatus($status);
        $parcel->setParcelId($parcelId);
        (new \ReflectionProperty(GlsParcel::class, 'id'))->setValue($parcel, $id);

        return $this->parcels[$id] = $parcel;
    }

    private function controller(): GlsParcelController
    {
        $repository = $this->createStub(GlsParcelRepository::class);
        $repository->method('find')->willReturnCallback(fn (mixed $id): ?GlsParcel => \is_int($id) ? ($this->parcels[$id] ?? null) : null);
        $repository->method('findByIds')->willReturnCallback(fn (array $ids): array => array_values(array_filter(array_map(fn (int $id): ?GlsParcel => $this->parcels[$id] ?? null, $ids))));
        $repository->method('findOneByShipment')->willReturnCallback(fn (): ?GlsParcel => $this->stored);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(fn (string $class, mixed $id): ?object => $id === 7 ? $this->shipment : null);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof GlsParcel) {
                $this->stored = $entity;
            }
        });

        $catalog = new DeliveryPointCatalog(new MockHttpClient(), new ArrayAdapter(), new RecordingLogger());
        $pickup = ['name' => 'Bolt Kft.', 'street' => 'Fő utca', 'house_number' => '1', 'city' => 'Budapest', 'zip_code' => '1011', 'country_iso_code' => 'HU'];
        $service = new GlsParcelService(
            $this->gls->access(),
            new GlsParcelPayloadFactory(new StreetSplitter(), $catalog, new CodPolicy(), $pickup, false, false),
            new EligibilityChecker(new CodPolicy()),
            $repository,
            $em,
            GlsParcelWorkflow::create(),
            new RecordingStateMachine(),
            'A4_2x2',
        );

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/admin/gls-parcels');
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $token->getId() === GlsParcelController::CSRF_ID && $token->getValue() === 'jo');

        return new GlsParcelController($service, $repository, $em, $urls, $csrf);
    }
}
