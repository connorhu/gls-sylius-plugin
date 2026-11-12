<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Pickup;

use CodeConjure\SyliusCarrierBundle\Model\CarrierShipmentInterface;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use Sylius\Component\Shipping\Model\ShipmentInterface;

/**
 * A választott pont ellenőrzése a GLS pontlistája szerint: létezik-e, és a
 * mód fajtájához tartozik-e (csomagpont-módon csomagpont, automata-módon
 * automata).
 *
 * A widget `filter-type`-ja a vevő böngészőjében hat, a mezőbe bármi
 * érkezhet — egy másik szállító azonosítója, egy régi lapból visszaküldött
 * érték, vagy szándékos hamisítás. A KÖTELEZŐSÉG nem ennek a dolga (az a
 * közös szállító-csomag `pickup_point_required` csoportja), és elérhetetlen
 * pontlistánál a pont átmegy: a GLS kiesése nem állíthatja le a pénztárt.
 */
final readonly class PickupPointValidator
{
    public const string UNKNOWN = 'codeconjure.gls.pickup_point.unknown';

    public const string WRONG_TYPE = 'codeconjure.gls.pickup_point.wrong_type';

    public function __construct(private DeliveryPointCatalog $catalog)
    {
    }

    /** A hiba fordítási kulcsa (`validators` domain), vagy null. */
    public function validate(ShipmentInterface $shipment): ?string
    {
        $kind = GlsDeliveryKind::fromShippingMethod($shipment->getMethod());

        if ($kind === null || !$kind->isPickupPoint() || !$shipment instanceof CarrierShipmentInterface) {
            return null;
        }

        $id = $shipment->getPickupPointId();

        if ($id === null || $id === '' || !$this->catalog->isAvailable()) {
            return null;
        }

        $point = $this->catalog->find($id);

        if ($point === null) {
            return self::UNKNOWN;
        }

        return $point->matches($kind) ? null : self::WRONG_TYPE;
    }
}
