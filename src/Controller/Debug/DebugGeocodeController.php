<?php

declare(strict_types=1);

namespace App\Controller\Debug;

use App\Service\WeatherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Development-only controller used to inspect raw geocoding results.
 */
final class DebugGeocodeController extends AbstractController
{
    public function __construct(
        private readonly WeatherService $weatherService,
    ) {
    }

    /**
     * Exposes a debug endpoint for geocoding lookups.
     *
     * Example:
     *   GET /_debug/geocode?city=Paris
     */
    #[Route('/_debug/geocode', name: 'debug_geocode', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        // Prevent exposure outside development environment
        if (!(bool) $this->getParameter('kernel.debug')) {
            throw $this->createNotFoundException();
        }

        $city = (string) $request->query->get('city', '');
        if ($city === '') {
            return $this->json(['error' => 'Missing query: city'], 400);
        }

        try {
            $result = $this->weatherService->geocodeCity($city);

            if ($result === null) {
                return $this->json([
                    'error' => 'No geocoding result',
                    'query' => $city,
                ], 404);
            }

            return $this->json([
                'query'  => $city,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            // Return structured error in dev mode for easier debugging
            $payload = ['error' => 'Geocoding failed'];

            try {
                if ((bool) $this->getParameter('kernel.debug')) {
                    $payload['detail'] = $e->getMessage();
                }
            } catch (\Throwable) {
                // Ignore parameter retrieval issues
            }

            return $this->json($payload, 502);
        }
    }
}
