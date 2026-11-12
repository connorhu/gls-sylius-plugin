<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Model;

use CodeConjure\SyliusCarrierBundle\Model\CarrierShippingMethodInterface;

/**
 * A host szállítási módjának GLS-szerződése.
 *
 * Ma nem tesz hozzá metódust a közös szállító-szerződéshez: a GLS-fajtát a
 * `getDeliveryKindSlug()` adja (`GlsDeliveryKind` értékei). Jelölő
 * interfész, hogy a plugin felismerje a host módját — és hogy egy későbbi
 * GLS-specifikus mód-beállítás (pl. csomagtípus) itt kapjon helyet.
 */
interface GlsShippingMethodInterface extends CarrierShippingMethodInterface
{
}
