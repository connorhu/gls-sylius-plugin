<?php

declare(strict_types=1);

use CodeConjure\SyliusGlsPlugin\Address\StreetSplitter;
use CodeConjure\SyliusGlsPlugin\Api\ApiAccess;
use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\Cod\PickupPointCashCheck;
use CodeConjure\SyliusGlsPlugin\Command\GlsTrackingSyncCommand;
use CodeConjure\SyliusGlsPlugin\Controller\GlsParcelController;
use CodeConjure\SyliusGlsPlugin\Eligibility\EligibilityChecker;
use CodeConjure\SyliusGlsPlugin\Form\Extension\CheckoutShipmentTypeExtension;
use CodeConjure\SyliusGlsPlugin\Form\Type\ChannelBasedGlsCalculatorConfigurationType;
use CodeConjure\SyliusGlsPlugin\Form\Type\GlsCalculatorConfigurationType;
use CodeConjure\SyliusGlsPlugin\Form\Type\GlsSizePricesType;
use CodeConjure\SyliusGlsPlugin\Menu\AdminMenuListener;
use CodeConjure\SyliusGlsPlugin\Packaging\ParcelEstimator;
use CodeConjure\SyliusGlsPlugin\Parcel\GlsParcelPayloadFactory;
use CodeConjure\SyliusGlsPlugin\Parcel\GlsParcelService;
use CodeConjure\SyliusGlsPlugin\Payment\CodPaymentMethodsResolver;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use CodeConjure\SyliusGlsPlugin\Pickup\PickupPointValidator;
use CodeConjure\SyliusGlsPlugin\Repository\GlsParcelRepository;
use CodeConjure\SyliusGlsPlugin\Shipping\GlsShippingCalculator;
use CodeConjure\SyliusGlsPlugin\Shipping\GlsShippingMethodEligibilityChecker;
use CodeConjure\SyliusGlsPlugin\Tracking\GlsTrackingSyncService;
use CodeConjure\SyliusGlsPlugin\Twig\GlsExtension;
use CodeConjure\SyliusGlsPlugin\Twig\GlsRuntime;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Symfony\Component\HttpClient\HttpClient;
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

    // A GLS nyilvános pontlistája (#255): a választott pont neve és címe, a
    // fajta-ellenőrzés és a készpénz-kérdés forrása. Saját HTTP-kliens, hogy
    // a host `framework.http_client` beállításától (pl. egy scoped kliens
    // base_uri-ja) ne függjön; a gyorsítótár a hosté (`cache.app`).
    $services->set('codeconjure_gls.delivery_points.http_client')
        ->class(\Symfony\Contracts\HttpClient\HttpClientInterface::class)
        ->factory([HttpClient::class, 'create']);

    $services->set(DeliveryPointCatalog::class)
        ->args([
            service('codeconjure_gls.delivery_points.http_client'),
            service('cache.app'),
            service('logger')->nullOnInvalid(),
            param('codeconjure_gls.api.country'),
        ])
        ->tag('monolog.logger', ['channel' => 'gls']);

    $services->set(PickupPointValidator::class)
        ->args([service(DeliveryPointCatalog::class)]);

    $services->set(PickupPointCashCheck::class)
        ->args([service(DeliveryPointCatalog::class)]);

    $services->set(CheckoutShipmentTypeExtension::class)
        ->args([service(PickupPointValidator::class), service('translator')])
        ->tag('form.type_extension');

    $services->set(GlsRuntime::class)
        ->args([
            service(DeliveryPointCatalog::class),
            service(PickupPointCashCheck::class),
            service(CodPolicy::class),
            param('codeconjure_gls.api.country'),
            service(ApiAccess::class),
            service(GlsParcelRepository::class),
            service(GlsParcelPayloadFactory::class),
            service(ParcelEstimator::class),
            service(GlsShippingCalculator::class),
        ])
        ->tag('twig.runtime');

    // --- A csomagfeladás az adminból (#257) ---

    $services->set(GlsParcelRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(StreetSplitter::class);

    $services->set(GlsParcelPayloadFactory::class)
        ->args([
            service(StreetSplitter::class),
            service(DeliveryPointCatalog::class),
            service(CodPolicy::class),
            param('codeconjure_gls.pickup_address'),
            param('codeconjure_gls.notifications.email'),
            param('codeconjure_gls.notifications.sms'),
        ]);

    $services->set(GlsParcelService::class)
        ->args([
            service(ApiAccess::class),
            service(GlsParcelPayloadFactory::class),
            service(EligibilityChecker::class),
            service(GlsParcelRepository::class),
            service('doctrine.orm.entity_manager'),
            service('state_machine.gls_parcel'),
            service('sylius_abstraction.state_machine'),
            param('codeconjure_gls.label.format'),
        ]);

    $services->set(GlsParcelController::class)
        ->args([
            service(GlsParcelService::class),
            service(GlsParcelRepository::class),
            service('doctrine.orm.entity_manager'),
            service('router'),
            service('security.csrf.token_manager'),
        ])
        ->public()
        ->tag('controller.service_arguments');

    $services->set(AdminMenuListener::class)
        ->tag('kernel.event_listener', ['event' => 'sylius.menu.admin.main']);

    // --- A nyomkövetés-szinkron (#258) ---

    $services->set(GlsTrackingSyncService::class)
        ->args([
            service(ApiAccess::class),
            service(GlsParcelRepository::class),
            service('doctrine.orm.entity_manager'),
            service('state_machine.gls_parcel'),
            service(GlsParcelService::class),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'gls']);

    $services->set(GlsTrackingSyncCommand::class)
        ->args([service(GlsTrackingSyncService::class)])
        ->tag('console.command');

    // --- A díjszámítás (#260) ---

    $services->set(ParcelEstimator::class)
        ->args([
            param('codeconjure_gls.packaging.allowance_mm'),
            param('codeconjure_gls.packaging.allowance_g'),
        ]);

    $services->set(GlsShippingCalculator::class)
        ->args([
            service(ParcelEstimator::class),
            service(CodPolicy::class),
            param('codeconjure_gls.notifications.email'),
            param('codeconjure_gls.notifications.sms'),
        ])
        ->tag('sylius.shipping_calculator', [
            'calculator' => GlsShippingCalculator::TYPE,
            'label' => 'codeconjure.gls.form.calculator.label',
            'form_type' => ChannelBasedGlsCalculatorConfigurationType::class,
        ]);

    // A Sylius összetett eligibility-ellenőrzője a címkéből gyűjti a
    // tagjait: a nem férő kosárnál a GLS-mód a 2. lépésen meg sem jelenik.
    $services->set(GlsShippingMethodEligibilityChecker::class)
        ->args([service(ParcelEstimator::class)])
        ->tag('sylius.shipping_method_eligibility_checker');

    foreach ([GlsSizePricesType::class, GlsCalculatorConfigurationType::class, ChannelBasedGlsCalculatorConfigurationType::class] as $formType) {
        $services->set($formType)->tag('form.type');
    }
};
