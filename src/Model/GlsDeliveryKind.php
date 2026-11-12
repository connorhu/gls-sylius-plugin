<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Model;

/**
 * A három GLS szállítási fajta — mindegyik KÜLÖN szállítási mód a boltban
 * (#251: a vevő választás előtt egyszer dönt, a térképen nem kell szűrnie).
 *
 * A backing érték a HOST slugja (`getDeliveryKindSlug()`), `gls_` előtaggal,
 * hogy egy közös host-enumban se ütközzön más szállítóéval. A plugin így a
 * host enumját nem ismeri — csak a slugot.
 */
enum GlsDeliveryKind: string
{
    case HomeDelivery = 'gls_home_delivery';
    case ParcelShop = 'gls_parcel_shop';
    case ParcelLocker = 'gls_parcel_locker';

    public function isPickupPoint(): bool
    {
        return $this !== self::HomeDelivery;
    }

    public function isHomeDelivery(): bool
    {
        return $this === self::HomeDelivery;
    }

    /**
     * A GLS csomagpont-adat `DeliveryPointType`-ja: 1 = ParcelShop,
     * 2 = ParcelLocker. Házhoz szállításnál nincs.
     */
    public function deliveryPointType(): ?int
    {
        return match ($this) {
            self::HomeDelivery => null,
            self::ParcelShop => 1,
            self::ParcelLocker => 2,
        };
    }

    /**
     * A GLS térkép-widget `filter-type` értéke és a nyilvános pontlista
     * (`map.gls-hungary.com/data/deliveryPoints/{ország}.json`) `type` mezője.
     * Házhoz szállításnál nincs.
     */
    public function mapFilterType(): ?string
    {
        return match ($this) {
            self::HomeDelivery => null,
            self::ParcelShop => 'parcel-shop',
            self::ParcelLocker => 'parcel-locker',
        };
    }

    /**
     * A mód GLS-fajtája, vagy null, ha a mód nem GLS-é (más szállító, nincs
     * fajtája, vagy nem a host-szerződést beszéli).
     */
    public static function fromShippingMethod(?object $method): ?self
    {
        if (!$method instanceof GlsShippingMethodInterface) {
            return null;
        }

        return self::tryFrom($method->getDeliveryKindSlug() ?? '');
    }

    /**
     * A szállítmány GLS-fajtája a SAJÁT slugjából (a host a mód választásakor
     * pillanatképet tesz el), vagy null.
     */
    public static function fromShipment(?object $shipment): ?self
    {
        if (!$shipment instanceof GlsShipmentInterface) {
            return null;
        }

        return self::tryFrom($shipment->getDeliveryKindSlug() ?? '');
    }

    /** A módé vagy a szállítmányé — a sablonok mindkettőt kérdezik. */
    public static function of(?object $subject): ?self
    {
        return $subject instanceof GlsShipmentInterface ? self::fromShipment($subject) : self::fromShippingMethod($subject);
    }
}
