<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CommunityAnswer;
use App\Models\CommunityQuestion;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailable;
use App\Services\Community\PostScreen;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Decide whether one post may go on a public page.
 *
 * A job, not a service call from the controller — and here that is not only the
 * AI invariant (#1: a visitor request must never be able to cause AI spend, and
 * "post a question" is the most obviously abusable trigger there could be). It
 * is also the right shape for the feature: posting returns immediately and says
 * "we are looking at this", which is honest whether the answer takes two
 * seconds or until somebody opens the admin tomorrow.
 *
 * ## Three outcomes, and the safe default
 *
 * - **Published** — the deterministic screen found nothing and the model says
 *   it is fine. This is the ordinary case and the reason the board is not dead
 *   on arrival.
 * - **Rejected** — the model says it is abuse, spam or a solicitation. Recorded
 *   with its reason rather than deleted, so a pattern across one account is
 *   visible later.
 * - **Pending** — everything else, including *every* failure mode: AI switched
 *   off, the cap reached, the API down, a malformed answer, an exception. A
 *   post that cannot be judged waits for a human.
 *
 * That last point is the whole design. Every path that is not an explicit
 * "this is fine" leaves the row exactly as it was created, which is unpublished
 * — so a bug here cannot put unreviewed text on the site, it can only make the
 * admin queue longer.
 */
class TriageCommunityPost implements ShouldQueue
{
    use Queueable;

    private const FEATURE = 'community_triage';

    /**
     * One retry.
     *
     * A transient API failure is worth a second go; anything more and the post
     * simply belongs in the human queue, which is where a failure leaves it
     * anyway.
     */
    public int $tries = 2;

    public function __construct(
        /** @var class-string<CommunityQuestion|CommunityAnswer> */
        public string $type,
        public int $id,
    ) {}

    public static function for(CommunityQuestion|CommunityAnswer $post): self
    {
        return new self($post::class, $post->id);
    }

    public function handle(AiClient $ai, PostScreen $screen): void
    {
        /** @var CommunityQuestion|CommunityAnswer|null $post */
        $post = ($this->type)::query()->find($this->id);

        // Deleted, or already decided by a human while this sat in the queue.
        // The admin's decision wins: they looked at it.
        if ($post === null || ! $post->status->isWaiting()) {
            return;
        }

        $text = $this->text($post);

        /*
         * The flat rules first, and they hold rather than reject.
         *
         * A URL or an email address in the prose is the common abuse here and
         * needs no model to spot. Holding rather than refusing keeps the
         * judgement with a person — a regex should not be able to accuse
         * anybody of anything.
         */
        if (($reason = $screen->hold($text)) !== null) {
            $post->forceFill(['moderation_note' => $reason])->save();

            return;
        }

        if (! $ai->isEnabled()) {
            // The documented fallback: with the model off, the queue is the
            // feature. Nothing is published without a human.
            return;
        }

        try {
            $verdict = $ai->json(
                self::FEATURE,
                <<<'SYSTEM'
                You moderate a gift-ideas board. People ask what to buy for someone
                and other people answer. Decide whether one post may be published.

                Publish anything that is a genuine question about buying a gift, or a
                genuine attempt to answer one — including blunt, dull, oddly specific
                or badly written ones. Being unhelpful is not a reason to refuse.

                Refuse only: advertising or self-promotion, anything sexual, hateful
                or harassing, attempts to move the conversation off the site, obvious
                spam, and requests for gifts that would be illegal to buy or give.

                Hold anything you are unsure about. A human reads the held ones.
                SYSTEM,
                $text,
                [
                    'verdict' => 'publish | refuse | hold',
                    'reason' => 'short key, e.g. advertising, sexual, harassment, offsite, spam, illegal, unsure',
                ],
                maxTokens: 200,
            );
        } catch (AiUnavailable $e) {
            // Capped, misconfigured or down. The post waits, which is exactly
            // what it was already doing.
            Log::info('Community triage unavailable, leaving post for review.', [
                'type' => $this->type,
                'id' => $this->id,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        $reason = is_string($verdict['reason'] ?? null)
            ? mb_substr($verdict['reason'], 0, 190)
            : null;

        match ($verdict['verdict'] ?? 'hold') {
            'publish' => $post->publish(),
            'refuse' => $post->refuse($reason),
            // Anything else, including a value the model invented, is a hold.
            default => $post->forceFill(['moderation_note' => $reason])->save(),
        };
    }

    /**
     * What the model reads.
     *
     * The title and body of a question; the body of an answer. Never the
     * author's name or email — moderation is a judgement about the writing, and
     * a model that can see who wrote something can be inconsistent about who
     * wrote it.
     */
    private function text(Model $post): string
    {
        if ($post instanceof CommunityQuestion) {
            return trim($post->title."\n\n".(string) $post->body);
        }

        return (string) $post->body;
    }

    /**
     * A failed job leaves the post pending, which is the safe state.
     *
     * Stated rather than left to chance: without this the default retry
     * exhaustion is silent, and "why is nothing being published" is a much
     * harder question than "why is the queue long".
     */
    public function failed(\Throwable $e): void
    {
        Log::warning('Community triage failed; post stays in the review queue.', [
            'type' => $this->type,
            'id' => $this->id,
            'error' => $e->getMessage(),
        ]);
    }
}
