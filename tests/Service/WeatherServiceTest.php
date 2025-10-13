<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WeatherService;
use App\Tests\Support\CacheStubTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * WeatherService tests focused on current/hourly enrichment and cache behavior.
 *
 * Notes:
 * - Uses MockHttpClient to avoid real HTTP calls.
 * - Uses a lightweight CacheInterface stub to validate single fetch per key.
 */
final class WeatherServiceTest extends TestCase
{
    use CacheStubTrait;

    /** Simple in-memory cache stub with hit counter. */
    private function cacheStub(): array
    {
        $tracker = new \App\Tests\Support\CacheTracker();

        $cache = new class($tracker) implements CacheInterface {
            private \App\Tests\Support\CacheTracker $tracker;

            public function __construct(\App\Tests\Support\CacheTracker $tracker)
            {
                $this->tracker = $tracker;
            }

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                if (\array_key_exists($key, $this->tracker->store)) {
                    return $this->tracker->store[$key];
                }

                ++$this->tracker->hits;

                $item = new class implements \Symfony\Contracts\Cache\ItemInterface {
                    public function getKey(): string
                    {
                        return 'test_key';
                    }

                    public function get(): mixed
                    {
                        return null;
                    }

                    public function set(mixed $value): static
                    {
                        return $this;
                    }

                    public function expiresAt(?\DateTimeInterface $expiration): static
                    {
                        return $this;
                    }

                    public function expiresAfter(int|\DateInterval|null $time): static
                    {
                        return $this;
                    }

                    public function tag($tags): static
                    {
                        return $this;
                    }

                    public function isHit(): bool
                    {
                        return false;
                    }

                    public function getMetadata(): array
                    {
                        return [];
                    }
                };

                $value                      = $callback($item);
                $this->tracker->store[$key] = $value;

                return $value;
            }

            public function delete(string $key): bool
            {
                unset($this->tracker->store[$key]);

                return true;
            }

            public function invalidateTags(array $tags): bool
            {
                return true;
            }

            public function prune(): bool
            {
                return true;
            }
        };

        return [$cache, $tracker];
    }

    /** Returns a minimal but consistent Open-Meteo-like payload. */
    private function samplePayload(): array
    {
        $nowIso = '2025-10-09T10:00';

        return [
            'timezone' => 'Europe/Paris',
            'hourly'   => [
                'time'                      => ['2025-10-09T10:00', '2025-10-09T11:00', '2025-10-09T12:00'],
                'temperature_2m'            => [14.2, 15.0, 15.4],
                'apparent_temperature'      => [13.7, 14.4, 14.8],
                'wind_speed_10m'            => [12.0, 14.0, 16.0],
                'wind_gusts_10m'            => [22.0, 26.0, 28.0],
                'winddirection_10m'         => [320, 330, 340],
                'precipitation'             => [0.0, 0.2, 0.0],
                'precipitation_probability' => [10, 35, 5],
                'weathercode'               => [3, 2, 1],
                'relative_humidity_2m'      => [75, 70, 65],
                'uv_index'                  => [0.2, 0.6, 1.0],
            ],
            'daily' => [
                'time'               => ['2025-10-09', '2025-10-10'],
                'temperature_2m_min' => [11.0, 10.5],
                'temperature_2m_max' => [18.0, 17.5],
                'precipitation_sum'  => [0.4, 0.0],
                'weathercode'        => [2, 1],
                'uv_index_max'       => [3.5, 3.2],
            ],
            'current_weather' => [
                'time'          => $nowIso,
                'temperature'   => 14.2,
                'windspeed'     => 12.0,
                'winddirection' => 320,
                'is_day'        => 1,
                'weathercode'   => 3,
            ],
        ];
    }

    public function testGetForecastByCoordsEnrichesCurrentAndHourly(): void
    {
        $payload                  = $this->samplePayload();
        $responses                = [new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), ['http_code' => 200])];
        $client                   = new MockHttpClient($responses, 'https://api.open-meteo.com');
        [$cache, $_store, $_hits] = $this->cacheStub();

        $svc = new WeatherService($client, $cache);
        $out = $svc->getForecastByCoords(48.8566, 2.3522, 'Europe/Paris', hours: 2);

        self::assertIsArray($out);
        self::assertArrayHasKey('current', $out);
        self::assertArrayHasKey('hourly', $out);

        $cur = $out['current'];
        self::assertArrayHasKey('temperature', $cur);
        self::assertArrayHasKey('windspeed', $cur);
        self::assertArrayHasKey('gusts', $cur);
        self::assertArrayHasKey('precip_probability', $cur);
        self::assertArrayHasKey('feels_like', $cur);

        self::assertIsFloat($cur['temperature']);
        self::assertIsFloat($cur['windspeed']);
        self::assertTrue($cur['gusts'] === null || is_float($cur['gusts']));
        self::assertTrue($cur['precip_probability'] === null || is_float($cur['precip_probability']));
        self::assertTrue($cur['feels_like'] === null || is_float($cur['feels_like']));

        $hourly = $out['hourly'];
        self::assertCount(2, $hourly);
        $h0 = $hourly[0];
        self::assertArrayHasKey('gusts', $h0);
        self::assertArrayHasKey('precip_probability', $h0);
        self::assertArrayHasKey('feels_like', $h0);
    }

    public function testCacheHitAvoidsSecondHttpCall(): void
    {
        $payload     = $this->samplePayload();
        $callCounter = 0;

        $client = new MockHttpClient(function () use (&$callCounter, $payload) {
            ++$callCounter;

            return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), ['http_code' => 200]);
        }, 'https://api.open-meteo.com');

        // Create a shared cache stub and hit counter
        [$cache, $tracker] = $this->createCacheStub();

        // ---- STEP 2: simulate WeatherService using same cache ----
        $svc = new WeatherService($client, $cache);

        // First call
        $out1 = $svc->getForecastByCoords(48.8566, 2.3522, 'Europe/Paris', hours: 2);
        self::assertIsArray($out1);
        self::assertSame(1, $callCounter);
        self::assertSame(1, $tracker->hits, 'First cache miss triggers one callback');

        // Second call
        $out2 = $svc->getForecastByCoords(48.8566, 2.3522, 'Europe/Paris', hours: 2);
        self::assertIsArray($out2);
        self::assertSame(1, $callCounter, 'Cache reused, no extra HTTP call');
        self::assertSame(1, $tracker->hits, 'Cache callback not re-executed');
    }
}
