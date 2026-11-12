<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Parcel;

use CodeConjure\SyliusGlsPlugin\Address\StreetSplitter;
use CodeConjure\SyliusGlsPlugin\Api\ApiAccess;
use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Eligibility\EligibilityChecker;
use CodeConjure\SyliusGlsPlugin\Entity\GlsParcel;
use CodeConjure\SyliusGlsPlugin\Entity\GlsParcelStatus;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Parcel\GlsParcelOperationException;
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
use CodeConjure\SyliusGlsPlugin\Tests\Unit\Pickup\DeliveryPointTest;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A feladás, a címke, a sztornó és az átadás (#257) a valódi `gls_parcel`
 * állapotgéppel és egy hamis MyGLS-szel.
 */
final class GlsParcelServiceTest extends TestCase
{
    private FakeMyGls $gls;

    private RecordingStateMachine $sylius;

    private ?GlsParcel $stored = null;

    protected function setUp(): void
    {
        $this->gls = new FakeMyGls();
        $this->sylius = new RecordingStateMachine();
    }

    public function testRegisteringStoresTheGlsParcelId(): void
    {
        $this->gls->queue(['ParcelInfoList' => [['ClientReference' => '000123', 'ParcelId' => 987654]], 'PrepareLabelsError' => []]);

        $parcel = $this->service()->register($this->shipment());

        self::assertSame(GlsParcelStatus::Registered, $parcel->getInternalStatus());
        self::assertSame(987654, $parcel->getParcelId());
        self::assertNull($parcel->getParcelNumber(), 'A nyomkövetési szám csak a címkével születik.');
        self::assertSame(['ParcelService.svc/json/PrepareLabels_20190201'], array_map(static fn (string $m): string => 'ParcelService.svc/json/' . $m, $this->gls->methods()));
        self::assertSame('000123', $this->gls->value(0, 'ParcelList', 0, 'ClientReference'));
    }

    /** A MyGLS részleges hibája (HTTP 200 mellett) a csomag hibája lesz, újrapróbálható állapotban. */
    public function testAGlsErrorMarksTheParcelAsFailed(): void
    {
        $this->gls->queue(['ParcelInfoList' => [], 'PrepareLabelsError' => [['ClientReferenceList' => ['000123'], 'ErrorCode' => 13, 'ErrorDescription' => 'Parcel validation issue', 'ParcelIdList' => []]]]);

        try {
            $this->service()->register($this->shipment());
            self::fail('Kivétel várt.');
        } catch (GlsParcelOperationException $e) {
            self::assertStringContainsString('Parcel validation issue', $e->getMessage());
        }

        self::assertNotNull($this->stored);
        self::assertSame(GlsParcelStatus::RegistrationFailed, $this->stored->getInternalStatus());
        self::assertStringContainsString('13', (string) $this->stored->getLastApiError());
    }

    public function testARetryAfterAFailureRegisters(): void
    {
        $this->gls->queue(['ParcelInfoList' => [], 'PrepareLabelsError' => [['ClientReferenceList' => ['000123'], 'ErrorCode' => 13, 'ErrorDescription' => 'x', 'ParcelIdList' => []]]]);
        $this->gls->queue(['ParcelInfoList' => [['ClientReference' => '000123', 'ParcelId' => 5]], 'PrepareLabelsError' => []]);
        $shipment = $this->shipment();

        try {
            $this->service()->register($shipment);
        } catch (GlsParcelOperationException) {
        }

        $parcel = $this->service()->register($shipment);

        self::assertSame(GlsParcelStatus::Registered, $parcel->getInternalStatus());
        self::assertSame(1, $parcel->getRetryCount());
        self::assertNull($parcel->getLastApiError());
    }

    /** A feladás előtti ellenőrzés hibája API-hívás nélkül állít meg. */
    public function testAnIneligibleShipmentIsRefusedWithoutAnApiCall(): void
    {
        $order = OrderFactory::order(GlsDeliveryKind::HomeDelivery, paymentState: OrderPaymentStates::STATE_AWAITING_PAYMENT);

        try {
            $this->service()->register($this->shipment($order));
            self::fail('Kivétel várt.');
        } catch (GlsParcelOperationException $e) {
            self::assertStringContainsString('nincs kifizetve', $e->getMessage());
        }

        self::assertSame([], $this->gls->calls);
    }

    public function testAnAlreadyRegisteredParcelIsNotRegisteredTwice(): void
    {
        $this->gls->queue(['ParcelInfoList' => [['ClientReference' => '000123', 'ParcelId' => 5]], 'PrepareLabelsError' => []]);
        $shipment = $this->shipment();
        $this->service()->register($shipment);

        $this->expectException(GlsParcelOperationException::class);
        $this->service()->register($shipment);
    }

    public function testTheLabelBringsTheTrackingNumber(): void
    {
        $parcel = $this->registered(5);
        $this->gls->queue(['Labels' => array_values(unpack('C*', '%PDF-1.4') ?: []), 'PrintDataInfoList' => [['ParcelId' => 5, 'ClientReference' => '000123', 'ParcelNumber' => 51234567890]], 'GetPrintedLabelsErrorList' => []]);

        $pdf = $this->service()->labels([$parcel]);

        self::assertSame('%PDF-1.4', $pdf);
        self::assertSame('51234567890', $parcel->getParcelNumber());
        self::assertSame(GlsParcelStatus::LabelReady, $parcel->getInternalStatus());
        self::assertSame('51234567890', $parcel->getShipment()->getTracking(), 'A Sylius szállítmány nyomkövetési száma is kitöltődik.');
        self::assertSame('A4_2x2', $this->gls->value(0, 'TypeOfPrinter'));
    }

    public function testParcelsWithoutAGlsIdHaveNoLabel(): void
    {
        $this->expectException(GlsParcelOperationException::class);

        $this->service()->labels([new GlsParcel($this->shipment(), '000123', GlsDeliveryKind::HomeDelivery)]);
    }

    public function testCancellingDeletesTheLabelAtGls(): void
    {
        $parcel = $this->registered(5);
        $this->gls->queue(['SuccessfullyDeletedList' => [['ParcelId' => 5, 'SubParcelIdList' => []]], 'DeleteLabelsErrorList' => []]);

        $this->service()->cancel($parcel);

        self::assertSame(GlsParcelStatus::Cancelled, $parcel->getInternalStatus());
        self::assertSame(5, $this->gls->value(0, 'ParcelIdList', 0));
    }

    public function testAFailedCancelKeepsTheParcel(): void
    {
        $parcel = $this->registered(5);
        $this->gls->queue(['SuccessfullyDeletedList' => [], 'DeleteLabelsErrorList' => [['ErrorCode' => 7, 'ErrorDescription' => 'Already picked up', 'ClientReferenceList' => [], 'ParcelIdList' => [5]]]]);

        try {
            $this->service()->cancel($parcel);
            self::fail('Kivétel várt.');
        } catch (GlsParcelOperationException $e) {
            self::assertStringContainsString('Already picked up', $e->getMessage());
        }

        self::assertSame(GlsParcelStatus::Registered, $parcel->getInternalStatus());
    }

    /** Az átadás a Sylius szállítmányt is „kiszállítva" állapotba viszi. */
    public function testHandingOverShipsTheSyliusShipment(): void
    {
        $parcel = $this->registered(5);
        $parcel->setInternalStatus(GlsParcelStatus::LabelReady);

        $this->service()->handOver($parcel);

        self::assertSame(GlsParcelStatus::HandedOver, $parcel->getInternalStatus());
        self::assertSame(['sylius_shipment:ship'], $this->sylius->applied);
        self::assertNotNull($parcel->getHandedOverAt());
    }

    /**
     * API nélkül (#253): a GLS felületén feladott csomag nyomkövetési száma
     * kézzel kerül be, és a szállítmány ettől „kiszállítva".
     */
    public function testAManualHandOverRecordsTheTrackingNumber(): void
    {
        $shipment = $this->shipment();

        $parcel = $this->service(manual: true)->manualHandOver($shipment, ' 51234567890 ', 1.2);

        self::assertTrue($parcel->isManual());
        self::assertSame('51234567890', $parcel->getParcelNumber());
        self::assertSame(GlsParcelStatus::HandedOver, $parcel->getInternalStatus());
        self::assertSame('51234567890', $shipment->getTracking());
        self::assertSame(['sylius_shipment:ship'], $this->sylius->applied);
        self::assertSame('Pécs', $parcel->getCity(), 'A kézi feladás is pillanatképet tesz el.');
        self::assertSame([], $this->gls->calls);
    }

    public function testAManualHandOverNeedsATrackingNumber(): void
    {
        $this->expectException(GlsParcelOperationException::class);

        $this->service(manual: true)->manualHandOver($this->shipment(), '  ');
    }

    /** API nélküli módban a feladás-hívás hangos hiba, nem csendes semmi. */
    public function testRegisteringWithoutTheApiIsRefused(): void
    {
        $this->expectException(GlsParcelOperationException::class);

        $this->service(manual: true)->register($this->shipment());
    }

    private function registered(int $parcelId): GlsParcel
    {
        $parcel = new GlsParcel($this->shipment(), '000123', GlsDeliveryKind::HomeDelivery);
        $parcel->setParcelId($parcelId);
        $parcel->setInternalStatus(GlsParcelStatus::Registered);

        return $parcel;
    }

    private function shipment(?\Sylius\Component\Core\Model\Order $order = null): GlsShipment
    {
        $order ??= OrderFactory::order(GlsDeliveryKind::HomeDelivery);
        $order->setNumber('000123');
        $shipment = $order->getShipments()->first();
        self::assertInstanceOf(GlsShipment::class, $shipment);
        $shipment->setState(ShipmentInterface::STATE_READY);

        return $shipment;
    }

    private function service(bool $manual = false): GlsParcelService
    {
        $json = json_encode(['items' => [DeliveryPointTest::SHOP, DeliveryPointTest::LOCKER]], \JSON_THROW_ON_ERROR);
        $catalog = new DeliveryPointCatalog(new MockHttpClient(new MockResponse($json)), new ArrayAdapter(), new RecordingLogger());
        $pickup = ['name' => 'Bolt Kft.', 'street' => 'Fő utca', 'house_number' => '1', 'city' => 'Budapest', 'zip_code' => '1011', 'country_iso_code' => 'HU'];
        $factory = new GlsParcelPayloadFactory(new StreetSplitter(), $catalog, new CodPolicy(), $pickup, false, false);

        $repository = $this->createStub(GlsParcelRepository::class);
        $repository->method('findOneByShipment')->willReturnCallback(fn (): ?GlsParcel => $this->stored);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof GlsParcel) {
                $this->stored = $entity;
            }
        });

        $access = $manual
            ? self::manualAccess()
            : $this->gls->access();

        return new GlsParcelService($access, $factory, new EligibilityChecker(new CodPolicy()), $repository, $em, GlsParcelWorkflow::create(), $this->sylius, 'A4_2x2');
    }

    private static function manualAccess(): ApiAccess
    {
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();

        return new ApiAccess(new \Symfony\Component\HttpClient\Psr18Client(new MockHttpClient(), $factory, $factory), $factory, $factory, null, null, null, null, 'HU', 'Sylius');
    }
}
