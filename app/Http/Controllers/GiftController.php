<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Interest;
use App\Enums\TasteSource;
use App\Enums\Vibe;
use App\Models\Event;
use App\Models\Recipient;
use App\Services\Gift\RejectionMemory;
use App\Services\Gift\Suggestion;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\TasteBrief;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Gift Whisperer.
 *
 * Describe someone, get four suggestions with a reason attached to each. The
 * wizard is a GET page so it can be indexed and shared; the results come from a
 * POST, because a brief is a description of a real person and does not belong
 * in a URL that ends up in a referrer header or a browser history someone else
 * can read.
 *
 * No AI runs here — none can. The interest map was widened overnight and
 * giftability was classified after the last ingest; this endpoint is retrieval
 * and arithmetic. See docs/features/ai-invariant.md.
 */
class GiftController extends Controller
{
    public function show(Request $request, CurrentMarket $current, RejectionMemory $memory): Response
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

        return Inertia::render('Gift/Wizard', [
            'options' => $this->options(),
            'recipients' => $this->recipients($request),
            'picks' => null,
            'brief' => null,
        ]);
    }

    /**
     * Score a brief and return the picks.
     *
     * Renders the same page rather than redirecting, so the wizard keeps its
     * answers on screen next to the results — someone who dislikes a suggestion
     * wants to adjust one answer, not start again.
     */
    public function suggest(Request $request, CurrentMarket $current, SuggestionEngine $engine, RejectionMemory $memory): Response
    {
        $validated = $this->validateBrief($request);
        $recipient = $this->recipient($request, $validated);
        $brief = $this->brief($validated, $current, $recipient);

        /*
         * Everything already rejected for this brief, remembered server-side.
         *
         * This is also what makes "Try again" mean something: it used to
         * re-post the same brief and re-render the same four cards, which is
         * not what the button says.
         */
        $key = $memory->key($brief);
        $picks = $engine->suggest($brief->excluding($memory->all($key)));

        // Answering the same six questions about the same person twice is the
        // kind of small indignity that stops people coming back. `remember`
        // rather than always-on, because a brief for "something silly for the
        // office" is not what you want restored next Christmas.
        if ($recipient !== null && $request->boolean('remember')) {
            $recipient->update(array_filter([
                'occasion' => $validated['occasion'] ?? null,
                'age_band' => $validated['age_band'] ?? null,
            ], fn ($v) => $v !== null));

            $recipient->describeTaste(array_filter([
                'interests' => $validated['interests'] ?? null,
                'vibe' => $validated['vibe'] ?? null,
                'values' => $validated['values'] ?? null,
                'avoid' => $validated['avoid'] ?? null,
            ], fn ($v) => $v !== null), TasteSource::Suggested);
        }

        // Append-only, no personal data: which interests and budget band
        // produced how many results. This is what tells us months from now that
        // "gardening" returns nothing in Spain.
        Event::record('gift.suggest', [
            'market' => $current->value(),
            'interests' => $brief->interests,
            'vibe' => $brief->vibe?->value,
            'results' => count($picks),
        ]);

        return Inertia::render('Gift/Wizard', [
            'options' => $this->options(),
            'recipients' => $this->recipients($request),
            'picks' => $this->present($picks, $current),
            'brief' => $validated,
        ]);
    }

    /**
     * "Show me something else" — one rejection, a whole board back.
     *
     * ## Why this renders four cards and not one
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
     * The two routes stay separate only so `gift.swap` keeps its own signal:
     * how often people reject a suggestion is worth knowing on its own.
     */
    public function swap(Request $request, CurrentMarket $current, SuggestionEngine $engine, RejectionMemory $memory): Response
    {
        $validated = $this->validateBrief($request);
        $brief = $this->brief($validated, $current, $this->recipient($request, $validated));

        $key = $memory->key($brief);

        // Remembered before scoring, so the rejected one cannot come back in
        // the very response that acknowledges it.
        $memory->remember($key, $request->integer('rejected'));

        $picks = $engine->suggest($brief->excluding($memory->all($key)));

        Event::record('gift.swap', [
            'market' => $current->value(),
            'rejected' => $request->integer('rejected'),
        ]);

        // What came back is now also "already seen", so the next swap moves on
        // rather than reshuffling the same four.
        $memory->remember($key, ...array_map(fn ($pick) => $pick->group->id, $picks));

        return Inertia::render('Gift/Wizard', [
            'options' => $this->options(),
            'recipients' => $this->recipients($request),
            'picks' => $this->present($picks, $current),
            'brief' => $validated,
        ]);
    }

    /** @return array<string, mixed> */
    private function validateBrief(Request $request): array
    {
        return $request->validate([
            'interests' => ['array', 'max:8'],
            'interests.*' => ['string', 'max:40'],
            'vibe' => ['nullable', 'string', 'in:'.implode(',', Vibe::values())],
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
            'age_band' => ['nullable', 'string', 'max:20'],
            'recipient_id' => ['nullable', 'uuid'],
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
     * @param  array<string, mixed>  $validated
     */
    private function brief(array $validated, CurrentMarket $current, ?Recipient $recipient = null): TasteBrief
    {
        if ($recipient !== null) {
            $stored = TasteBrief::fromRecipient($recipient, $current->get(), (int) config('giftcoves.gift.results'));

            $validated += array_filter([
                'interests' => $stored->interests ?: null,
                'vibe' => $stored->vibe?->value,
                'budget_min' => $stored->budgetMin === null ? null : $stored->budgetMin / 100,
                'budget_max' => $stored->budgetMax === null ? null : $stored->budgetMax / 100,
                'avoid' => $stored->avoid ?: null,
                'values' => $stored->values ?: null,
                'relationship' => $stored->relationship,
                'occasion' => $stored->occasion,
                'age_band' => $stored->ageBand,
            ], fn ($v) => $v !== null);
        }

        return new TasteBrief(
            market: $current->get(),
            interests: array_values((array) ($validated['interests'] ?? [])),
            vibe: isset($validated['vibe']) ? Vibe::tryFrom((string) $validated['vibe']) : null,
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
     * @param  list<Suggestion>  $picks
     * @return list<array<string, mixed>>
     */
    private function present(array $picks, CurrentMarket $current): array
    {
        return array_map(fn (Suggestion $pick) => [
            'id' => $pick->group->id,
            'title' => $pick->group->title,
            'brand' => $pick->group->brand,
            'image' => $pick->group->image_url,
            'price' => $pick->group->min_price,
            'merchantCount' => $pick->group->merchant_count,
            'url' => $current->url("p/{$pick->group->id}/{$pick->group->slug}"),
            /*
             * One reason, not a breakdown. Three reasons read as a machine
             * justifying itself; the strongest signal is almost always the true
             * one. The key is translated client-side so the reason speaks the
             * market's language.
             */
            'reason' => $pick->topSignal(),
            'reasonMatch' => $pick->primaryInterest,
        ], $picks);
    }

    /** @return array<string, mixed> */
    private function options(): array
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
            'values' => ['sustainable', 'local', 'handmade'],
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
    private function recipients(Request $request): array
    {
        return Owner::fromRequest($request)
            ->scope(Recipient::query())
            ->orderBy('name')
            ->get()
            ->map(fn (Recipient $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'relationship' => $r->relationship,
                'interests' => (array) $r->interests,
                'vibe' => $r->vibe,
                'budgetMin' => $r->budget_min,
                'budgetMax' => $r->budget_max,
                'avoid' => (array) $r->avoid,
                'values' => (array) $r->values,
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
