<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Interest;
use App\Enums\Preference;
use App\Enums\RecipientType;
use App\Enums\TasteSource;
use App\Enums\Vibe;
use App\Models\DailyPickSet;
use App\Models\Event;
use App\Models\Recipient;
use App\Services\Gift\GiftHistory;
use App\Services\Gift\GiftResults;
use App\Services\Gift\GiftTags;
use App\Services\Gift\RejectionMemory;
use App\Services\Gift\Suggestion;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\TasteBrief;
use App\Services\Guides\CoveMarkup;
use App\Services\Search\GiftIntentParser;
use App\Services\Seo\PageMeta;
use App\Services\Social\MyPeople;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Gift Whisperer.
 *
 * Describe someone, get eight suggestions with a reason attached to each. The
 * wizard is a GET page so it can be indexed and shared; the results come from a
 * POST, because a brief is a description of a real person and does not belong
 * in a URL that ends up in a referrer header or a browser history someone else
 * can read.
 *
 * Since 2026-09-26 `/gift` is "Find a gift", one flow with three ways in:
 * "Who is it for?" first, then the questions below, This or that
 * (TasteController) or a persona Cove. The questions and This or that end on
 * the same results, built by App\Services\Gift\GiftResults and drawn by
 * resources/js/Components/GiftResults.tsx. See docs/features/find-a-gift.md.
 *
 * No AI runs here — none can. The interest map was widened overnight and
 * giftability was classified after the last ingest; this endpoint is retrieval
 * and arithmetic. See docs/features/ai-invariant.md.
 */
class GiftController extends Controller
{
    public function show(Request $request, CurrentMarket $current, RejectionMemory $memory, SuggestionEngine $engine): Response
    {
        $this->seo($current);

        /*
         * Opening the wizard is starting over, so the rejections go.
         *
         * Without this, "Start over" returned to a page that silently still
         * refused everything the previous sitting had rejected — and the button
         * says the opposite of that.
         */
        $memory->flush();

        /*
         * `?for=<person>`: straight to the ideas for somebody already saved,
         * from the reminder email and the person's page. Only the owner's own
         * person; anybody else's id opens the empty wizard, as if it were not
         * there. See docs/features/gift-history.md.
         */
        $for = (string) $request->query('for', '');
        $recipient = Str::isUuid($for) ? $this->recipient($request, ['recipient_id' => $for]) : null;

        if ($recipient !== null) {
            $validated = $this->withStored(['recipient_id' => $recipient->id], $current, $recipient);
            $brief = $this->brief($validated, $current, $recipient);
            $picks = $engine->suggest($brief->excluding($this->given($recipient)));

            return $this->board($request, $current, $picks, $validated, $recipient, $brief);
        }

        return Inertia::render('Gift/Wizard', [
            'options' => $this->options(),
            'recipients' => $this->recipients($request, $current),
            /*
             * "Who is it for?" as cards of the people you know (owner,
             * 2026-09-27: "the cards of the people you know / are connected
             * with instead of just the name"): the same rows as My people,
             * saved people and friends alike, from the same service, so a
             * card here and a row there never disagree. Signed-in only: a
             * visitor without an account has no people.
             */
            'people' => $request->user() === null ? [] : app(MyPeople::class)->for($request->user(), $current),
            'picks' => null,
            'brief' => null,
            'recipientList' => null,
            'personas' => $this->personaShelf($current),
            'tasteUrl' => $current->url('gift/taste'),
        ]);
    }

    /**
     * The persona Coves: the third way in, "Start from a type".
     *
     * First added as a column beside the questions (owner, 2026-09-26),
     * because a visitor who recognises "the home cook" or "the one who has
     * everything" on sight is one click from a finished shelf rather than six
     * questions away from one. Since "Find a gift" became one flow the same
     * shelf is one of its three ways, after "Who is it for?".
     *
     * Each carries the relationship its plan was written for, when it has
     * one, so the page can put the Coves for the person just chosen first
     * without asking again. A persona for anyone (no relationship) stays in
     * the list for everybody. Twelve, newest first: enough for a
     * relationship to find its own among them, while the page shows four.
     *
     * @return list<array{title: string, intro: string, url: string, scene: string|null, relationship: string|null}>
     */
    private function personaShelf(CurrentMarket $current): array
    {
        return DailyPickSet::query()
            ->forMarket($current->get())
            ->personas()
            ->published()
            ->with('plan:id,edition_id,brief')
            ->orderByDesc('published_at')
            ->limit(12)
            ->get(['id', 'kind', 'slug', 'theme_title', 'theme_blurb', 'scene'])
            ->map(fn (DailyPickSet $persona): array => [
                'title' => (string) $persona->theme_title,
                'intro' => app(CoveMarkup::class)->plain($persona->theme_blurb),
                'url' => $current->url($persona->kind->path((string) $persona->slug, $current->get())),
                'scene' => $persona->scene?->value,
                'relationship' => RecipientType::tryFrom((string) ($persona->plan?->brief['relationship'] ?? ''))?->value,
            ])
            ->values()
            ->all();
    }

