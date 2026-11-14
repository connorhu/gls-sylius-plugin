<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Packaging;

/** Egy becsült csomag: a mérete a ráhagyással együtt, és a méretosztálya. */
final readonly class EstimatedParcel
{
    public function __construct(
        public ParcelDimensions $dimensions,
        public SizeClass $sizeClass,
    ) {
    }
}
