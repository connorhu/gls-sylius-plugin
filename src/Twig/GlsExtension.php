<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Twig;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class GlsExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('gls_handles_delivery', static fn (?object $method): bool => GlsDeliveryKind::fromShippingMethod($method) !== null),
            new TwigFunction('gls_delivery_kind', static fn (?object $method): ?string => GlsDeliveryKind::fromShippingMethod($method)?->value),
        ];
    }
}