    /**
     * Score a brief and return the picks.
     *
     * Renders the same page rather than redirecting, so the wizard keeps its
     * answers next to the results — someone who dislikes a suggestion wants to
     * adjust one answer, not start again. The page keeps the answers in
     * component state and shows them as a summary above the cards, with an
     * "Adjust" button that returns to the questions without a request.
     *
     * ## Every action renders `suggest(brief minus memory)`
     *
     * This one, {@see swap()} and {@see more()} all render exactly that, and
     * differ only in what they add to the memory first: nothing, the one
     * rejected id, or the whole board on screen. The ranker is deterministic,
     * so the board a visitor is looking at can always be recomputed here — and
     * nothing in this controller ever trusts a client-supplied list of what is
     * on screen. A plain re-post of the same brief therefore returns the same
     * four cards, which is what lets the summary's "remember" tick re-post
     * without the board changing under the visitor.
     */
    public function suggest(Request $request, CurrentMarket $current, SuggestionEngine $engine, RejectionMemory $memory): Response
    {
        $validated = $this->validateBrief($request);
        $recipient = $this->recipient($request, $validated);
        $brief = $this->brief($validated, $current, $recipient);

        // Everything already rejected for this brief, remembered server-side.
        $key = $memory->key($brief);
        $picks = $engine->suggest($brief->excluding([...$memory->all($key), ...$this->given($recipient)]));

        $this->rememberFor($request, $recipient, $validated);

        // Append-only, no personal data: which interests and budget band
        // produced how many results. This is what tells us months from now that
        // "gardening" returns nothing in Spain.
        Event::record('gift.suggest', [
            'market' => $current->value(),
            'interests' => $brief->interests,
            'vibe' => $brief->vibe?->value,
            'preferences' => $brief->preferences,
            'results' => count($picks),
        ]);

        return $this->board($request, $current, $picks, $validated, $recipient, $brief);
    }

    /**
     * "Show me something else" — one rejection, a whole board back.
     *
     * ## Why this renders eight cards and not one
     *
     * It used to score with `withLimit(1)` and render `picks` as that single
     * replacement, so the four-card grid collapsed to one card: the three the
     * visitor had kept were thrown away by the render, not by the ranker.
     *
     * The fix is to stop making a swap a different kind of render. The ranker is
     * deterministic, so "top four, minus the one you rejected" **is** the three
     * that were kept plus the next one down — no id round-trip, no splice, and
     * no trusting a client-supplied ordering of what is currently on screen.
     *
     * ## Why only the rejected id is remembered
     *
     * Until 2026-09-13 this also remembered the whole board it returned, so
     * that a "Try again" re-post would show something new. The side effect was
     * that the *second* swap excluded the three cards the visitor had kept, and
     * replaced all four — the opposite of what the paragraph above promises,
     * and invisible to the tests, none of which asserted the kept three
     * survived. "Try again" is gone; {@see more()} is the explicit way to move
     * past a board. A swap now remembers exactly the one opinion it was given.
     *
     * The two routes stay separate only so `gift.swap` keeps its own signal:
     * how often people reject a suggestion is worth knowing on its own.
     */
    public function swap(Request $request, CurrentMarket $current, SuggestionEngine $engine, RejectionMemory $memory): Response
    {
        $validated = $this->validateBrief($request);
        $recipient = $this->recipient($request, $validated);
        $brief = $this->brief($validated, $current, $recipient);

        $key = $memory->key($brief);

        // Remembered before scoring, so the rejected one cannot come back in
        // the very response that acknowledges it.
        $memory->remember($key, $request->integer('rejected'));

        $picks = $engine->suggest($brief->excluding([...$memory->all($key), ...$this->given($recipient)]));

        $this->rememberFor($request, $recipient, $validated);

        Event::record('gift.swap', [
            'market' => $current->value(),
            'rejected' => $request->integer('rejected'),
        ]);

        return $this->board($request, $current, $picks, $validated, $recipient, $brief);
    }

