<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Entity;

/**
 * A `gls_parcel` workflow helyei (#257).
 *
 * A GLS-nél a „regisztrált" és a „címke kész" élesen külön állapot: a
 * `PrepareLabels` csak a GLS adatbázis-azonosítót (`ParcelId`) adja, a
 * nyomkövetési szám (`ParcelNumber`) a `GetPrintedLabels`-nél születik.
 */
enum GlsParcelStatus: string
{
    case Eligible = 'eligible';
    case RegistrationFailed = 'registration_failed';
    case Registered = 'registered';
    case LabelReady = 'label_ready';
    case HandedOver = 'handed_over';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case DeliveryFailed = 'delivery_failed';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    /** Feladható (újra) — még nincs élő csomag a GLS-nél. */
    public function isRegistrable(): bool
    {
        return \in_array($this, [self::Eligible, self::RegistrationFailed, self::Cancelled], true);
    }

    /** Sztornózható: él a GLS-nél, de még nincs átadva. */
    public function isCancellable(): bool
    {
        return \in_array($this, [self::Registered, self::LabelReady], true);
    }

    /** Van (vagy lehet) címkéje — a PDF újra letölthető. */
    public function hasLabel(): bool
    {
        return \in_array($this, [self::Registered, self::LabelReady, self::HandedOver, self::InTransit, self::DeliveryFailed], true);
    }

    /** Átadva a GLS-nek — a nyomkövetés innen él. */
    public function isHandedOverOrBeyond(): bool
    {
        return \in_array($this, [self::HandedOver, self::InTransit, self::Delivered, self::DeliveryFailed, self::Returned], true);
    }
}
