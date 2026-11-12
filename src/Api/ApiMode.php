<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Api;

/**
 * A plugin két működési módja.
 *
 * - `Api`: MyGLS-szerződés — feladás, címke, sztornó, nyomkövetés az adminból.
 * - `Manual`: hitelesítő adat nélkül — szállítási módok, csomagpont-választó
 *   és díjszámítás megy, a csomagot kézzel kell feladni a GLS felületén
 *   (pl. ecsomag + GLS GO).
 */
enum ApiMode: string
{
    case Api = 'api';
    case Manual = 'manual';
}
