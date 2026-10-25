<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\BackgroundCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;

/**
 * Build a slow page's cached value in the background (BackgroundCache).
 * Unique per key, so a burst of visitors to an old or missing page is one
 * build.
 */
#[Queue('default')]
class RefreshCachedValue implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** One try: the next visitor to the still-old page queues it again. */
    public int $tries = 1;

    /** The popular-searches build ran well past 30 s on production; generous. */
    public int $timeout = 600;

    /** Held until the job ends; this only matters if a worker dies holding it. */
    public int $uniqueFor = 900;

    /**
     * @param  array{class-string, string}  $builder
     * @param  list<mixed>  $args
     */
    public function __construct(
        public readonly string $key,
        public readonly array $builder,
        public readonly array $args = [],
    ) {}

    public function uniqueId(): string
    {
        return $this->key;
    }

    public function handle(): void
    {
        BackgroundCache::rebuild($this->key, $this->builder, $this->args);
    }
}
