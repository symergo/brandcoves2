<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An admin-editable override for one declared discovery mode.
 *
 * Named `...Record` rather than `ModeProfile` because the value object of that
 * name is what the pipeline actually uses — this is only where an override is
 * stored. Two classes with one name in two namespaces is how someone ends up
 * importing the wrong one and wondering why their weights do nothing.
 */
class ModeProfileRecord extends Model
{
    protected $table = 'mode_profiles';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'retrievers' => 'array',
            'scoring' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Only the fields this row actually overrides.
     *
     * Nulls are stripped so `array_replace_recursive` leaves the config value
     * in place. An override that only changes λ must say only λ, or it silently
     * freezes every other field at whatever the config said the day the row was
     * written.
     *
     * @return array<string, mixed>
     */
    public function toProfileArray(): array
    {
        return array_filter([
            'position' => $this->position,
            'retrievers' => $this->retrievers,
            'scoring' => $this->scoring,
            'layout' => $this->layout,
            'enabled' => $this->enabled,
        ], fn ($value) => $value !== null);
    }
}
