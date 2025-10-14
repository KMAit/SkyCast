<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WeatherService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'weather:invalidate',
    description: 'Invalidate cached forecast for a given coordinate pair.',
)]
class InvalidateForecastCommand extends Command
{
    public function __construct(private readonly WeatherService $weatherService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('lat', InputArgument::REQUIRED, 'Latitude')
            ->addArgument('lon', InputArgument::REQUIRED, 'Longitude');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lat = (float) $input->getArgument('lat');
        $lon = (float) $input->getArgument('lon');

        $this->weatherService->invalidateForecast($lat, $lon);

        $output->writeln(sprintf('✅ Cache invalidated for coordinates %.2f, %.2f', $lat, $lon));

        return Command::SUCCESS;
    }
}
