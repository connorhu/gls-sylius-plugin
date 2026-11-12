<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Tests\Support;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\ShipmentInterface;

/** A Sylius állapotgépének helyettese: a szállítmány `ship` átmenete `ready`-ből. */
final class RecordingStateMachine implements StateMachineInterface
{
    /** @var list<string> */
    public array $applied = [];

    public function can(object $subject, string $graphName, string $transition): bool
    {
        return $graphName === 'sylius_shipment' && $transition === 'ship' && $subject instanceof ShipmentInterface && $subject->getState() === ShipmentInterface::STATE_READY;
    }

    public function apply(object $subject, string $graphName, string $transition, array $context = []): void
    {
        $this->applied[] = $graphName . ':' . $transition;

        if ($subject instanceof ShipmentInterface) {
            $subject->setState(ShipmentInterface::STATE_SHIPPED);
        }
    }

    public function getEnabledTransitions(object $subject, string $graphName): array
    {
        return [];
    }

    public function getTransitionFromState(object $subject, string $graphName, string $fromState): ?string
    {
        return null;
    }

    public function getTransitionToState(object $subject, string $graphName, string $toState): ?string
    {
        return null;
    }
}
