<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Yaml\Yaml;

/**
 * A service-konfig PHP-fájlban él, nem XML-ben: a Symfony 8 kivezette az
 * `XmlFileLoader`-t (a FoxPost plugin #205-ös tanulsága).
 */
final class CodeConjureSyliusGlsExtension extends Extension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public const string MONOLOG_CHANNEL = 'gls';

    /** @var array<string, string> */
    public const array APP_CONFIG_FILES = [
        'sylius_twig_hooks' => 'hooks.yaml',
        'framework' => 'gls_parcel.yaml',
        'sylius_resource' => 'sylius_resource.yaml',
        'sylius_grid' => 'grid.yaml',
    ];

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /** @param array<mixed> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        foreach (self::section($config, 'api') as $key => $value) {
            $container->setParameter('codeconjure_gls.api.' . $key, $value);
        }

        $container->setParameter('codeconjure_gls.pickup_address', self::section($config, 'pickup_address'));

        $cod = $config['cod'] ?? null;

        if (!\is_array($cod) || !\is_array($cod['payment_method_codes'] ?? null) || !\is_int($cod['max_amount'] ?? null) || !\is_bool($cod['international'] ?? null)) {
            throw new \LogicException('A `codeconjure_gls.cod` konfigurációs szakasz hiányos.');
        }

        $codes = [];

        foreach ($cod['payment_method_codes'] as $code) {
            if (!\is_string($code)) {
                throw new \LogicException('A `codeconjure_gls.cod.payment_method_codes` csak kódokat (stringeket) tartalmazhat.');
            }

            $codes[] = $code;
        }

        $container->setParameter('codeconjure_gls.cod.payment_method_codes', $codes);
        $container->setParameter('codeconjure_gls.cod.max_amount', $cod['max_amount']);
        $container->setParameter('codeconjure_gls.cod.international', $cod['international']);

        foreach (['label', 'notifications'] as $section) {
            foreach (self::section($config, $section) as $key => $value) {
                $container->setParameter('codeconjure_gls.' . $section . '.' . $key, $value);
            }
        }

        foreach (self::section($config, 'packaging') as $key => $value) {
            $container->setParameter('codeconjure_gls.packaging.' . $key, $value);
        }

        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__) . '/Resources/config'));
        $loader->load('services.php');
    }

    /**
     * A feldolgozott konfiguráció egy szakasza; a `Configuration` fája csak
     * skalár leveleket enged, ezt itt a típus is kimondja.
     *
     * @param array<mixed> $config
     *
     * @return array<string, scalar|null>
     */
    private static function section(array $config, string $name): array
    {
        $section = $config[$name] ?? null;

        if (!\is_array($section)) {
            throw new \LogicException(\sprintf('A `%s.%s` konfigurációs szakasz hiányzik.', Configuration::ALIAS, $name));
        }

        $typed = [];

        foreach ($section as $key => $value) {
            if ($value !== null && !\is_scalar($value)) {
                throw new \LogicException(\sprintf('A `%s.%s.%s` nem skalár.', Configuration::ALIAS, $name, $key));
            }

            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    public function prepend(ContainerBuilder $container): void
    {
        // A csatorna a pluginé; a prod handler a hosté (docs/installation.md),
        // különben a `gls` rekordok prodban elvesznek.
        if ($container->hasExtension('monolog')) {
            $container->prependExtensionConfig('monolog', ['channels' => [self::MONOLOG_CHANNEL]]);
        }

        // A plugin saját YAML-jai, mindegyik a gyökérkulcsa szerinti
        // extensionnek (a FoxPost plugin mintája): a hookok (#255) és az
        // admin csomagkezelés (#257) — workflow, resource, grid. Mind ÚJ
        // kulcs, a prepend tehát hozzáad, és a host felülírhatja.
        foreach (self::APP_CONFIG_FILES as $extension => $file) {
            if (!$container->hasExtension($extension)) {
                continue;
            }

            $parsed = Yaml::parseFile(\dirname(__DIR__) . '/Resources/config/app/' . $file);
            \assert(\is_array($parsed) && \is_array($parsed[$extension] ?? null));

            /** @var array<string, mixed> $config */
            $config = $parsed[$extension];
            $container->prependExtensionConfig($extension, $config);
        }

        $this->prependDoctrineMigrations($container);
    }

    protected function getMigrationsNamespace(): string
    {
        return 'CodeConjure\\SyliusGlsPlugin\\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@CodeConjureSyliusGlsPlugin/src/Migrations';
    }

    /**
     * A plugin migrációi a Sylius mag migrációi UTÁN futnak: a későbbi
     * oszlop-bővítések (`sylius_shipping_method`, #254) a magtáblákra
     * épülnek.
     *
     * @return list<string>
     */
    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return ['Sylius\\Bundle\\CoreBundle\\Migrations'];
    }
}
