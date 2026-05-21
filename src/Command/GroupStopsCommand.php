<?php

namespace App\Command;

use App\Service\Gtfs\StopAreaBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:gtfs:group-stops',
    description: 'Rebuilds StopArea groupings from current Stops',
)]
final class GroupStopsCommand extends Command
{
    public function __construct(private readonly StopAreaBuilder $builder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Group stops into stop areas');
        $start = microtime(true);

        $stats = $this->builder->rebuild();
        $elapsed = round(microtime(true) - $start, 2);

        $io->definitionList(
            ['areas created' => (string) $stats['areas']],
            ['stops attached' => (string) $stats['stops']],
            ['orphan stops' => (string) $stats['orphans']],
            ['elapsed' => "{$elapsed}s"],
        );

        if ($stats['orphans'] > 0) {
            $io->warning("There are {$stats['orphans']} orphan stops not attached to any area.");
        } else {
            $io->success('All stops attached to an area.');
        }

        return Command::SUCCESS;
    }
}
