<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Form\Type;

use Sylius\Bundle\CoreBundle\Form\Type\ChannelCollectionType;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A `gls` calculator admin-űrlapja: csatornánként egy díjtábla, a csatorna
 * alap-pénznemében — a Sylius `flat_rate` mintájára.
 */
final class ChannelBasedGlsCalculatorConfigurationType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'entry_type' => GlsCalculatorConfigurationType::class,
            'entry_options' => static fn (ChannelInterface $channel): array => [
                'label' => $channel->getName(),
                'currency' => (string) $channel->getBaseCurrency()?->getCode(),
            ],
        ]);
    }

    public function getParent(): string
    {
        return ChannelCollectionType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'codeconjure_gls_channel_based_shipping_calculator';
    }
}
