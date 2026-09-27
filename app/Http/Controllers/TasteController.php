<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RecipientType;
use App\Enums\TasteSource;
use App\Models\Event;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Services\Gift\GiftFeedback;
use App\Services\Gift\GiftHistory;
use App\Services\Gift\GiftResults;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\SuggestionProfile;
use App\Services\Gift\TasteBrief;
use App\Services\Gift\TasteChoice;
use App\Services\Gift\TasteChoiceReader;
use App\Services\Gift\TasteDeck;
use App\Services\Gift\TasteProfile;
use App\Services\Gift\TasteProfiler;
use App\Services\Ideas\OfflineIdeaPicker;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * This or that: taste discovery by choosing.
 *
 * A dozen rounds of real products, mostly two at a time, and a taste worked
 * out from what was picked: interests, a price band, what to leave out. Then
 * ideas from the suggestion engine, and the option to keep the result on a
 * person so Find a gift starts from it.
 *
 * Two doors, one page:
 *
 * - `/gift/taste`, for a giver guessing what somebody would pick, or for
 *   yourself. Works without an account; keeping the result on a new person
 *   needs one, like every other way of making a person (routes/web.php).
 * - `/for/{token}/taste`, for the person themselves, from the page their
 *   giver sent them. The token is the whole authorisation there, as on the
 *   rest of that page, and it writes only taste, never the giver's budget or
 *   notes.
 *
 * ## Nothing is stored while choosing
 *
 * The rounds and the choices live in the page. Each request carries the
 * choices so far as product ids and what was pressed, and the server reads
 * the tags and prices from the catalogue itself (TasteChoiceReader), so no
 * table holds a half-finished session and nothing needs pruning.
 *
 * No AI anywhere on this path (invariant 1): a random draw, a few rules for
 * pairing, and arithmetic. See docs/features/taste-discovery.md.
 */
class TasteController extends Controller
{
    public function show(Request $request, CurrentMarket $current, TasteDeck $deck): Response
    {
        app(PageMeta::class)->set(
            title: __('site.gift.taste.title'),
            description: __('site.gift.taste.seo_description'),
            canonical: url($current->url('gift/taste')),
        );

        return Inertia::render('Gift/Taste', [
            ...$this->giverPage($request, $current),
            'carried' => $this->carried($request, $current),
            'rounds' => $this->firstRounds($deck, $current),
            'result' => null,
        ]);
    }

    /**
     * Who "Find a gift" already said this is for, so the page does not ask.
     *
     * `?person=<id>` is one of the visitor's own people (owner-scoped: any
     * other id is ignored, as if it were not there); `?relationship=mother`
     * is one of the closed vocabulary. Either one means "someone else", so
     * the page's own "for someone or for yourself?" is skipped. Neither is
     * a description of the person, only who they are, so both may sit in a
     * URL where the answers may not. See docs/features/find-a-gift.md.
     *
     * @return array{person: array{id: string, name: string}|null, relationship: string|null}
     */
    private function carried(Request $request, CurrentMarket $current): array
    {
        $id = (string) $request->query('person', '');
        $recipient = Str::isUuid($id)
            ? Owner::fromRequest($request)->scope(Recipient::query())->find($id)
            : null;

        $relationship = $recipient !== null
            ? app(GiftResults::class)->relationshipType($recipient->relationship, $current->get())
            : RecipientType::tryFrom((string) $request->query('relationship', ''));

        return [
            'person' => $recipient === null ? null : ['id' => $recipient->id, 'name' => $recipient->name],
            'relationship' => $relationship?->value,
        ];
    }

