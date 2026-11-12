<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Pickup;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;

/**
 * Egy GLS csomagpont vagy csomagautomata a nyilvános pontlistából.
 *
 * Az `id` ugyanaz, amit a térkép-widget a választáskor ad, és amit a MyGLS
 * a `PSD` szolgáltatás paramétereként vár (`1027-CSOMAGPONT05`,
 * `HU1012-PLOCKER001`) — tehát a szállítmány `pickupPointId`-ja
 * leképezés nélkül.
 */
final readonly class DeliveryPoint
{
    private const array TYPES = ['parcel-shop', 'parcel-locker'];

    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public string $countryCode,
        public string $postalCode,
        public string $city,
        public string $address,
        public bool $acceptsCash,
    ) {
    }

    /**
     * A pontlista egy sora; ami nem a várt alakú, arra null — a külső adat
     * alakja nem a miénk, egy hibás sor nem viheti el a többit.
     *
     * @param array<mixed> $row
     */
    public static function fromArray(array $row): ?self
    {
        $contact = $row['contact'] ?? null;

        if (!\is_array($contact)) {
            return null;
        }

        $id = self::string($row, 'id');
        $name = self::string($row, 'name');
        $type = self::string($row, 'type');
        $countryCode = self::string($contact, 'countryCode');
        $postalCode = self::string($contact, 'postalCode');
        $city = self::string($contact, 'city');
        $address = self::string($contact, 'address');

        if ($id === null || $id === '' || $name === null || $type === null || !\in_array($type, self::TYPES, true) ||
            $countryCode === null || $postalCode === null || $city === null || $address === null) {
            return null;
        }

        $features = $row['features'] ?? [];

        return new self($id, $name, $type, $countryCode, $postalCode, $city, $address, \is_array($features) && \in_array('acceptsCash', $features, true));
    }

    /** @param array<mixed> $row */
    private static function string(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function addressLine(): string
    {
        return \sprintf('%s %s, %s', $this->postalCode, $this->city, $this->address);
    }

    public function matches(GlsDeliveryKind $kind): bool
    {
        return $kind->mapFilterType() === $this->type;
    }
}
