<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Ai\PromptBank;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One editable prompt: the rules a writer works to, and how its brief is laid out.
 *
 * The code asks {@see PromptBank} for a slot and never for a row, because the
 * answer is usually "the shipped default" and a caller that reached for the row
 * would have to know that.
 *
 * @property string $slot
 * @property string|null $system
 * @property string|null $user_template
 * @property bool $enabled
 */
class PromptTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * Flushed here rather than from the screen that saves it.
     *
     * A cached prompt that survives its own edit is the failure this has to
     * prevent, and the edit can arrive from the Filament resource, a seeder or a
     * tinker session. The model is the one place all three pass through.
     */
    protected static function booted(): void
    {
        $forget = fn () => app(PromptBank::class)->flush();

        static::saved($forget);
        static::deleted($forget);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
