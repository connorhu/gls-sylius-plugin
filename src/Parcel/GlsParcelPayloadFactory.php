<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Parcel;

use CodeConjure\Gls\Model\Address;
use CodeConjure\Gls\Model\Parcel;
use CodeConjure\Gls\Model\ParcelProperty;
use CodeConjure\Gls\Model\Service;
use CodeConjure\SyliusGlsPlugin\Address\StreetSplitter;
use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Entity\GlsParcel;
use CodeConjure\SyliusGlsPlugin\Model\GlsShipmentInterface;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * Sylius-szállítmány → GLS-csomag (#257), két lépésben:
 *
 * 1. `snapshot()`: a feladandó adatok a `GlsParcel`-re kerülnek — az admin
 *    ezt látja (kézi módban ezt másolja át a GLS felületére), és egy
 *    újrapróba is ebből megy;
 * 2. `build()`: a pillanatképből a MyGLS `Parcel` (API-mód).
 *
 * A címsor a `StreetSplitter`-en át a `Street` / `HouseNumber` /
 * `HouseNumberInfo` hármasra bomlik. Átvételi pontos módnál a bolt nem kér
 * szállítási címet (#241): a név a számlázási címről, a kézbesítési cím a
 * pont címe a GLS pontlistájából.
 */
final readonly class GlsParcelPayloadFactory
{
    private const array PICKUP_REQUIRED = ['name', 'street', 'city', 'zip_code', 'country_iso_code'];

    /**
     * @param array<string, scalar|null> $pickupAddress a `codeconjure_gls.pickup_address` konfiguráció
     */
    public function __construct(
        private StreetSplitter $streetSplitter,
        private DeliveryPointCatalog $catalog,
        private CodPolicy $codPolicy,
        private array $pickupAddress,
        private bool $notifyByEmail,
        private bool $notifyBySms,
    ) {
    }

    public function snapshot(GlsParcel $parcel, ShipmentInterface&GlsShipmentInterface $shipment, ?float $weight = null, int $count = 1): void
    {
        $order = $shipment->getOrder();

        if (!$order instanceof OrderInterface) {
            throw new \LogicException('A szállítmánynak nincs rendelése.');
        }

        $address = $order->getShippingAddress();
        $nameSource = $address ?? $order->getBillingAddress();
        $kind = $parcel->getDeliveryKind();

        $parcel->setRecipientName(trim((string) ($nameSource?->getCompany() ?: $nameSource?->getFullName())));
        $parcel->setRecipientPhone(self::filled($shipment->getPhoneNumber()) ?? self::filled($address?->getPhoneNumber() ?? $nameSource?->getPhoneNumber()));
        $parcel->setRecipientEmail(self::filled($order->getCustomer()?->getEmail()));
        $parcel->setPickupPointId($kind->isPickupPoint() ? self::filled($shipment->getPickupPointId()) : null);

        $point = $kind->isPickupPoint() && $address === null && $parcel->getPickupPointId() !== null
            ? $this->catalog->find($parcel->getPickupPointId())
            : null;

        if ($point !== null) {
            $split = $this->streetSplitter->split($point->address);
            $parcel->setZipCode($point->postalCode);
            $parcel->setCity($point->city);
            $parcel->setCountryCode($point->countryCode);
        } else {
            $split = $this->streetSplitter->split((string) $address?->getStreet());
            $parcel->setZipCode(self::filled($address?->getPostcode()));
            $parcel->setCity(self::filled($address?->getCity()));
            $parcel->setCountryCode(strtoupper((string) ($address?->getCountryCode() ?: 'HU')));
        }

        $parcel->setStreet(self::filled($split->street));
        $parcel->setHouseNumber($split->houseNumber);
        $parcel->setHouseNumberInfo($split->houseNumberInfo);

        $parcel->setCodAmount($this->codPolicy->isCashOnDelivery($order->getLastPayment()?->getMethod())
            ? (int) round($order->getTotal() / 100)
            : null);
        $parcel->setWeight($weight);
        $parcel->setCount($count);
        $parcel->touch();
    }

    /**
     * @throws \LogicException a feladó címe hiányos (konfiguráció)
     * @throws \InvalidArgumentException a pillanatkép nem ad érvényes MyGLS csomagot
     */
    public function build(GlsParcel $parcel, int $clientNumber): Parcel
    {
        $delivery = new Address(
            $parcel->getRecipientName(),
            (string) $parcel->getStreet(),
            (string) $parcel->getCity(),
            (string) $parcel->getZipCode(),
            $parcel->getCountryCode(),
            $parcel->getHouseNumber(),
            $parcel->getHouseNumberInfo(),
            $parcel->getRecipientName(),
            $parcel->getRecipientPhone(),
            $parcel->getRecipientEmail(),
        );

        $services = [];

        if ($parcel->getPickupPointId() !== null) {
            $services[] = Service::psd($parcel->getPickupPointId());
        }

        if ($this->notifyByEmail && $parcel->getRecipientEmail() !== null) {
            $services[] = Service::fds($parcel->getRecipientEmail());

            if ($this->notifyBySms && $parcel->getRecipientPhone() !== null) {
                $services[] = Service::fss($parcel->getRecipientPhone());
            }
        }

        $cod = $parcel->getCodAmount();

        return new Parcel(
            clientNumber: $clientNumber,
            pickupAddress: $this->pickupAddress(),
            deliveryAddress: $delivery,
            clientReference: $parcel->getOrderNumber(),
            count: $parcel->getCount(),
            codAmount: $cod,
            codReference: $cod === null ? null : $parcel->getOrderNumber(),
            codCurrency: $cod === null ? null : 'HUF',
            services: $services,
            properties: $parcel->getWeight() === null ? [] : [new ParcelProperty(weight: $parcel->getWeight())],
        );
    }

    private function pickupAddress(): Address
    {
        $value = fn (string $key): ?string => self::filled(isset($this->pickupAddress[$key]) ? (string) $this->pickupAddress[$key] : null);
        $missing = array_values(array_filter(self::PICKUP_REQUIRED, static fn (string $key): bool => $value($key) === null));

        if ($missing !== []) {
            throw new \LogicException(\sprintf(
                'A feladó címe hiányos a GLS-beállításban: %s.',
                implode(', ', array_map(static fn (string $key): string => 'codeconjure_gls.pickup_address.' . $key, $missing)),
            ));
        }

        return new Address(
            (string) $value('name'),
            (string) $value('street'),
            (string) $value('city'),
            (string) $value('zip_code'),
            (string) $value('country_iso_code'),
            $value('house_number'),
            $value('house_number_info'),
            $value('contact_name'),
            $value('contact_phone'),
            $value('contact_email'),
        );
    }

    private static function filled(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
