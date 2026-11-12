<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Eligibility;

use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Model\GlsDeliveryKind;
use CodeConjure\SyliusGlsPlugin\Model\GlsShipmentInterface;
use CodeConjure\SyliusGlsPlugin\Model\GlsShippingMethodInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\OrderPaymentStates;

/**
 * Feladható-e a szállítmány GLS-csomagként — a feladás (#257) ELŐTT, hogy a
 * hiba ne a MyGLS félig sikeres válaszából derüljön ki.
 *
 * Minden hibát egyszerre jelez (nem az elsőnél áll meg), kivéve a két
 * kizáró okot (nem GLS-szállítmány, törölt rendelés).
 */
final readonly class EligibilityChecker
{
    private const array MESSAGES = [
        'no_delivery_kind' => 'A szállítási mód nem GLS-mód.',
        'order_cancelled' => 'A rendelés törölve van.',
        'order_not_paid' => 'A rendelés még nincs kifizetve.',
        CodPolicy::REASON_AMOUNT => 'Az utánvét összege meghaladja a GLS felső határát.',
        CodPolicy::REASON_ABROAD => 'Külföldre a GLS-szerződés szerint nincs utánvét.',
        'missing_pickup_point' => 'Hiányzó GLS csomagpont / automata.',
        'missing_name' => 'Hiányzó címzettnév.',
        'missing_phone' => 'Hiányzó telefonszám.',
        'missing_email' => 'Hiányzó e-mail-cím.',
        'missing_zip' => 'Hiányzó irányítószám.',
        'missing_city' => 'Hiányzó város.',
        'missing_address' => 'Hiányzó utca/házszám.',
        'missing_house_number' => 'A címben nincs házszám (a GLS csak 0-nál nagyobb számot fogad el).',
    ];

    public function __construct(private CodPolicy $codPolicy)
    {
    }

    public function check(ShipmentInterface&GlsShipmentInterface $shipment): EligibilityResult
    {
        $kind = GlsDeliveryKind::tryFrom($shipment->getDeliveryKindSlug() ?? '');

        if ($kind === null) {
            return EligibilityResult::ineligible([self::error('no_delivery_kind')]);
        }

        $order = $shipment->getOrder();

        if (!$order instanceof OrderInterface) {
            return EligibilityResult::ineligible([self::error('no_delivery_kind')]);
        }

        if ($order->getState() === OrderInterface::STATE_CANCELLED) {
            return EligibilityResult::ineligible([self::error('order_cancelled')]);
        }

        $codes = [];

        if ($this->codPolicy->isCashOnDelivery($order->getLastPayment()?->getMethod())) {
            $reason = $this->codPolicy->refusalReason($order);

            if ($reason !== null) {
                $codes[] = $reason;
            }
        } elseif ($order->getPaymentState() !== OrderPaymentStates::STATE_PAID) {
            $codes[] = 'order_not_paid';
        }

        $address = $order->getShippingAddress();
        $phone = self::filled($shipment->getPhoneNumber()) ? $shipment->getPhoneNumber() : $address?->getPhoneNumber();
        $method = $shipment->getMethod();
        $phoneRequired = $kind->isPickupPoint() || ($method instanceof GlsShippingMethodInterface && $method->requiresRecipientPhone());

        if ($kind->isPickupPoint()) {
            // A PSD szolgáltatás (B függelék): ContactName, ContactPhone,
            // ContactEmail kötelező a kézbesítési címen.
            if (!self::filled($shipment->getPickupPointId())) {
                $codes[] = 'missing_pickup_point';
            }

            if (!self::filled($address?->getFullName())) {
                $codes[] = 'missing_name';
            }
        }

        if ($phoneRequired && !self::filled($phone)) {
            $codes[] = 'missing_phone';
        }

        if ($kind->isPickupPoint() && !self::filled($order->getCustomer()?->getEmail())) {
            $codes[] = 'missing_email';
        }

        if ($kind->isHomeDelivery()) {
            if (!self::filled($address?->getPostcode())) {
                $codes[] = 'missing_zip';
            }

            if (!self::filled($address?->getCity())) {
                $codes[] = 'missing_city';
            }

            $street = $address?->getStreet();

            if (!self::filled($street)) {
                $codes[] = 'missing_address';
            } elseif (preg_match('/(?<!\d)0*[1-9]\d*/', (string) $street) !== 1) {
                $codes[] = 'missing_house_number';
            }
        }

        if ($codes === []) {
            return EligibilityResult::eligible();
        }

        return EligibilityResult::ineligible(array_map(self::error(...), $codes));
    }

    /** @return array{code: string, message: string} */
    private static function error(string $code): array
    {
        return ['code' => $code, 'message' => self::MESSAGES[$code] ?? $code];
    }

    private static function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
