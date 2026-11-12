<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Cod;

use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * Az utánvét szabályai GLS-szállítmánynál.
 *
 * - Belföldön mindhárom GLS módon van utánvét (#251).
 * - A beszedhető összeg felső határa a GLS `maxCodAmount`-ja: belföldön
 *   500 000 Ft (mérve az ecsomag árlapján, #260). Fillérben.
 * - Külföldre csak szerződés szerint (az ecsomag nem ad) — alapból tiltva.
 *
 * Melyik fizetési mód utánvét: a bolt fizetésimód-KÓDJAIBÓL (konfiguráció),
 * mert a Sylius nem ismeri az utánvétet mint fogalmat.
 */
final readonly class CodPolicy
{
    public const string REASON_AMOUNT = 'cod_amount_too_high';

    public const string REASON_ABROAD = 'cod_abroad';

    public const int DEFAULT_MAX_AMOUNT = 50_000_000;

    /**
     * @param list<string> $paymentMethodCodes
     */
    public function __construct(
        private array $paymentMethodCodes = ['cash_on_delivery'],
        private int $maxAmount = self::DEFAULT_MAX_AMOUNT,
        private bool $international = false,
        private string $domesticCountryCode = 'HU',
    ) {
    }

    public function isCashOnDelivery(?PaymentMethodInterface $method): bool
    {
        return $method !== null && \in_array($method->getCode(), $this->paymentMethodCodes, true);
    }

    /** A rendelésnek van GLS-szállítmánya — csak ilyenkor szól bele a szabály. */
    public function appliesTo(OrderInterface $order): bool
    {
        foreach ($order->getShipments() as $shipment) {
            if (GlsDeliveryKind::fromShippingMethod($shipment->getMethod()) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Miért NEM lehet ennél a rendelésnél utánvét (`REASON_*`), vagy null,
     * ha lehet — vagy ha a rendelés nem GLS-é.
     */
    public function refusalReason(OrderInterface $order): ?string
    {
        if (!$this->appliesTo($order)) {
            return null;
        }

        $country = $order->getShippingAddress()?->getCountryCode();

        if (!$this->international && $country !== null && $country !== $this->domesticCountryCode) {
            return self::REASON_ABROAD;
        }

        if ($order->getTotal() > $this->maxAmount) {
            return self::REASON_AMOUNT;
        }

        return null;
    }
}