    /** The next few rounds, for both doors. JSON: the page keeps its own state. */
    public function next(Request $request, CurrentMarket $current, TasteDeck $deck, TasteChoiceReader $reader): JsonResponse
    {
        $validated = $request->validate([
            ...$this->choiceRules('present'),
            'exclude' => ['array', 'max:60'],
            'exclude.*' => ['integer'],
            'from' => ['required', 'integer', 'min:0', 'max:'.TasteDeck::ROUNDS],
        ]);

        $choices = $reader->read($validated['choices'], $current->get());

        $exclude = array_values(array_unique([
            ...array_map('intval', $validated['exclude'] ?? []),
            ...$this->shownIds($choices),
        ]));

        return response()->json([
            'rounds' => $this->present($deck->next($current->get(), $choices, $exclude, (int) $validated['from'])),
        ]);
    }

    /** What the choices say, and ideas to match. */
    public function result(Request $request, CurrentMarket $current, TasteChoiceReader $reader, SuggestionEngine $engine): Response
    {
        $validated = $request->validate([
            ...$this->choiceRules('required'),
            'for' => ['nullable', 'string', 'in:someone,me'],
            // Choosing for one of your saved people (`?person=` on the page):
            // what they were already given is left out of the ideas.
            'recipient_id' => ['nullable', 'uuid'],
            // Who "Find a gift" said this is for, without a saved person.
            'relationship' => ['nullable', 'string', Rule::in(RecipientType::values())],
        ]);

        $for = $validated['for'] ?? 'someone';

        // Owner-scoped: somebody else's id is ignored, as if it were not sent.
        $recipient = $for === 'someone' && ! empty($validated['recipient_id'])
            ? Owner::fromRequest($request)->scope(Recipient::query())->find($validated['recipient_id'])
            : null;

        $relationship = $for !== 'someone' ? null : ($recipient !== null
            ? app(GiftResults::class)->relationshipType($recipient->relationship, $current->get())
            : RecipientType::tryFrom((string) ($validated['relationship'] ?? '')));

        $outcome = $this->outcome(
            $validated['choices'],
            $current,
            $reader,
            $engine,
            $for,
            given: $recipient === null ? [] : app(GiftHistory::class)->excludedGroupIds($recipient),
            relationship: $relationship,
            brief: $brief,
            recipientId: $recipient?->id,
        );

        /*
         * The thumbs already given to these ideas, so a liked card is drawn
         * pressed (docs/features/find-a-gift.md, "Thumbs up, thumbs down").
         */
        $votes = app(GiftFeedback::class)->votesOn(
            Owner::fromRequest($request),
            $recipient,
            array_map(fn (array $pick) => (int) $pick['id'], $outcome['picks']),
        );
        $outcome['picks'] = array_map(fn (array $pick) => [...$pick, 'vote' => $votes[(int) $pick['id']] ?? null], $outcome['picks']);

        return Inertia::render('Gift/Taste', [
            ...$this->giverPage($request, $current),
            'carried' => [
                'person' => $recipient === null ? null : ['id' => $recipient->id, 'name' => $recipient->name],
                'relationship' => $relationship?->value,
            ],
            'rounds' => [],
            'result' => [
                ...$outcome,
                /*
                 * The rest of the one results page, as the questions end on
                 * it: Open as a page, Coves others made, the next step for a
                 * saved person, Ask others, and the person's own list for a
                 * save. See App\Services\Gift\GiftResults.
                 */
                ...$this->giverExtras($request, $current, $outcome, $brief, $recipient),
            ],
        ]);
    }

