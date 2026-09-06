<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AlertState;
use App\Models\SearchAlert;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Watching a search. Behind `auth`: the notification needs somewhere to go.
 */
class SearchAlertController extends Controller
{
    public function store(Request $request, CurrentMarket $current, SearchService $search): RedirectResponse
    {
        $validated = $request->validate([
            'term' => ['required', 'string', 'max:120'],
            // Euros from the form, cents in the column — as the price alert does.
            'max_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $term = SearchAlert::normalise($validated['term']);

        if ($term === '') {
            return back()->with('error', __('site.search.watch_needs_term'));
        }

        $maxPrice = isset($validated['max_price']) && $validated['max_price'] !== ''
            ? (int) round(((float) $validated['max_price']) * 100)
            : null;

        /*
         * What matches today is what the person is looking at, so none of it
         * is news. Seeding the seen set with it means the first notification
         * is about something that was not on this page.
         */
        $seen = $search->matchingGroupIds(new SearchQuery(
            market: $current->get(),
            term: $term,
            maxPrice: $maxPrice,
            logged: false,
            liveTerm: '',
        ), 200);

        SearchAlert::updateOrCreate(
            ['user_id' => $request->user()->id, 'market' => $current->value(), 'term' => $term],
            [
                'max_price' => $maxPrice,
                'state' => AlertState::Active->value,
                'seen_group_ids' => $seen,
                'notified_at' => null,
            ],
        );

        return back()->with('success', __('site.search.watch_created'));
    }

    public function destroy(Request $request, CurrentMarket $current, string $market, string $alert): RedirectResponse
    {
        SearchAlert::query()
            ->whereKey($alert)
            ->where('user_id', $request->user()->id)
            ->delete();

        return back()->with('success', __('site.search.watch_removed'));
    }
}
