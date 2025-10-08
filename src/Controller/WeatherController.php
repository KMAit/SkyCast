<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\WeatherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Handles weather forecast requests by city or coordinates.
 */
final class WeatherController extends AbstractController
{
    public function __construct(
        private readonly WeatherService $weatherService,
    ) {
    }

    /**
     * Fetches a weather forecast by city name or coordinates.
     */
    #[Route('/weather', name: 'weather', methods: ['GET'])]
    public function fetch(Request $request): JsonResponse
    {
        $city = $request->query->get('city');
        $lat  = $request->query->get('lat');
        $lon  = $request->query->get('lon');

        try {
            if ($city) {
                // Step 1: Geocode to resolve city coordinates
                $geo = $this->weatherService->geocodeCity((string) $city);
                if ($geo === null || $geo['latitude'] === null || $geo['longitude'] === null) {
                    return $this->json(['error' => 'City not found'], 404);
                }

                // Step 2: Fetch forecast using coordinates
                $result = $this->weatherService->getForecastByCoords(
                    (float) $geo['latitude'],
                    (float) $geo['longitude']
                );

                if ($result === null) {
                    return $this->json(['error' => 'Unable to fetch forecast.'], 400);
                }

                // Attach place metadata
                $result['place'] = [
                    'name'    => (string) ($geo['name'] ?? $city),
                    'country' => (string) ($geo['country'] ?? ''),
                    'admin1'  => (string) ($geo['admin1'] ?? ''),
                ];

                return $this->json($result);
            }

            if ($lat && $lon) {
                // Fetch forecast directly from coordinates
                $result = $this->weatherService->getForecastByCoords((float) $lat, (float) $lon);
                if ($result === null) {
                    return $this->json(['error' => 'Unable to fetch forecast.'], 400);
                }

                return $this->json($result);
            }

            // Missing query parameters
            return $this->json(['error' => 'Missing query: city or lat/lon'], 400);
        } catch (\Throwable $e) {
            // Handle runtime errors
            $payload = ['error' => 'Unable to fetch forecast.'];

            try {
                if ((bool) $this->getParameter('kernel.debug')) {
                    $payload['detail'] = $e->getMessage();
                }
            } catch (\Throwable) {
                // ignore
            }

            return $this->json($payload, 502);
        }
    }
}
