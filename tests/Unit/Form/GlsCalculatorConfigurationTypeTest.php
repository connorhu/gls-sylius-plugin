<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Unit\Form;

use CodeConjure\SyliusGlsPlugin\Form\Type\ChannelBasedGlsCalculatorConfigurationType;
use CodeConjure\SyliusGlsPlugin\Form\Type\GlsCalculatorConfigurationType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

/**
 * A GLS díjtábla admin-űrlapja (#260): forintban szerkeszthető, a
 * konfigurációba fillérben kerül — pontosan abban az alakban, amit a
 * `GlsRateTable` olvas.
 */
final class GlsCalculatorConfigurationTypeTest extends TestCase
{
    private const array SIZES = ['XS' => '1590', 'S' => '1590', 'M' => '1590', 'L' => '2590', 'XL' => '2590'];

    public function testTheSubmittedForintsBecomeTheFillerConfiguration(): void
    {
        $form = self::factory()->create(GlsCalculatorConfigurationType::class, null, ['currency' => 'HUF', 'validation_groups' => ['sylius']]);

        $form->submit([
            'base' => self::SIZES,
            'delivery' => ['XS' => '150', 'S' => '150', 'M' => '150', 'L' => '550', 'XL' => '550'],
            'dispatch' => ['XS' => '0', 'S' => '0', 'M' => '0', 'L' => '0', 'XL' => '0'],
            'go_unit_price' => '',
            'email_fee' => '60',
            'sms_fee' => '120',
            'cod_fee' => '600',
            'cod_fee_high' => '1190',
            'cod_threshold' => '30000',
            'country_base' => "DE: 7790 11890 15190 18890 23290\npl: 7790 11890 15190 18890 23290\n",
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame([
            'base' => ['XS' => 159_000, 'S' => 159_000, 'M' => 159_000, 'L' => 259_000, 'XL' => 259_000],
            'delivery' => ['XS' => 15_000, 'S' => 15_000, 'M' => 15_000, 'L' => 55_000, 'XL' => 55_000],
            'dispatch' => ['XS' => 0, 'S' => 0, 'M' => 0, 'L' => 0, 'XL' => 0],
            'go_unit_price' => null,
            'email_fee' => 6_000,
            'sms_fee' => 12_000,
            'cod_fee' => 60_000,
            'cod_fee_high' => 119_000,
            'cod_threshold' => 3_000_000,
            'country_base' => [
                'DE' => ['XS' => 779_000, 'S' => 1_189_000, 'M' => 1_519_000, 'L' => 1_889_000, 'XL' => 2_329_000],
                'PL' => ['XS' => 779_000, 'S' => 1_189_000, 'M' => 1_519_000, 'L' => 1_889_000, 'XL' => 2_329_000],
            ],
        ], $form->getData());
    }

    public function testTheStoredConfigurationIsShownInForints(): void
    {
        $form = self::factory()->create(GlsCalculatorConfigurationType::class, [
            'go_unit_price' => 129_000,
            'country_base' => ['DE' => ['XS' => 779_000, 'S' => 1_189_000, 'M' => 1_519_000, 'L' => 1_889_000, 'XL' => 2_329_000]],
        ], ['currency' => 'HUF']);

        $view = $form->createView();

        self::assertSame('1290.00', $view['go_unit_price']->vars['value']);
        self::assertSame('DE: 7790 11890 15190 18890 23290', $view['country_base']->vars['value']);
    }

    public function testAMalformedCountryLineIsRejected(): void
    {
        $form = self::factory()->create(GlsCalculatorConfigurationType::class, null, ['currency' => 'HUF']);

        $form->submit(['country_base' => 'DE: 7790 11890']);

        self::assertFalse($form->get('country_base')->isSynchronized());
    }

    /** A csatornánkénti burok a Sylius saját csatorna-gyűjteményére épül, mint a `flat_rate`. */
    public function testTheChannelBasedTypeExtendsTheSyliusChannelCollection(): void
    {
        $parent = new ChannelBasedGlsCalculatorConfigurationType()->getParent();

        self::assertTrue(class_exists($parent), $parent);
        self::assertSame(new \Sylius\Bundle\CoreBundle\Form\Type\Shipping\Calculator\ChannelBasedFlatRateConfigurationType()->getParent(), $parent);
    }

    private static function factory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }
}
