<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Model;

use CodeConjure\SyliusCarrierBundle\Model\CarrierShipmentInterface;

/**
 * A host szállítmányának GLS-szerződése: a közös szállító-mezők
 * (csomagpont, telefon) és a fajta-slug.
 *
 * A `getDeliveryKindSlug()` ugyanaz a metódus, amit a FoxPost plugin
 * `DeliveryKindAwareInterface`-e kér — a host `Shipment`-je mindkettőt
 * implementálhatja egyetlen metódussal. A GLS plugin szándékosan nem függ a
 * FoxPost plugintól (#253).
 */
interface GlsShipmentInterface extends CarrierShipmentInterface
{
    public function getDeliveryKindSlug(): ?string;
}
