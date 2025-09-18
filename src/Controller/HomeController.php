<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\WeatherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Home page for SkyCast.
 *
 * Accepts either:
 *   - ?city=Paris
 *   - ?lat=48.8566&lon=2.3522
 * Renders the home template with cards + hourly forecast data when available.
 */
final class HomeController extends AbstractController
{
    public function __construct(
        private readonly WeatherService $weatherService,
    ) {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $city = (string) $request->query->get('city', '');
        $lat = $request->query->get('lat');
        $lon = $request->query->get('lon');

        $forecast = null;

        if ('' !== $city) {
            $forecast = $this->weatherService->getForecastByCity(
                $city,
                timezone: 'Europe/Paris',
                hours: 12
            );
        } elseif (null !== $lat && null !== $lon) {
            $forecast = $this->weatherService->getForecastByCoords(
                (float) $lat,
                (float) $lon,
                timezone: 'Europe/Paris',
                hours: 12
            );
        }

        // Default placeholders
        $cards = [
            'temperature' => '— en attente de résultats —',
            'wind' => '— en attente de résultats —',
            'precipitation' => '— en attente de résultats —',
        ];
        $hours = []; // expected by _forecast_hourly.html.twig

        if (null !== $forecast) {
            // Fill cards from current weather (fallback-safe)
            $current = $forecast['current'] ?? null;

            if (null !== $current) {
                $currentTemp = $current['temperature'] ?? null; // °C
                $currentWind = $current['windspeed'] ?? null;   // km/h

                $firstHourPrecip = null;
                if (!empty($forecast['hourly']) && isset($forecast['hourly'][0]['precip'])) {
                    $firstHourPrecip = (float) $forecast['hourly'][0]['precip']; // mm
                }

                $cards['temperature'] = null !== $currentTemp ? sprintf('<strong>%.1f°C</strong>', (float) $currentTemp) : '—';
                $cards['wind'] = null !== $currentWind ? sprintf('<strong>%.0f km/h</strong>', (float) $currentWind) : '—';
                $cards['precipitation'] = null !== $firstHourPrecip ? sprintf('<strong>%.1f mm</strong>', $firstHourPrecip) : '—';
            }

            // Map hourly data for the partial
            foreach ($forecast['hourly'] as $row) {
                $isoTime = (string) ($row['time'] ?? '');
                $hhmm = '' !== $isoTime ? substr($isoTime, 11, 5) : '—:—';

                $hours[] = [
                    'time' => $hhmm,
                    'temp' => isset($row['temperature']) ? sprintf('%.1f°C', (float) $row['temperature']) : '—',
                    'wind' => isset($row['wind']) ? sprintf('%.0f km/h', (float) $row['wind']) : '—',
                    'precip' => isset($row['precip']) ? sprintf('%.1f mm', (float) $row['precip']) : '—',
                ];
            }
        }

        return $this->render('home/index.html.twig', [
            'title' => 'SkyCast - Votre météo simplifiée',
            'app_name' => 'SkyCast',
            'cards' => $cards,
            'hours' => $hours,
            'city' => $city,
        ]);
    }
}
