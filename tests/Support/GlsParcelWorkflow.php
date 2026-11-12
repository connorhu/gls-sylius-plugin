<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Support;

use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Yaml\Yaml;

/**
 * A plugin VALÓDI `gls_parcel` állapotgépe a `gls_parcel.yaml`-ból — a
 * tesztek így a szállított definíciót mérik, nem egy másolatot.
 */
final class GlsParcelWorkflow
{
    public static function create(): StateMachine
    {
        $parsed = Yaml::parseFile(\dirname(__DIR__, 2) . '/src/Resources/config/app/gls_parcel.yaml');
        \assert(\is_array($parsed) && \is_array($parsed['framework'] ?? null) && \is_array($parsed['framework']['workflows'] ?? null));
        /** @var array{places: list<string>, initial_marking: string, transitions: array<string, array{from: list<string>, to: string}>, marking_store: array{property: string}} $config */
        $config = $parsed['framework']['workflows']['gls_parcel'];

        $builder = new DefinitionBuilder($config['places']);
        $builder->setInitialPlaces([$config['initial_marking']]);

        foreach ($config['transitions'] as $name => $transition) {
            foreach ($transition['from'] as $from) {
                $builder->addTransition(new Transition($name, $from, $transition['to']));
            }
        }

        return new StateMachine($builder->build(), new MethodMarkingStore(true, $config['marking_store']['property']), name: 'gls_parcel');
    }
}
