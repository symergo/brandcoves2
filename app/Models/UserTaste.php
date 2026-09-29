<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's own gift taste, "Mijn smaak" (docs/features/my-taste.md).
 *
 * One row per account. No budget: that is the giver's to decide.
 *
 * @property int $user_id
 * @property list<string> $interests
 * @property string|null $vibe
 * @property list<string> $preferences
 * @property list<string> $values
 * @property list<string> $avoid
 * @property string|null $age_band
 */
class UserTaste extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'interests', 'vibe', 'preferences', 'values', 'avoid', 'age_band'];

    protected function casts(): array
    {
        return [
            'interests' => 'array',
            'preferences' => 'array',
            'values' => 'array',
            'avoid' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Nothing said at all: treated as no taste, everywhere it is read. */
    public function isEmpty(): bool
    {
        return (array) $this->interests === []
            && $this->vibe === null
            && (array) $this->preferences === []
            && (array) $this->values === []
            && (array) $this->avoid === []
            && $this->age_band === null;
    }
}
