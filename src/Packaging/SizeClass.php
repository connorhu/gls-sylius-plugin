<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Packaging;

/**
 * A GLS csomagméret-osztályai, kicsitől nagyig. A backing érték a díjtábla
 * kulcsa a szállítási mód calculator-konfigurációjában.
 */
enum SizeClass: string
{
    case XS = 'XS';
    case S = 'S';
    case M = 'M';
    case L = 'L';
    case XL = 'XL';
}
