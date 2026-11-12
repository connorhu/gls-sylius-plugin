<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Parcel;

/**
 * Egy csomagművelet (feladás, címke, sztornó, átadás) nem sikerült — az
 * üzenet az adminnak szól, magyarul, a GLS hibakódjával, ha van.
 */
final class GlsParcelOperationException extends \RuntimeException
{
}
