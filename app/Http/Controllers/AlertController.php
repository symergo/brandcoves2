<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AlertState;
use App\Models\PriceAlert;
use App\Models\ProductGroup;
use App\Models\RestockAlert;
use App\Services\Alerts\AlertEligibility;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Price-drop and back-in-stock alerts.
 *
 * Requires an account, unlike lists: an alert has to reach someone later, and
 * a cookie identity has nowhere to deliver to.
 */
class AlertController extends Controller
{
    public function store(Request $request, CurrentMarket $current, AlertEligibility $eligibility): RedirectResponse
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer'],
            'type' => ['required', 'in:price,restock'],
            'target_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $group = ProductGroup::query()
            ->forMarket($current->get())
            ->find($validated['group_id']);

        if ($group === null) {
            throw new NotFoundHttpException;
        }

        /*
         * COMPLIANCE GATE.
         *
         * Some sources do not permit a price-tracking feature, and an alert is
         * price tracking with a delivery mechanism attached. A product sold only
         * by such a source cannot carry an alert at all — checked here, not just
         * hidden in the UI, so a hand-built POST cannot create one.
         */
        if (! $eligibility->isEligible($group)) {
            return back()->with('error', __('site.alerts.not_available'));
        }

        $baseline = $eligibility->watchableOffers($group)
            ->whereNotNull('price')
            ->min('price');

        if ($baseline === null) {
            return back()->with('error', __('site.alerts.not_available'));
        }

        if ($validated['type'] === 'price') {
            PriceAlert::updateOrCreate(
                ['group_id' => $group->id, 'user_id' => $request->user()->id],
                [
                    'baseline_price' => $baseline,
                    'target_price' => isset($validated['target_price'])
                        ? (int) round($validated['target_price'] * 100)
                        : null,
                    // Re-arming a previously triggered alert is the common case:
                    // the price went down, then back up.
                    'state' => AlertState::Active->value,
                    'notified_at' => null,
                ],
            );
        } else {
            RestockAlert::updateOrCreate(
                ['group_id' => $group->id, 'user_id' => $request->user()->id],
                ['state' => AlertState::Active->value, 'notified_at' => null],
            );
        }

        return back()->with('success', __('site.alerts.created'));
    }

    public function destroy(Request $request, CurrentMarket $current, string $market, string $group): RedirectResponse
    {
        PriceAlert::query()
            ->where('group_id', $group)
            ->where('user_id', $request->user()->id)
            ->delete();

        RestockAlert::query()
            ->where('group_id', $group)
            ->where('user_id', $request->user()->id)
            ->delete();

        return back()->with('success', __('site.alerts.removed'));
    }
}
