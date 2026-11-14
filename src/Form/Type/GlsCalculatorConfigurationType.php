<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Form\Type;

use CodeConjure\SyliusGlsPlugin\Form\DataTransformer\CountryBasePricesTransformer;
use Sylius\Bundle\MoneyBundle\Form\Type\MoneyType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * A `gls` calculator díjtáblája egy csatornán (`Shipping\GlsRateTable`).
 * Forintban szerkeszthető, fillérben tárolódik.
 */
final class GlsCalculatorConfigurationType extends AbstractType
{
    private const string PREFIX = 'codeconjure.gls.form.calculator.';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currency = $options['currency'];

        foreach (['base', 'delivery', 'dispatch'] as $table) {
            $builder->add($table, GlsSizePricesType::class, [
                'label' => self::PREFIX . $table,
                'currency' => $currency,
            ]);
        }

        $builder->add('go_unit_price', MoneyType::class, [
            'label' => self::PREFIX . 'go_unit_price',
            'help' => self::PREFIX . 'go_unit_price_help',
            'currency' => $currency,
            'required' => false,
            'constraints' => [new GreaterThanOrEqual(0, groups: ['sylius'])],
        ]);

        foreach (['email_fee', 'sms_fee', 'cod_fee', 'cod_fee_high', 'cod_threshold'] as $fee) {
            $builder->add($fee, MoneyType::class, [
                'label' => self::PREFIX . $fee,
                'currency' => $currency,
                'empty_data' => '0',
                'constraints' => [new NotBlank(groups: ['sylius']), new GreaterThanOrEqual(0, groups: ['sylius'])],
            ]);
        }

        $builder->add('country_base', TextareaType::class, [
            'label' => self::PREFIX . 'country_base',
            'help' => self::PREFIX . 'country_base_help',
            'required' => false,
            'invalid_message' => self::PREFIX . 'country_base_invalid',
        ]);
        $builder->get('country_base')->addModelTransformer(new CountryBasePricesTransformer());
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
        return 'codeconjure_gls_shipping_calculator';
    }
}
