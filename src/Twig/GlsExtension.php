<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Twig;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class GlsExtension extends AbstractExtension
{
    /** A GLS térkép-widget betöltője (`<gls-dpm>`, `<gls-dpm-dialog>`). */
    public const string MAP_WIDGET_URL = 'https://map.gls-hungary.com/widget/gls-dpm.js';

    public function getFunctions(): array
    {
        return [
            new TwigFunction('gls_handles_delivery', static fn (?object $subject): bool => GlsDeliveryKind::of($subject) !== null),
            new TwigFunction('gls_delivery_kind', static fn (?object $subject): ?string => GlsDeliveryKind::of($subject)?->value),
            new TwigFunction('gls_map_widget_url', static fn (): string => self::MAP_WIDGET_URL),
            new TwigFunction('gls_map_country', [GlsRuntime::class, 'mapCountry']),
            new TwigFunction('gls_pickup_point', [GlsRuntime::class, 'pickupPoint']),
            new TwigFunction('gls_cod_card_only', [GlsRuntime::class, 'codCardOnly']),
            new TwigFunction('gls_is_cash_on_delivery', [GlsRuntime::class, 'isCashOnDelivery']),
            new TwigFunction('gls_api_enabled', [GlsRuntime::class, 'apiEnabled']),
            new TwigFunction('gls_parcel_for_shipment', [GlsRuntime::class, 'parcelForShipment']),
            new TwigFunction('gls_parcel_preview', [GlsRuntime::class, 'parcelPreview']),
            new TwigFunction('gls_tracking_url', [GlsRuntime::class, 'trackingUrl']),
            new TwigFunction('gls_cod_fee', [GlsRuntime::class, 'codFee']),
            new TwigFunction('gls_parcel_estimate', [GlsRuntime::class, 'parcelEstimate']),
        ];
    }
}
