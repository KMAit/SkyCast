<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WeatherService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Verifies that invalidateForecast() correctly calls invalidateTags()
 * on the cache adapter with the expected tag name.
 */
final class WeatherCacheInvalidateTest extends TestCase
{
    public function testInvalidateForecastCallsInvalidateTags(): void
    {
        $called    = false;
        $tagPassed = null;

        // Mock TagAwareCacheInterface to intercept invalidateTags()
        $cacheMock = new class($called, $tagPassed) implements TagAwareCacheInterface {
            public bool $called = false;
            public ?array $tags = null;

            public function __construct(&$called, &$tagPassed)
            {
                $this->called = &$called;
                $this->tags   = &$tagPassed;
            }

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                return null;
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function invalidateTags(array $tags): bool
            {
                $this->called = true;
                $this->tags   = $tags;

                return true;
            }

            public function save(ItemInterface $item): bool
            {
                return true;
            }

            public function clear(string $prefix = ''): bool
            {
                return true;
            }

            public function deleteItem(string $key): bool
            {
                return true;
            }

            public function deleteItems(array $keys): bool
            {
                return true;
            }

            public function hasItem(string $key): bool
            {
                return false;
            }

            public function getItem(string $key): ItemInterface
            {
                throw new \RuntimeException('not needed');
            }

            public function getItems(array $keys = []): iterable
            {
                return [];
            }
        };

        // Minimal mock HTTP client (never called)
        $httpMock = $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class);

        // Instantiate WeatherService with mocked cache
        $svc = new WeatherService($httpMock, $cacheMock);

        // Call method under test
        $svc->invalidateForecast(48.8566, 2.3522);

        // Assertions
        self::assertTrue($cacheMock->called, 'invalidateTags() should be called');
        self::assertIsArray($cacheMock->tags);
        self::assertSame('forecast_48.86_2.35', $cacheMock->tags[0]);
    }
}
