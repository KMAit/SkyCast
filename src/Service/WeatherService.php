<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Small wrapper around Open-Meteo APIs.
 *
 * Responsibilities:
 *  - Geocode a city name to coordinates
 *  - Fetch current + hourly forecast for given coordinates
 *  - Convenience: fetch forecast for a city (geocode + forecast)
 *
 * Note: no reverse geocoding on purpose (not provided as a stable public endpoint).
 */
final class WeatherService
{
    /** Base URL for geocoding (city → coordinates). */
    private const GEO_BASE = 'https://geocoding-api.open-meteo.com/v1/search';

    /** Base URL for weather forecast. */
    private const METEO_BASE = 'https://api.open-meteo.com/v1/forecast';

    public function __construct(
        private readonly HttpClientInterface $http,
    ) {
    }

    /**
     * Geocode a city name using Open-Meteo.
     *
     * @param string $cityName Human-entered city name
     * @param int    $count    Max results to fetch (default 1)
     * @param string $language Output language (e.g. "fr")
     *
     * @return array|null Normalized first match or null when no result / on failure.
     *                    Keys:
     *                    - name      (string)
     *                    - latitude  (float|null)
     *                    - longitude (float|null)
     *                    - country   (string)
     *                    - admin1    (string)
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
        } catch (TransportExceptionInterface|\Throwable) {
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
     * Fetch current conditions and a short hourly forecast for given coordinates.
     *
     * @param float  $latitude  Decimal degrees
     * @param float  $longitude Decimal degrees
     * @param string $timezone  IANA TZ (e.g. "Europe/Paris" or "auto")
     * @param int    $hours     Number of hourly points to keep (default 12)
     *
     * @return array|null Forecast payload or null on failure.
     *                    Keys:
     *                    - location: [latitude, longitude]
     *                    - current:  current weather data or null
     *                    - hourly:   list of rows {time, temperature, wind, precip}
     */
    public function getForecastByCoords(
        float $latitude,
        float $longitude,
        string $timezone = 'auto',
        int $hours = 12,
    ): ?array {
        try {
            $response = $this->http->request('GET', self::METEO_BASE, [
                'query' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'timezone' => $timezone,
                    'current_weather' => 'true',
                    'hourly' => 'temperature_2m,precipitation,wind_speed_10m',
                ],
                'timeout' => 8,
            ]);

            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface|\Throwable) {
            return null;
        }

        if (!isset($payload['hourly']['time'], $payload['hourly']['temperature_2m'])) {
            return null;
        }

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
     * Convenience: geocode a city, then fetch its forecast.
     *
     * @param string $city     City name
     * @param string $timezone IANA TZ (e.g. "Europe/Paris" or "auto")
     * @param int    $hours    Number of hourly points to keep (default 12)
     *
     * @return array|null same structure as getForecastByCoords(),
     *                    with an extra "place" key describing the matched city
     */
    public function getForecastByCity(string $city, string $timezone = 'auto', int $hours = 12): ?array
    {
        $geoData = $this->geocodeCity($city);
        if (!$geoData || null === $geoData['latitude'] || null === $geoData['longitude']) {
            return null;
        }

        $forecast = $this->getForecastByCoords($geoData['latitude'], $geoData['longitude'], $timezone, $hours);
        if (null === $forecast) {
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
