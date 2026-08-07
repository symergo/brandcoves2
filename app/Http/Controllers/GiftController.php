<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Interest;
use App\Enums\Vibe;
use App\Models\Event;
use App\Models\Recipient;
use App\Services\Gift\GiftBrief;
use App\Services\Gift\GiftEngine;
use App\Services\Gift\GiftPick;
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
    public function show(Request $request, CurrentMarket $current): Response
    {
        $this->seo($current);

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
    public function suggest(Request $request, CurrentMarket $current, GiftEngine $engine): Response
    {
        $validated = $this->validateBrief($request);
        $brief = $this->brief($validated, $current);

        $picks = $engine->suggest($brief);

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
     * Replace one card without disturbing the other three.
     *
     * The excluded list carries every group already on screen plus everything
     * swapped away, so "show me something else" never loops back to what was
     * just rejected — which is the single fastest way to lose someone's trust
     * in a recommender.
     */
    public function swap(Request $request, CurrentMarket $current, GiftEngine $engine): Response
    {
        $validated = $this->validateBrief($request);

        $exclude = array_map('intval', (array) $request->input('exclude', []));

        $brief = $this->brief($validated, $current)
            ->excluding($exclude);

        // One replacement, not a fresh set.
        $replacement = $engine->suggest(new GiftBrief(
            market: $brief->market,
            interests: $brief->interests,
            vibe: $brief->vibe,
            budgetMin: $brief->budgetMin,
            budgetMax: $brief->budgetMax,
            avoid: $brief->avoid,
            values: $brief->values,
            relationship: $brief->relationship,
            occasion: $brief->occasion,
            ageBand: $brief->ageBand,
            excludeGroupIds: $brief->excludeGroupIds,
            limit: 1,
        ));

        Event::record('gift.swap', [
            'market' => $current->value(),
            'rejected' => $request->integer('rejected'),
        ]);

        return Inertia::render('Gift/Wizard', [
            'options' => $this->options(),
            'recipients' => $this->recipients($request),
            'picks' => $this->present($replacement, $current),
            'brief' => $validated,
            'isSwap' => true,
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
        ]);
    }

    /** @param array<string, mixed> $validated */
    private function brief(array $validated, CurrentMarket $current): GiftBrief
    {
        return new GiftBrief(
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
            limit: (int) config('brandcoves.gift.results'),
        );
    }

    /**
     * @param  list<GiftPick>  $picks
     * @return list<array<string, mixed>>
     */
    private function present(array $picks, CurrentMarket $current): array
    {
        return array_map(fn (GiftPick $pick) => [
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