    /**
     * What only a giver's result carries, beside the ideas.
     *
     * @param  array<string, mixed>  $outcome
     * @return array<string, mixed>
     */
    private function giverExtras(Request $request, CurrentMarket $current, array $outcome, TasteBrief $brief, ?Recipient $recipient): array
    {
        $results = app(GiftResults::class);

        $extras = $results->extras(
            $brief,
            $current,
            $request->user(),
            $recipient,
            array_map(fn (array $pick) => (int) $pick['id'], $outcome['picks']),
        );

        return [
            ...$extras,
            // Already in the outcome, from the same brief.
            'offlineIdeas' => $outcome['offlineIdeas'],
            'into' => $results->recipientList(Owner::fromRequest($request), $recipient, $current),
            /*
             * "Refine with the questions": what was learned, as the answers
             * the questions post, so the same board opens with Adjust, Eight
             * more and "Something else" beside it. Avoided interests keep
             * their tag spelling (`interest:gaming`), as when saved on a
             * person. POSTed from the page, never put in a URL.
             */
            'refine' => array_filter([
                'interests' => $brief->interests,
                'vibe' => $brief->vibe?->value,
                'preferences' => $brief->preferences,
                'values' => $brief->values,
                'avoid' => $brief->avoid,
                'budget_min' => $brief->budgetMin === null ? null : $brief->budgetMin / 100,
                'budget_max' => $brief->budgetMax === null ? null : $brief->budgetMax / 100,
                'relationship' => $brief->relationship,
                'recipient_id' => $recipient?->id,
            ], fn ($v) => $v !== null && $v !== []),
        ];
    }

    /**
     * Keep what was learned on a person: one of yours, or somebody new.
     *
     * Written the way Find a gift's "remember" writes (GiftController::
     * rememberFor): the taste through `describeTaste()`, which refuses when
     * the person has described themselves through their link, and the price
     * band directly, because what you spend on somebody is your fact and not
     * their taste. Added to what is stored, never replacing it
     * (TasteProfile::mergedWith).
     */
    public function save(Request $request, CurrentMarket $current, TasteChoiceReader $reader): JsonResponse
    {
        $validated = $request->validate([
            ...$this->choiceRules('required'),
            'recipient_id' => ['nullable', 'uuid', 'required_without:name'],
            'name' => ['nullable', 'string', 'max:80', 'required_without:recipient_id'],
        ]);

        $owner = Owner::fromRequest($request);
        $profile = TasteProfiler::fromConfig()->profile($reader->read($validated['choices'], $current->get()));

        abort_if($profile->isEmpty(), 422, __('site.gift.taste.nothing_to_save'));

        if (! empty($validated['recipient_id'])) {
            $recipient = $owner->scope(Recipient::query())->find($validated['recipient_id']);

            if ($recipient === null) {
                throw new NotFoundHttpException;
            }
        } else {
            // A new person needs an account, as everywhere else: the route
            // that makes one sits behind `auth` since 2026-09-06, because an
            // anonymous caller could otherwise create people without limit.
            abort_unless($owner->isSignedIn(), 403);

            $recipient = Recipient::create([
                ...$owner->attributes(),
                'name' => trim((string) $validated['name']),
            ]);
        }

        $taste = $profile->mergedWith([
            'interests' => $recipient->interests,
            'avoid' => $recipient->avoid,
            'vibe' => $recipient->vibe,
            'preferences' => $recipient->preferences,
            'values' => $recipient->values,
        ]);

        $written = $recipient->describeTaste($taste, TasteSource::Suggested);

        if ($profile->budgetMin !== null) {
            $recipient->update([
                'budget_min' => $profile->budgetMin,
                'budget_max' => $profile->budgetMax,
            ]);
        }

        return response()->json([
            'name' => $recipient->name,
            // False when they described their own taste: theirs stays.
            'tasteWritten' => $written,
        ]);
    }

    public function selfShow(Request $request, CurrentMarket $current, TasteDeck $deck, string $market, string $token): Response
    {
        $recipient = $this->findByToken($token);

        $this->selfMeta($recipient);

        return Inertia::render('Gift/Taste', [
            ...$this->selfPage($current, $recipient, $token),
            'rounds' => $this->firstRounds($deck, $current),
            'result' => null,
        ]);
    }

    public function selfResult(Request $request, CurrentMarket $current, TasteChoiceReader $reader, SuggestionEngine $engine, string $market, string $token): Response
    {
        $recipient = $this->findByToken($token);
        $validated = $request->validate($this->choiceRules('required'));

        $this->selfMeta($recipient);

        return Inertia::render('Gift/Taste', [
            ...$this->selfPage($current, $recipient, $token),
            'rounds' => [],
            'result' => $this->outcome($validated['choices'], $current, $reader, $engine, 'me', withIdeas: false),
        ]);
    }

