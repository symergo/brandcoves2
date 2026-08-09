<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TasteSource;
use App\Http\Requests\RecipientTasteRequest;
use App\Models\Recipient;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * People you might buy for.
 *
 * A recipient can be created with little more than a name — the wizard fills in
 * the rest, which is where asking about someone's interests actually makes
 * sense. But the fields must all be *writable* from here, because for a long
 * time they were not: only name, relationship, occasion and birthday could be
 * set, while the engine reads interests, vibe, values, avoid and budget. The
 * "use what we know about Mum" shortcut therefore restored an empty brief every
 * time, and looked for all the world like a working feature.
 */
class RecipientController extends Controller
{
    public function store(RecipientTasteRequest $request, CurrentMarket $current): RedirectResponse
    {
        $owner = Owner::fromRequest($request);
        abort_unless($owner->exists(), 403);

        $request->validate(['name' => ['required', 'string', 'max:80']]);

        $recipient = Recipient::create([
            ...$owner->attributes(),
            ...$request->context(),
        ]);

        // Anything the creator already knows is a guess, however confident.
        $recipient->describeTaste($request->taste(), TasteSource::Suggested);

        return back()->with('success', __('site.lists.recipient_added'));
    }

    public function update(RecipientTasteRequest $request, CurrentMarket $current, string $market, string $recipient): RedirectResponse
    {
        $model = $this->findOwned($request, $recipient);

        $model->update($request->context());

        /*
         * Silently ignored once the person has answered for themselves, rather
         * than rejected. The owner is not doing anything wrong by having an
         * older opinion — it is simply no longer the best evidence, and an
         * error message here would be scolding them for it.
         */
        $model->describeTaste($request->taste(), TasteSource::Suggested);

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
