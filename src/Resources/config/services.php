<?php

declare(strict_types=1);

use CodeConjure\SyliusGlsPlugin\Api\ApiAccess;
use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Eligibility\EligibilityChecker;
use CodeConjure\SyliusGlsPlugin\Payment\CodPaymentMethodsResolver;
use CodeConjure\SyliusGlsPlugin\Twig\GlsExtension;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Symfony\Component\HttpClient\Psr18Client;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('codeconjure_gls.http_client', Psr18Client::class);
    $services->set('codeconjure_gls.psr17_factory', Psr17Factory::class);

    // A MyGLS `Client` SZÁNDÉKOSAN nem service: API nélküli módban a
    // példányosítása dobna, és vele minden rá függő service. Az API-t hívó
    // service-ek az ApiAccess-t kérik, és a hívás pillanatában `client()`.
    $services->set(ApiAccess::class)
        ->args([
            service('codeconjure_gls.http_client'),
            service('codeconjure_gls.psr17_factory'),
            service('codeconjure_gls.psr17_factory'),
            param('codeconjure_gls.api.username'),
            param('codeconjure_gls.api.password'),
            param('codeconjure_gls.api.client_number'),
            param('codeconjure_gls.api.environment'),
            param('codeconjure_gls.api.country'),
            param('codeconjure_gls.api.webshop_engine'),
        ]);

    $services->set(CodPolicy::class)
        ->args([
            param('codeconjure_gls.cod.payment_method_codes'),
            param('codeconjure_gls.cod.max_amount'),
            param('codeconjure_gls.cod.international'),
        ]);

    // Az utánvét kivétele a választékból, ha a GLS nem engedi (#254). A
    // KONKRÉT `sylius.resolver.payment_methods` id-t dekorálja, NEM az
    // interfész-aliast: a Sylius fogyasztói (a pénztár fizetésimód-űrlapja,
    // a Twig-függvény, az API) az id-t kapják, és egy alias dekorálása csak
    // az aliast irányítja át — a szűrő az ő útjukon némán kimaradna (mérve
    // a befogadó boltban, 2026-11-12). A host saját dekorátoraival
    // láncba fűzhető: csak elvesz a listából.
    $services->set(CodPaymentMethodsResolver::class)
        ->decorate(CodPaymentMethodsResolver::DECORATED_SERVICE)
        ->args([service('.inner'), service(CodPolicy::class)]);

    $services->set(EligibilityChecker::class)
        ->args([service(CodPolicy::class)]);

    $services->set(GlsExtension::class)
        ->tag('twig.extension');
};
