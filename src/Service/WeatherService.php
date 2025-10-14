<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
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
 * Notes:
 *  - Cache keys are Redis-safe (no reserved characters).
 *  - Timeouts are conservative to keep the app responsive under network hiccups.
 */
class WeatherService
{
    /** Base URL for geocoding (city → coordinates). */
    private const GEO_BASE = 'https://geocoding-api.open-meteo.com/v1/search';

    /** Base URL for weather forecast. */
    private const METEO_BASE = 'https://api.open-meteo.com/v1/forecast';

    private const PRECIP_EPSILON = 0.1; // mm ~ “no rain”
    private const DRIZZLE_CUTOFF = 0.5; // mm under which “drizzle” icon is shown

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly TagAwareCacheInterface $cache,
    ) {
    }

    /**
     * Geocode a city name using Open-Meteo.
     *
     * @param string $cityName Human-entered city name
     * @param int    $count    Max results to fetch (default 1)
     * @param string $language Output language (e.g. "fr")
     *
     * @return array|null Normalized first match or null when no result
     */
    public function geocodeCity(string $cityName, int $count = 1, string $language = 'fr'): ?array
    {
        if ('' === trim($cityName)) {
            return null;
        }

        $normalized = mb_strtolower(trim($cityName));
        $cacheKey   = $this->cacheKey('geocode', $language, (string) $count, $normalized);

        try {
            // 1st try: normalized, fr (cached)
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

            if (!isset($payload['results'][0])) {
                // 2nd try: original case, same language (uncached small call)
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
                    // 3rd try: English fallback (uncached small call)
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
     *                    - hourly:   list of rows {time, temperature, wind, gusts, precip, precip_probability, ...}
     *                    - daily:    list of rows {date, tmin, tmax, precip_mm, weathercode}
     *                    - hours_today: hourly rows within the current local day (00:00→23:00)
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

                // Add tags to invalidate by coordinate pair
                if (method_exists($item, 'tag')) {
                    $item->tag([
                        'forecast',
                        sprintf(
                            'forecast_%s_%s',
                            number_format($latitude, 2),
                            number_format($longitude, 2)
                        ),
                    ]);
                }

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

        if (!isset($payload['hourly']['time'], $payload['hourly']['temperature_2m'])) {
            return null;
        }

        // Extract hourly raw arrays (defensive with defaults)
        $times          = $payload['hourly']['time'];
        $temperatures   = $payload['hourly']['temperature_2m']            ?? [];
        $apparentTemps  = $payload['hourly']['apparent_temperature']      ?? [];
        $windspeeds     = $payload['hourly']['wind_speed_10m']            ?? [];
        $windgusts      = $payload['hourly']['wind_gusts_10m']            ?? [];
        $precipitations = $payload['hourly']['precipitation']             ?? [];
        $precipProb     = $payload['hourly']['precipitation_probability'] ?? [];
        $weathercodes   = $payload['hourly']['weathercode']               ?? [];
        $humidities     = $payload['hourly']['relative_humidity_2m']      ?? []; // %
        $uvIndexes      = $payload['hourly']['uv_index']                  ?? []; // 0..11+
        $winddirs       = $payload['hourly']['winddirection_10m']         ?? [];

        // Current block (derive icon/label from weather code when possible)
        $currentWeather = $payload['current_weather'] ?? null;
        $current        = null;

        if (is_array($currentWeather)) {
            $cCode = isset($currentWeather['weathercode']) ? (int) $currentWeather['weathercode'] : null;
            $cMap  = $this->mapWeatherCode($cCode);

            $curHumidity = null;
            $curUvi      = null;
            $curGusts    = null;
            $curPrecProb = null;
            $curFeels    = null;

            if (!empty($currentWeather['time'])) {
                $curIdx = $this->findStartIndex($times, (string) $currentWeather['time'], $timezone);

                if ($curIdx !== null) {
                    $curHumidity = isset($humidities[$curIdx]) ? (float) $humidities[$curIdx] : null;
                    $curUvi      = isset($uvIndexes[$curIdx]) ? (float) $uvIndexes[$curIdx] : null;
                    $curGusts    = isset($windgusts[$curIdx]) ? (float) $windgusts[$curIdx] : null;
                    $curPrecProb = isset($precipProb[$curIdx]) ? (float) $precipProb[$curIdx] : null;

                    // Prefer API apparent_temperature when present; otherwise compute.
                    $apiApp = isset($apparentTemps[$curIdx]) ? (float) $apparentTemps[$curIdx] : null;
                    if ($apiApp !== null) {
                        $curFeels = $apiApp;
                    } else {
                        $curTemp  = isset($currentWeather['temperature']) ? (float) $currentWeather['temperature'] : null;
                        $curWind  = isset($currentWeather['windspeed']) ? (float) $currentWeather['windspeed'] : null; // km/h
                        $curFeels = $this->computeFeelsLike($curTemp, $curWind, $curHumidity);
                    }
                }
            }

            $current = [
                'temperature'        => isset($currentWeather['temperature']) ? (float) $currentWeather['temperature'] : null,
                'windspeed'          => isset($currentWeather['windspeed']) ? (float) $currentWeather['windspeed'] : null,
                'winddirection'      => isset($currentWeather['winddirection']) ? (int) $currentWeather['winddirection'] : null,
                'gusts'              => $curGusts,           // km/h
                'time'               => (string) ($currentWeather['time'] ?? ''),
                'is_day'             => isset($currentWeather['is_day']) ? (int) $currentWeather['is_day'] : null,
                'weathercode'        => $cCode,
                'icon'               => $cMap['icon'],
                'label'              => $cMap['label'],
                'humidity'           => $curHumidity,        // %
                'uv_index'           => $curUvi,             // 0..11+
                'precip_probability' => $curPrecProb,        // %
                'feels_like'         => $curFeels,           // °C
            ];
        }

        // Rolling $hours window starting at current (timezone-safe)
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

            $tC   = isset($temperatures[$idx]) ? (float) $temperatures[$idx] : null;
            $ws   = isset($windspeeds[$idx]) ? (float) $windspeeds[$idx] : null;
            $rh   = isset($humidities[$idx]) ? (float) $humidities[$idx] : null;
            $feel = isset($apparentTemps[$idx]) ? (float) $apparentTemps[$idx] : $this->computeFeelsLike($tC, $ws, $rh);

            $hourly[] = [
                'time'               => (string) ($times[$idx] ?? ''),
                'temperature'        => $tC,
                'feels_like'         => $feel,
                'wind'               => $ws,
                'gusts'              => isset($windgusts[$idx]) ? (float) $windgusts[$idx] : null,
                'precip'             => $prec,
                'precip_probability' => isset($precipProb[$idx]) ? (float) $precipProb[$idx] : null,
                'precip_label'       => $this->humanizePrecip($prec),
                'weathercode'        => $code,
                'icon'               => $state['icon'],
                'label'              => $state['label'],
                'humidity'           => $rh,
                'uv_index'           => isset($uvIndexes[$idx]) ? (float) $uvIndexes[$idx] : null,
                'winddirection'      => isset($winddirs[$idx]) ? (float) $winddirs[$idx] : null,
            ];
        }

        // Daily (7 days)
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
                // Compute daily averages from hourly data
                $dayStart = new \DateTimeImmutable($dDates[$i]);
                $nextDay  = $dayStart->modify('+1 day');

                $indices = array_keys(array_filter($times, fn ($t) => $t >= $dayStart->format('Y-m-d\T00:00') && $t < $nextDay->format('Y-m-d\T00:00')
                ));

                $humidValues = array_map(fn ($idx) => $humidities[$idx] ?? null, $indices);
                $windValues  = array_map(fn ($idx) => $windspeeds[$idx] ?? null, $indices);

                $avgHumidity = $this->average($humidValues);
                $avgWind     = $this->average($windValues);

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
                    'avg_humidity' => $avgHumidity ?? null,
                    'wind_speed'   => $avgWind     ?? null,
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
     * @return array|null Same structure as getForecastByCoords(),
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
        if ($code === null) {
            return ['icon' => 'na', 'label' => 'Indisponible'];
        }

        return match (true) {
            0  === $code => ['icon' => 'sun',       'label' => 'Ciel clair'],
            1  === $code => ['icon' => 'sun',       'label' => 'Ciel dégagé'],
            2  === $code => ['icon' => 'cloud-sun', 'label' => 'Partiellement nuageux'],
            3  === $code => ['icon' => 'cloud',     'label' => 'Couvert'],
            45 === $code,
            48 === $code                 => ['icon' => 'fog',       'label' => 'Brouillard'],
            ($code >= 51 && $code <= 57) => ['icon' => 'drizzle',   'label' => 'Bruine'],
            ($code >= 61 && $code <= 67) => ['icon' => 'rain',      'label' => 'Pluie'],
            ($code >= 71 && $code <= 77) => ['icon' => 'snow',      'label' => 'Neige'],
            ($code >= 80 && $code <= 82) => ['icon' => 'rain',      'label' => 'Averses'],
            ($code >= 85 && $code <= 86) => ['icon' => 'snow',      'label' => 'Averses de neige'],
            ($code >= 95 && $code <= 99) => ['icon' => 'thunder',   'label' => 'Orage'],
            default                      => ['icon' => 'cloud-sun', 'label' => 'Indéterminé'],
        };
    }

    /**
     * Return the starting index in $times for the first slot >= $nowIso.
     * Compares using DateTimeImmutable in the same timezone for robustness.
     *
     * @param list<string> $times  ISO 8601 hours (e.g. "2025-09-21T23:00")
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
                $slot = new \DateTimeImmutable((string) $iso, new \DateTimeZone($tz));
            } catch (\Throwable) {
                continue;
            }
            if ($slot >= $now) {
                return $i;
            }
        }

        // Fallback: last available block if none is >= now
        return max(0, \count($times) - 1);
    }

    /**
     * Convert precipitation amount (mm) to a human-friendly label.
     */
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
     * Build the list of hourly slots for the current local day (00:00 → 23:00),
     * enriched with temperature, wind, gusts, precipitation, probabilities, icon/label, and flags.
     *
     * @param array<string,mixed> $payload Open-Meteo response
     *
     * @return list<array{
     *   time: string,
     *   temperature: float|null,
     *   feels_like: float|null,
     *   wind: float|null,
     *   gusts: float|null,
     *   precip: float|null,
     *   precip_probability: float|null,
     *   weathercode: int|null,
     *   icon: string,
     *   label: string,
     *   humidity: float|null,
     *   uv_index: float|null,
     *   is_past: bool,
     *   is_now: bool,
     *   winddirection: float|null
     * }>
     */
    private function buildHoursToday(array $payload, string $tz = 'Europe/Paris'): array
    {
        if (!isset($payload['hourly']['time'])) {
            return [];
        }

        $times          = $payload['hourly']['time'];
        $temperatures   = $payload['hourly']['temperature_2m']            ?? [];
        $apparentTemps  = $payload['hourly']['apparent_temperature']      ?? [];
        $windspeeds     = $payload['hourly']['wind_speed_10m']            ?? [];
        $windgusts      = $payload['hourly']['wind_gusts_10m']            ?? [];
        $precipitations = $payload['hourly']['precipitation']             ?? [];
        $precipProb     = $payload['hourly']['precipitation_probability'] ?? [];
        $weathercodes   = $payload['hourly']['weathercode']               ?? [];
        $humidities     = $payload['hourly']['relative_humidity_2m']      ?? [];
        $uvIndexes      = $payload['hourly']['uv_index']                  ?? [];
        $winddirs       = $payload['hourly']['winddirection_10m']         ?? [];

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

            $tC   = isset($temperatures[$i]) ? (float) $temperatures[$i] : null;
            $ws   = isset($windspeeds[$i]) ? (float) $windspeeds[$i] : null;
            $rh   = isset($humidities[$i]) ? (float) $humidities[$i] : null;
            $feel = isset($apparentTemps[$i]) ? (float) $apparentTemps[$i] : $this->computeFeelsLike($tC, $ws, $rh);

            $out[] = [
                'time'               => (string) $iso,
                'temperature'        => $tC,
                'feels_like'         => $feel,
                'wind'               => $ws,
                'gusts'              => isset($windgusts[$i]) ? (float) $windgusts[$i] : null,
                'precip'             => $mm,
                'precip_probability' => isset($precipProb[$i]) ? (float) $precipProb[$i] : null,
                'precip_label'       => $this->humanizePrecip($mm),
                'weathercode'        => $code,
                'icon'               => $state['icon'],
                'label'              => $state['label'],
                'humidity'           => $rh, // %
                'uv_index'           => isset($uvIndexes[$i]) ? (float) $uvIndexes[$i] : null, // 0..11+
                'is_past'            => $slot < $now,
                'is_now'             => $slot->format('H') === $now->format('H'),
                'winddirection'      => isset($winddirs[$i]) ? (float) $winddirs[$i] : null,
            ];
        }

        return $out;
    }

    /**
     * Ensure the hourly query has enough horizon so that today is fully covered
     * and include the variables required by the UI (gusts, precip prob, UV, apparent temp).
     */
    private function hourlyHorizonQuery(string $tz): array
    {
        return [
            'timezone'        => $tz,
            'current_weather' => 'true',
            // hourly variables (include weathercode for icons/labels)
            'hourly' => implode(',', [
                'temperature_2m',
                'apparent_temperature',
                'precipitation',
                'precipitation_probability',
                'wind_speed_10m',
                'wind_gusts_10m',
                'winddirection_10m',
                'weathercode',
                'relative_humidity_2m',
                'uv_index',
            ]),
            // daily variables
            'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum,weathercode,uv_index_max',
            // enough horizon for rolling windows and crossing midnight
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

        return ($code >= 51 && $code <= 57)  // drizzle
            || ($code >= 61 && $code <= 67)  // rain
            || ($code >= 80 && $code <= 82)  // showers
            || ($code >= 95 && $code <= 99); // thunder
    }

    /**
     * Resolve a consistent weather state (icon + label) for an hourly slot.
     *
     * Logic:
     * - If precipitation is missing: fall back to weather code mapping.
     * - If precipitation is ≤ epsilon: override rainy codes to "Cloudy" variants to avoid conflicts.
     * - If precipitation is > epsilon: human-friendly precipitation label + drizzle/rain/snow icons.
     *
     * @param int|null   $code Weather code from API
     * @param float|null $prec Precipitation (mm)
     *
     * @return array{icon: string, label: string}
     */
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

    /**
     * Compute an approximate "feels like" temperature in °C.
     * Uses Steadman apparent temperature approximation:
     *   AT = T + 0.33*e - 0.70*wind - 4.00
     * where e is water vapour pressure (hPa):
     *   e = (rh/100) * 6.105 * exp(17.27*T / (237.7 + T))
     * and wind is in m/s (converted from km/h).
     *
     * Returns null when inputs are insufficient.
     */
    private function computeFeelsLike(?float $tempC, ?float $windKmH, ?float $rh): ?float
    {
        if ($tempC === null || $windKmH === null || $rh === null) {
            return null;
        }
        try {
            $windMs = $windKmH / 3.6;
            $e      = ($rh / 100.0) * 6.105 * \exp((17.27 * $tempC) / (237.7 + $tempC));

            return $tempC + (0.33 * $e) - (0.70 * $windMs) - 4.0;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Build a Redis-safe cache key by hashing the parts.
     *
     * @param string ...$parts Logical parts that will be joined into a single key.
     */
    protected function cacheKey(string ...$parts): string
    {
        $raw = implode('.', array_map(
            static fn ($p) => mb_strtolower(trim((string) $p)),
            $parts
        ));

        // Replace Symfony reserved characters with dots
        // Reserved: {}()/\@:
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

    private function average(array $values): ?float
    {
        $filtered = array_filter($values, fn ($v) => $v !== null);
        if (!$filtered) {
            return null;
        }

        return array_sum($filtered) / count($filtered);
    }

    /**
     * Invalidate cached forecast for a specific coordinate pair.
     */
    public function invalidateForecast(float $latitude, float $longitude): void
    {
        $tag = sprintf(
            'forecast_%s_%s',
            number_format($latitude, 2),
            number_format($longitude, 2)
        );

        if (method_exists($this->cache, 'invalidateTags')) {
            $this->cache->invalidateTags([$tag]);
        }
    }
}
