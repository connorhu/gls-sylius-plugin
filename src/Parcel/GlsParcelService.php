<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Parcel;

use CodeConjure\Gls\Exception\GlsApiException;
use CodeConjure\Gls\LabelFormat;
use CodeConjure\Gls\Response\ErrorInfo;
use CodeConjure\SyliusGlsPlugin\Api\ApiAccess;
use CodeConjure\SyliusGlsPlugin\Eligibility\EligibilityChecker;
use CodeConjure\SyliusGlsPlugin\Entity\GlsParcel;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Model\GlsShipmentInterface;
use CodeConjure\SyliusGlsPlugin\Repository\GlsParcelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Shipping\ShipmentTransitions;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * A GLS csomag életciklusa az adminból (#257).
 *
 * - `register()`: `PrepareLabels` → GLS `ParcelId` (API-mód);
 * - `labels()`: `GetPrintedLabels` → címke-PDF és nyomkövetési szám;
 * - `cancel()`: `DeleteLabels`;
 * - `handOver()`: a csomag átadva a GLS-nek, a Sylius szállítmány `ship`;
 * - `manualHandOver()`: API nélkül (#253) — a GLS felületén feladott csomag
 *   kézzel rögzített nyomkövetési számával.
 *
 * A MyGLS HTTP 200 mellett is adhat hibalistát: ez is a csomag hibája lesz
 * (`registration_failed`), újrapróbálható állapotban.
 */
final readonly class GlsParcelService
{
    public function __construct(
        private ApiAccess $apiAccess,
        private GlsParcelPayloadFactory $payloadFactory,
        private EligibilityChecker $eligibilityChecker,
        private GlsParcelRepository $repository,
        private EntityManagerInterface $entityManager,
        private WorkflowInterface $workflow,
        private StateMachineInterface $syliusStateMachine,
        private string $labelFormat,
    ) {
    }

    public function register(ShipmentInterface&GlsShipmentInterface $shipment, ?float $weight = null, int $count = 1): GlsParcel
    {
        if (!$this->apiAccess->isEnabled()) {
            throw new GlsParcelOperationException('A MyGLS API nincs beállítva — a csomagot a GLS felületén kell feladni, és a nyomkövetési számot kézzel rögzíteni.');
        }

        $this->assertEligible($shipment);
        $parcel = $this->parcelFor($shipment);

        if (!$parcel->getInternalStatus()->isRegistrable()) {
            throw new GlsParcelOperationException(\sprintf('A csomag már fel van adva (állapot: %s). Előbb sztornózd.', $parcel->getInternalStatus()->value));
        }

        $this->payloadFactory->snapshot($parcel, $shipment, $weight, $count);

        try {
            $client = $this->apiAccess->client();
            $result = $client->prepareLabels([$this->payloadFactory->build($parcel, $client->credentials()->clientNumber)]);
        } catch (GlsApiException|\InvalidArgumentException|\LogicException $e) {
            $this->fail($parcel, $e->getMessage());
        }

        $info = $result->parcels[0] ?? null;

        if ($result->hasErrors() || $info === null) {
            $this->fail($parcel, self::describe($result->errors) ?: 'A MyGLS nem adott csomagazonosítót.');
        }

        $parcel->setParcelId($info->parcelId);
        $parcel->setRegisteredAt(new \DateTimeImmutable());
        $parcel->recordError(null);
        $this->workflow->apply($parcel, 'register');
        $this->entityManager->flush();

        return $parcel;
    }

    /**
     * A címke-PDF (max. 99 csomag egy kérésben). Az első nyomtatás adja a
     * nyomkövetési számot; később ugyanez a hívás újranyomtat.
     *
     * @param list<GlsParcel> $parcels
     */
    public function labels(array $parcels, int $printPosition = 1): string
    {
        $byId = [];

        foreach ($parcels as $parcel) {
            if ($parcel->getParcelId() !== null && $parcel->getInternalStatus()->hasLabel()) {
                $byId[$parcel->getParcelId()] = $parcel;
            }
        }

        if ($byId === []) {
            throw new GlsParcelOperationException('A kijelölt csomagok közül egyiknek sincs GLS-címkéje (még nincs feladva, vagy kézzel adták fel).');
        }

        try {
            $result = $this->apiAccess->client()->getPrintedLabels(array_keys($byId), LabelFormat::from($this->labelFormat), $printPosition);
        } catch (GlsApiException|\InvalidArgumentException|\LogicException $e) {
            throw new GlsParcelOperationException('A GLS címke nem készült el: ' . $e->getMessage(), previous: $e);
        }

        if ($result->pdf === null || $result->pdf === '') {
            throw new GlsParcelOperationException('A GLS címke nem készült el: ' . (self::describe($result->errors) ?: 'üres válasz.'));
        }

        $now = new \DateTimeImmutable();

        foreach ($result->printData as $data) {
            $parcel = $byId[$data->parcelId] ?? null;

            if ($parcel === null) {
                continue;
            }

            if ($data->parcelNumber !== null) {
                $parcel->setParcelNumber((string) $data->parcelNumber);
                $parcel->getShipment()->setTracking((string) $data->parcelNumber);
            }

            $parcel->setLabelGeneratedAt($now);
            $parcel->touch();

            if ($this->workflow->can($parcel, 'print_label')) {
                $this->workflow->apply($parcel, 'print_label');
            }
        }

        $this->entityManager->flush();

        return $result->pdf;
    }

    public function cancel(GlsParcel $parcel): void
    {
        if (!$parcel->getInternalStatus()->isCancellable() || $parcel->getParcelId() === null) {
            throw new GlsParcelOperationException(\sprintf('A csomag ebben az állapotban nem sztornózható (%s).', $parcel->getInternalStatus()->value));
        }

        try {
            $result = $this->apiAccess->client()->deleteLabels([$parcel->getParcelId()]);
        } catch (GlsApiException|\InvalidArgumentException|\LogicException $e) {
            $parcel->recordError($e->getMessage());
            $this->entityManager->flush();

            throw new GlsParcelOperationException('A GLS sztornó nem sikerült: ' . $e->getMessage(), previous: $e);
        }

        $deleted = array_map(static fn ($deleted): int => $deleted->parcelId, $result->deleted);

        if (!\in_array($parcel->getParcelId(), $deleted, true)) {
            $message = self::describe($result->errors) ?: 'a MyGLS nem igazolta a törlést.';
            $parcel->recordError($message);
            $this->entityManager->flush();

            throw new GlsParcelOperationException('A GLS sztornó nem sikerült: ' . $message);
        }

        $parcel->setParcelId(null);
        $parcel->setParcelNumber(null);
        $parcel->getShipment()->setTracking(null);
        $parcel->recordError(null);
        $this->workflow->apply($parcel, 'cancel');
        $this->entityManager->flush();
    }

    public function handOver(GlsParcel $parcel): void
    {
        if (!$this->workflow->can($parcel, 'hand_over')) {
            throw new GlsParcelOperationException('Átadni csak kinyomtatott címkéjű csomagot lehet.');
        }

        $this->workflow->apply($parcel, 'hand_over');
        $parcel->setHandedOverAt(new \DateTimeImmutable());
        $parcel->touch();
        $this->ship($parcel->getShipment());
        $this->entityManager->flush();
    }

    public function manualHandOver(ShipmentInterface&GlsShipmentInterface $shipment, string $trackingNumber, ?float $weight = null, int $count = 1): GlsParcel
    {
        $trackingNumber = trim($trackingNumber);

        if ($trackingNumber === '') {
            throw new GlsParcelOperationException('A GLS nyomkövetési száma kötelező.');
        }

        $parcel = $this->parcelFor($shipment);

        if (!$this->workflow->can($parcel, 'manual_hand_over')) {
            throw new GlsParcelOperationException(\sprintf('A csomag már fel van adva (állapot: %s).', $parcel->getInternalStatus()->value));
        }

        $this->payloadFactory->snapshot($parcel, $shipment, $weight, $count);
        $parcel->setManual(true);
        $parcel->setParcelNumber($trackingNumber);
        $parcel->setHandedOverAt(new \DateTimeImmutable());
        $shipment->setTracking($trackingNumber);
        $this->workflow->apply($parcel, 'manual_hand_over');
        $this->ship($shipment);
        $this->entityManager->flush();

        return $parcel;
    }

    private function assertEligible(ShipmentInterface&GlsShipmentInterface $shipment): void
    {
        $result = $this->eligibilityChecker->check($shipment);

        if (!$result->eligible) {
            throw new GlsParcelOperationException(implode(' ', array_map(static fn (array $error): string => $error['message'], $result->errors)));
        }
    }

    private function parcelFor(ShipmentInterface&GlsShipmentInterface $shipment): GlsParcel
    {
        $parcel = $this->repository->findOneByShipment($shipment);

        if ($parcel !== null) {
            $parcel->incrementRetryCount();

            return $parcel;
        }

        $kind = GlsDeliveryKind::fromShipment($shipment) ?? throw new GlsParcelOperationException('A szállítmány nem GLS-szállítmány.');
        $order = $shipment->getOrder();
        $parcel = new GlsParcel($shipment, $order instanceof OrderInterface ? (string) $order->getNumber() : '', $kind);
        $this->entityManager->persist($parcel);

        return $parcel;
    }

    private function fail(GlsParcel $parcel, string $message): never
    {
        $parcel->recordError($message);

        if ($this->workflow->can($parcel, 'register_fail')) {
            $this->workflow->apply($parcel, 'register_fail');
        }

        $this->entityManager->flush();

        throw new GlsParcelOperationException('A GLS feladás nem sikerült: ' . $message);
    }

    private function ship(ShipmentInterface $shipment): void
    {
        if ($this->syliusStateMachine->can($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP)) {
            $this->syliusStateMachine->apply($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP);
        }
    }

    /** @param list<ErrorInfo> $errors */
    private static function describe(array $errors): string
    {
        return implode(' ', array_map(static fn (ErrorInfo $error): string => \sprintf('[%d] %s', $error->code, $error->description), $errors));
    }
}
