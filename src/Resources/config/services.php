<?php

declare(strict_types=1);

use CodeConjure\SyliusGlsPlugin\Api\ApiAccess;
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
};
