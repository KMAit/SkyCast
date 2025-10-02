<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\WeatherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * SkyCast landing page controller.
 *
 * Input modes:
 *  - GET /?city=Paris
 *  - GET /?lat=48.85&lon=2.35   (from geolocation)
 *
 * Renders the page with KPI cards and an hourly forecast slice.
 */
final class HomeController extends AbstractController
{
    public function __construct(
        private readonly WeatherService $weatherService,
    ) {
    }

    /**
     * Render the home page with optional forecast data (current, hourly, daily).
     *
     * @param Request $request HTTP request (reads 'city' or 'lat'/'lon' query params)
     *
     * @return Response Full HTML response
     */
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // --- Read query parameters
        $city = (string) $request->query->get('city', '');
        $lat  = $request->query->get('lat');
        $lon  = $request->query->get('lon');

        // --- Defaults
        $forecast            = null;
        $error               = null;
        $place               = null;
        $coords              = null;
        $current_updated_ago = null;

        // --- Fetch forecast from city or coordinates
        if ($city !== '') {
            $forecast = $this->weatherService->getForecastByCity($city, timezone: 'Europe/Paris', hours: 12);
            if ($forecast === null) {
                $error = sprintf('Impossible de trouver les prévisions pour « %s ».', $city);
            } else {
                $place  = $forecast['place']    ?? null;
                $coords = $forecast['location'] ?? null;
            }
        } elseif ($lat !== null && $lon !== null) {
            $forecast = $this->weatherService->getForecastByCoords((float) $lat, (float) $lon, timezone: 'Europe/Paris', hours: 12);
            if ($forecast === null) {
                $error = 'Impossible de récupérer les prévisions pour votre position.';
            } else {
                $coords = $forecast['location'] ?? null;
            }
        }

        // --- Normalize slices from forecast (defensive)
        $current    = is_array($forecast) ? ($forecast['current'] ?? null) : null;
        $hourly     = is_array($forecast) ? ($forecast['hourly'] ?? []) : [];
        $hoursToday = is_array($forecast) ? ($forecast['hours_today'] ?? []) : [];
        $daily      = is_array($forecast) ? ($forecast['daily'] ?? []) : [];
        $place      = is_array($forecast) ? ($forecast['place'] ?? $place) : $place;
        $coords     = is_array($forecast) ? ($forecast['location'] ?? $coords) : $coords;

        // --- KPI cards (temperature / wind / precip)
        // Temperature and wind from current; precip label from first hourly slot if present.
        $cards = [
            'temperature'   => '—',
            'wind'          => '—',
            'precipitation' => '—',
        ];

        if ($current !== null) {
            if (isset($current['temperature']) && $current['temperature'] !== null) {
                $cards['temperature'] = sprintf('<strong>%.1f°C</strong>', (float) $current['temperature']);
            }
            if (isset($current['windspeed']) && $current['windspeed'] !== null) {
                $cards['wind'] = sprintf('<strong>%.0f km/h</strong>', (float) $current['windspeed']);
            }
            if (!empty($forecast['current']['time'])) {
                try {
                    // Use same TZ as elsewhere to avoid inconsistencies
                    $tz                  = new \DateTimeZone('Europe/Paris');
                    $at                  = new \DateTimeImmutable((string) $forecast['current']['time'], $tz);
                    $now                 = new \DateTimeImmutable('now', $tz);
                    $current_updated_ago = $this->humanizeAgo($at, $now);
                } catch (\Throwable) {
                    $current_updated_ago = null;
                }
            }
        }
        if (!empty($hourly)) {
            // Prefer readable label (e.g. "Aucune pluie", "Pluie modérée") from service
            $first = $hourly[0];
            if (isset($first['precip_label']) && $first['precip_label'] !== null) {
                $cards['precipitation'] = $first['precip_label'];
            } elseif (isset($first['precip']) && $first['precip'] !== null) {
                $cards['precipitation'] = sprintf('%.1f mm', (float) $first['precip']);
            }
        }

        // --- Render
        return $this->render('home/index.html.twig', [
            'title'               => 'SkyCast - Votre météo simplifiée',
            'app_name'            => 'SkyCast',
            'city'                => $city,
            'current'             => $current,
            'current_updated_ago' => $current_updated_ago,
            'cards'               => $cards,
            'hours'               => $hourly,      // used by the chart
            'hours_today'         => $hoursToday,  // used by the carousel
            'days'                => $daily,       // 7-day daily forecast
            'coords'              => $coords,
            'place'               => $place,
            'error'               => $error,
        ]);
    }

    /**
     * Humanize a timestamp difference into short French text.
     * Examples: "à l’instant", "il y a 2 min", "il y a 3 h", "il y a 5 j".
     */
    private function humanizeAgo(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $delta = $to->getTimestamp() - $from->getTimestamp();

        if ($delta < 45) {
            return 'à l’instant';
        }
        if ($delta < 90) {
            return 'il y a 1 min';
        }
        $mins = (int) floor($delta / 60);
        if ($mins < 60) {
            return "il y a {$mins} min";
        }
        $hours = (int) floor($mins / 60);
        if ($hours < 24) {
            return "il y a {$hours} h";
        }
        $days = (int) floor($hours / 24);

        return "il y a {$days} j";
    }
}
