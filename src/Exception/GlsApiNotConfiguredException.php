<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Exception;

/**
 * A MyGLS API nincs (vagy hibásan van) beállítva.
 *
 * Szándékosan NEM a `CodeConjure\Gls\Exception\GlsApiException` leszármazottja:
 * ez nem hálózati vagy GLS-oldali hiba, hanem a bolt konfigurációja — egy
 * `catch (GlsApiException)` retry-ág ne nyelje le.
 */
final class GlsApiNotConfiguredException extends \LogicException
{
}