    /**
     * "Four more" — past the board on screen, to the next one.
     *
     * The board the visitor is looking at is recomputed here rather than read
     * from the request: the ranker is deterministic and the memory holds every
     * exclusion, so `suggest(brief minus memory)` *is* what is on screen. Those
     * ids go into the memory, and the next `suggest` is the next board. Two
     * engine runs, each well under 100 ms, is the price of never trusting a
     * client-supplied list of ids — a list that could just as well name the
     * four the visitor wanted to keep.
     *
     * This replaces "Try again", which re-posted the same brief and, because
     * `suggest()` is idempotent, showed the same four cards unless something had
     * been swapped away first. A button that does nothing most of the time is
     * worse than no button.
     */
    public function more(Request $request, CurrentMarket $current, SuggestionEngine $engine, RejectionMemory $memory): Response
    {
        $validated = $this->validateBrief($request);
        $recipient = $this->recipient($request, $validated);
        $brief = $this->brief($validated, $current, $recipient);

        $key = $memory->key($brief);

        $given = $this->given($recipient);
        $shown = $engine->suggest($brief->excluding([...$memory->all($key), ...$given]));
        $memory->remember($key, ...array_map(fn (Suggestion $pick) => $pick->group->id, $shown));

        $picks = $engine->suggest($brief->excluding([...$memory->all($key), ...$given]));

        $this->rememberFor($request, $recipient, $validated);

        Event::record('gift.more', [
            'market' => $current->value(),
            'results' => count($picks),
        ]);

        return $this->board($request, $current, $picks, $validated, $recipient, $brief);
    }

    /**
     * The results page, the same from every action.
     *
     * @param  list<Suggestion>  $picks
     * @param  array<string, mixed>  $validated
     */
    private function board(Request $request, CurrentMarket $current, array $picks, array $validated, ?Recipient $recipient, TasteBrief $brief): Response
    {
        $results = app(GiftResults::class);

        return Inertia::render('Gift/Wizard', [
            'options' => $this->options(),
            'recipients' => $this->recipients($request, $current),
            'picks' => $results->cards($picks, $current),
            'brief' => $validated,
            'recipientList' => $results->recipientList(Owner::fromRequest($request), $recipient, $current),
            /*
             * Everything under the cards, the same on every way into ideas:
             * "Open as a page", the ideas without a shop, Coves others made,
             * the next step for a saved person, and "Ask others". See
             * App\Services\Gift\GiftResults and docs/features/find-a-gift.md.
             */
            ...$results->extras(
                $brief,
                $current,
                $request->user(),
                $recipient,
                array_map(fn (Suggestion $pick) => $pick->group->id, $picks),
            ),
        ]);
    }

    /**
     * What this person was already given, never to be suggested again: the
     * products in their gift history and whatever was merged with them.
     *
     * @return list<int>
     */
    private function given(?Recipient $recipient): array
    {
        return $recipient === null ? [] : app(GiftHistory::class)->excludedGroupIds($recipient);
    }

    /**
     * Keep these answers on the person, when asked to.
     *
     * Answering the same questions about the same person twice is the kind of
     * small indignity that stops people coming back. Opt-in rather than
     * always-on, because a brief for "something silly for the office" is not
     * what you want restored next Christmas.
     *
     * Two writes, because two gates. The taste (interests, vibe, values, avoid)
     * goes through `describeTaste()`, which refuses when the person has
     * described themselves through their own link — a guess must not overwrite
     * what they said. The budget and the occasion are the *giver's* facts, not
     * the person's taste, so they are written directly and survive that gate.
     * Without the budget here, "use what we know about Mum" restored everything
     * except what you spend on her.
     *
     * Called from every action, not only the first: a visitor who ticks the box
     * on the results summary and then presses "Four more" has still asked.
     *
     * @param  array<string, mixed>  $validated
     */
    private function rememberFor(Request $request, ?Recipient $recipient, array $validated): void
    {
        if ($recipient === null || ! $request->boolean('remember')) {
            return;
        }

        $recipient->update(array_filter([
            'occasion' => $validated['occasion'] ?? null,
            'age_band' => $validated['age_band'] ?? null,
            'budget_max' => isset($validated['budget_max'])
                ? (int) round((float) $validated['budget_max'] * 100)
                : null,
        ], fn ($v) => $v !== null));

        $recipient->describeTaste(array_filter([
            'interests' => $validated['interests'] ?? null,
            'vibe' => $validated['vibe'] ?? null,
            'preferences' => $validated['preferences'] ?? null,
            'values' => $validated['values'] ?? null,
            'avoid' => $validated['avoid'] ?? null,
        ], fn ($v) => $v !== null), TasteSource::Suggested);
    }

