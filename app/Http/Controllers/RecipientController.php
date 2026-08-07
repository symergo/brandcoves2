<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Recipient;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * People you might buy for.
 *
 * Deliberately thin here: a recipient is created with little more than a name,
 * and everything else is filled in by the Gift Whisperer wizard, which is where
 * asking about someone's interests actually makes sense.
 */
class RecipientController extends Controller
{
    public function store(Request $request, CurrentMarket $current): RedirectResponse
    {
        $owner = Owner::fromRequest($request);
        abort_unless($owner->exists(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'relationship' => ['nullable', 'string', 'max:40'],
            'occasion' => ['nullable', 'string', 'max:40'],
            'birthday' => ['nullable', 'date'],
        ]);

        Recipient::create([...$owner->attributes(), ...$validated]);

        return back()->with('success', __('site.lists.recipient_added'));
    }

    public function update(Request $request, CurrentMarket $current, string $market, string $recipient): RedirectResponse
    {
        $model = $this->findOwned($request, $recipient);

        $model->update($request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'relationship' => ['nullable', 'string', 'max:40'],
            'occasion' => ['nullable', 'string', 'max:40'],
            'birthday' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]));

        return back();
    }

    public function destroy(Request $request, CurrentMarket $current, string $market, string $recipient): RedirectResponse
    {
        // Lists survive: the foreign key nulls out rather than cascading, so
        // deleting a person never destroys the gift research done for them.
        $this->findOwned($request, $recipient)->delete();

        return back()->with('success', __('site.lists.recipient_removed'));
    }

    private function findOwned(Request $request, string $id): Recipient
    {
        $recipient = Owner::fromRequest($request)
            ->scope(Recipient::query())
            ->find($id);

        if ($recipient === null) {
            throw new NotFoundHttpException;
        }

        return $recipient;
    }
}
