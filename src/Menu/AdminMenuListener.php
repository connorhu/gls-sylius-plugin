<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

/**
 * „GLS csomagok" az admin Értékesítés menüjében, a szállítmányok ELÉ (#257).
 */
final class AdminMenuListener
{
    private const string KEY = 'gls_parcels';

    public function __invoke(MenuBuilderEvent $event): void
    {
        $sales = $event->getMenu()->getChild('sales');

        if ($sales === null) {
            return;
        }

        $sales->addChild(self::KEY, ['route' => 'codeconjure_gls_admin_parcel_index'])
            ->setLabel('codeconjure.gls.ui.parcels')
            ->setLabelAttribute('icon', 'tabler:package')
            ->setExtra('routes', [['pattern' => '/^codeconjure_gls_admin_parcel_/']]);

        // `array_values()`: az `array_filter` megtartja a kulcsokat, az
        // `array_splice` viszont offszetet vár (a FoxPost plugin tanulsága).
        $children = array_values(array_filter(array_keys($sales->getChildren()), static fn (string $key): bool => $key !== self::KEY));
        $position = array_search('shipments', $children, true);

        if ($position === false) {
            $children[] = self::KEY;
        } else {
            array_splice($children, $position, 0, [self::KEY]);
        }

        $sales->reorderChildren($children);
    }
}
