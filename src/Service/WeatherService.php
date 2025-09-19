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
 *  - Fetch daily forecast (min/max temps, precipitation, weather code)
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
     * Fetch current conditions, an hourly slice, and a 7-day daily forecast for given coordinates.
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
     *                    - daily:    list of rows {date, tmin, tmax, precip_mm, weathercode}
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
                    // Hourly variables
                    'hourly' => 'temperature_2m,precipitation,wind_speed_10m',
                    // Daily variables
                    'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum,weathercode',
                    'forecast_days' => 7,
                ],
                'timeout' => 8,
            ]);

            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface|\Throwable) {
            return null;
        }

        // --- Hourly mapping ---
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

        // --- Current mapping ---
        $currentWeather = $payload['current_weather'] ?? null;
        $current = $currentWeather ? [
            'temperature' => isset($currentWeather['temperature']) ? (float) $currentWeather['temperature'] : null,
            'windspeed' => isset($currentWeather['windspeed']) ? (float) $currentWeather['windspeed'] : null,
            'winddirection' => isset($currentWeather['winddirection']) ? (int) $currentWeather['winddirection'] : null,
            'time' => (string) ($currentWeather['time'] ?? ''),
            'is_day' => isset($currentWeather['is_day']) ? (int) $currentWeather['is_day'] : null,
            'weathercode' => isset($currentWeather['weathercode']) ? (int) $currentWeather['weathercode'] : null,
        ] : null;

        // --- Daily mapping (7 days) ---
        $daily = [];
        if (isset($payload['daily']['time'])) {
            $dDates = $payload['daily']['time'] ?? [];
            $tmax = $payload['daily']['temperature_2m_max'] ?? [];
            $tmin = $payload['daily']['temperature_2m_min'] ?? [];
            $precSum = $payload['daily']['precipitation_sum'] ?? [];
            $wcode = $payload['daily']['weathercode'] ?? [];

            $dCount = count($dDates);
            for ($i = 0; $i < $dCount; ++$i) {
                $daily[] = [
                    'date' => (string) ($dDates[$i] ?? ''),                        // e.g. "2025-09-19"
                    'tmin' => isset($tmin[$i]) ? (float) $tmin[$i] : null,        // °C
                    'tmax' => isset($tmax[$i]) ? (float) $tmax[$i] : null,        // °C
                    'precip_mm' => isset($precSum[$i]) ? (float) $precSum[$i] : null,  // mm
                    'weathercode' => isset($wcode[$i]) ? (int) $wcode[$i] : null,
                ];
            }
        }

        return [
            'location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
            'current' => $current,
            'hourly' => $hourlyForecasts,
            'daily' => $daily,
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
