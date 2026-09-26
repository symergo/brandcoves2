<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enums\Market;
use App\Mail\PeopleQuestionMail;
use App\Models\CommunityQuestion;
use App\Models\Friendship;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * A published question, sent to the asker's people.
 *
 * "Send it to your people" (owner, 2026-09-26): somebody asking what to buy
 * their mother is most likely to get a useful answer from the people who know
 * them, and those people are already on the site as their friends.
 *
 * ## Who hears about it
 *
 * Every account linked to the asker by a friendship (both rows exist for every
 * friendship, see `Friends`), and nobody else. A saved person without an
 * account has no inbox; a person who once opened a link without becoming a
 * friend is not "your people".
 *
 * ## When
 *
 * Only once the question is **published**, never when it is posted. A held or
 * refused question is not on the board, and sending it to twenty people would
 * be publishing it by another route: the moderation is the whole reason the
 * board may exist (ask-others.md). `CommunityQuestion::publish()` queues the job
 * that calls this, and nothing else does.
 *
 * ## Three switches and one limit
 *
 * - the asker's `ask_people_off_at`: they said not to send their questions;
 * - the receiver's `people_questions_off_at`: they said not to show them;
 * - the receiver's `people_question_emails_off_at`: the inbox row still, no
 *   email. The unsubscribe link sets this one;
 * - **one per asker per receiver per day** (a rolling 24 hours): somebody who
 *   asks three questions in an evening reaches each friend once. The second
 *   and third are on the board for anybody who follows the first.
 *
 * ## What it carries
 *
 * The asker's name and the question's title, both already public on the board
 * once the question is published, and a link to it. Nothing from any list:
 * the list a question was asked from is the asker's own business
 * (`wishlist_id` is read only for them), and an email is read in more places
 * than a page is.
 */
class QuestionToPeople
{
    public const KIND = 'ask.people_question';

    /**
     * Send it, once. Returns how many people were told, for the tests and logs.
     */
    public function send(CommunityQuestion $question): int
    {
        if (! $question->status->isPublished()) {
            return 0;
        }

        /*
         * Claimed with an update that only succeeds while the column is null.
         * Two workers, or a question refused and then published again by hand,
         * cannot send it twice: only one of them gets the row.
         */
        $claimed = CommunityQuestion::query()
            ->whereKey($question->id)
            ->whereNull('people_notified_at')
            ->update(['people_notified_at' => now()]);

        if ($claimed === 0) {
            return 0;
        }

        $asker = User::query()->find($question->user_id);

        if ($asker === null || $asker->ask_people_off_at !== null) {
            return 0;
        }

        $friendIds = Friendship::query()
            ->where('user_id', $asker->id)
            ->pluck('friend_id')
            ->all();

        $receivers = User::query()
            ->whereIn('id', $friendIds)
            // Never to yourself. A friendship with yourself cannot exist (a
            // CHECK refuses it), and this says so once more where it matters.
            ->whereKeyNot($asker->id)
            ->whereNull('people_questions_off_at')
            ->get();

        $told = 0;

        foreach ($receivers as $receiver) {
            if ($this->toldToday($receiver, $asker)) {
                continue;
            }

            $this->tell($receiver, $asker, $question);
            $told++;
        }

        return $told;
    }

    /** Has this receiver already heard from this asker in the last 24 hours? */
    private function toldToday(User $receiver, User $asker): bool
    {
        return Notification::query()
            ->where('user_id', $receiver->id)
            ->where('kind', self::KIND)
            ->whereRaw("payload->>'asker_id' = ?", [(string) $asker->id])
            ->where('created_at', '>=', now()->subDay())
            ->exists();
    }

    private function tell(User $receiver, User $asker, CommunityQuestion $question): void
    {
        /*
         * In the receiver's language: the market they chose, else the one the
         * question was asked in. A job has no request to take a locale from,
         * and the asker's language is the wrong guess for their friend abroad.
         */
        $market = $receiver->preferred_market ?? $question->market;
        $language = $market->language();

        $title = (string) __('site.ask.people.notice', [
            'name' => $asker->displayName(),
            'question' => $question->title,
        ], $language);

        // The question lives in the market it was asked in; that is its address.
        $path = '/'.$question->market->value."/ask/{$question->id}/{$question->slug()}";

        Notification::create([
            'user_id' => $receiver->id,
            'kind' => self::KIND,
            'title' => $title,
            'body' => (string) __('site.ask.people.notice_body', [], $language),
            'url' => $path,
            // What the daily limit reads, by id rather than by name.
            'payload' => ['asker_id' => $asker->id, 'question_id' => $question->id],
        ]);

        $this->email($receiver, $asker, $question, $title, $path, $market);
    }

    /**
     * The same, by email, for a receiver who has not turned the email off.
     *
     * After the inbox row, never instead of it: the inbox is the record and the
     * email the delivery, as with the occasion reminders.
     */
    private function email(User $receiver, User $asker, CommunityQuestion $question, string $title, string $path, Market $market): void
    {
        if (blank($receiver->email) || $receiver->people_question_emails_off_at !== null) {
            return;
        }

        $base = rtrim((string) config('app.url'), '/');

        Mail::to($receiver->email)->queue(new PeopleQuestionMail(
            heading: $title,
            askerName: $asker->displayName(),
            question: $question->title,
            url: $base.$path,
            language: $market->language(),
            // Permanent, like the reminders' link: an unsubscribe link that
            // expires fails exactly when somebody is annoyed enough to use it.
            unsubscribeUrl: URL::signedRoute('ask.people-emails.stop', [
                'market' => $market->value,
                'user' => $receiver->id,
            ]),
        ));
    }
}
