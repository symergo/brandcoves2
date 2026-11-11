<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Models\Recipient;

/**
 * A person saved by the signed-in user. The using test sets `$this->me`
 * (a `User`) in its setUp.
 */
trait SavesPeopleForMe
{
    /** @param array<string, mixed> $attributes */
    protected function saved(string $name, array $attributes = []): Recipient
    {
        return Recipient::create(['owner_user_id' => $this->me->id, 'name' => $name, ...$attributes]);
    }
}