    /** @return array<string, mixed> */
    private function validateBrief(Request $request): array
    {
        return $request->validate([
            'interests' => ['array', 'max:8'],
            'interests.*' => ['string', 'max:40'],
            'vibe' => ['nullable', 'string', 'in:'.implode(',', Vibe::values())],
            // A taste is several of the axes, so a list; capped at three
            // because a person who picks six has described nothing. Both
            // poles of one axis cannot both be true, and the wizard clears
            // the opposite as you pick, so the server does not police it.
            'preferences' => ['array', 'max:3'],
            'preferences.*' => ['string', Rule::in(Preference::values())],
            // Euros in the payload, cents everywhere else — the wizard shows a
            // slider in the currency people think in.
            'budget_min' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'budget_max' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'avoid' => ['array', 'max:10'],
            'avoid.*' => ['string', 'max:40'],
            'values' => ['array', 'max:3'],
            'values.*' => ['string', 'in:sustainable,local,handmade'],
            'relationship' => ['nullable', 'string', 'max:40'],
            'occasion' => ['nullable', 'string', 'max:40'],
            // One of the fixed groups, the same strings a product is tagged with.
            'age_band' => ['nullable', 'string', Rule::in(GiftTags::AGE_BANDS)],
            'recipient_id' => ['nullable', 'uuid'],
            // Validated so it echoes back in `brief`, and the tick survives the
            // round trip. TasteBrief never sees it.
            'remember' => ['boolean'],
        ]);
    }

    /**
     * The person this brief is about, when the visitor picked a saved one.
     *
     * Scoped to the owner: a guessed uuid must not attach somebody else's
     * mother to this request.
     *
     * @param  array<string, mixed>  $validated
     */
    private function recipient(Request $request, array $validated): ?Recipient
    {
        if (empty($validated['recipient_id'])) {
            return null;
        }

        return Owner::fromRequest($request)
            ->scope(Recipient::query())
            ->find($validated['recipient_id']);
    }

    /**
     * Build the brief, starting from what we already know about the person.
     *
     * `TasteBrief::fromRecipient()` existed from the beginning and had no
     * callers, so the wizard's "use what we know about Mum" shortcut restored
     * nothing at all. Posted answers overlay the stored ones rather than
     * replacing them wholesale: the visitor is answering *this* time's
     * questions, not re-describing her from scratch.
     *
     * The `+=` is the contract: a key the wizard posted wins, even when it is
     * `[]` or `null` — Laravel's `validated()` keeps an empty array under the
     * `array` rule — and only an *absent* key is filled from the profile. So
     * clearing "avoid" in the wizard really clears it, while the occasion and
     * age band, which the wizard never asks, still come from what is stored.
     * The wizard posts every key it edits for exactly this reason.
     *
     * @param  array<string, mixed>  $validated
     */
    private function brief(array $validated, CurrentMarket $current, ?Recipient $recipient = null): TasteBrief
    {
        if ($recipient !== null) {
            $validated = $this->withStored($validated, $current, $recipient);
        }

        /*
         * "Heeft alles al" typed as an interest is not an interest: searched
         * word for word it finds titles containing "alles". Read with the
         * search box's own word lists and turned into the brief's flag, which
         * prefers what gets used up or done (docs/features/has-everything.md).
         */
        $parser = app(GiftIntentParser::class);
        $interests = [];
        $hasEverything = false;

        foreach ((array) ($validated['interests'] ?? []) as $interest) {
            if (is_string($interest) && Interest::tryFrom($interest) === null && $parser->saysHasEverything($interest, $current->get())) {
                $hasEverything = true;

                continue;
            }

            $interests[] = $interest;
        }

        return new TasteBrief(
            market: $current->get(),
            interests: $interests,
            hasEverything: $hasEverything,
            vibe: isset($validated['vibe']) ? Vibe::tryFrom((string) $validated['vibe']) : null,
            preferences: array_values((array) ($validated['preferences'] ?? [])),
            budgetMin: isset($validated['budget_min']) ? (int) round((float) $validated['budget_min'] * 100) : null,
            budgetMax: isset($validated['budget_max']) ? (int) round((float) $validated['budget_max'] * 100) : null,
            avoid: array_values((array) ($validated['avoid'] ?? [])),
            values: array_values((array) ($validated['values'] ?? [])),
            relationship: $validated['relationship'] ?? null,
            occasion: $validated['occasion'] ?? null,
            ageBand: $validated['age_band'] ?? null,
            limit: (int) config('giftcoves.gift.results'),
        );
    }

