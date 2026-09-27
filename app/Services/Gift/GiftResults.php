<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Enums\RecipientType;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Cove\CommunityCoves;
use App\Services\Ideas\OfflineIdeaPicker;
use App\Services\Search\GiftIntentParser;
use App\Services\Wishlist\ListMaker;
use App\Support\CurrentMarket;
use App\Support\Owner;

/**
 * The one results page of "Find a gift", as data.
 *
 * Three ways lead to gift ideas (the questions, This or that, and a gift
 * landing page) and each used to build its own results: crowd picks showed on
 * two of them, the ideas without a shop on two, Coves others made on one, the
 * next step after a past gift on one. Every way now asks this class, so a
 * section added here reaches all of them at once. See
 * docs/features/find-a-gift.md.
 *
 * Retrieval and arithmetic only: the suggestion engine has already run, and
 * nothing here can call a model (invariant 1).
 */
class GiftResults
{
    /**
     * The cards, in the engine's order. The same shape everywhere, so the
     * page draws one kind of card.
     *
     * @param  list<Suggestion>  $picks
     * @param  array<int, string>  $votes  group id => 'up' | 'down', the thumbs already given (GiftFeedback::votesOn)
     * @return list<array<string, mixed>>
     */
    public function cards(array $picks, CurrentMarket $current, array $votes = []): array
    {
        return array_map(fn (Suggestion $pick) => $this->card(
            $pick->group,
            $current,
            // What it has in common with the brief, not a sentence about it
            // (owner, 2026-09-14; docs/features/gift-whisperer.md).
            $pick->fits(),
            // Five or more different people's lists (crowd-picks.md).
            $pick->chosenByOthers(),
            $votes[$pick->group->id] ?? null,
        ), $picks);
    }

    /**
     * One card from a product and what the engine said about it. Its own
     * method for the gift landing pages, which cache the engine's verdict
     * and load the products fresh.
     *
     * @param  list<array{kind: string, value: string}>  $fits
     * @return array<string, mixed>
     */
    public function card(ProductGroup $group, CurrentMarket $current, array $fits = [], bool $chosenByOthers = false, ?string $vote = null): array
    {
        return [
            'id' => $group->id,
            'title' => $group->displayTitle(),
            'brand' => $group->brand,
            'image' => $group->image_url,
            'price' => $group->min_price,
            'merchantCount' => $group->merchant_count,
            'url' => $current->url("p/{$group->id}/{$group->slug}"),
            'fits' => $fits,
            'chosenByOthers' => $chosenByOthers,
            // The thumb already given, to draw it pressed (find-a-gift.md).
            'vote' => $vote,
        ];
    }

    /**
     * Everything under the cards: the page that can be kept, the ideas
     * nobody sells here, Coves other people made, the next step after what a
     * saved person was given, and the way to ask other people.
     *
     * @param  list<int>  $shownIds  what is on the board, so a next step does not repeat a card
     * @param  list<PastGift>|null  $past  the person's gift history when the caller already read it
     * @return array<string, mixed>
     */
    public function extras(
        TasteBrief $brief,
        CurrentMarket $current,
        ?User $viewer,
        ?Recipient $recipient = null,
        array $shownIds = [],
        bool $withPageUrl = true,
        ?array $past = null,
    ): array {
        return [
            /*
             * "Open as a page": the gift landing page nearest this brief, a
             * GET address that can be kept, shared and found again, which a
             * POSTed board cannot. Null when no such page exists or the board
             * is empty, and on a landing page itself, which already is one.
             */
            'pageUrl' => $withPageUrl && $shownIds !== [] ? app(GiftLandingLinks::class)->pageFor($brief) : null,
            // Id and wording only (docs/features/offline-ideas.md).
            'offlineIdeas' => app(OfflineIdeaPicker::class)->forBrief($brief),
            // "Coves others made for someone like this" (community-coves.md).
            'communityCoves' => app(CommunityCoves::class)->forBrief($brief, $viewer),
            /*
             * "The next step" after what this person was given, and the way
             * to their gift history. Only with a saved person; the history
             * page is behind a sign-in. See docs/features/gift-history.md.
             */
            'nextSteps' => $recipient === null ? [] : app(NextSteps::class)->cards(
                $recipient,
                $current->get(),
                past: $past,
                alsoExclude: $shownIds,
            ),
            'personUrl' => $recipient !== null && $viewer !== null
                ? $current->url("people/{$recipient->id}")
                : null,
            // The last line of every results page: when none of this fits,
            // other people can suggest something (docs/features/ask-others.md).
            'askUrl' => $current->url('ask'),
        ];
    }

    /**
     * The list a pick should land on: the chosen person's, made if missing.
     *
     * Only a `for_someone` list qualifies — a group list is a shortlist other
     * people are paying into, not somewhere to file research. Made here, once,
     * rather than lazily by the Save button, because that button reloads the
     * page's shared props with a partial GET, and the results are a POST.
     * Signed-in owners only: an anonymous visitor cannot save at all. See
     * docs/features/gift-whisperer.md, "Saving lands on that person's list".
     *
     * @return array{id: string, title: string, kind: string}|null
     */
    public function recipientList(Owner $owner, ?Recipient $recipient, CurrentMarket $current): ?array
    {
        if ($recipient === null) {
            return null;
        }

        $list = $owner->scope(Wishlist::query())
            ->where('recipient_id', $recipient->id)
            ->where('kind', ListKind::ForSomeone->value)
            ->latest('created_at')
            ->first();

        if ($list === null) {
            if (! $owner->isSignedIn()) {
                return null;
            }

            $list = app(ListMaker::class)->make(
                $owner,
                $current,
                __('site.lists.for_person', ['name' => $recipient->name]),
                recipientId: $recipient->id,
            );
        }

        return ['id' => $list->id, 'title' => $list->displayTitle(), 'kind' => $list->kind->value];
    }

    /**
     * A free-text relationship ("mama", "my brother") as the closed
     * vocabulary, or null. A vocabulary value passes as itself; anything else
     * is read with the search box's word lists, as "Open as a page" does.
     */
    public function relationshipType(?string $relationship, Market $market): ?RecipientType
    {
        $relationship = trim((string) $relationship);

        if ($relationship === '') {
            return null;
        }

        $known = RecipientType::tryFrom(mb_strtolower($relationship));

        if ($known !== null) {
            return $known;
        }

        $read = app(GiftIntentParser::class)->parse($relationship, $market, giftContext: true)->relationship;

        return $read === null ? null : RecipientType::tryFrom($read);
    }
}
