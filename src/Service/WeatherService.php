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
    public function geocodeCity(string $name, int $count = 1, string $language = 'fr'): ?array
    {
        if ('' === trim($name)) {
            return null;
        }

        try {
            $response = $this->http->request('GET', self::GEO_BASE, [
                'query' => [
                    'name' => $name,
                    'count' => $count,
                    'language' => $language,
                    'format' => 'json',
                ],
                'timeout' => 8,
            ]);

            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            // Network/timeout error — return null so controller can decide what to display
            return null;
        } catch (\Throwable $e) {
            return null;
        }

        if (!isset($data['results'][0])) {
            return null;
        }

        $r = $data['results'][0];

        return [
            'name' => (string) ($r['name'] ?? $name),
            'latitude' => isset($r['latitude']) ? (float) $r['latitude'] : null,
            'longitude' => isset($r['longitude']) ? (float) $r['longitude'] : null,
            'country' => (string) ($r['country'] ?? ''),
            'admin1' => (string) ($r['admin1'] ?? ''),
        ];
    }

    /**
     * Fetch current and hourly forecast for given coordinates.
     *
     * @return array|null Example:
     *                    [
     *                    'location' => ['latitude'=>.., 'longitude'=>..],
     *                    'current'  => ['temperature'=>22.1, 'windspeed'=>14.3, 'winddirection'=>240, 'time'=>'2025-09-18T14:00'],
     *                    'hourly'   => [
     *                    ['time'=>'2025-09-18T14:00', 'temperature'=>22.1, 'wind'=>14.3, 'precip'=>0.0],
     *                    ...
     *                    ]
     *                    ]
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

            $data = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }

        // Basic shape checks
        if (!isset($data['hourly']['time'], $data['hourly']['temperature_2m'])) {
            return null;
        }

        // Build a compact hourly array
        $times = $data['hourly']['time'];
        $temps = $data['hourly']['temperature_2m'] ?? [];
        $winds = $data['hourly']['wind_speed_10m'] ?? [];
        $precips = $data['hourly']['precipitation'] ?? [];

        $hourly = [];
        $len = min($hours, count($times));
        for ($i = 0; $i < $len; ++$i) {
            $hourly[] = [
                'time' => (string) ($times[$i] ?? ''),
                'temperature' => isset($temps[$i]) ? (float) $temps[$i] : null,      // °C
                'wind' => isset($winds[$i]) ? (float) $winds[$i] : null,      // km/h
                'precip' => isset($precips[$i]) ? (float) $precips[$i] : null,  // mm
            ];
        }

        $current = $data['current_weather'] ?? null;

        return [
            'location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
            'current' => $current ? [
                'temperature' => isset($current['temperature']) ? (float) $current['temperature'] : null,
                'windspeed' => isset($current['windspeed']) ? (float) $current['windspeed'] : null,
                'winddirection' => isset($current['winddirection']) ? (int) $current['winddirection'] : null,
                'time' => (string) ($current['time'] ?? ''),
                'is_day' => isset($current['is_day']) ? (int) $current['is_day'] : null,
                'weathercode' => isset($current['weathercode']) ? (int) $current['weathercode'] : null,
            ] : null,
            'hourly' => $hourly,
        ];
    }

    /**
     * Convenience method: geocode a city then fetch forecast for it.
     * Returns null if any step fails.
     */
    public function getForecastByCity(string $city, string $timezone = 'auto', int $hours = 12): ?array
    {
        $geo = $this->geocodeCity($city);
        if (!$geo || null === $geo['latitude'] || null === $geo['longitude']) {
            return null;
        }

        $forecast = $this->getForecastByCoords($geo['latitude'], $geo['longitude'], $timezone, $hours);
        if (!$forecast) {
            return null;
        }

        $forecast['place'] = [
            'name' => $geo['name'],
            'country' => $geo['country'],
            'admin1' => $geo['admin1'],
        ];

        return $forecast;
    }
}
