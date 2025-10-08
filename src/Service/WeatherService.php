<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Weather data provider using Open-Meteo public APIs.
 *
 * Responsibilities:
 *  - Geocode city names to coordinates
 *  - Fetch current, hourly, and daily forecasts
 *  - Handle caching with Redis-safe keys
 *
 * Requests are lightweight and cached conservatively.
 */
final class WeatherService
{
    /** Base URL for geocoding (city → coordinates). */
    private const GEO_BASE = 'https://geocoding-api.open-meteo.com/v1/search';

    /** Base URL for weather forecast. */
    private const METEO_BASE = 'https://api.open-meteo.com/v1/forecast';

    private const PRECIP_EPSILON = 0.1;
    private const DRIZZLE_CUTOFF = 0.5;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Geocode a city name using Open-Meteo API with fallbacks.
     */
    public function geocodeCity(string $cityName, int $count = 1, string $language = 'fr'): ?array
    {
        if ('' === trim($cityName)) {
            return null;
        }

        $normalized = mb_strtolower(trim($cityName));
        $cacheKey   = $this->cacheKey('geocode', $language, (string) $count, $normalized);

        try {
            // Primary call: normalized city name in requested language
            $payload = $this->cache->get($cacheKey, function (ItemInterface $item) use ($normalized, $count, $language) {
                $item->expiresAfter(86400);
                $response = $this->http->request('GET', self::GEO_BASE, [
                    'query' => [
                        'name'     => $normalized,
                        'count'    => $count,
                        'language' => $language,
                        'format'   => 'json',
                    ],
                    'headers' => [
                        'User-Agent' => 'SkyCast/1.0 (+https://example.local)',
                        'Accept'     => 'application/json',
                    ],
                    'timeout' => 8,
                ]);

                return $response->toArray(false);
            });

            // Fallbacks if empty results
            if (!isset($payload['results'][0])) {
                // Try original casing, same language
                $response2 = $this->http->request('GET', self::GEO_BASE, [
                    'query' => [
                        'name'     => trim($cityName),
                        'count'    => $count,
                        'language' => $language,
                        'format'   => 'json',
                    ],
                    'headers' => [
                        'User-Agent' => 'SkyCast/1.0 (+https://example.local)',
                        'Accept'     => 'application/json',
                    ],
                    'timeout' => 8,
                ]);
                $payload2 = $response2->toArray(false);

                if (isset($payload2['results'][0])) {
                    $payload = $payload2;
                } else {
                    // Try English as a last resort
                    $response3 = $this->http->request('GET', self::GEO_BASE, [
                        'query' => [
                            'name'     => trim($cityName),
                            'count'    => $count,
                            'language' => 'en',
                            'format'   => 'json',
                        ],
                        'headers' => [
                            'User-Agent' => 'SkyCast/1.0 (+https://example.local)',
                            'Accept'     => 'application/json',
                        ],
                        'timeout' => 8,
                    ]);
                    $payload3 = $response3->toArray(false);

                    if (!isset($payload3['results'][0])) {
                        $hint = $this->extractApiError(is_array($payload) ? $payload : []);
                        throw new \DomainException('City not found: '.$cityName.$hint);
                    }

                    $payload = $payload3;
                }
            }
        } catch (\DomainException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Geocoding failed: '.$e->getMessage(), 0, $e);
        }

        $first = $payload['results'][0];

        return [
            'name'      => (string) ($first['name'] ?? $cityName),
            'latitude'  => isset($first['latitude']) ? (float) $first['latitude'] : null,
            'longitude' => isset($first['longitude']) ? (float) $first['longitude'] : null,
            'country'   => (string) ($first['country'] ?? ''),
            'admin1'    => (string) ($first['admin1'] ?? ''),
        ];
    }

