<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RecipientType;
use App\Models\ProductGroup;
use App\Services\Gift\CarriedWho;
use App\Services\Gift\GiftHistory;
use App\Services\Gift\SwipeDeck;
use App\Services\Images\ImageProxy;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Swipe gifts: one product at a time, right to put it on the list, left to
 * pass, for as long as the visitor likes (owner, 2026-09-28: "don't limit
 * it, but add a way to navigate away to stop").
 *
 * Like This or that, the page holds the swipes and nothing is stored until a
 * right swipe saves a product, which goes through the ordinary save
 * (`POST /list-items`), so the list, the toast and Undo are the ones the rest
 * of the site has. See docs/features/swipe-gifts.md.
 */
class SwipeController extends Controller
{
    public function show(Request $request, CurrentMarket $current, SwipeDeck $deck, CarriedWho $who): Response
    {
        app(PageMeta::class)->set(
            title: __('site.gift.swipe.title'),
            description: __('site.gift.swipe.seo_description'),
            canonical: url($current->url('gift/swipe')),
        );

        $carried = $who->read($request, $current);
        $person = $carried['person'] === null ? null : $who->recipient($request, $carried['person']['id']);

        return Inertia::render('Gift/Swipe', [
            'carried' => $carried,
            // The first cards start from what is known about who it is for (DeckSeeds).
            'cards' => $this->present($deck->next(
                $current->get(),
                [],
                [],
                $person === null ? [] : app(GiftHistory::class)->excludedGroupIds($person),
                SwipeDeck::BATCH,
                $who->seed($request, $current, $carried['person']['id'] ?? null, $carried['relationship'], $carried['forMe']),
            )),
            'urls' => [
                'next' => $current->url('gift/swipe/next'),
                'finder' => $current->url('gift'),
                // Opened from My taste: Stop goes back there, to see it filled in.
                'back' => $request->query('from') === 'my-taste' ? $current->url('my-taste') : null,
                // Swiping for yourself, signed in, feeds "Mijn smaak" (my-taste.md).
                'mine' => $request->user() !== null && $carried['forMe'] ? $current->url('my-taste/learn') : null,
            ],
        ]);
    }

    /** The next batch. JSON: the page keeps its own swipes. */
    public function next(Request $request, CurrentMarket $current, SwipeDeck $deck, CarriedWho $who): JsonResponse
    {
        // Endless by design, so the lists grow; the caps keep one request
        // bounded, and the page sends only the most recent swipes past them.
        $validated = $request->validate([
            'yes' => ['array', 'max:200'],
            'yes.*' => ['integer'],
            'no' => ['array', 'max:200'],
            'no.*' => ['integer'],
            'exclude' => ['array', 'max:400'],
            'exclude.*' => ['integer'],
            'recipient_id' => ['nullable', 'uuid'],
            // Who it is for, as the page was opened with, for the same seed.
            'relationship' => ['nullable', 'string', Rule::in(RecipientType::values())],
            'for_me' => ['boolean'],
        ]);

        // What a saved person was already given is never offered again.
        $person = $who->recipient($request, $validated['recipient_id'] ?? null);

        return response()->json([
            'cards' => $this->present($deck->next(
                $current->get(),
                array_map('intval', $validated['yes'] ?? []),
                array_map('intval', $validated['no'] ?? []),
                [
                    ...array_map('intval', $validated['exclude'] ?? []),
                    ...($person === null ? [] : app(GiftHistory::class)->excludedGroupIds($person)),
                ],
                SwipeDeck::BATCH,
                $who->seed(
                    $request,
                    $current,
                    $validated['recipient_id'] ?? null,
                    $validated['relationship'] ?? null,
                    (bool) ($validated['for_me'] ?? false),
                ),
            )),
        ]);
    }

    /**
     * @param  list<ProductGroup>  $groups
     * @return list<array<string, mixed>>
     */
    private function present(array $groups): array
    {
        return array_map(fn (ProductGroup $group) => [
            'id' => $group->id,
            'title' => $group->displayTitle(),
            'brand' => $group->brand,
            'image' => $group->image_url,
            // The picture fills a phone screen here, so the proxy's larger copies (image-proxy.md).
            'imageToken' => app(ImageProxy::class)->token($group->image_url),
            'price' => $group->min_price,
        ], $groups);
    }
}
