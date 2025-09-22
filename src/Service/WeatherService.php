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

    private const PRECIP_EPSILON = 0.1;
    private const DRIZZLE_CUTOFF = 0.5; // mm under which we show "drizzle" icon

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
                    'name'     => $cityName,
                    'count'    => $count,
                    'language' => $language,
                    'format'   => 'json',
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
            'name'      => (string) ($firstResult['name'] ?? $cityName),
            'latitude'  => isset($firstResult['latitude']) ? (float) $firstResult['latitude'] : null,
            'longitude' => isset($firstResult['longitude']) ? (float) $firstResult['longitude'] : null,
            'country'   => (string) ($firstResult['country'] ?? ''),
            'admin1'    => (string) ($firstResult['admin1'] ?? ''),
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
        string $timezone = 'Europe/Paris',
        int $hours = 12,
    ): ?array {
        try {
            $response = $this->http->request('GET', self::METEO_BASE, [
                'query' => array_merge(
                    [
                        'latitude'  => $latitude,
                        'longitude' => $longitude,
                    ],
                    $this->hourlyHorizonQuery($timezone)
                ),
                'timeout' => 8,
            ]);

            $payload    = $response->toArray(false);
            $hoursToday = $this->buildHoursToday($payload, $timezone);
        } catch (\Throwable) {
            return null;
        }

        // --- Guard: hourly arrays must exist
        if (!isset($payload['hourly']['time'], $payload['hourly']['temperature_2m'])) {
            return null;
        }

        // --- Extract hourly raw arrays
        $times          = $payload['hourly']['time'];
        $temperatures   = $payload['hourly']['temperature_2m'] ?? [];
        $windspeeds     = $payload['hourly']['wind_speed_10m'] ?? [];
        $precipitations = $payload['hourly']['precipitation']  ?? [];
        $weathercodes   = $payload['hourly']['weathercode']    ?? [];
        // --- Current block (icon/label derived from weathercode if present)
        $currentWeather = $payload['current_weather'] ?? null;
        $current        = null;
        if (is_array($currentWeather)) {
            $cCode   = isset($currentWeather['weathercode']) ? (int) $currentWeather['weathercode'] : null;
            $cMap    = $this->mapWeatherCode($cCode);
            $current = [
                'temperature'   => isset($currentWeather['temperature']) ? (float) $currentWeather['temperature'] : null,
                'windspeed'     => isset($currentWeather['windspeed']) ? (float) $currentWeather['windspeed'] : null,
                'winddirection' => isset($currentWeather['winddirection']) ? (int) $currentWeather['winddirection'] : null,
                'time'          => (string) ($currentWeather['time'] ?? ''),
                'is_day'        => isset($currentWeather['is_day']) ? (int) $currentWeather['is_day'] : null,
                'weathercode'   => $cCode,
                'icon'          => $cMap['icon'],
                'label'         => $cMap['label'],
            ];
        }

        // --- Rolling 12h window starting at current (timezone-safe)
        $startIndex = 0;
        if (!empty($payload['current_weather']['time'])) {
            $startIndex = $this->findStartIndex($times, (string) $payload['current_weather']['time'], $timezone);
        }
        $windowCount = min($hours, max(0, count($times) - $startIndex));

        $hourly = [];
        for ($i = 0; $i < $windowCount; ++$i) {
            $idx  = $startIndex + $i;
            $code = isset($weathercodes[$idx]) ? (int) $weathercodes[$idx] : null;

            $prec  = isset($precipitations[$idx]) ? (float) $precipitations[$idx] : null;
            $state = $this->resolveHourlyState($code, $prec);

            $hourly[] = [
                'time'         => (string) ($times[$idx] ?? ''),
                'temperature'  => isset($temperatures[$idx]) ? (float) $temperatures[$idx] : null,
                'wind'         => isset($windspeeds[$idx]) ? (float) $windspeeds[$idx] : null,
                'precip'       => $prec,
                'precip_label' => $this->humanizePrecip($prec),
                'weathercode'  => $code,
                'icon'         => $state['icon'],
                'label'        => $state['label'],
            ];
        }

        // --- Daily (7 days from API; we requested 3 days for safety but daily provides 7 by default if asked)
        $daily = [];
        if (isset($payload['daily']['time'])) {
            $dDates  = $payload['daily']['time']               ?? [];
            $tmax    = $payload['daily']['temperature_2m_max'] ?? [];
            $tmin    = $payload['daily']['temperature_2m_min'] ?? [];
            $precSum = $payload['daily']['precipitation_sum']  ?? [];
            $wcode   = $payload['daily']['weathercode']        ?? [];

            $dCount = count($dDates);
            for ($i = 0; $i < $dCount; ++$i) {
                $code = isset($wcode[$i]) ? (int) $wcode[$i] : null;
                $map  = $this->mapWeatherCode($code);
                $mm   = isset($precSum[$i]) ? (float) $precSum[$i] : null;

                $daily[] = [
                    'date'         => (string) ($dDates[$i] ?? ''),
                    'tmin'         => isset($tmin[$i]) ? (float) $tmin[$i] : null,
                    'tmax'         => isset($tmax[$i]) ? (float) $tmax[$i] : null,
                    'precip_mm'    => $mm,
                    'precip_label' => $this->humanizePrecip($mm),
                    'weathercode'  => $code,
                    'icon'         => $map['icon'],
                    'label'        => $map['label'],
                ];
            }
        }

        return [
            'location' => [
                'latitude'  => $latitude,
                'longitude' => $longitude,
            ],
            'current'     => $current,
            'hourly'      => $hourly,
            'daily'       => $daily,
            'hours_today' => $hoursToday,
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
            'name'    => $geoData['name'],
            'country' => $geoData['country'],
            'admin1'  => $geoData['admin1'],
        ];

        return $forecast;
    }

    /**
     * Map Open-Meteo weather codes to an icon slug and a short human label.
     * Icon slugs must exist in the SVG sprite (e.g. icon-sun, icon-cloud, …).
     *
     * @param int|null $code Open-Meteo weathercode
     *
     * @return array{icon:string,label:string}
     */
    private function mapWeatherCode(?int $code): array
    {
        if (null === $code) {
            return ['icon' => 'na', 'label' => 'Indisponible'];
        }

        // Reference: https://open-meteo.com/en/docs (Weathercode)
        return match (true) {
            0  === $code => ['icon' => 'sun',       'label' => 'Ciel clair'],
            1  === $code,
            2  === $code,
            3  === $code => ['icon' => 'cloud-sun', 'label' => 'Partiellement nuageux'],
            45 === $code,
            48 === $code               => ['icon' => 'fog',       'label' => 'Brouillard'],
            $code >= 51 && $code <= 57 => ['icon' => 'drizzle',   'label' => 'Bruine'],
            $code >= 61 && $code <= 67 => ['icon' => 'rain',      'label' => 'Pluie'],
            $code >= 71 && $code <= 77 => ['icon' => 'snow',      'label' => 'Neige'],
            $code >= 80 && $code <= 82 => ['icon' => 'rain',      'label' => 'Averses'],
            $code >= 85 && $code <= 86 => ['icon' => 'snow',      'label' => 'Averses de neige'],
            $code >= 95 && $code <= 99 => ['icon' => 'thunder',   'label' => 'Orage'],
            default                    => ['icon' => 'na',        'label' => 'Indéterminé'],
        };
    }

    /**
     * Return the starting index in $times for the first slot >= $nowIso.
     * Compares using DateTimeImmutable in the same timezone for robustness.
     *
     * @param list<string> $times  ISO 8601 hours from Open-Meteo (e.g. "2025-09-21T23:00")
     * @param string       $nowIso ISO 8601 current time from payload["current_weather"]["time"]
     * @param string       $tz     IANA timezone (e.g. "Europe/Paris")
     */
    private function findStartIndex(array $times, string $nowIso, string $tz = 'Europe/Paris'): int
    {
        try {
            $now = new \DateTimeImmutable($nowIso, new \DateTimeZone($tz));
        } catch (\Throwable) {
            return 0;
        }

        foreach ($times as $i => $iso) {
            try {
                $slot = new \DateTimeImmutable($iso, new \DateTimeZone($tz));
            } catch (\Throwable) {
                continue;
            }
            if ($slot >= $now) {
                return $i;
            }
        }

        // fallback: last available block if none is >= now
        return max(0, \count($times) - 1);
    }

    /**
     * Convert precipitation amount (mm) to a human-friendly label.
     */
    private function humanizePrecip(?float $mm): string
    {
        if ($mm === null) {
            return '—';
        }
        if ($mm < 0.1) {
            return 'Aucune pluie';
        }
        if ($mm < 1.0) {
            return 'Faible pluie';
        }
        if ($mm < 4.0) {
            return 'Pluie modérée';
        }
        if ($mm < 10.0) {
            return 'Pluie soutenue';
        }

        return 'Pluie forte';
    }

    /**
     * Build the list of hourly slots for the current local day (00:00 → 23:00),
     * enriched with temperature, wind, precipitation, icon/label, and flags.
     *
     * @param array<string,mixed> $payload Open-Meteo response
     *
     * @return list<array{
     *   time: string,
     *   temperature: float|null,
     *   wind: float|null,
     *   precip: float|null,
     *   weathercode: int|null,
     *   icon: string,
     *   label: string,
     *   is_past: bool,
     *   is_now: bool
     * }>
     */
    private function buildHoursToday(array $payload, string $tz = 'Europe/Paris'): array
    {
        if (!isset($payload['hourly']['time'])) {
            return [];
        }

        $times          = $payload['hourly']['time'];
        $temperatures   = $payload['hourly']['temperature_2m'] ?? [];
        $windspeeds     = $payload['hourly']['wind_speed_10m'] ?? [];
        $precipitations = $payload['hourly']['precipitation']  ?? [];
        $weathercodes   = $payload['hourly']['weathercode']    ?? [];

        // Determine the local "today" boundaries
        $now        = new \DateTimeImmutable('now', new \DateTimeZone($tz));
        $startOfDay = $now->setTime(0, 0);
        $endOfDay   = $now->setTime(23, 59, 59);

        $out = [];
        foreach ($times as $i => $iso) {
            try {
                $slot = new \DateTimeImmutable((string) $iso, new \DateTimeZone($tz));
            } catch (\Throwable) {
                continue;
            }

            // Keep only slots that belong to "today" (local)
            if ($slot < $startOfDay || $slot > $endOfDay) {
                continue;
            }

            $code  = isset($weathercodes[$i]) ? (int) $weathercodes[$i] : null;
            $map   = $this->mapWeatherCode($code);
            $mm    = isset($precipitations[$i]) ? (float) $precipitations[$i] : null;
            $state = $this->resolveHourlyState($code, $mm);

            $out[] = [
                'time'         => (string) $iso,
                'temperature'  => isset($temperatures[$i]) ? (float) $temperatures[$i] : null,
                'wind'         => isset($windspeeds[$i]) ? (float) $windspeeds[$i] : null,
                'precip'       => $mm,
                'precip_label' => $this->humanizePrecip($mm),
                'weathercode'  => $code,
                'icon'         => $state['icon'],
                'label'        => $state['label'],
                'is_past'      => $slot < $now,
                'is_now'       => $slot->format('H') === $now->format('H'),
            ];
        }

        return $out;
    }

    /**
     * Ensure the hourly query has enough horizon (48h) so that today is fully covered.
     */
    private function hourlyHorizonQuery(string $tz): array
    {
        return [
            'timezone'        => $tz,
            'current_weather' => 'true',
            // hourly variables (include weathercode for icons/labels)
            'hourly' => 'temperature_2m,precipitation,wind_speed_10m,weathercode',
            // daily variables
            'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum,weathercode',
            // ensure enough horizon to cross midnight safely
            'forecast_days' => 7,
        ];
    }

    /**
     * True if code belongs to rain/thunder families in Open-Meteo WMO table.
     */
    private function isRainCode(?int $code): bool
    {
        if (null === $code) {
            return false;
        }

        return ($code >= 51 && $code <= 67)  // drizzle / freezing rain
            || ($code >= 80 && $code <= 82)  // showers
            || ($code >= 95 && $code <= 99); // thunder
    }

    /**
     * Resolve a consistent weather state (icon + label) for an hourly slot.
     *
     * Logic:
     * - If precipitation is missing: fall back to weather code mapping.
     * - If precipitation is ≤ epsilon (≈0 mm): override rainy codes to "Cloudy" to avoid conflicts.
     * - If precipitation is > epsilon: use a human-friendly precipitation label
     *   and select drizzle/rain/snow icons depending on intensity and code.
     *
     * @param int|null   $code Weather code from API (may indicate rain, snow, etc.)
     * @param float|null $prec Precipitation amount (mm)
     *
     * @return array{icon: string, label: string} Icon identifier + user-facing label
     */
    private function resolveHourlyState(?int $code, ?float $prec): array
    {
        // No precipitation data: fallback to weather code mapping
        if ($prec === null) {
            return $this->mapWeatherCode($code);
        }

        // Zero or near zero precipitation: avoid showing "rain" when it does not rain
        if ($prec <= self::PRECIP_EPSILON) {
            // If the code indicates rain, degrade to a neutral "Cloudy"
            if ($this->isRainCode($code)) {
                return ['icon' => 'cloud-sun', 'label' => 'Cloudy'];
            }

            return $this->mapWeatherCode($code);
        }

        // Measurable precipitation: prefer intensity-based label
        $label = $this->humanizePrecip($prec);

        // Snow codes → force snow icon
        if ($code !== null && $code >= 71 && $code <= 86) {
            return ['icon' => 'snow', 'label' => $label];
        }

        // Distinguish drizzle vs rain based on threshold
        return [
            'icon'  => ($prec < self::DRIZZLE_CUTOFF ? 'drizzle' : 'rain'),
            'label' => $label,
        ];
    }
}
