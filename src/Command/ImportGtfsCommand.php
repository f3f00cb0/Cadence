<?php

namespace App\Command;

use App\Service\Gtfs\GtfsImporter;
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
        $regroup = !$input->getOption('no-group');

        $io->title('Import GTFS STAS');
        $start = microtime(true);

        try {
            $stats = $this->importer->importFromUrl($url, $regroup);
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

        if (!$regroup) {
            $io->warning('Skipped StopArea grouping (--no-group). /api/areas/* will serve no departures until `app:gtfs:group-stops` runs.');
        } elseif (($stats['orphan_stops'] ?? 0) > 0) {
            $io->warning("{$stats['orphan_stops']} orphan stop(s) not attached to any area.");
        }

        return Command::SUCCESS;
    }
}
