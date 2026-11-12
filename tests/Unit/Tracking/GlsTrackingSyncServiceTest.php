<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Tracking;

use CodeConjure\SyliusGlsPlugin\Address\StreetSplitter;
use CodeConjure\SyliusGlsPlugin\Api\ApiAccess;
use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
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
use CodeConjure\SyliusGlsPlugin\Tracking\GlsTrackingSyncService;
use CodeConjure\SyliusGlsPlugin\Tracking\GlsTrackingSyncSummary;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * A nyomkövetés-szinkron (#258) a valódi állapotgéppel és hamis MyGLS-szel.
 */
final class GlsTrackingSyncServiceTest extends TestCase
{
    private FakeMyGls $gls;

    private RecordingLogger $logger;

    private RecordingStateMachine $sylius;

    /** @var list<GlsParcel> */
    private array $parcels = [];

    protected function setUp(): void
    {
        $this->gls = new FakeMyGls();
        $this->logger = new RecordingLogger();
        $this->sylius = new RecordingStateMachine();
    }

    public function testADeliveredParcelIsMarkedDelivered(): void
    {
        $parcel = $this->parcel('51000000001', GlsParcelStatus::HandedOver);
        $this->gls->queue(self::response([51000000001 => [['1', '2026-11-10'], ['5', '2026-11-12'], ['4', '2026-11-11']]]));

        $summary = $this->sync();

        self::assertSame(GlsParcelStatus::Delivered, $parcel->getInternalStatus());
        self::assertSame('5', $parcel->getGlsStatus(), 'A LEGFRISSEBB státusz dönt, nem a lista sorrendje.');
        self::assertSame([1, 1, 1, 0, 0], [$summary->found, $summary->synced, $summary->transitions, $summary->skipped, $summary->failed]);
        self::assertSame('HU', $this->gls->value(0, 'LanguageIsoCode'));
    }

    /**
     * A GLS szkennelése bizonyítja az átadást: ha az admin elfelejtette az
     * „Átadva" gombot, a szinkron pótolja — a Sylius szállítmány is `ship`.
     */
    public function testAScanOfAPrintedParcelHandsItOver(): void
    {
        $parcel = $this->parcel('51000000001', GlsParcelStatus::LabelReady);
        $this->gls->queue(self::response([51000000001 => [['2', '2026-11-12']]]));

        $summary = $this->sync();

        self::assertSame(GlsParcelStatus::InTransit, $parcel->getInternalStatus());
        self::assertSame(['sylius_shipment:ship'], $this->sylius->applied);
        self::assertSame(1, $summary->transitions);
    }

    public function testDataOnlyAtGlsLeavesAPrintedParcelAlone(): void
    {
        $parcel = $this->parcel('51000000001', GlsParcelStatus::LabelReady);
        $this->gls->queue(self::response([51000000001 => [['51', '2026-11-12']]]));

        $summary = $this->sync();

        self::assertSame(GlsParcelStatus::LabelReady, $parcel->getInternalStatus());
        self::assertSame('51', $parcel->getGlsStatus());
        self::assertSame([], $this->sylius->applied);
        self::assertSame([1, 0], [$summary->synced, $summary->transitions]);
    }

    /** Az ismeretlen kód nem állítja meg a szinkront: eltéve, naplózva, átmenet nélkül. */
    public function testAnUnknownCodeIsStoredAndLogged(): void
    {
        $unknown = $this->parcel('51000000001', GlsParcelStatus::InTransit);
        $delivered = $this->parcel('51000000002', GlsParcelStatus::InTransit);
        $this->gls->queue(self::response([51000000001 => [['75', '2026-11-12']], 51000000002 => [['92', '2026-11-12']]]));

        $this->sync();

        self::assertSame(GlsParcelStatus::InTransit, $unknown->getInternalStatus());
        self::assertSame('75', $unknown->getGlsStatus());
        self::assertSame(GlsParcelStatus::Delivered, $delivered->getInternalStatus());
        self::assertTrue($this->logged('75'));
    }

    /** 101 csomag → két kérés (a MyGLS-határ 100). */
    public function testTheParcelsAreQueriedInBatchesOfAHundred(): void
    {
        $first = [];

        for ($i = 1; $i <= 101; ++$i) {
            $number = 51000000000 + $i;
            $this->parcel((string) $number, GlsParcelStatus::HandedOver);
            if ($i <= 100) {
                $first[$number] = [['2', '2026-11-12']];
            }
        }

        $this->gls->queue(self::response($first));
        $this->gls->queue(self::response([51000000101 => [['2', '2026-11-12']]]));

        $summary = $this->sync();

        self::assertCount(2, $this->gls->calls);
        self::assertCount(100, (array) $this->gls->value(0, 'ParcelNumberList'));
        self::assertSame([51000000101], $this->gls->value(1, 'ParcelNumberList'));
        self::assertSame([101, 101, 101], [$summary->found, $summary->synced, $summary->transitions]);
    }

    /** A válaszból hiányzó csomag hibás, a többi feldolgozva — a számlálók összege a találat. */
    public function testAMissingParcelIsAFailureAndTheRestIsProcessed(): void
    {
        $this->parcel('51000000001', GlsParcelStatus::HandedOver);
        $this->parcel('51000000002', GlsParcelStatus::HandedOver);
        $this->parcel('kézi-elírás', GlsParcelStatus::HandedOver);
        $this->gls->queue(self::response([51000000001 => [['2', '2026-11-12']]], [['ErrorCode' => 3, 'ErrorDescription' => 'Parcel not found', 'ParcelIdList' => [], 'ClientReferenceList' => []]]));

        $summary = $this->sync();

        self::assertSame([3, 1, 1, 1], [$summary->found, $summary->synced, $summary->failed, $summary->skipped]);
        self::assertSame($summary->found, $summary->synced + $summary->skipped + $summary->failed);
        self::assertSame([51000000001, 51000000002], $this->gls->value(0, 'ParcelNumberList'), 'A nem szám nyomkövetési szám nem megy a GLS-hez.');
    }

    public function testAFailedRequestCountsTheWholeBatchAsFailed(): void
    {
        $this->parcel('51000000001', GlsParcelStatus::HandedOver);
        $this->gls->queue(['ErrorCode' => -1, 'ErrorDescription' => 'Unauthorized']);

        $summary = $this->sync();

        self::assertSame([1, 0, 1], [$summary->found, $summary->synced, $summary->failed]);
        self::assertTrue($this->logged('Unauthorized'));
    }

    /** API nélkül nincs mit lekérdezni — a futás nem hiba, a csomagok kihagyottak. */
    public function testWithoutTheApiNothingIsQueried(): void
    {
        $this->parcel('51000000001', GlsParcelStatus::HandedOver);

        $summary = $this->sync(manual: true);

        self::assertTrue($summary->apiDisabled);
        self::assertSame([1, 1], [$summary->found, $summary->skipped]);
        self::assertSame([], $this->gls->calls);
    }

    /**
     * @param array<int, list<array{string, string}>> $parcels
     * @param list<array<string, mixed>> $errors
     *
     * @return array<string, mixed>
     */
    private static function response(array $parcels, array $errors = []): array
    {
        $list = [];

        foreach ($parcels as $number => $statuses) {
            $list[] = [
                'ParcelNumber' => $number,
                'ParcelStatusList' => array_map(static fn (array $status): array => [
                    'StatusCode' => $status[0],
                    'StatusDate' => '/Date(' . ((new \DateTimeImmutable($status[1] . ' 12:00:00 UTC'))->getTimestamp() * 1000) . ')/',
                    'StatusDescription' => 'leírás ' . $status[0],
                ], $statuses),
            ];
        }

        return ['ParcelList' => $list, 'GetParcelListStatusesErrors' => $errors];
    }

    private function parcel(string $number, GlsParcelStatus $status): GlsParcel
    {
        $order = OrderFactory::order(GlsDeliveryKind::HomeDelivery);
        $shipment = $order->getShipments()->first();
        self::assertInstanceOf(GlsShipment::class, $shipment);
        $shipment->setState(ShipmentInterface::STATE_READY);

        $parcel = new GlsParcel($shipment, '000123', GlsDeliveryKind::HomeDelivery);
        $parcel->setParcelNumber($number);
        $parcel->setInternalStatus($status);

        return $this->parcels[] = $parcel;
    }

    private function logged(string $needle): bool
    {
        foreach ($this->logger->records as $record) {
            if (str_contains($record['message'] . json_encode($record['context']), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function sync(bool $manual = false): GlsTrackingSyncSummary
    {
        $factory = new Psr17Factory();
        $access = $manual
            ? new ApiAccess(new Psr18Client(new MockHttpClient(), $factory, $factory), $factory, $factory, null, null, null, null, 'HU', 'Sylius')
            : $this->gls->access();

        $repository = $this->createStub(GlsParcelRepository::class);
        $repository->method('findForTracking')->willReturnCallback(fn (): array => $this->parcels);
        $em = $this->createStub(EntityManagerInterface::class);
        $workflow = GlsParcelWorkflow::create();

        $catalog = new DeliveryPointCatalog(new MockHttpClient(), new ArrayAdapter(), new RecordingLogger());
        $parcelService = new GlsParcelService(
            $access,
            new GlsParcelPayloadFactory(new StreetSplitter(), $catalog, new CodPolicy(), [], false, false),
            new EligibilityChecker(new CodPolicy()),
            $repository,
            $em,
            $workflow,
            $this->sylius,
            'A4_2x2',
        );

        return (new GlsTrackingSyncService($access, $repository, $em, $workflow, $parcelService, $this->logger))->syncActive();
    }
}
