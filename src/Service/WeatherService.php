<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WeatherService
{
    private const GEO_BASE = 'https://geocoding-api.open-meteo.com/v1/search';
    private const METEO_BASE = 'https://api.open-meteo.com/v1/forecast';

    public function __construct(
        private readonly HttpClientInterface $http,
    ) {
    }

    /**
     * Geocode a city name to coordinates using Open-Meteo's free geocoding API.
     *
     * @return array|null Example:
     *                    [
     *                    'name' => 'Paris',
     *                    'latitude' => 48.8566,
     *                    'longitude' => 2.3522,
     *                    'country' => 'France',
     *                    'admin1' => 'Île-de-France',
     *                    ]
     */
    public function geocodeCity(string $cityName, int $count = 1, string $language = 'fr'): ?array
    {
        if ('' === trim($cityName)) {
            return null;
        }

        try {
            $response = $this->http->request('GET', self::GEO_BASE, [
                'query' => [
                    'name' => $cityName,
                    'count' => $count,
                    'language' => $language,
                    'format' => 'json',
                ],
                'timeout' => 8,
            ]);

            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }

        if (!isset($payload['results'][0])) {
            return null;
        }

        $firstResult = $payload['results'][0];

        return [
            'name' => (string) ($firstResult['name'] ?? $cityName),
            'latitude' => isset($firstResult['latitude']) ? (float) $firstResult['latitude'] : null,
            'longitude' => isset($firstResult['longitude']) ? (float) $firstResult['longitude'] : null,
            'country' => (string) ($firstResult['country'] ?? ''),
            'admin1' => (string) ($firstResult['admin1'] ?? ''),
        ];
    }

    /**
     * Fetch current and hourly forecast for given coordinates.
     */
    public function getForecastByCoords(float $latitude, float $longitude, string $timezone = 'auto', int $hours = 12): ?array
    {
        try {
            $response = $this->http->request('GET', self::METEO_BASE, [
                'query' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'timezone' => $timezone,
                    'current_weather' => 'true',
                    'hourly' => implode(',', [
                        'temperature_2m',
                        'precipitation',
                        'wind_speed_10m',
                    ]),
                ],
                'timeout' => 8,
            ]);

            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }

        if (!isset($payload['hourly']['time'], $payload['hourly']['temperature_2m'])) {
            return null;
        }

        // Extract hourly arrays
        $times = $payload['hourly']['time'];
        $temperatures = $payload['hourly']['temperature_2m'] ?? [];
        $windspeeds = $payload['hourly']['wind_speed_10m'] ?? [];
        $precipitations = $payload['hourly']['precipitation'] ?? [];

        $hourlyForecasts = [];
        $limit = min($hours, count($times));

        for ($i = 0; $i < $limit; ++$i) {
            $hourlyForecasts[] = [
                'time' => (string) ($times[$i] ?? ''),
                'temperature' => isset($temperatures[$i]) ? (float) $temperatures[$i] : null,
                'wind' => isset($windspeeds[$i]) ? (float) $windspeeds[$i] : null,
                'precip' => isset($precipitations[$i]) ? (float) $precipitations[$i] : null,
            ];
        }

        $currentWeather = $payload['current_weather'] ?? null;

        return [
            'location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
            'current' => $currentWeather ? [
                'temperature' => isset($currentWeather['temperature']) ? (float) $currentWeather['temperature'] : null,
                'windspeed' => isset($currentWeather['windspeed']) ? (float) $currentWeather['windspeed'] : null,
                'winddirection' => isset($currentWeather['winddirection']) ? (int) $currentWeather['winddirection'] : null,
                'time' => (string) ($currentWeather['time'] ?? ''),
                'is_day' => isset($currentWeather['is_day']) ? (int) $currentWeather['is_day'] : null,
                'weathercode' => isset($currentWeather['weathercode']) ? (int) $currentWeather['weathercode'] : null,
            ] : null,
            'hourly' => $hourlyForecasts,
        ];
    }

    /**
     * Convenience method: geocode a city then fetch forecast for it.
     */
    public function getForecastByCity(string $city, string $timezone = 'auto', int $hours = 12): ?array
    {
        $geoData = $this->geocodeCity($city);
        if (!$geoData || null === $geoData['latitude'] || null === $geoData['longitude']) {
            return null;
        }

        $forecast = $this->getForecastByCoords($geoData['latitude'], $geoData['longitude'], $timezone, $hours);
        if (!$forecast) {
            return null;
        }

        $forecast['place'] = [
            'name' => $geoData['name'],
            'country' => $geoData['country'],
            'admin1' => $geoData['admin1'],
        ];

        return $forecast;
    }
}