    /**
     * The person's own choices become their own taste.
     *
     * Stamped `Self`, so the giver's guesses no longer overwrite it, which is
     * the rule the rest of this page lives under. Laid over what they said
     * before only when it was *they* who said it: merging into the giver's
     * guesses and stamping the lot `Self` would pass a guess off as their
     * own answer. The budget is never written from here; it is the giver's.
     */
    public function selfSave(Request $request, CurrentMarket $current, TasteChoiceReader $reader, string $market, string $token): JsonResponse
    {
        $recipient = $this->findByToken($token);
        $validated = $request->validate($this->choiceRules('required'));

        $profile = TasteProfiler::fromConfig()->profile($reader->read($validated['choices'], $current->get()));

        abort_if($profile->isEmpty(), 422, __('site.gift.taste.nothing_to_save'));

        $stored = $recipient->taste_source === TasteSource::Self ? [
            'interests' => $recipient->interests,
            'avoid' => $recipient->avoid,
            'vibe' => $recipient->vibe,
            'preferences' => $recipient->preferences,
            'values' => $recipient->values,
        ] : [];

        $recipient->describeTaste($profile->mergedWith($stored), TasteSource::Self);

        session()->flash('success', __('site.gift.taste.self_saved'));

        return response()->json(['redirect' => $current->url("for/{$token}")]);
    }

    /**
     * The profile, the ideas, and the choices echoed back so the page can
     * send them again to keep the result.
     *
     * @param  list<array<string, mixed>>  $raw
     * @param  list<int>  $given  products the chosen person was already given
     * @param  TasteBrief|null  $brief  set to the brief the ideas came from, for a caller that builds more on it
     * @return array<string, mixed>
     */
    protected function outcome(
        array $raw,
        CurrentMarket $current,
        TasteChoiceReader $reader,
        SuggestionEngine $engine,
        string $for,
        bool $withIdeas = true,
        array $given = [],
        ?RecipientType $relationship = null,
        ?TasteBrief &$brief = null,
        ?string $recipientId = null,
    ): array {
        $choices = $reader->read($raw, $current->get());
        $profile = TasteProfiler::fromConfig()->profile($choices);

        /*
         * Ranked for yourself when choosing for yourself, where a sensible
         * thing at a sensible price is the point; for somebody else
         * otherwise. The products already shown are left out: they have been
         * seen, and the ideas should be new.
         */
        $brief = $profile->brief(
            $current->get(),
            (int) config('giftcoves.gift.results'),
            // The products already shown, and what the person was already
            // given when they are one of yours (docs/features/gift-history.md).
            [...$this->shownIds($choices), ...$given],
            $for === 'me' ? SuggestionProfile::forMyself() : SuggestionProfile::forSomeone(),
            // Who "Find a gift" said it is for: an editor's `recipient:` tag
            // scores, and Coves others made for the same kind of person match.
            $relationship?->value,
        )
            // One of the owner's saved people, already scoped by the caller:
            // the engine reads the thumbs given for them.
            ->aboutRecipient($recipientId);

        $picks = $engine->suggest($brief);

        // Append-only, no personal data: how many rounds, and what was found.
        Event::record('gift.taste', [
            'market' => $current->value(),
            'answered' => $profile->answered,
            'interests' => $profile->interests,
            'results' => count($picks),
        ]);

        return [
            'profile' => $profile->toArray(),
            'thin' => $profile->answered < TasteDeck::EXPLORE,
            // The same cards as the questions' results (GiftResults::cards).
            'picks' => app(GiftResults::class)->cards($picks, $current),
            'choices' => array_values($raw),
            'for' => $for,
            /*
             * Ideas nobody sells here, from what other people typed onto
             * their lists and a person approved. Only on the giver's page:
             * the person choosing through their own link is describing
             * themselves, not shopping. See docs/features/offline-ideas.md.
             */
            'offlineIdeas' => $withIdeas ? app(OfflineIdeaPicker::class)->forBrief($brief) : [],
        ];
    }

