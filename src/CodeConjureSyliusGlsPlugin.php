<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin;

use CodeConjure\SyliusGlsPlugin\DependencyInjection\CodeConjureSyliusGlsExtension;
use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class CodeConjureSyliusGlsPlugin extends Bundle
{
    use SyliusPluginTrait;

    /**
     * A konfiguráció gyökérkulcsa a rövid `codeconjure_gls`, nem a
     * névkonvencióból adódó `code_conjure_sylius_gls` — a `Bundle`
     * alapértelmezése az eltérő aliast kivétellel utasítaná el.
     */
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new CodeConjureSyliusGlsExtension();
        }

        return $this->extension;
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
