<?php

namespace App\Command;

use App\Service\Gtfs\GtfsImporter;
use App\Service\Gtfs\StopAreaBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:gtfs:import',
    description: 'Downloads the STAS GTFS zip and imports it into the database',
)]
final class ImportGtfsCommand extends Command
{
    public function __construct(
        private readonly GtfsImporter $importer,
        private readonly StopAreaBuilder $areaBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('url', InputArgument::OPTIONAL, 'Override GTFS zip URL');
        $this->addOption('no-group', null, InputOption::VALUE_NONE, 'Skip the StopArea grouping pass at the end of the import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $url = $input->getArgument('url');

        $io->title('Import GTFS STAS');
        $start = microtime(true);

        try {
            $stats = $this->importer->importFromUrl($url);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $elapsed = round(microtime(true) - $start, 1);
        $io->success("Import OK in {$elapsed}s");
        $io->definitionList(...array_map(
            fn($k, $v) => [$k => (string) $v],
            array_keys($stats),
            array_values($stats),
        ));

        if (!$input->getOption('no-group')) {
            $io->section('Grouping stops into stop areas');
            $groupStart = microtime(true);
            try {
                $groupStats = $this->areaBuilder->rebuild();
            } catch (\Throwable $e) {
                $io->error('StopArea grouping failed: ' . $e->getMessage());
                return Command::FAILURE;
            }
            $groupElapsed = round(microtime(true) - $groupStart, 1);
            $io->definitionList(
                ['areas created' => (string) $groupStats['areas']],
                ['stops attached' => (string) $groupStats['stops']],
                ['orphan stops' => (string) $groupStats['orphans']],
                ['elapsed' => "{$groupElapsed}s"],
            );
            if ($groupStats['orphans'] > 0) {
                $io->warning("{$groupStats['orphans']} orphan stop(s) not attached to any area.");
            }
        } else {
            $io->note('Skipped StopArea grouping (--no-group). Run `app:gtfs:group-stops` manually.');
        }

        return Command::SUCCESS;
    }
}
