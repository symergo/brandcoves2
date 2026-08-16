<?php

declare(strict_types=1);

namespace App\Enums;

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
enum ModerationStatus: string
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

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
