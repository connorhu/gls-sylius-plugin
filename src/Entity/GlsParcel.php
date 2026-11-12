<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Entity;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Repository\GlsParcelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * Egy szállítmány GLS-csomagja (#257) — szállítmányonként egy sor, a
 * FoxPost `FoxpostParcel` mintájára helyben frissítve (sztornó után újra
 * feladható).
 *
 * Két úton születik: API-módban a `PrepareLabels` → `GetPrintedLabels`
 * párral (`parcelId`, majd `parcelNumber`), kézi módban a GLS felületén
 * feladott csomag kézzel rögzített nyomkövetési számával (`manual`).
 */
#[ORM\Entity(repositoryClass: GlsParcelRepository::class)]
#[ORM\Table(name: 'gls_parcel')]
#[ORM\Index(columns: ['internal_status'], name: 'idx_gls_parcel_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_gls_parcel_created')]
class GlsParcel implements ResourceInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    protected ?int $id = null;

    #[ORM\OneToOne(targetEntity: ShipmentInterface::class)]
    #[ORM\JoinColumn(name: 'shipment_id', nullable: false, unique: true, onDelete: 'RESTRICT')]
    private ShipmentInterface $shipment;

    #[ORM\Column(name: 'order_number', type: Types::STRING, length: 30)]
    private string $orderNumber;

    #[ORM\Column(name: 'delivery_kind', type: Types::STRING, length: 32, enumType: GlsDeliveryKind::class)]
    private GlsDeliveryKind $deliveryKind;

    /** Kézi feladás (API nélkül, a GLS felületén). */
    #[ORM\Column(name: 'manual', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $manual = false;

    /** A GLS adatbázis-azonosítója (`PrepareLabels`) — a címkéhez és a sztornóhoz kell. */
    #[ORM\Column(name: 'parcel_id', type: Types::BIGINT, nullable: true, unique: true)]
    private ?string $parcelId = null;

    /** A nyomkövetési szám (`GetPrintedLabels`, vagy kézzel rögzítve). */
    #[ORM\Column(name: 'parcel_number', type: Types::STRING, length: 30, nullable: true)]
    private ?string $parcelNumber = null;

    #[ORM\Column(name: 'parcel_count', type: Types::SMALLINT, options: ['default' => 1])]
    private int $count = 1;

    /** Kilogramm (a GLS `Weight`). */
    #[ORM\Column(name: 'weight', type: Types::DECIMAL, precision: 7, scale: 2, nullable: true)]
    private ?string $weight = null;

    // --- A feladott adatok pillanatképe ---

    #[ORM\Column(name: 'recipient_name', type: Types::STRING, length: 150, options: ['default' => ''])]
    private string $recipientName = '';

    #[ORM\Column(name: 'recipient_phone', type: Types::STRING, length: 30, nullable: true)]
    private ?string $recipientPhone = null;

    #[ORM\Column(name: 'recipient_email', type: Types::STRING, length: 150, nullable: true)]
    private ?string $recipientEmail = null;

    #[ORM\Column(name: 'pickup_point_id', type: Types::STRING, length: 50, nullable: true)]
    private ?string $pickupPointId = null;

    #[ORM\Column(name: 'zip_code', type: Types::STRING, length: 10, nullable: true)]
    private ?string $zipCode = null;

    #[ORM\Column(name: 'city', type: Types::STRING, length: 60, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(name: 'street', type: Types::STRING, length: 150, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(name: 'house_number', type: Types::STRING, length: 10, nullable: true)]
    private ?string $houseNumber = null;

    #[ORM\Column(name: 'house_number_info', type: Types::STRING, length: 50, nullable: true)]
    private ?string $houseNumberInfo = null;

    #[ORM\Column(name: 'country_code', type: Types::STRING, length: 2, options: ['default' => 'HU'])]
    private string $countryCode = 'HU';

    /** Forintban (egész), vagy null, ha nincs utánvét. */
    #[ORM\Column(name: 'cod_amount', type: Types::INTEGER, nullable: true)]
    private ?int $codAmount = null;

    // --- Állapot ---

    #[ORM\Column(name: 'internal_status', type: Types::STRING, length: 40, enumType: GlsParcelStatus::class)]
    private GlsParcelStatus $internalStatus = GlsParcelStatus::Eligible;

    /** A GLS utolsó nyomkövetési státuszkódja (#258). */
    #[ORM\Column(name: 'gls_status', type: Types::STRING, length: 20, nullable: true)]
    private ?string $glsStatus = null;

    #[ORM\Column(name: 'last_api_error', type: Types::TEXT, nullable: true)]
    private ?string $lastApiError = null;

    #[ORM\Column(name: 'last_api_error_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastApiErrorAt = null;

    #[ORM\Column(name: 'retry_count', type: Types::SMALLINT, options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'registered_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $registeredAt = null;

    #[ORM\Column(name: 'label_generated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $labelGeneratedAt = null;

    #[ORM\Column(name: 'handed_over_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $handedOverAt = null;

    public function __construct(ShipmentInterface $shipment, string $orderNumber, GlsDeliveryKind $deliveryKind)
    {
        $this->shipment = $shipment;
        $this->orderNumber = $orderNumber;
        $this->deliveryKind = $deliveryKind;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShipment(): ShipmentInterface
    {
        return $this->shipment;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getDeliveryKind(): GlsDeliveryKind
    {
        return $this->deliveryKind;
    }

    public function isManual(): bool
    {
        return $this->manual;
    }

    public function setManual(bool $manual): void
    {
        $this->manual = $manual;
    }

    public function getParcelId(): ?int
    {
        return $this->parcelId === null ? null : (int) $this->parcelId;
    }

    public function setParcelId(?int $parcelId): void
    {
        $this->parcelId = $parcelId === null ? null : (string) $parcelId;
    }

    public function getParcelNumber(): ?string
    {
        return $this->parcelNumber;
    }

    public function setParcelNumber(?string $parcelNumber): void
    {
        $this->parcelNumber = $parcelNumber;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function setCount(int $count): void
    {
        $this->count = $count;
    }

    public function getWeight(): ?float
    {
        return $this->weight === null ? null : (float) $this->weight;
    }

    public function setWeight(?float $weight): void
    {
        $this->weight = $weight === null ? null : number_format($weight, 2, '.', '');
    }

    public function getRecipientName(): string
    {
        return $this->recipientName;
    }

    public function setRecipientName(string $recipientName): void
    {
        $this->recipientName = $recipientName;
    }

    public function getRecipientPhone(): ?string
    {
        return $this->recipientPhone;
    }

    public function setRecipientPhone(?string $recipientPhone): void
    {
        $this->recipientPhone = $recipientPhone;
    }

    public function getRecipientEmail(): ?string
    {
        return $this->recipientEmail;
    }

    public function setRecipientEmail(?string $recipientEmail): void
    {
        $this->recipientEmail = $recipientEmail;
    }

    public function getPickupPointId(): ?string
    {
        return $this->pickupPointId;
    }

    public function setPickupPointId(?string $pickupPointId): void
    {
        $this->pickupPointId = $pickupPointId;
    }

    public function getZipCode(): ?string
    {
        return $this->zipCode;
    }

    public function setZipCode(?string $zipCode): void
    {
        $this->zipCode = $zipCode;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): void
    {
        $this->city = $city;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(?string $street): void
    {
        $this->street = $street;
    }

    public function getHouseNumber(): ?string
    {
        return $this->houseNumber;
    }

    public function setHouseNumber(?string $houseNumber): void
    {
        $this->houseNumber = $houseNumber;
    }

    public function getHouseNumberInfo(): ?string
    {
        return $this->houseNumberInfo;
    }

    public function setHouseNumberInfo(?string $houseNumberInfo): void
    {
        $this->houseNumberInfo = $houseNumberInfo;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function setCountryCode(string $countryCode): void
    {
        $this->countryCode = $countryCode;
    }

    public function getCodAmount(): ?int
    {
        return $this->codAmount;
    }

    public function setCodAmount(?int $codAmount): void
    {
        $this->codAmount = $codAmount;
    }

    public function getInternalStatus(): GlsParcelStatus
    {
        return $this->internalStatus;
    }

    public function setInternalStatus(GlsParcelStatus $internalStatus): void
    {
        $this->internalStatus = $internalStatus;
    }

    public function getGlsStatus(): ?string
    {
        return $this->glsStatus;
    }

    public function setGlsStatus(?string $glsStatus): void
    {
        $this->glsStatus = $glsStatus;
    }

    public function getLastApiError(): ?string
    {
        return $this->lastApiError;
    }

    public function getLastApiErrorAt(): ?\DateTimeImmutable
    {
        return $this->lastApiErrorAt;
    }

    public function recordError(?string $error): void
    {
        $this->lastApiError = $error;
        $this->lastApiErrorAt = $error === null ? null : new \DateTimeImmutable();
        $this->touch();
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function incrementRetryCount(): void
    {
        ++$this->retryCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getRegisteredAt(): ?\DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function setRegisteredAt(?\DateTimeImmutable $registeredAt): void
    {
        $this->registeredAt = $registeredAt;
    }

    public function getLabelGeneratedAt(): ?\DateTimeImmutable
    {
        return $this->labelGeneratedAt;
    }

    public function setLabelGeneratedAt(?\DateTimeImmutable $labelGeneratedAt): void
    {
        $this->labelGeneratedAt = $labelGeneratedAt;
    }

    public function getHandedOverAt(): ?\DateTimeImmutable
    {
        return $this->handedOverAt;
    }

    public function setHandedOverAt(?\DateTimeImmutable $handedOverAt): void
    {
        $this->handedOverAt = $handedOverAt;
    }
}
