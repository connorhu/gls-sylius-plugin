<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Address;

/** Egy címsor a MyGLS `Street` / `HouseNumber` / `HouseNumberInfo` hármasaként. */
final readonly class SplitStreet
{
    public function __construct(
        public string $street,
        public ?string $houseNumber = null,
        public ?string $houseNumberInfo = null,
    ) {
    }
}
