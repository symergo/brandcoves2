<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only interaction log: searches, click-outs, gift swaps, shortlists,
 * rejections, reactions.
 *
 * Nothing reads this yet, and that is fine. It exists from day one because the
 * learning loop it enables — what *this* audience finds surprising — needs
 * months of history to be worth anything, and history cannot be backfilled.
 */
class Event extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Record one interaction.
     *
     * Never throws. This is analytics hanging off a request that has already
     * done its real work — losing a row is not worth failing a page the visitor
     * is waiting for. The market and identity come from the payload rather than
     * from globals so the method stays usable from a job.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function record(string $kind, array $payload = []): void
    {
        try {
            static::create([
                'kind' => $kind,
                'market' => $payload['market'] ?? null,
                'user_id' => auth()->id(),
                'payload' => $payload,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