    /**
     * Fetch forecast data (current, hourly, daily) for given coordinates.
     */
    public function getForecastByCoords(
        float $latitude,
        float $longitude,
        string $timezone = 'Europe/Paris',
        int $hours = 12,
    ): ?array {
        $cacheKey = $this->cacheKey(
            'forecast',
            number_format($latitude, 3, '.', ''),
            number_format($longitude, 3, '.', ''),
            $timezone
        );

        try {
            /** @var array<string,mixed> $payload */
            $payload = $this->cache->get($cacheKey, function (ItemInterface $item) use ($latitude, $longitude, $timezone) {
                $item->expiresAfter(600);

                $response = $this->http->request('GET', self::METEO_BASE, [
                    'query' => array_merge(
                        [
                            'latitude'  => $latitude,
                            'longitude' => $longitude,
                        ],
                        $this->hourlyHorizonQuery($timezone)
                    ),
                    'timeout' => 4.0,
                ]);

                return $response->toArray(false);
            });

            $hoursToday = $this->buildHoursToday($payload, $timezone);
        } catch (\Throwable) {
            return null;
        }

        // Guard: minimal hourly arrays must exist
        if (!isset($payload['hourly']['time'], $payload['hourly']['temperature_2m'])) {
            return null;
        }

        // Raw arrays
        $times          = $payload['hourly']['time'];
        $temperatures   = $payload['hourly']['temperature_2m']       ?? [];
        $windspeeds     = $payload['hourly']['wind_speed_10m']       ?? [];
        $precipitations = $payload['hourly']['precipitation']        ?? [];
        $weathercodes   = $payload['hourly']['weathercode']          ?? [];
        $humidities     = $payload['hourly']['relative_humidity_2m'] ?? [];
        $uvIndexes      = $payload['hourly']['uv_index']             ?? [];
        $winddirs       = $payload['hourly']['winddirection_10m']    ?? [];

        // Current block
        $currentWeather = $payload['current_weather'] ?? null;
        $current        = null;

        if (is_array($currentWeather)) {
            $cCode = isset($currentWeather['weathercode']) ? (int) $currentWeather['weathercode'] : null;
            $cMap  = $this->mapWeatherCode($cCode);

            $curHumidity = null;
            $curUvi      = null;

            if (!empty($payload['current_weather']['time'])) {
                $curIdx      = $this->findStartIndex($times, (string) $payload['current_weather']['time'], $timezone);
                $curHumidity = isset($humidities[$curIdx]) ? (float) $humidities[$curIdx] : null;
                $curUvi      = isset($uvIndexes[$curIdx]) ? (float) $uvIndexes[$curIdx] : null;
            }

            $current = [
                'temperature'   => isset($currentWeather['temperature']) ? (float) $currentWeather['temperature'] : null,
                'windspeed'     => isset($currentWeather['windspeed']) ? (float) $currentWeather['windspeed'] : null,
                'winddirection' => isset($currentWeather['winddirection']) ? (int) $currentWeather['winddirection'] : null,
                'time'          => (string) ($currentWeather['time'] ?? ''),
                'is_day'        => isset($currentWeather['is_day']) ? (int) $currentWeather['is_day'] : null,
                'weathercode'   => $cCode,
                'icon'          => $cMap['icon'],
                'label'         => $cMap['label'],
                'humidity'      => $curHumidity, // %
                'uv_index'      => $curUvi,      // 0..11+
            ];
        }

        // Rolling window for hourly slice starting at "now" index
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
                'time'          => (string) ($times[$idx] ?? ''),
                'temperature'   => isset($temperatures[$idx]) ? (float) $temperatures[$idx] : null,
                'wind'          => isset($windspeeds[$idx]) ? (float) $windspeeds[$idx] : null,
                'precip'        => $prec,
                'precip_label'  => $this->humanizePrecip($prec),
                'weathercode'   => $code,
                'icon'          => $state['icon'],
                'label'         => $state['label'],
                'humidity'      => isset($humidities[$idx]) ? (float) $humidities[$idx] : null,
                'uv_index'      => isset($uvIndexes[$idx]) ? (float) $uvIndexes[$idx] : null,
                'winddirection' => isset($winddirs[$idx]) ? (float) $winddirs[$idx] : null,
            ];
        }

        // Daily (up to 7 days)
        $daily = [];
        if (isset($payload['daily']['time'])) {
            $dDates      = $payload['daily']['time']               ?? [];
            $tmax        = $payload['daily']['temperature_2m_max'] ?? [];
            $tmin        = $payload['daily']['temperature_2m_min'] ?? [];
            $precSum     = $payload['daily']['precipitation_sum']  ?? [];
            $wcode       = $payload['daily']['weathercode']        ?? [];
            $uviMaxDaily = $payload['daily']['uv_index_max']       ?? [];

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
                    'uv_index_max' => isset($uviMaxDaily[$i]) ? (float) $uviMaxDaily[$i] : null,
                ];
            }
        }

        return [
            'location'    => ['latitude' => $latitude, 'longitude' => $longitude],
            'current'     => $current,
            'hourly'      => $hourly,
            'daily'       => $daily,
            'hours_today' => $hoursToday,
        ];
    }

    /**
     * Convenience: geocode a city, then fetch its forecast.
     */
    public function getForecastByCity(string $city, string $timezone = 'auto', int $hours = 12): ?array
    {
        $geo = $this->geocodeCity($city);
        if (!$geo || $geo['latitude'] === null || $geo['longitude'] === null) {
            return null;
        }

        $forecast = $this->getForecastByCoords($geo['latitude'], $geo['longitude'], $timezone, $hours);
        if ($forecast === null) {
            return null;
        }

        $forecast['place'] = [
            'name'    => $geo['name'],
            'country' => $geo['country'],
            'admin1'  => $geo['admin1'],
        ];

        return $forecast;
    }

    // ------------------------- Internals -------------------------

    private function mapWeatherCode(?int $code): array
    {
        if ($code === null) {
            return ['icon' => 'na', 'label' => 'Indisponible'];
        }

        return match (true) {
            0  === $code => ['icon' => 'sun',       'label' => 'Ciel clair'],
            1  === $code => ['icon' => 'sun',       'label' => 'Ciel dégagé'],
            2  === $code => ['icon' => 'cloud-sun', 'label' => 'Partiellement nuageux'],
            3  === $code => ['icon' => 'cloud',     'label' => 'Couvert'],
            45 === $code,
            48 === $code                 => ['icon' => 'fog',     'label' => 'Brouillard'],
            ($code >= 51 && $code <= 57) => ['icon' => 'drizzle', 'label' => 'Bruine'],
            ($code >= 61 && $code <= 67) => ['icon' => 'rain',    'label' => 'Pluie'],
            ($code >= 71 && $code <= 77) => ['icon' => 'snow',    'label' => 'Neige'],
            ($code >= 80 && $code <= 82) => ['icon' => 'rain',    'label' => 'Averses'],
            ($code >= 85 && $code <= 86) => ['icon' => 'snow',    'label' => 'Averses de neige'],
            ($code >= 95 && $code <= 99) => ['icon' => 'thunder', 'label' => 'Orage'],
            default                      => ['icon' => 'cloud-sun', 'label' => 'Indéterminé'],
        };
    }

    private function humanizePrecip(?float $mm): string
    {
        if ($mm === null || $mm <= 0.0) {
            return 'Aucune pluie';
        }
        if ($mm < 0.5) {
            return 'Faible pluie';
        }
        if ($mm < 4.0) {
            return 'Pluie modérée';
        }

        return 'Pluie forte';
    }

    /**
     * Return the starting index in $times for the first slot >= $nowIso (timezone-aware).
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

        // Fallback: last available index
        return max(0, \count($times) - 1);
    }

    /**
     * Build the list of hourly slots for the current local day (00:00 → 23:59).
     */
    private function buildHoursToday(array $payload, string $tz = 'Europe/Paris'): array
    {
        if (!isset($payload['hourly']['time'])) {
            return [];
        }

        $times          = $payload['hourly']['time'];
        $temperatures   = $payload['hourly']['temperature_2m']       ?? [];
        $windspeeds     = $payload['hourly']['wind_speed_10m']       ?? [];
        $precipitations = $payload['hourly']['precipitation']        ?? [];
        $weathercodes   = $payload['hourly']['weathercode']          ?? [];
        $humidities     = $payload['hourly']['relative_humidity_2m'] ?? [];
        $uvIndexes      = $payload['hourly']['uv_index']             ?? [];
        $winddirs       = $payload['hourly']['winddirection_10m']    ?? [];

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
            if ($slot < $startOfDay || $slot > $endOfDay) {
                continue;
            }

            $code  = isset($weathercodes[$i]) ? (int) $weathercodes[$i] : null;
            $mm    = isset($precipitations[$i]) ? (float) $precipitations[$i] : null;
            $state = $this->resolveHourlyState($code, $mm);

            $out[] = [
                'time'          => (string) $iso,
                'temperature'   => isset($temperatures[$i]) ? (float) $temperatures[$i] : null,
                'wind'          => isset($windspeeds[$i]) ? (float) $windspeeds[$i] : null,
                'precip'        => $mm,
                'precip_label'  => $this->humanizePrecip($mm),
                'weathercode'   => $code,
                'icon'          => $state['icon'],
                'label'         => $state['label'],
                'humidity'      => isset($humidities[$i]) ? (float) $humidities[$i] : null,
                'uv_index'      => isset($uvIndexes[$i]) ? (float) $uvIndexes[$i] : null,
                'is_past'       => $slot < $now,
                'is_now'        => $slot->format('H') === $now->format('H'),
                'winddirection' => isset($winddirs[$i]) ? (float) $winddirs[$i] : null,
            ];
        }

        return $out;
    }

    private function isRainCode(?int $code): bool
    {
        if (null === $code) {
            return false;
        }

        return ($code >= 51 && $code <= 57)  // drizzle
            || ($code >= 61 && $code <= 67)  // rain
            || ($code >= 80 && $code <= 82)  // showers
            || ($code >= 95 && $code <= 99); // thunder
    }

    private function resolveHourlyState(?int $code, ?float $prec): array
    {
        if ($prec === null) {
            return $this->mapWeatherCode($code);
        }

        if ($prec <= self::PRECIP_EPSILON) {
            if ($this->isRainCode($code)) {
                return ($code === 3)
                    ? ['icon' => 'cloud',     'label' => 'Couvert']
                    : ['icon' => 'cloud-sun', 'label' => 'Nuageux'];
            }

            return $this->mapWeatherCode($code);
        }

        $label = $this->humanizePrecip($prec);

        if ($code !== null && $code >= 71 && $code <= 86) {
            return ['icon' => 'snow', 'label' => $label];
        }

        return [
            'icon'  => ($prec < self::DRIZZLE_CUTOFF ? 'drizzle' : 'rain'),
            'label' => $label,
        ];
    }

    private function hourlyHorizonQuery(string $tz): array
    {
        return [
            'timezone'        => $tz,
            'current_weather' => 'true',
            'hourly'          => 'temperature_2m,precipitation,wind_speed_10m,winddirection_10m,weathercode,relative_humidity_2m,uv_index',
            'daily'           => 'temperature_2m_max,temperature_2m_min,precipitation_sum,weathercode,uv_index_max',
            'forecast_days'   => 7,
        ];
    }

    /**
     * Build a cache key that is safe for Symfony Cache (no reserved characters).
     * Reserved characters are replaced by dots: {}()/\@:
     */
    private function cacheKey(string ...$parts): string
    {
        $raw = implode('.', array_map(
            static fn ($p) => mb_strtolower(trim((string) $p)),
            $parts
        ));

        return preg_replace('/[{}()\/\\\\@:]+/', '.', $raw) ?? 'k';
    }

    /**
     * Extract a short API error from a payload (if any).
     */
    private function extractApiError(array $payload): string
    {
        foreach (['error', 'reason', 'message', 'detail'] as $k) {
            if (isset($payload[$k]) && is_string($payload[$k]) && $payload[$k] !== '') {
                return ' (API: '.$payload[$k].')';
            }
        }

        return '';
    }
}
