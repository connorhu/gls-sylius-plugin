<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Shipping;

use CodeConjure\SyliusGlsPlugin\Packaging\SizeClass;

/**
 * Egy GLS-mód díjtáblája egy csatornán, fillérben — a mód calculator-
 * konfigurációjának egy csatornára eső része:
 *
 * ```yaml
 * base:          { XS: 159000, S: 159000, M: 159000, L: 259000, XL: 259000 }
 * delivery:      { XS: 0, ... }        # a mód kézbesítési felára (dtl / dts / dta)
 * dispatch:      { XS: 0, ... }        # a bolt feladási felára (ptl / pts / pta)
 * go_unit_price: ~                     # GLS GO-keret egységára; üres = nincs GO
 * email_fee:     6000                  # FDS
 * sms_fee:       12000                 # FSS
 * cod_fee:       60000                 # utánvét a sáv-határig
 * cod_fee_high:  119000                # utánvét a sáv-határ fölött
 * cod_threshold: 3000000               # a beszedendő összeg sáv-határa
 * country_base:  { DE: { XS: ..., ... } }  # országonkénti alapár (külföld)
 * ```
 *
 * A hiányzó méret- vagy díjkulcs 0 — a konfigurációs űrlap mindet kitölti.
 */
final readonly class GlsRateTable
{
    /**
     * @param array<string, int>                $base
     * @param array<string, int>                $delivery
     * @param array<string, int>                $dispatch
     * @param array<string, array<string, int>> $countryBase
     */
    private function __construct(
        private array $base,
        private array $delivery,
        private array $dispatch,
        private ?int $goUnitPrice,
        private int $emailFee,
        private int $smsFee,
        private int $codFee,
        private int $codFeeHigh,
        private int $codThreshold,
        private array $countryBase,
    ) {
    }

    /** @param array<array-key, mixed> $configuration */
    public static function fromArray(array $configuration): self
    {
        $countryBase = [];

        foreach (self::array($configuration['country_base'] ?? []) as $country => $prices) {
            $countryBase[strtoupper((string) $country)] = self::sizes($prices);
        }

        $go = $configuration['go_unit_price'] ?? null;

        return new self(
            self::sizes($configuration['base'] ?? []),
            self::sizes($configuration['delivery'] ?? []),
            self::sizes($configuration['dispatch'] ?? []),
            $go === null || $go === '' ? null : self::int($go),
            self::int($configuration['email_fee'] ?? 0),
            self::int($configuration['sms_fee'] ?? 0),
            self::int($configuration['cod_fee'] ?? 0),
            self::int($configuration['cod_fee_high'] ?? 0),
            self::int($configuration['cod_threshold'] ?? 0),
            $countryBase,
        );
    }

    public function parcelPrice(SizeClass $size, string $countryCode, bool $email, bool $sms): int
    {
        $base = isset($this->countryBase[$countryCode])
            ? $this->countryBase[$countryCode][$size->value] ?? 0
            : $this->goUnitPrice ?? $this->base[$size->value] ?? 0;

        return $base
            + ($this->delivery[$size->value] ?? 0)
            + ($this->dispatch[$size->value] ?? 0)
            + ($email ? $this->emailFee : 0)
            + ($email && $sms ? $this->smsFee : 0);
    }

    /**
     * A sávos utánvét-díj. A beszedendő összeget az alacsonyabb díjjal
     * számoljuk: ha így a sáv-határ fölé megy, a magasabb jár.
     *
     * @param int $amountWithoutFee a tételek és a szállítás, a díj nélkül
     */
    public function cashOnDeliveryFee(int $amountWithoutFee): int
    {
        return $amountWithoutFee + $this->codFee > $this->codThreshold ? $this->codFeeHigh : $this->codFee;
    }

    /** @return array<string, int> */
    private static function sizes(mixed $prices): array
    {
        $sizes = [];

        foreach (SizeClass::cases() as $size) {
            $sizes[$size->value] = self::int(self::array($prices)[$size->value] ?? 0);
        }

        return $sizes;
    }

    /** @return array<array-key, mixed> */
    private static function array(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
