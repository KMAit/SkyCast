<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Lightweight cache tracker used to simulate Symfony CacheInterface behavior.
 *
 * - Tracks number of callback executions ("hits")
 * - Stores computed values in-memory
 */
final class CacheTracker
{
    /** @var array<string,mixed> */
    public array $store = [];

    /** Number of times the callback was executed (cache misses) */
    public int $hits = 0;
}
