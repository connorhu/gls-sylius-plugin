<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Cod;

use CodeConjure\SyliusCarrierBundle\Model\CarrierShipmentInterface;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * Utánvétnél csak kártyával lehet-e fizetni a rendelés GLS-pontján (#255).
 *
 * A pont a pénztár 2. lépésében dől el, a fizetési mód csak a 3.-ban, ezért a
 * térkép-widget nem szűrhet fizetési mód szerint, és az utánvét mindhárom GLS
 * módon választható marad (#251). A vevőt a 3. lépés figyelmezteti, ennek a
 * válasza szerint:
 *
 * - automata: mindig (a GLS automatái nem fogadnak készpénzt — ezt a
 *   pontlista nélkül is tudjuk);
 * - csomagpont: ha a pontlista szerint a pont nem fogad készpénzt;
 * - ismeretlen pont vagy elérhetetlen lista: nem állítunk semmit.
 */
final readonly class PickupPointCashCheck
{
    public function __construct(private DeliveryPointCatalog $catalog)
    {
    }

    public function isCardOnly(OrderInterface $order): bool
    {
        foreach ($order->getShipments() as $shipment) {
            $kind = GlsDeliveryKind::fromShippingMethod($shipment->getMethod());

            if ($kind === GlsDeliveryKind::ParcelLocker) {
                return true;
            }

            if ($kind !== GlsDeliveryKind::ParcelShop || !$shipment instanceof CarrierShipmentInterface) {
                continue;
            }

            $id = $shipment->getPickupPointId();
            $point = $id === null || $id === '' ? null : $this->catalog->find($id);

            if ($point !== null && !$point->acceptsCash) {
                return true;
            }
        }

        return false;
    }
}
