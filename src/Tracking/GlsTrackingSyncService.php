<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tracking;

use CodeConjure\Gls\Client;
use CodeConjure\Gls\Response\ParcelStatus;
use CodeConjure\SyliusGlsPlugin\Api\ApiAccess;
use CodeConjure\SyliusGlsPlugin\Entity\GlsParcel;
use CodeConjure\SyliusGlsPlugin\Entity\GlsParcelStatus;
use CodeConjure\SyliusGlsPlugin\Parcel\GlsParcelOperationException;
use CodeConjure\SyliusGlsPlugin\Parcel\GlsParcelService;
use CodeConjure\SyliusGlsPlugin\Repository\GlsParcelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * A GLS csomagok nyomkövetés-szinkronja (#258): a nyitott csomagok
 * nyomkövetési számait 100-as kötegekben kérdezi le
 * (`GetParcelListStatuses`), és a LEGFRISSEBB státusz szerint lépteti őket.
 *
 * - A nyers kód mindig eltárolódik; az ismeretlen kód átmenet nélkül marad,
 *   és naplózódik — nem állítja meg a szinkront.
 * - Ha egy kinyomtatott címkéjű csomagot a GLS már szkennelt, a szinkron
 *   pótolja az elfelejtett „Átadva" lépést (a Sylius szállítmány `ship`).
 * - API nélküli módban nincs mit lekérdezni: a futás nem hiba.
 */
final readonly class GlsTrackingSyncService
{
    private LoggerInterface $logger;

    public function __construct(
        private ApiAccess $apiAccess,
        private GlsParcelRepository $repository,
        private EntityManagerInterface $entityManager,
        private WorkflowInterface $workflow,
        private GlsParcelService $parcelService,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function syncActive(): GlsTrackingSyncSummary
    {
        $parcels = $this->repository->findForTracking();
        $found = \count($parcels);

        if (!$this->apiAccess->isEnabled()) {
            $summary = new GlsTrackingSyncSummary(found: $found, skipped: $found, apiDisabled: true);
            $this->logger->info('A GLS nyomkövetés-szinkron kihagyva: nincs MyGLS API.', $summary->toLogContext());

            return $summary;
        }

        $byNumber = [];
        $skipped = 0;

        foreach ($parcels as $parcel) {
            $number = trim((string) $parcel->getParcelNumber());

            if (preg_match('/^[1-9]\d*$/', $number) !== 1) {
                ++$skipped;
                $this->logger->warning('A GLS csomag nyomkövetési száma nem szám, nem kérdezhető: {number}', ['number' => $number, 'order' => $parcel->getOrderNumber()]);

                continue;
            }

            $byNumber[(int) $number] = $parcel;
        }

        $synced = $transitions = $failed = 0;
        $client = $this->apiAccess->client();

        foreach (array_chunk($byNumber, Client::MAX_STATUS_QUERIES, true) as $batch) {
            try {
                $result = $client->getParcelListStatuses(array_keys($batch));
            } catch (\Throwable $e) {
                $failed += \count($batch);
                $this->logger->warning('A GLS nyomkövetés lekérdezése elbukott: {error}', ['error' => $e->getMessage(), 'parcels' => \count($batch)]);

                continue;
            }

            foreach ($result->errors as $error) {
                $this->logger->warning('A GLS nyomkövetés hibát jelzett: [{code}] {description}', ['code' => $error->code, 'description' => $error->description]);
            }

            foreach ($batch as $number => $parcel) {
                $latest = self::latest($result->forParcelNumber($number)->statuses ?? []);

                if ($latest === null) {
                    ++$failed;
                    $this->logger->warning('A GLS nem adott státuszt a csomagra: {number}', ['number' => $number, 'order' => $parcel->getOrderNumber()]);

                    continue;
                }

                ++$synced;

                if ($this->apply($parcel, $latest)) {
                    ++$transitions;
                }
            }
        }

        $this->entityManager->flush();

        $summary = new GlsTrackingSyncSummary($found, $synced, $transitions, $skipped, $failed);
        $this->logger->info('A GLS nyomkövetés-szinkron kész.', $summary->toLogContext());

        return $summary;
    }

    private function apply(GlsParcel $parcel, ParcelStatus $status): bool
    {
        $parcel->setGlsStatus($status->code);
        $parcel->touch();
        $outcome = GlsStatusMap::outcome($status->code);
        $moved = false;

        if ($outcome === TrackingOutcome::Unknown) {
            $this->logger->info('Ismeretlen GLS státuszkód: {code} ({description})', ['code' => $status->code, 'description' => $status->description, 'order' => $parcel->getOrderNumber()]);
        }

        if ($outcome->provesHandOver() && $parcel->getInternalStatus() === GlsParcelStatus::LabelReady) {
            try {
                $this->parcelService->handOver($parcel);
                $moved = true;
            } catch (GlsParcelOperationException $e) {
                $this->logger->warning('A GLS csomag automatikus átadása elbukott: {error}', ['error' => $e->getMessage(), 'order' => $parcel->getOrderNumber()]);
            }
        }

        $transition = $outcome->transition();

        if ($transition !== null && $this->workflow->can($parcel, $transition)) {
            $this->workflow->apply($parcel, $transition);
            $moved = true;
        }

        return $moved;
    }

    /** @param list<ParcelStatus> $statuses */
    private static function latest(array $statuses): ?ParcelStatus
    {
        $latest = null;

        foreach ($statuses as $status) {
            if ($latest === null || $status->date >= $latest->date) {
                $latest = $status;
            }
        }

        return $latest;
    }
}
