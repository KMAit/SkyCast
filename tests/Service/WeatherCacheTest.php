<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WeatherService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Integration-like tests focusing on cache reuse and invalidation.
 * Uses a lightweight in-memory cache tracker.
 */
final class WeatherCacheTest extends TestCase
{
    private function samplePayload(): array
    {
        $nowIso = '2025-10-09T10:00';

        return [
            'timezone' => 'Europe/Paris',
            'hourly'   => [
                'time'                      => ['2025-10-09T10:00', '2025-10-09T11:00'],
                'temperature_2m'            => [15.0, 16.0],
                'apparent_temperature'      => [14.5, 15.2],
                'wind_speed_10m'            => [10.0, 12.0],
                'wind_gusts_10m'            => [20.0, 24.0],
                'precipitation'             => [0.0, 0.2],
                'precipitation_probability' => [5, 25],
                'weathercode'               => [1, 2],
                'relative_humidity_2m'      => [75, 70],
                'uv_index'                  => [0.4, 0.8],
            ],
            'current_weather' => [
                'time'        => $nowIso,
                'temperature' => 15.0,
                'windspeed'   => 10.0,
                'weathercode' => 1,
                'is_day'      => 1,
            ],
        ];
    }

    /** Creates a very small in-memory cache + tracker. */
    private function createCacheStub(): array
    {
        $tracker = new class {
            public int $hits    = 0;
            public array $store = [];
        };

        $cache = new class($tracker) implements CacheInterface {
            private $tracker;

            public function __construct($tracker)
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
                        return 'test';
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

    public function testWeatherServiceReusesCacheBetweenCalls(): void
    {
        $payload     = $this->samplePayload();
        $callCounter = 0;

        $client = new MockHttpClient(function () use (&$callCounter, $payload) {
            ++$callCounter;

            return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR));
        });

        [$cache, $tracker] = $this->createCacheStub();

        $svc = new WeatherService($client, $cache);

        // First call: cache miss
        $svc->getForecastByCoords(48.8566, 2.3522);
        self::assertSame(1, $callCounter);
        self::assertSame(1, $tracker->hits, 'One callback execution on miss');

        // Second call: cache hit
        $svc->getForecastByCoords(48.8566, 2.3522);
        self::assertSame(1, $callCounter, 'No extra HTTP call');
        self::assertSame(1, $tracker->hits, 'No new cache callback');
    }

    public function testCacheInvalidationForcesNewHttpRequest(): void
    {
        $payload     = $this->samplePayload();
        $callCounter = 0;

        $client = new MockHttpClient(function () use (&$callCounter, $payload) {
            ++$callCounter;

            return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR));
        });

        [$cache, $tracker] = $this->createCacheStub();

        // 👇 expose protected cacheKey() via subclass
        $svc = new class($client, $cache) extends WeatherService {
            public function key(string $prefix, string $lat, string $lon, string $tz): string
            {
                return $this->cacheKey($prefix, $lat, $lon, $tz);
            }
        };

        // First call populates cache
        $svc->getForecastByCoords(48.8566, 2.3522);
        self::assertSame(1, $callCounter);
        self::assertSame(1, $tracker->hits);

        // Delete using the real key
        $key = $svc->key('forecast',
            number_format(48.8566, 3, '.', ''),
            number_format(2.3522, 3, '.', ''),
            'Europe/Paris'
        );
        $cache->delete($key);

        // Second call triggers new HTTP
        $svc->getForecastByCoords(48.8566, 2.3522);
        self::assertSame(2, $callCounter, 'After deletion, a new HTTP request occurs');
        self::assertSame(2, $tracker->hits, 'Cache callback re-executed');
    }
}