    /**
     * The answers, with every one the wizard did not post filled from what is
     * stored on the person. See {@see brief()} for why an absent key, and
     * only an absent one, is filled.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function withStored(array $validated, CurrentMarket $current, Recipient $recipient): array
    {
        $stored = TasteBrief::fromRecipient($recipient, $current->get(), (int) config('giftcoves.gift.results'));

        return $validated + array_filter([
            'interests' => $stored->interests ?: null,
            'vibe' => $stored->vibe?->value,
            'preferences' => $stored->preferences ?: null,
            'budget_min' => $stored->budgetMin === null ? null : $stored->budgetMin / 100,
            'budget_max' => $stored->budgetMax === null ? null : $stored->budgetMax / 100,
            'avoid' => $stored->avoid ?: null,
            'values' => $stored->values ?: null,
            'relationship' => $stored->relationship,
            'occasion' => $stored->occasion,
            'age_band' => $stored->ageBand,
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return [
            'interests' => array_map(fn (Interest $i) => [
                'value' => $i->value,
                'label' => $i->label(),
            ], Interest::cases()),
            'vibes' => array_map(fn (Vibe $v) => [
                'value' => $v->value,
                'label' => $v->label(),
            ], Vibe::cases()),
            /*
             * Which way their taste goes, as the axes themselves rather than
             * a flat list of words: the wizard draws each one as its two
             * ends, because a person recognises their own taste by being
             * shown both. Asked in the vibe step, since a step of its own is
             * a step people skip.
             */
            'preferences' => array_map(fn (array $axis) => [
                'axis' => $axis['axis'],
                'poles' => array_map(fn (Preference $p) => [
                    'value' => $p->value,
                    'label' => $p->label(),
                ], $axis['poles']),
            ], Preference::axes()),
            // The fixed age groups, the same strings an editor tags a
            // product with (GiftTags::AGE_BANDS), so the giver's answer and
            // the tag meet as one value.
            'ages' => array_map(fn (string $band) => [
                'value' => $band,
                'label' => __('site.gift.age_band', ['band' => $band]),
            ], GiftTags::AGE_BANDS),
            /*
             * "Who is it for?" without a saved person: the closed vocabulary
             * an editor tags products with (RecipientType), so the answer
             * meets `recipient:` tags, the gift landing pages and the persona
             * Coves as one value rather than as free text to be read.
             */
            'relationships' => RecipientType::options(),
        ];
    }

    /**
     * People this visitor has already described.
     *
     * Offered as a shortcut at step one: the second time you buy for your
     * mother, you should not have to describe her again.
     *
     * @return list<array<string, mixed>>
     */
    private function recipients(Request $request, CurrentMarket $current): array
    {
        $results = app(GiftResults::class);

        return Owner::fromRequest($request)
            ->scope(Recipient::query())
            ->orderBy('name')
            ->get()
            ->map(fn (Recipient $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'relationship' => $r->relationship,
                // "mama" as `mother`, so the persona Coves for her can come
                // first without asking who she is again.
                'relationshipType' => $results->relationshipType($r->relationship, $current->get())?->value,
                'interests' => (array) $r->interests,
                'vibe' => $r->vibe,
                'preferences' => (array) $r->preferences,
                'budgetMin' => $r->budget_min,
                'budgetMax' => $r->budget_max,
                'avoid' => (array) $r->avoid,
                'values' => (array) $r->values,
                'ageBand' => $r->age_band,
                /*
                 * "Vraag het {naam} zelf" (owner, 2026-09-27): their own link,
                 * where they play This or that, suggest products and say
                 * "this is me". Null once an account is behind them: then
                 * they keep their own wish lists, reached from their page.
                 */
                'selfUrl' => $r->isLinked() ? null : url($current->url("for/{$r->share_token}")),
                'personUrl' => $current->url("people/{$r->id}"),
            ])
            ->all();
    }

    private function seo(CurrentMarket $current): void
    {
        app(PageMeta::class)->set(
            title: __('site.gift.title'),
            description: __('site.gift.seo_description'),
            canonical: url($current->url('gift')),
        );
    }
}
