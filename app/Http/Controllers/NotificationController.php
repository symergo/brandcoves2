<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\PriceAlert;
use App\Models\RestockAlert;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The in-app inbox, and the list of what is currently being watched.
 *
 * One page rather than two: "what did I miss" and "what am I waiting for" are
 * the same question asked at different times.
 */
class NotificationController extends Controller
{
    public function index(Request $request, CurrentMarket $current): Response
    {
        $user = $request->user();

        $notifications = Notification::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Notification $n) => [
                'id' => $n->id,
                'kind' => $n->kind,
                'title' => $n->title,
                'body' => $n->body,
                'url' => $n->url,
                'price' => $n->payload['price'] ?? null,
                'baseline' => $n->payload['baseline'] ?? null,
                'readAt' => $n->read_at?->toIso8601String(),
                'createdAt' => $n->created_at->toIso8601String(),
            ]);

        return Inertia::render('Notifications', [
            'notifications' => $notifications,
            'watching' => $this->watching($request, $current),
        ]);
    }

    /**
     * Mark everything read.
     *
     * All-or-nothing rather than per-item: the badge exists to say "there is
     * something new", and once the page has been opened there is not.
     */
    public function markAllRead(Request $request, CurrentMarket $current): RedirectResponse
    {
        Notification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back();
    }

    /**
     * Products this person has an alert on, price and restock merged.
     *
     * @return list<array<string, mixed>>
     */
    private function watching(Request $request, CurrentMarket $current): array
    {
        $userId = $request->user()->id;

        $rows = [];

        foreach (PriceAlert::query()->where('user_id', $userId)->with('group')->get() as $alert) {
            if ($alert->group === null) {
                continue;
            }

            $rows[$alert->group_id] = [
                'groupId' => $alert->group_id,
                'title' => $alert->group->title,
                'image' => $alert->group->image_url,
                'url' => $current->url("p/{$alert->group_id}/{$alert->group->slug}"),
                'currentPrice' => $alert->group->min_price,
                'baseline' => $alert->baseline_price,
                'target' => $alert->target_price,
                'state' => $alert->state->value,
                'restock' => false,
            ];
        }

        foreach (RestockAlert::query()->where('user_id', $userId)->with('group')->get() as $alert) {
            if ($alert->group === null) {
                continue;
            }

            $existing = $rows[$alert->group_id] ?? [
                'groupId' => $alert->group_id,
                'title' => $alert->group->title,
                'image' => $alert->group->image_url,
                'url' => $current->url("p/{$alert->group_id}/{$alert->group->slug}"),
                'currentPrice' => $alert->group->min_price,
                'baseline' => null,
                'target' => null,
                'state' => $alert->state->value,
            ];

            $rows[$alert->group_id] = [...$existing, 'restock' => true];
        }

        return array_values($rows);
    }
}
