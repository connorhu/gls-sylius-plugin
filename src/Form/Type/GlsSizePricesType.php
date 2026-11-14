<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Form\Type;

use CodeConjure\SyliusGlsPlugin\Packaging\SizeClass;
use Sylius\Bundle\MoneyBundle\Form\Type\MoneyType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Egy ár méretosztályonként (XS–XL), fillérben tárolva. */
final class GlsSizePricesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (SizeClass::cases() as $size) {
            $builder->add($size->value, MoneyType::class, [
                'label' => $size->value,
                'currency' => $options['currency'],
                'empty_data' => '0',
                'constraints' => [
                    new NotBlank(groups: ['sylius']),
                    new GreaterThanOrEqual(0, groups: ['sylius']),
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => null])
            ->setRequired('currency')
            ->setAllowedTypes('currency', 'string');
    }

    public function getBlockPrefix(): string
    {
        return 'codeconjure_gls_size_prices';
    }
}