    /** @return array<string, mixed> */
    private function giverPage(Request $request, CurrentMarket $current): array
    {
        $owner = Owner::fromRequest($request);

        return [
            'mode' => 'giver',
            'person' => null,
            'urls' => [
                'next' => $current->url('gift/taste/next'),
                'result' => $current->url('gift/taste'),
                'save' => $current->url('gift/taste/save'),
                'restart' => $current->url('gift/taste'),
                'finder' => $current->url('gift'),
                // "My gift profile", offered after choosing for yourself.
                'card' => $current->url('gift/card'),
            ],
            'total' => TasteDeck::ROUNDS,
            'recipients' => $owner->scope(Recipient::query())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Recipient $r) => ['id' => $r->id, 'name' => $r->name])
                ->all(),
            'canCreate' => $owner->isSignedIn(),
        ];
    }

    /** @return array<string, mixed> */
    private function selfPage(CurrentMarket $current, Recipient $recipient, string $token): array
    {
        return [
            'mode' => 'self',
            // Their name, and nothing the giver wrote: see RecipientProfileController.
            'person' => ['name' => $recipient->name],
            'urls' => [
                'next' => $current->url('gift/taste/next'),
                'result' => $current->url("for/{$token}/taste"),
                'save' => $current->url("for/{$token}/taste/save"),
                'restart' => $current->url("for/{$token}/taste"),
                'finder' => $current->url("for/{$token}"),
                // The same card as from /gift/taste: it carries nothing of
                // this page, no giver and no link back to it.
                'card' => $current->url('gift/card'),
            ],
            'total' => TasteDeck::ROUNDS,
            'recipients' => [],
            'canCreate' => false,
        ];
    }

    private function selfMeta(Recipient $recipient): void
    {
        // Never indexed: the token is the access, as on the rest of /for/{token}.
        app(PageMeta::class)->set(
            title: __('site.gift.taste.title'),
            robots: 'noindex, nofollow',
        );
    }

    /** @return list<list<array<string, mixed>>> */
    protected function firstRounds(TasteDeck $deck, CurrentMarket $current): array
    {
        return $this->present($deck->next($current->get(), [], [], 0));
    }

    /**
     * @param  list<list<ProductGroup>>  $rounds
     * @return list<list<array<string, mixed>>>
     */
    private function present(array $rounds): array
    {
        return array_map(fn (array $round) => array_map(fn (ProductGroup $group) => [
            'id' => $group->id,
            'title' => $group->displayTitle(),
            'brand' => $group->brand,
            'image' => $group->image_url,
            'price' => $group->min_price,
        ], $round), $rounds);
    }

    /**
     * @param  list<TasteChoice>  $choices
     * @return list<int>
     */
    private function shownIds(array $choices): array
    {
        return array_values(array_unique(array_merge([], ...array_map(fn (TasteChoice $c) => $c->ids(), $choices))));
    }

    /** @return array<string, mixed> */
    protected function choiceRules(string $presence): array
    {
        return [
            'choices' => [$presence, 'array', 'max:'.(TasteDeck::ROUNDS * 2)],
            'choices.*.shown' => ['required', 'array', 'min:1', 'max:2'],
            'choices.*.shown.*' => ['integer'],
            'choices.*.picked' => ['nullable', 'integer'],
            'choices.*.verdict' => ['nullable', 'string', 'in:like,dislike'],
        ];
    }

    private function findByToken(string $token): Recipient
    {
        $recipient = Recipient::query()->where('share_token', $token)->first();

        if ($recipient === null) {
            throw new NotFoundHttpException;
        }

        return $recipient;
    }
}
