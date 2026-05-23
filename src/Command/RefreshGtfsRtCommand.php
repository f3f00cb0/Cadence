<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Gtfs\GtfsRtImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:gtfs:rt:refresh',
    description: 'Fetches the STAS GTFS-Realtime feed, parses it, and replaces the trip-stop patches in the DB.',
)]
final class RefreshGtfsRtCommand extends Command
{
    public function __construct(private readonly GtfsRtImporter $importer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $result = $this->importer->refresh();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        $io->success(sprintf(
            '%d trip-stop updates ingested (%d canceled trips) in %d ms',
            $result['rows'],
            $result['canceled_trips'],
            $result['duration_ms'],
        ));
        return Command::SUCCESS;
    }
}
