<?php

declare(strict_types=1);

namespace CodeConjure\SyliusGlsPlugin\Command;

use CodeConjure\SyliusGlsPlugin\Tracking\GlsTrackingSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `gls:tracking:sync` — a nyitott GLS csomagok státusza (#258). Cronból fut
 * (l. docs/installation.md); a kimenet ugyanaz a számlálókészlet, amit a
 * `gls` csatorna záró naplósora kap.
 */
#[AsCommand(name: self::NAME, description: 'A GLS csomagok nyomkövetési státuszának szinkronja (MyGLS GetParcelListStatuses).')]
final class GlsTrackingSyncCommand extends Command
{
    public const string NAME = 'gls:tracking:sync';

    public function __construct(private readonly GlsTrackingSyncService $syncService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $summary = $this->syncService->syncActive();

        $output->writeln($summary->apiDisabled
            ? 'GLS tracking sync skipped: the MyGLS API is not configured.'
            : 'GLS tracking sync complete.');

        foreach ($summary->toLogContext() as $name => $count) {
            $output->writeln(\sprintf('  %-12s %d', $name . ':', $count));
        }

        return Command::SUCCESS;
    }
}
