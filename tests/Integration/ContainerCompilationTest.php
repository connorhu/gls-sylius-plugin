<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Integration;

use CodeConjure\Gls\Environment;
use CodeConjure\SyliusGlsPlugin\Api\ApiAccess;
use CodeConjure\SyliusGlsPlugin\Api\ApiMode;
use CodeConjure\SyliusGlsPlugin\Cod\CodPolicy;
use CodeConjure\SyliusGlsPlugin\CodeConjureSyliusGlsPlugin;
use CodeConjure\SyliusGlsPlugin\DependencyInjection\CodeConjureSyliusGlsExtension;
use CodeConjure\SyliusGlsPlugin\Eligibility\EligibilityChecker;
use CodeConjure\SyliusGlsPlugin\Form\Extension\CheckoutShipmentTypeExtension;
use CodeConjure\SyliusGlsPlugin\Payment\CodPaymentMethodsResolver;
use CodeConjure\SyliusGlsPlugin\Pickup\DeliveryPointCatalog;
use CodeConjure\SyliusGlsPlugin\Tests\Support\EmptyPaymentMethodsResolver;
use CodeConjure\SyliusGlsPlugin\Twig\GlsRuntime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * A konténer MINDKÉT módban fordul és példányosít (#253 „Kész, ha"):
 *
 * - env nélkül → kézi mód, a konténer fordul, az `ApiAccess` él és
 *   `Manual`-t mond;
 * - kitöltött env-vel → API-mód, és a `client()` valódi `Client`-et ad.
 *
 * A konténert a `Kernel::dumpContainer()` opcióival dumpoljuk és onnan
 * példányosítunk — a FoxPost pluginban a csupasz `ContainerBuilder` nem
 * mutatta meg az env-feloldás hibáit, a dumpolt konténer igen.
 */
final class ContainerCompilationTest extends TestCase
{
    private const array ENV = ['GLS_USERNAME', 'GLS_PASSWORD', 'GLS_CLIENT_NUMBER', 'GLS_ENVIRONMENT'];

    protected function tearDown(): void
    {
        foreach (self::ENV as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    public function testTheBundleExposesTheShortAlias(): void
    {
        $extension = (new CodeConjureSyliusGlsPlugin())->getContainerExtension();

        self::assertInstanceOf(CodeConjureSyliusGlsExtension::class, $extension);
        self::assertSame('codeconjure_gls', $extension->getAlias());
    }

    public function testWithoutEnvironmentVariablesTheContainerRunsInManualMode(): void
    {
        $access = $this->dumpedContainer([])->get(ApiAccess::class);

        self::assertInstanceOf(ApiAccess::class, $access);
        self::assertSame(ApiMode::Manual, $access->mode());
        self::assertSame([], $access->missingSettings());
    }

    public function testWithEnvironmentVariablesTheContainerRunsInApiMode(): void
    {
        $this->setEnv(['GLS_USERNAME' => 'api@example.com', 'GLS_PASSWORD' => 'titok', 'GLS_CLIENT_NUMBER' => '100000001', 'GLS_ENVIRONMENT' => 'test']);

        $access = $this->dumpedContainer([])->get(ApiAccess::class);

        self::assertInstanceOf(ApiAccess::class, $access);
        self::assertTrue($access->isEnabled());
        self::assertSame(Environment::Test, $access->client()->environment());
        self::assertSame(100000001, $access->client()->credentials()->clientNumber);
    }

    /**
     * A host a configban felülírhatja az env-alapértékeket (pl. secretből).
     */
    public function testExplicitConfigurationOverridesTheEnvironment(): void
    {
        $access = $this->dumpedContainer(['api' => [
            'username' => 'cfg@example.com',
            'password' => 'titok',
            'client_number' => '42',
            'environment' => 'production',
            'country' => 'SK',
        ]])->get(ApiAccess::class);

        self::assertInstanceOf(ApiAccess::class, $access);
        self::assertSame('cfg@example.com', $access->client()->credentials()->username);
        self::assertSame('https://api.mygls.sk', $access->client()->country()->apiBaseUrl($access->client()->environment()));
    }

    public function testThePickupAddressAndPackagingAreParameters(): void
    {
        $container = $this->dumpedContainer([
            'pickup_address' => ['name' => 'Bolt Kft.', 'city' => 'Budapest'],
            'packaging' => ['allowance_mm' => 10, 'allowance_g' => 50],
        ]);

        $address = $container->getParameter('codeconjure_gls.pickup_address');
        self::assertIsArray($address);
        self::assertSame('Bolt Kft.', $address['name']);
        self::assertSame('HU', $address['country_iso_code']);
        self::assertSame(10, $container->getParameter('codeconjure_gls.packaging.allowance_mm'));
        self::assertSame(50, $container->getParameter('codeconjure_gls.packaging.allowance_g'));
    }

    /**
     * Az utánvét-szűrő a KONKRÉT `sylius.resolver.payment_methods` id-t
     * dekorálja. Az interfész-alias dekorálása csak az aliast irányítaná át,
     * a Sylius fogyasztói (pénztár-űrlap, Twig, API) viszont az id-t kapják —
     * a szűrő az ő útjukon kimaradna.
     */
    public function testTheCodFilterDecoratesTheConcretePaymentMethodsResolverId(): void
    {
        $definition = $this->builder([])->getDefinition(CodPaymentMethodsResolver::class);

        self::assertSame('sylius.resolver.payment_methods', $definition->getDecoratedService()[0] ?? null);
    }

    /** A címke és az értesítések (#257): alapból A4_2x2, értesítés nélkül (#260). */
    public function testTheLabelAndNotificationDefaults(): void
    {
        $container = $this->dumpedContainer([]);

        self::assertSame('A4_2x2', $container->getParameter('codeconjure_gls.label.format'));
        self::assertFalse($container->getParameter('codeconjure_gls.notifications.email'));
        self::assertFalse($container->getParameter('codeconjure_gls.notifications.sms'));
    }

    public function testTheLabelFormatIsValidated(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->builder(['label' => ['format' => 'A4_2x4']]);
    }

    public function testTheCodPolicyIsConfigurable(): void
    {
        $container = $this->dumpedContainer(['cod' => ['payment_method_codes' => ['utanvet'], 'max_amount' => 100, 'international' => true]]);

        self::assertSame(['utanvet'], $container->getParameter('codeconjure_gls.cod.payment_method_codes'));
        self::assertSame(100, $container->getParameter('codeconjure_gls.cod.max_amount'));
        self::assertTrue($container->getParameter('codeconjure_gls.cod.international'));
        self::assertInstanceOf(CodPolicy::class, $container->get(CodPolicy::class));
        self::assertInstanceOf(EligibilityChecker::class, $container->get(EligibilityChecker::class));
    }

    public function testTheCodPolicyDefaultsToTheGlsLimitsAndNoCodAbroad(): void
    {
        $container = $this->dumpedContainer([]);

        self::assertSame(['cash_on_delivery'], $container->getParameter('codeconjure_gls.cod.payment_method_codes'));
        self::assertSame(50_000_000, $container->getParameter('codeconjure_gls.cod.max_amount'));
        self::assertFalse($container->getParameter('codeconjure_gls.cod.international'));
    }

    /**
     * A `Client` szándékosan NEM service: kézi módban a példányosítása
     * dobna, és vele minden rá függő service. Az API-t hívók az
     * `ApiAccess`-t kérik.
     */
    public function testTheMyGlsClientIsNotAService(): void
    {
        $container = $this->builder([]);

        self::assertFalse($container->has(\CodeConjure\Gls\Client::class));
    }

    /**
     * Monolog és Doctrine Migrations nélküli hoston a prepend nem dob, és
     * nem hivatkozik nem létező extensionre (az a konténer fordításán buktatna).
     */
    public function testPrependIsANoOpWithoutMonologAndMigrations(): void
    {
        $container = new ContainerBuilder();

        (new CodeConjureSyliusGlsExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('monolog'));
        self::assertSame([], $container->getExtensionConfig('doctrine_migrations'));
    }

    /**
     * A `gls` Monolog-csatorna a pluginból jön; a PROD handlert a hostnak
     * kell adnia (l. docs/installation.md), különben a rekordok elvesznek.
     */
    public function testTheGlsMonologChannelIsDeclared(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(self::stubExtension('monolog'));

        (new CodeConjureSyliusGlsExtension())->prepend($container);

        self::assertSame([['channels' => ['gls']]], $container->getExtensionConfig('monolog'));
    }

    public function testTheMigrationsNamespaceIsRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(self::stubExtension('doctrine_migrations'));
        $container->registerExtension(self::stubExtension('sylius_labs_doctrine_migrations_extra'));

        (new CodeConjureSyliusGlsExtension())->prepend($container);

        $paths = $container->getExtensionConfig('doctrine_migrations')[0]['migrations_paths'] ?? null;
        self::assertSame(['CodeConjure\\SyliusGlsPlugin\\Migrations' => '@CodeConjureSyliusGlsPlugin/src/Migrations'], $paths);
        self::assertDirectoryExists(\dirname(__DIR__, 2) . '/src/Migrations');
    }

    /** A csomagpont-választó szolgáltatásai (#255) példányosíthatók. */
    public function testThePickupPointServicesInstantiate(): void
    {
        $container = $this->dumpedContainer([]);

        self::assertInstanceOf(DeliveryPointCatalog::class, $container->get(DeliveryPointCatalog::class));
        // A `GlsRuntime` a csomag-repositoryn át Doctrine-t kér (#257); a
        // példányosítását a befogadó bolt tesztjei mérik.
        self::assertInstanceOf(CheckoutShipmentTypeExtension::class, $container->get(CheckoutShipmentTypeExtension::class));
    }

    public function testThePickupPointServicesAreTagged(): void
    {
        $container = $this->builder([]);

        self::assertTrue($container->getDefinition(CheckoutShipmentTypeExtension::class)->hasTag('form.type_extension'));
        self::assertTrue($container->getDefinition(GlsRuntime::class)->hasTag('twig.runtime'));
        self::assertSame([['channel' => 'gls']], $container->getDefinition(DeliveryPointCatalog::class)->getTag('monolog.logger'));
    }

    /** Az admin csomagkezelés (#257) szolgáltatásai a host valódi id-ira hivatkoznak. */
    public function testTheParcelServicesReferenceTheHostServices(): void
    {
        $container = $this->builder([]);
        $arguments = array_map('strval', $container->getDefinition(\CodeConjure\SyliusGlsPlugin\Parcel\GlsParcelService::class)->getArguments());

        self::assertContains('state_machine.gls_parcel', $arguments);
        self::assertContains('sylius_abstraction.state_machine', $arguments);
        self::assertContains('doctrine.orm.entity_manager', $arguments);
        self::assertTrue($container->getDefinition(\CodeConjure\SyliusGlsPlugin\Controller\GlsParcelController::class)->isPublic());
        self::assertTrue($container->getDefinition(\CodeConjure\SyliusGlsPlugin\Repository\GlsParcelRepository::class)->hasTag('doctrine.repository_service'));
        self::assertSame([['event' => 'sylius.menu.admin.main']], $container->getDefinition(\CodeConjure\SyliusGlsPlugin\Menu\AdminMenuListener::class)->getTag('kernel.event_listener'));
    }

    /** A workflow, a resource és a grid a megfelelő extensionnek megy. */
    public function testTheAdminConfigurationIsPrepended(): void
    {
        $container = new ContainerBuilder();

        foreach (['framework', 'sylius_resource', 'sylius_grid'] as $alias) {
            $container->registerExtension(self::stubExtension($alias));
        }

        (new CodeConjureSyliusGlsExtension())->prepend($container);

        $framework = $container->getExtensionConfig('framework')[0] ?? null;
        self::assertIsArray($framework);
        self::assertIsArray($framework['workflows'] ?? null);
        self::assertArrayHasKey('gls_parcel', $framework['workflows']);
        self::assertNotSame([], $container->getExtensionConfig('sylius_resource'));
        self::assertNotSame([], $container->getExtensionConfig('sylius_grid'));
    }

    /**
     * A hookok mind ÚJ kulcsok: a prepend hozzáad, a host felülírhat. Twig
     * Hooks nélküli hoston nem hivatkozik nem létező extensionre.
     */
    public function testTheTwigHooksArePrepended(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(self::stubExtension('sylius_twig_hooks'));

        (new CodeConjureSyliusGlsExtension())->prepend($container);

        $hooks = $container->getExtensionConfig('sylius_twig_hooks')[0]['hooks'] ?? null;
        self::assertIsArray($hooks);

        $widget = $hooks['sylius_shop.checkout.select_shipping.content.form.shipments.shipment.carrier_fields'] ?? null;
        $summary = $hooks['sylius_shop.shared.order.show.summary.addresses.shipping.pickup_point'] ?? null;
        $payment = $hooks['sylius_shop.shared.form.select_payment.payment.choice.details'] ?? null;
        self::assertIsArray($widget);
        self::assertIsArray($summary);
        self::assertIsArray($payment);
        self::assertSame(['template' => '@CodeConjureSyliusGlsPlugin/shop/checkout/select_shipping/pickup_point_widget.html.twig'], $widget['gls'] ?? null);
        self::assertArrayHasKey('gls', $summary);
        self::assertArrayHasKey('gls_cod_notice', $payment);

        $bare = new ContainerBuilder();
        (new CodeConjureSyliusGlsExtension())->prepend($bare);
        self::assertSame([], $bare->getExtensionConfig('sylius_twig_hooks'));
    }

    /** @param array<string, mixed> $config */
    private function builder(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        // A dekorált Sylius-resolver a HOST-ban él; itt egy helyettes áll a helyén.
        $container->register(CodPaymentMethodsResolver::DECORATED_SERVICE, EmptyPaymentMethodsResolver::class);
        // A host gyorsítótára és fordítója (#255).
        $container->register('cache.app', ArrayAdapter::class);
        $container->register('translator', IdentityTranslator::class);
        // Az admin csomagkezelés (#257) host-szolgáltatásai — a dumpolt
        // konténerben csak a hivatkozás feloldása számít.
        foreach (['doctrine', 'doctrine.orm.entity_manager', 'state_machine.gls_parcel', 'sylius_abstraction.state_machine', 'router', 'security.csrf.token_manager'] as $id) {
            $container->register($id, \stdClass::class)->setSynthetic(true);
        }

        (new CodeConjureSyliusGlsExtension())->load([$config], $container);

        foreach ($container->getDefinitions() as $id => $definition) {
            if (str_starts_with($id, 'CodeConjure\\')) {
                $definition->setPublic(true);
            }
        }

        return $container;
    }

    /** @param array<string, mixed> $config */
    private function dumpedContainer(array $config): Container
    {
        $container = $this->builder($config);
        $container->compile();

        $class = 'GlsContainer_' . bin2hex(random_bytes(4));
        $content = (new PhpDumper($container))->dump([
            'class' => $class,
            'base_class' => 'Container',
            'as_files' => true,
            'debug' => false,
            'inline_factories' => false,
            'inline_class_loader' => false,
        ]);
        self::assertIsArray($content);

        $rootCode = array_pop($content);
        $dir = sys_get_temp_dir() . '/gls_container_' . bin2hex(random_bytes(4)) . '/';
        $filesystem = new Filesystem();

        foreach ($content as $file => $code) {
            $filesystem->dumpFile($dir . $file, $code);
        }

        $filesystem->dumpFile($dir . $class . '.php', $rootCode);
        require $dir . $class . '.php';

        $dumped = new $class();
        self::assertInstanceOf(Container::class, $dumped);

        return $dumped;
    }

    /** @param array<string, string> $env */
    private function setEnv(array $env): void
    {
        foreach ($env as $name => $value) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    private static function stubExtension(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(private readonly string $alias)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return $this->alias;
            }
        };
    }
}
