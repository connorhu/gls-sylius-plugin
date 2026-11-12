<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Form\Extension;

use CodeConjure\SyliusGlsPlugin\Pickup\PickupPointValidator;
use Sylius\Bundle\CoreBundle\Form\Type\Checkout\ShipmentType;
use Sylius\Component\Shipping\Model\ShipmentInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A GLS pont ellenőrzése a pénztár szállítmány-űrlapján (#255).
 *
 * A `pickupPointId` mezőt a közös szállító-csomag adja; ez a kiterjesztés
 * csak a beküldött értéket nézi meg a GLS pontlistája szerint, és a hibát a
 * mezőre teszi — a vevő a 2. lépésen marad, és a panelnél látja.
 */
final class CheckoutShipmentTypeExtension extends AbstractTypeExtension
{
    public function __construct(
        private readonly PickupPointValidator $validator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $shipment = $form->getData();

            if (!$form->has('pickupPointId') || !$shipment instanceof ShipmentInterface) {
                return;
            }

            $error = $this->validator->validate($shipment);

            if ($error !== null) {
                $form->get('pickupPointId')->addError(new FormError($this->translator->trans($error, [], 'validators'), $error));
            }
        });
    }

    public static function getExtendedTypes(): iterable
    {
        return [ShipmentType::class];
    }
}
