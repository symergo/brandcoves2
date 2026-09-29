<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enums\EventType;
use App\Enums\Interest;
use App\Enums\ListKind;
use App\Enums\RecipientType;
use App\Models\Recipient;
use App\Models\Wishlist;
use App\Services\Gift\GiftResults;
use App\Services\Gift\GiftTags;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The ask form, filled in from what the site already knows.
 *
 * Three ways in carry who the question is about in the address (owner's
 * request, 2026-09-26):
 *
 * - `?relationship=mother` from Find a gift, when a kind of person was picked;
 * - `?person=<id>` from Find a gift, when one of your saved people was;
 * - `?list=<id>` from the page of a gift list or a group list you own.
 *
 * Each is an id or a vocabulary value, never a description, which is the rule
 * Find a gift keeps for its own addresses (find-a-gift.md: who it is for may
 * sit in a URL, the answers may not). The description is looked up here, for
 * the owner only: a saved person or a list that is not yours fills nothing.
 *
 * ## Never the name, never the notes
 *
 * The question goes on a public board. So the relationship is used only when it
 * reads as the closed vocabulary ("mama" as `mother`), and a relationship typed
 * by hand is left out, because free text about a person can hold their name.
 * A saved person's name, notes, birthday and what they were given are never
 * read. What is filled is what the form itself asks: interests, taste, what
 * matters, age group, budget and the occasion, all of which the asker sees and
 * can change or clear before anything is posted, and all of which go through
 * the same moderation as a question typed from scratch.
 */
class AskPrefill
{
    /** @return array<string, mixed>|null Null when nothing is known. */
    public function build(Request $request, CurrentMarket $current): ?array
    {
        $owner = Owner::fromRequest($request);

        $list = $this->list($request, $owner);
        $person = $list?->recipient_id !== null
            ? $this->person((string) $list->recipient_id, $owner)
            : $this->person((string) $request->query('person', ''), $owner);

        $kind = $person !== null
            ? app(GiftResults::class)->relationshipType($person->relationship, $current->get())
            : RecipientType::tryFrom((string) $request->query('relationship', ''));

        if ($list === null && $person === null && $kind === null) {
            return null;
        }

        return [
            'title' => $kind === null ? '' : (string) __('site.ask.prefill_title', [
                'who' => __('site.ask.prefill_who.'.$kind->value),
            ]),
            'interests' => $person === null ? [] : array_values(array_filter(
                (array) $person->interests,
                fn ($value) => is_string($value) && Interest::tryFrom($value) !== null,
            )),
            'age_band' => $person?->age_band !== null && in_array($person->age_band, GiftTags::AGE_BANDS, true)
                ? (string) __('site.gift.age_band', ['band' => $person->age_band])
                : '',
            // Euros, as the form takes them.
            'budget_max' => $person?->budget_max !== null
                ? rtrim(rtrim(number_format($person->budget_max / 100, 2, '.', ''), '0'), '.')
                : '',
            'occasion' => $list === null ? '' : $this->occasion($list),
            'list' => $list === null ? null : ['id' => $list->id, 'title' => $list->displayTitle(), 'kind' => $list->kind->value],
        ];
    }

    /**
     * The list this question is asked from, if it is yours and about somebody.
     *
     * A wish list of your own is about you, and "what should people buy me?"
     * is not a question for strangers; the list page does not offer it there.
     */
    public function list(Request $request, Owner $owner): ?Wishlist
    {
        $id = (string) ($request->query('list') ?? $request->input('list_id') ?? '');

        if (! Str::isUuid($id)) {
            return null;
        }

        $list = Wishlist::query()->find($id);

        if ($list === null || ! $list->isOwnedBy($owner) || $list->kind === ListKind::Mine) {
            return null;
        }

        return $list;
    }

    private function person(string $id, Owner $owner): ?Recipient
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        /** @var Recipient|null */
        return $owner->scope(Recipient::query())->find($id);
    }

    /** "Birthday, 12 October": the list's occasion and date, as far as it has them. */
    private function occasion(Wishlist $list): string
    {
        $type = $list->event_type instanceof EventType && $list->event_type !== EventType::Other
            ? $list->event_type->label()
            : null;

        $date = $list->event_date?->locale(app()->getLocale())->translatedFormat('j F');

        return mb_substr(implode(', ', array_filter([$type, $date])), 0, 40);
    }
}
