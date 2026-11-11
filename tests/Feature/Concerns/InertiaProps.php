<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * The props an Inertia page was rendered with, read from the view data so a
 * test sees exactly what the React page receives.
 */
trait InertiaProps
{
    /** @return array<string, mixed> */
    protected function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }
}
