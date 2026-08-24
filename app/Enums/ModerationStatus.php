<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether a piece of writing from a visitor may be shown to anybody else.
 *
 * Three states rather than a boolean, because "not published" hides two
 * completely different situations: nobody has looked at it yet, and somebody
 * looked and said no. Collapsing them means the queue cannot be worked — you
 * cannot tell the backlog from the rejects — and an author cannot be told
 * anything honest about where their post went.
 *
 * Default `Pending`, always. Nothing a stranger writes reaches a public page by
 * omission; publishing is an act, whether taken by the triage job or by a
 * human in the admin.
 */
enum ModerationStatus: string implements HasColor, HasLabel
{
    /** Written, waiting on a decision. Visible to its author and to admins. */
    case Pending = 'pending';

    /** Cleared, and on the public page. */
    case Published = 'published';

    /**
     * Refused. Kept rather than deleted: a rejected post is the evidence for
     * why an account was warned or removed, and deleting it makes every
     * moderation decision unauditable.
     */
    case Rejected = 'rejected';

    public function isPublished(): bool
    {
        return $this === self::Published;
    }

    /** Waiting on a human, as opposed to decided either way. */
    public function isWaiting(): bool
    {
        return $this === self::Pending;
    }

    public function label(): string
    {
        return __('site.ask.status.'.$this->value);
    }

    /**
     * The two Filament badge contracts, so the admin does not have to know.
     *
     * `community_questions.status` is cast to this enum, so a Filament column
     * receives the **case**, not its string value — and a `->color(fn (string
     * $state) => …)` closure copied from a resource whose status column is a
     * plain string dies with a TypeError the moment one row exists. Both
     * community resources had exactly that closure, and it 500'd the queue
     * pages.
     *
     * Implementing the contracts fixes it in one place rather than two, and
     * gets the label right as a side effect: without `HasLabel` a badge renders
     * the raw `pending`, where this returns the translated "Being read".
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Published => 'success',
            self::Rejected => 'danger',
            // Waiting is the state that wants working through, not an error.
            self::Pending => 'warning',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
