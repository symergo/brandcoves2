<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DiscoveryReaction;
use App\Services\Discover\DiscoveryRequest;
use App\Services\Discover\ModeEngine;
use App\Services\Discover\ModeRegistry;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The single mode-parameterised discovery endpoint.
 *
 * `GET /{market}/discover/{mode}` is the SSR landing — deep-linkable and
 * indexable per mode. `POST /{market}/discover` is what the dial calls as it
 * moves, returning JSON so the surface reorganises in place rather than
 * navigating; a full page load per dial position would make one control feel
 * like nine screens, which is exactly what the dial exists to avoid.
 *
 * ## Assumption flagged
 *
 * The spec's `POST /discover` is namespaced under `/{market}/` here. Market is
 * an invariant of this codebase — identity, prices and language are all scoped
 * to it — and an unprefixed discovery endpoint would be the one route that has
 * to resolve a market some other way. Say if you want the bare path and I will
 * add it as an alias that resolves the market from Accept-Language.
 */
class DiscoverController extends Controller
{
    public function show(
        Request $request,
        CurrentMarket $current,
        ModeEngine $engine,
        ModeRegistry $modes,
        string $market,
        ?string $mode = null,
    ): Response {
        $mode ??= 'search';

        abort_unless($modes->has($mode), 404);

        $result = $engine->discover(
            $mode,
            $this->buildRequest($request, $current),
            surprise: $this->surprise($request),
        );

        app(PageMeta::class)->set(
            title: __("site.discover.modes.{$mode}.title"),
            description: __("site.discover.modes.{$mode}.description"),
            canonical: url($current->url("discover/{$mode}")),
        );

        return Inertia::render('Discover', [
            'mode' => $mode,
            'stops' => $modes->stops(),
            'query' => $request->query('q'),
            'surprise' => $this->surprise($request),
            ...$result->toArray($current),
        ]);
    }

    /**
     * The dial.
     *
     * `dial` is a position on the intent axis, not a mode name: dragging
     * between Search and Serendipity blends the two profiles, so the same
     * surface visibly reorganises rather than snapping between layouts.
     */
    public function discover(
        Request $request,
        CurrentMarket $current,
        ModeEngine $engine,
        ModeRegistry $modes,
    ): JsonResponse {
        $validated = $request->validate([
            'mode' => ['nullable', 'string', 'max:40'],
            'dial' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'surprise' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'input' => ['array'],
            'input.query' => ['nullable', 'string', 'max:200'],
            'input.goal' => ['nullable', 'string', 'max:200'],
            'input.items' => ['array', 'max:8'],
            'input.items.*' => ['integer'],
            'context' => ['array'],
            'context.budget_min' => ['nullable', 'numeric', 'min:0'],
            'context.budget_max' => ['nullable', 'numeric', 'min:0'],
            'exclude' => ['array', 'max:120'],
            'exclude.*' => ['integer'],
            'overlays' => ['array'],
            'overlays.modality' => ['nullable', 'string', 'in:text,voice,image'],
            'overlays.social' => ['nullable', 'boolean'],
        ]);

        $mode = $validated['mode'] ?? 'search';

        if (! $modes->has($mode)) {
            $mode = 'search';
        }

        $result = $engine->discover(
            $mode,
            $this->buildRequest($request, $current),
            dial: isset($validated['dial']) ? (float) $validated['dial'] : null,
            surprise: $this->surprise($request),
        );

        return response()->json($result->toArray($current));
    }

    /**
     * A reaction on a result — the learning loop's raw material.
     *
     * Records the dominant scoring factor alongside the reaction. Without it a
     * row says "they liked it" but not "they liked it *for the reason we
     * thought*", and it is the second half that tunes a weight.
     */
    public function react(Request $request, CurrentMarket $current): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', 'max:40'],
            'group_id' => ['required', 'integer'],
            'reaction' => ['required', 'string', 'in:save,click,meh,hide,mindblown'],
            'factor' => ['nullable', 'string', 'max:40'],
            'position' => ['nullable', 'integer', 'min:0', 'max:500'],
        ]);

        $owner = Owner::fromRequest($request);
        abort_unless($owner->exists(), 403);

        DiscoveryReaction::create([
            'mode' => $validated['mode'],
            'market' => $current->value(),
            ...$owner->attributes('user_id', 'anon_id'),
            'group_id' => $validated['group_id'],
            'reaction' => $validated['reaction'],
            'dominant_factor' => $validated['factor'] ?? null,
            'position' => $validated['position'] ?? null,
        ]);

        return response()->json(['ok' => true]);
    }

    private function buildRequest(Request $request, CurrentMarket $current): DiscoveryRequest
    {
        $input = (array) $request->input('input', []);
        $context = (array) $request->input('context', []);
        $overlays = (array) $request->input('overlays', []);

        return new DiscoveryRequest(
            market: $current->get(),
            // The GET landing takes ?q= so a mode page is shareable with its
            // query intact; the POST takes input.query.
            query: $input['query'] ?? $request->query('q'),
            seedGroupIds: array_map('intval', (array) ($input['items'] ?? [])),
            goal: $input['goal'] ?? null,
            budgetMin: isset($context['budget_min']) ? (int) round((float) $context['budget_min'] * 100) : null,
            budgetMax: isset($context['budget_max']) ? (int) round((float) $context['budget_max'] * 100) : null,
            excludeGroupIds: array_slice(array_map('intval', (array) $request->input('exclude', [])), -120),
            limit: 24,
            modality: (string) ($overlays['modality'] ?? 'text'),
            social: (bool) ($overlays['social'] ?? false),
        );
    }

    /** The user's own surprise dial. 0.5 means "leave the profile alone". */
    private function surprise(Request $request): float
    {
        $surprise = $request->input('surprise', $request->query('surprise'));

        if ($surprise === null && $request->user() !== null) {
            $surprise = $request->user()->surprise_dial;
        }

        return $surprise === null ? 0.5 : max(0.0, min(1.0, (float) $surprise));
    }
}
