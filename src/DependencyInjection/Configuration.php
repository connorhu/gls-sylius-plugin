<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\DependencyInjection;

use CodeConjure\Gls\Country;
use CodeConjure\Gls\LabelFormat;
use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * A `codeconjure_gls` konfiguráció.
 *
 * Üresen is érvényes — ez az API nélküli mód. A hitelesítő adatok
 * alapértéke `%env(default::…)%`: a hiányzó env-változó null-ra oldódik,
 * nem EnvNotFoundException-re, és a mód FUTÁSIDŐBEN dől el
 * (`Api\ApiAccess`), konténer-újrafordítás nélkül.
 */
final class Configuration implements ConfigurationInterface
{
    public const string ALIAS = 'codeconjure_gls';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->arrayNode('api')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('username')->defaultValue('%env(default::GLS_USERNAME)%')->end()
                        ->scalarNode('password')->defaultValue('%env(default::GLS_PASSWORD)%')->end()
                        ->scalarNode('client_number')->defaultValue('%env(default::GLS_CLIENT_NUMBER)%')->end()
                        ->scalarNode('environment')
                            ->info('test vagy production — API-módban kötelező, nincs alapértéke.')
                            ->defaultValue('%env(default::GLS_ENVIRONMENT)%')
                        ->end()
                        ->enumNode('country')
                            ->info('A MyGLS-ország, aminek a domainjén az API fut.')
                            ->values(array_map(static fn (Country $country): string => $country->value, Country::cases()))
                            ->defaultValue(Country::Hungary->value)
                        ->end()
                        ->scalarNode('webshop_engine')->defaultValue('Sylius')->end()
                    ->end()
                ->end()
                ->arrayNode('pickup_address')
                    ->info('A feladó címe (a MyGLS PickupAddress) — csak API-módban kell.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('name')->defaultNull()->end()
                        ->scalarNode('street')->defaultNull()->end()
                        ->scalarNode('house_number')->defaultNull()->end()
                        ->scalarNode('house_number_info')->defaultNull()->end()
                        ->scalarNode('city')->defaultNull()->end()
                        ->scalarNode('zip_code')->defaultNull()->end()
                        ->scalarNode('country_iso_code')->defaultValue('HU')->end()
                        ->scalarNode('contact_name')->defaultNull()->end()
                        ->scalarNode('contact_phone')->defaultNull()->end()
                        ->scalarNode('contact_email')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('cod')
                    ->info('Az utánvét szabályai GLS-szállítmánynál (#254).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('payment_method_codes')
                            ->info('A bolt utánvétes fizetési módjainak kódja.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['cash_on_delivery'])
                        ->end()
                        ->integerNode('max_amount')
                            ->info('A beszedhető összeg felső határa fillérben (a GLS maxCodAmount-ja: 500 000 Ft).')
                            ->min(0)
                            ->defaultValue(CodPolicy::DEFAULT_MAX_AMOUNT)
                        ->end()
                        ->booleanNode('international')
                            ->info('Van-e utánvét külföldre — csak ha a GLS-szerződés adja (az ecsomag nem).')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('label')
                    ->info('A címke-PDF (#257, #263) — csak API-módban.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('format')
                            ->info('A MyGLS TypeOfPrinter: A4_2x2 (4 db A6 egy A4-en), A4_4x1, vagy hőnyomtató.')
                            ->values(array_map(static fn (LabelFormat $format): string => $format->value, LabelFormat::cases()))
                            ->defaultValue(LabelFormat::A4_2x2->value)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('notifications')
                    ->info('A GLS értesítései a címzettnek (#260): FDS (e-mail), FSS (SMS, csak FDS-sel). Alapból ki.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('email')->defaultFalse()->end()
                        ->booleanNode('sms')->defaultFalse()->end()
                    ->end()
                ->end()
                ->arrayNode('packaging')
                    ->info('A termékméretre a csomagolás miatt rászámolt ráhagyás (#260).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('allowance_mm')->min(0)->defaultValue(0)->end()
                        ->integerNode('allowance_g')->min(0)->defaultValue(0)->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
