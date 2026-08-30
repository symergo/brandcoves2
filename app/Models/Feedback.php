<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something a visitor told us about the site.
 *
 * See the migration for why the address is optional and why no IP is kept.
 *
 * @property string $message
 * @property string|null $email
 * @property string|null $path
 * @property Carbon|null $handled_at
 */
class Feedback extends Model
{
    /**
     * Laravel would pluralise this to `feedbacks`.
     */
    protected $table = 'feedback';

    protected $guarded = [];

    /**
     * The address never leaves the server in a payload.
     *
     * Nothing serialises a feedback row to a visitor today, and that is exactly
     * when to add this — the guard costs nothing now and is the sort of thing
     * nobody remembers before the screen that leaks it. The message body is not
     * hidden, because the admin queue is the whole point of the row; it is the
     * reply address that has no business anywhere else.
     *
     * @var list<string>
     */
    protected $hidden = ['email'];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'handled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForMarket(Builder $query, Market $market): void
    {
        $query->where('market', $market->value);
    }

    /** Still waiting for somebody to read it. */
    public function scopeUnhandled(Builder $query): void
    {
        $query->whereNull('handled_at');
    }
}
