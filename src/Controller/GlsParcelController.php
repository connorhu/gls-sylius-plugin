<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Controller;

use CodeConjure\SyliusGlsPlugin\Entity\GlsParcel;
use CodeConjure\SyliusGlsPlugin\Model\GlsShipmentInterface;
use CodeConjure\SyliusGlsPlugin\Parcel\GlsParcelOperationException;
use CodeConjure\SyliusGlsPlugin\Parcel\GlsParcelService;
use CodeConjure\SyliusGlsPlugin\Repository\GlsParcelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * A GLS csomagműveletek az adminból (#257).
 *
 * Az útvonal SZÁNDÉKOSAN `/admin` nélküli: az előtagot a plugin
 * `routes.yaml`-jának importja adja `/%sylius_admin.path_name%`-ként — így a
 * műveletek a Sylius admin tűzfalán belül maradnak egy nem alapértelmezett
 * admin-útvonalú boltban is (a FoxPost plugin #209-es tanulsága).
 *
 * Minden módosító művelet POST, `gls_parcel` CSRF-tokennel. A tömeges
 * műveletek a csomaglista KIJELÖLT SORAINAK azonosítóit kapják `ids[]`-ben —
 * ezek `GlsParcel`-ek, nem szállítmányok (a FoxPost #208-as tanulsága).
 */
#[Route('/gls/parcels', name: 'codeconjure_gls_admin_parcel_')]
final readonly class GlsParcelController
{
    public const string CSRF_ID = 'gls_parcel';

    public const string INDEX_ROUTE = 'codeconjure_gls_admin_parcel_index';

    public function __construct(
        private GlsParcelService $service,
        private GlsParcelRepository $repository,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/register/{shipmentId}', name: 'register', methods: ['POST'])]
    public function register(Request $request, int $shipmentId): Response
    {
        return $this->guarded($request, function () use ($request, $shipmentId): string {
            $parcel = $this->service->register($this->shipment($shipmentId), self::weight($request), self::count($request));

            return \sprintf('GLS csomag feladva (GLS azonosító: %d). A címke nyomtatásával kap nyomkövetési számot.', $parcel->getParcelId() ?? 0);
        });
    }

    #[Route('/manual/{shipmentId}', name: 'manual_hand_over', methods: ['POST'])]
    public function manualHandOver(Request $request, int $shipmentId): Response
    {
        return $this->guarded($request, function () use ($request, $shipmentId): string {
            $parcel = $this->service->manualHandOver(
                $this->shipment($shipmentId),
                (string) $request->request->get('tracking_number', ''),
                self::weight($request),
                self::count($request),
            );

            return \sprintf('GLS csomag rögzítve, nyomkövetési szám: %s. A szállítmány kiszállítva.', $parcel->getParcelNumber() ?? '');
        });
    }

    #[Route('/{id}/hand-over', name: 'hand_over', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function handOver(Request $request, int $id): Response
    {
        return $this->guarded($request, function () use ($id): string {
            $this->service->handOver($this->parcel($id));

            return 'GLS csomag átadva. A szállítmány kiszállítva.';
        });
    }

    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(Request $request, int $id): Response
    {
        return $this->guarded($request, function () use ($id): string {
            $this->service->cancel($this->parcel($id));

            return 'GLS csomag sztornózva.';
        });
    }

    #[Route('/{id}/label', name: 'label', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function label(Request $request, int $id): Response
    {
        try {
            return self::pdf($this->service->labels([$this->parcel($id)], self::printPosition($request)));
        } catch (GlsParcelOperationException $e) {
            return $this->back($request, 'error', $e->getMessage());
        }
    }

    #[Route('/bulk-label', name: 'bulk_label', methods: ['POST'])]
    public function bulkLabel(Request $request): Response
    {
        if (!$this->csrfValid($request)) {
            return $this->back($request, 'error', 'Érvénytelen űrlap-token — töltsd újra az oldalt.');
        }

        try {
            return self::pdf($this->service->labels($this->repository->findByIds(self::ids($request)), self::printPosition($request)));
        } catch (GlsParcelOperationException $e) {
            return $this->back($request, 'error', $e->getMessage());
        }
    }

    /**
     * Tömeges sztornó: csomagonként, hogy egy hibás ne vigye el a többit.
     * A nem sztornózható (pl. már átadott) csomag KIHAGYOTT, nem hiba.
     */
    #[Route('/bulk-cancel', name: 'bulk_cancel', methods: ['POST'])]
    public function bulkCancel(Request $request): Response
    {
        if (!$this->csrfValid($request)) {
            return $this->back($request, 'error', 'Érvénytelen űrlap-token — töltsd újra az oldalt.');
        }

        $done = $skipped = 0;
        $errors = [];

        foreach ($this->repository->findByIds(self::ids($request)) as $parcel) {
            if (!$parcel->getInternalStatus()->isCancellable()) {
                ++$skipped;

                continue;
            }

            try {
                $this->service->cancel($parcel);
                ++$done;
            } catch (GlsParcelOperationException $e) {
                $errors[] = $parcel->getOrderNumber() . ': ' . $e->getMessage();
            }
        }

        $summary = \sprintf('Tömeges sztornó: %d sztornózva, %d kihagyva, %d hibás.', $done, $skipped, \count($errors));

        return $this->back($request, $errors === [] ? 'success' : 'error', implode("\n", [$summary, ...$errors]));
    }

    /** @param \Closure(): string $operation */
    private function guarded(Request $request, \Closure $operation): Response
    {
        if (!$this->csrfValid($request)) {
            return $this->back($request, 'error', 'Érvénytelen űrlap-token — töltsd újra az oldalt.');
        }

        try {
            return $this->back($request, 'success', $operation());
        } catch (GlsParcelOperationException $e) {
            return $this->back($request, 'error', $e->getMessage());
        }
    }

    private function shipment(int $id): ShipmentInterface&GlsShipmentInterface
    {
        $shipment = $this->entityManager->find(ShipmentInterface::class, $id);

        if (!$shipment instanceof ShipmentInterface || !$shipment instanceof GlsShipmentInterface) {
            throw new GlsParcelOperationException('A szállítmány nem található.');
        }

        return $shipment;
    }

    private function parcel(int $id): GlsParcel
    {
        $parcel = $this->repository->find($id);

        if (!$parcel instanceof GlsParcel) {
            throw new GlsParcelOperationException('A GLS csomag nem található.');
        }

        return $parcel;
    }

    private function csrfValid(Request $request): bool
    {
        return $this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_ID, (string) $request->request->get('_csrf_token', '')));
    }

    private function back(Request $request, string $type, string $message): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }

        return new RedirectResponse($request->headers->get('referer') ?? $this->urlGenerator->generate(self::INDEX_ROUTE));
    }

    private static function pdf(string $pdf): Response
    {
        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => \sprintf('attachment; filename="gls_cimkek_%s.pdf"', date('Y-m-d_His')),
        ]);
    }

    /** @return list<int> */
    private static function ids(Request $request): array
    {
        $ids = [];

        foreach ($request->request->all('ids') as $id) {
            if (\is_scalar($id) && ctype_digit((string) $id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    private static function weight(Request $request): ?float
    {
        $value = str_replace(',', '.', trim((string) $request->request->get('weight', '')));

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private static function count(Request $request): int
    {
        $value = (int) $request->request->get('count', 1);

        return max(1, min(99, $value));
    }

    private static function printPosition(Request $request): int
    {
        $value = (int) ($request->query->get('print_position') ?? $request->request->get('print_position', 1));

        return max(1, min(4, $value));
    }
}
