<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Preference;
use App\Enums\Vibe;
use App\Models\UserTaste;
use App\Services\Gift\GiftTags;
use App\Services\Gift\TasteChoiceReader;
use App\Services\Gift\TasteProfiler;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Mijn smaak": your own gift taste, on your account (owner, 2026-09-29).
 *
 * Filled in here, or learned by playing This or that for yourself (`learn`).
 * Friends on GiftCoves who look for a gift for you start from it, and so does
 * "Voor mezelf" in Find a gift (OwnTaste, TasteBrief::fromRecipient).
 *
 * No budget, on the owner's word: what somebody spends is the giver's
 * decision, a filter in their own search. No vibe and no values either
 * (owner, 2026-09-29: both removed site-wide; the pairs of opposites cover the
 * feel). Their columns stay until a later release drops them (expand /
 * contract), and are written empty. See docs/features/my-taste.md.
 */
class MyTasteController extends Controller
{
    public function show(Request $request, CurrentMarket $current): Response
    {
        app(PageMeta::class)->set(title: __('site.my_taste.title'), robots: 'noindex, nofollow');

        $taste = UserTaste::query()->find($request->user()->id);

        return Inertia::render('MyTaste', [
            'taste' => [
                'interests' => array_values((array) ($taste?->interests ?? [])),
                'preferences' => array_values((array) ($taste?->preferences ?? [])),
                'avoid' => array_values((array) ($taste?->avoid ?? [])),
                'ageBand' => $taste?->age_band,
            ],
            'options' => array_intersect_key(
                app(GiftController::class)->options(),
                array_flip(['interests', 'preferences', 'ages']),
            ),
            'urls' => [
                'update' => $current->url('my-taste'),
                // This or that, for yourself: its result offers "Keep as my taste".
                'learn' => $current->url('gift/taste').'?for=me',
                // Swiping for yourself fills it as you go, and Stop comes back here.
                'swipe' => $current->url('gift/swipe').'?for=me&from=my-taste',
            ],
        ]);
    }

    public function update(Request $request, CurrentMarket $current): RedirectResponse
    {
        $validated = $request->validate([
            // The same bounds as Find a gift's own answers (GiftController::validateBrief).
            'interests' => ['array', 'max:8'],
            'interests.*' => ['string', 'max:40'],
            'preferences' => ['array', 'max:3'],
            'preferences.*' => ['string', Rule::in(Preference::values())],
            'avoid' => ['array', 'max:10'],
            'avoid.*' => ['string', 'max:40'],
            'age_band' => ['nullable', 'string', Rule::in(GiftTags::AGE_BANDS)],
        ]);

        $this->keep($request->user()->id, [
            'interests' => $validated['interests'] ?? [],
            'preferences' => $validated['preferences'] ?? [],
            'avoid' => $validated['avoid'] ?? [],
            'age_band' => $validated['age_band'] ?? null,
        ]);

        $cleared = UserTaste::query()->find($request->user()->id) === null;

        return redirect($current->url('my-taste'))->with('success', __($cleared ? 'site.my_taste.cleared' : 'site.my_taste.saved'));
    }

    /**
     * This or that, played for yourself, kept as your taste.
     *
     * The page sends its choices and the taste is worked out here, from the
     * catalogue, as every other save of a This or that result is: a posted
     * profile would be whatever the page said. What was learned is merged into
     * what you already said (TasteProfile::mergedWith); the age is left alone.
     *
     * Swipe gifts, played for yourself, sends its swipes here too (owner,
     * 2026-09-29: "include results from swiping and vibe"; the vibe meant the
     * pairs of opposites, "Hoe mag het voelen" being removed): each swipe is a
     * one-card choice, right a like and left a dislike, so the profiler reads
     * interests and taste poles from them as it does from This or
     * that's single cards. Hence the higher cap: a swipe session has no end,
     * and the page sends its latest hundred.
     */
    public function learn(Request $request, CurrentMarket $current, TasteChoiceReader $reader): JsonResponse
    {
        $validated = $request->validate([
            'choices' => ['required', 'array', 'max:100'],
            'choices.*.shown' => ['required', 'array', 'min:1', 'max:2'],
            'choices.*.shown.*' => ['integer'],
            'choices.*.picked' => ['nullable', 'integer'],
            'choices.*.verdict' => ['nullable', 'string', 'in:like,dislike'],
        ]);

        $profile = TasteProfiler::fromConfig()->profile($reader->read($validated['choices'], $current->get()));

        abort_if($profile->isEmpty(), 422, __('site.gift.taste.nothing_to_save'));

        $taste = UserTaste::query()->find($request->user()->id);

        $merged = $profile->mergedWith([
            'interests' => $taste?->interests ?? [],
            'avoid' => $taste?->avoid ?? [],
            'preferences' => $taste?->preferences ?? [],
        ]);

        $this->keep($request->user()->id, [
            'interests' => array_slice(array_values($merged['interests'] ?? []), 0, 8),
            'preferences' => array_slice(array_values($merged['preferences'] ?? []), 0, 3),
            'avoid' => array_slice(array_values($merged['avoid'] ?? []), 0, 10),
            'age_band' => $taste?->age_band,
        ]);

        return response()->json(['url' => $current->url('my-taste')]);
    }

    /**
     * One row per person, and none for a taste that says nothing: an empty
     * row would read as "has a taste" to nobody's benefit.
     *
     * @param  array{interests: list<string>, preferences: list<string>, avoid: list<string>, age_band: string|null}  $fields
     */
    private function keep(int $userId, array $fields): void
    {
        // Removed site-wide (2026-09-29): written empty until the columns go.
        $fields += ['vibe' => null, 'values' => []];

        $fields['interests'] = array_values(array_unique(array_filter(array_map('trim', $fields['interests']))));
        $fields['avoid'] = array_values(array_unique(array_filter(array_map('trim', $fields['avoid']))));

        $taste = new UserTaste(['user_id' => $userId, ...$fields]);

        if ($taste->isEmpty()) {
            UserTaste::query()->whereKey($userId)->delete();

            return;
        }

        UserTaste::query()->updateOrCreate(['user_id' => $userId], $fields);
    }
}
