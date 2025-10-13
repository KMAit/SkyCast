<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Contracts\Cache\CacheInterface;

/**
 * Trait providing a simple in-memory CacheInterface stub with hit tracking.
 *
 * Designed for use in service-level unit tests where the Symfony cache
 * layer should be simulated without external dependencies.
 */
trait CacheStubTrait
{
    /** @return array{0:CacheInterface, 1:CacheTracker} */
    protected function createCacheStub(): array
    {
        $tracker = new CacheTracker();

        $cache = new class($tracker) implements CacheInterface {
            private CacheTracker $tracker;

            public function __construct(CacheTracker $tracker)
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
}
