<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Symfony\Contracts\Cache\CacheInterface;

/**
 * Tiny in-memory cache double for unit tests.
 * Implements only what WeatherService uses (CacheInterface::get/delete).
 */
final class FakeCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        // Simple array-backed cache; no stampede protection for tests.
        if (\array_key_exists($key, $this->store)) {
            return $this->store[$key];
        }
        $value             = $callback(null, $metadata);
        $this->store[$key] = $value;

        return $value;
    }

    public function delete(string $key): bool
    {
        $existed = \array_key_exists($key, $this->store);
        unset($this->store[$key]);

        return $existed;
    }
}
