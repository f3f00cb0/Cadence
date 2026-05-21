<?php

namespace App\Command;

use App\Service\Velivert\VelivertFetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:velivert:refresh',
    description: 'Fetches the GBFS feed and upserts Vélivert stations',
)]
final class FetchVelivertCommand extends Command
{
    public function __construct(private readonly VelivertFetcher $fetcher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $result = $this->fetcher->refresh();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        $io->success(sprintf('%d stations refreshed', $result['stations_upserted']));
        return Command::SUCCESS;
    }
}
