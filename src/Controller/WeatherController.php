<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\WeatherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class WeatherController extends AbstractController
{
    public function __construct(
        private readonly WeatherService $weatherService,
    ) {
    }

    /**
     * Fetch forecast by city name or coordinates.
     *
     * Examples:
     *   /weather?city=Paris
     *   /weather?lat=48.8566&lon=2.3522
     */
    #[Route('/weather', name: 'weather', methods: ['GET'])]
    public function fetch(Request $request): JsonResponse
    {
        $city = $request->query->get('city');
        $lat = $request->query->get('lat');
        $lon = $request->query->get('lon');

        $result = null;

        if ($city) {
            $result = $this->weatherService->getForecastByCity($city);
        } elseif ($lat && $lon) {
            $result = $this->weatherService->getForecastByCoords((float) $lat, (float) $lon);
        }

        if (null === $result) {
            return $this->json(
                ['error' => 'Unable to fetch forecast.'],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        return $this->json($result);
    }
}
