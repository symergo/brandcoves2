<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyPickSet;
use App\Services\Cove\SavedCoves;
use App\Support\CurrentMarket;
use App\Support\ListName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Save a Cove into My Coves, take it out again, or make it a list of your own.
 * Behind `auth`; a guest's press is stashed by SaveIntentController and
 * finished after sign-in. See docs/features/saved-coves.md.
 */
class SavedCoveController extends Controller
{
    public function store(Request $request, CurrentMarket $current, SavedCoves $saved, string $market, string $set): RedirectResponse
    {
        $saved->save($request->user(), $this->published($set));

        return back();
    }

    public function destroy(Request $request, CurrentMarket $current, SavedCoves $saved, string $market, string $set): RedirectResponse
    {
        // Unsaving a Cove that has since been unpublished still works: the
        // bookmark is the person's, whatever state the Cove is in.
        $cove = DailyPickSet::query()->find((int) $set);

        if ($cove !== null) {
            $saved->unsave($request->user(), $cove);
        }

        return back();
    }

    public function copy(Request $request, CurrentMarket $current, SavedCoves $saved, string $market, string $set): RedirectResponse
    {
        $list = $saved->copyToList($request->user(), $this->published($set));

        return redirect()->to($current->url("lists/{$list->id}"))
            ->with(ListName::flash(ListName::mention('site.saved_coves.copied', (string) $list->title, $list->kind)));
    }

    /** Only a published Cove can be saved or copied; anything else is a 404. */
    private function published(string $set): DailyPickSet
    {
        $cove = DailyPickSet::query()->find((int) $set);

        if ($cove === null || ! $cove->isPublished()) {
            throw new NotFoundHttpException;
        }

        return $cove;
    }
}
