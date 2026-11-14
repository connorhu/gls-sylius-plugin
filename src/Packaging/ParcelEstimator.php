<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Packaging;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use Sylius\Component\Shipping\Model\ShipmentInterface;
use Sylius\Component\Shipping\Model\ShippableInterface;
use Sylius\Component\Shipping\Model\ShippingSubjectInterface;

/**
 * A szállítmány csomagjai a tételek méretéből (bolt-issue #260).
 *
 * - Egy csomag a benne lévő tételek egymásra rakva (`ParcelDimensions::
 *   stackedWith`), plusz a csomagolás ráhagyása (minden oldalhoz mm,
 *   csomagonként g).
 * - Ami egy csomagba nem fér, szétbomlik: first-fit decreasing — a tételek
 *   térfogat (majd súly) szerint csökkenő sorrendben az első olyan csomagba
 *   kerülnek, amelyik velük együtt még befér a mód legnagyobb osztályába.
 * - Ha egyetlen tétel (a ráhagyással) önmagában sem fér be, a szállítmány
 *   ezen a módon nem adható fel: `null`.
 *
 * A mértékegység a Sylius-változat szélessége / magassága / mélysége mm-ben,
 * a súlya grammban (a host konvenciója). A hiányzó adat 0-nak számít: a
 * teljes adatot a host indulási kapuja őrzi, nem ez az osztály.
 */
final readonly class ParcelEstimator
{
    public function __construct(
        private int $allowanceMm = 0,
        private int $allowanceG = 0,
    ) {
    }

    /** @return list<EstimatedParcel>|null */
    public function estimate(ShippingSubjectInterface $subject, GlsDeliveryKind $kind): ?array
    {
        $items = self::items($subject);

        usort($items, static fn (ParcelDimensions $a, ParcelDimensions $b): int => [$b->volume(), $b->weight] <=> [$a->volume(), $a->weight]);

        /** @var list<ParcelDimensions> $contents */
        $contents = [];

        foreach ($items as $item) {
            if (!SizeLimits::fits($kind, $this->packed($item))) {
                return null;
            }

            foreach ($contents as $index => $parcel) {
                $candidate = $parcel->stackedWith($item);

                if (SizeLimits::fits($kind, $this->packed($candidate))) {
                    $contents[$index] = $candidate;

                    continue 2;
                }
            }

            $contents[] = $item;
        }

        if ($contents === []) {
            $contents[] = ParcelDimensions::empty();
        }

        return array_map(function (ParcelDimensions $parcel) use ($kind): EstimatedParcel {
            $packed = $this->packed($parcel);
            $class = SizeLimits::classify($kind, $packed);
            \assert($class !== null);

            return new EstimatedParcel($packed, $class);
        }, $contents);
    }

    private function packed(ParcelDimensions $contents): ParcelDimensions
    {
        return $contents->grownBy($this->allowanceMm, $this->allowanceG);
    }

    /**
     * Egységenként egy tétel (a mennyiség a szállítmány-egységek száma). Ha
     * a tárgy nem ad egységeket, a szállítható változatok egyszer-egyszer.
     *
     * @return list<ParcelDimensions>
     */
    private static function items(ShippingSubjectInterface $subject): array
    {
        $shippables = [];

        if ($subject instanceof ShipmentInterface) {
            foreach ($subject->getUnits() as $unit) {
                $shippables[] = $unit->getShippable();
            }
        } else {
            $shippables = array_values($subject->getShippables()->toArray());
        }

        return array_values(array_map(
            static fn (ShippableInterface $shippable): ParcelDimensions => ParcelDimensions::of(
                (float) $shippable->getShippingWidth(),
                (float) $shippable->getShippingHeight(),
                (float) $shippable->getShippingDepth(),
                (float) $shippable->getShippingWeight(),
            ),
            array_filter($shippables, static fn (?ShippableInterface $shippable): bool => $shippable !== null),
        ));
    }
}
