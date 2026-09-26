<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GiftProfileCard;
use App\Services\Gift\GiftProfile;
use App\Services\Gift\TasteChoiceReader;
use App\Services\Gift\TasteDeck;
use App\Services\Gift\TasteProfiler;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use App\Support\Owner;
use App\Support\ShareCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "My gift profile": a card somebody makes about themselves after This or
 * that, with a link they can send. Opening the link lands in Find a gift
 * with that profile filled in, and invites the visitor to make their own.
 *
 * - **Opt-in.** Nothing is stored until the person presses the button.
 * - **Worked out here.** The page sends its choices, never a profile, and
 *   the profile is computed from the catalogue, as when saving a result.
 * - **The conclusion only.** The card keeps interests, a budget and what to
 *   leave out, never the choices, and a name only when one was typed.
 * - **Revocable.** The maker holds a key (in the response and in their
 *   session); a signed-in maker can also remove it while signed in.
 * - **Never indexed.** It is somebody's profile, not a page for search.
 *
 * No AI (invariant 1). See docs/features/gift-profile-card.md.
 */
class GiftProfileCardController extends Controller
{
    /** Where a maker's keys live in their session, by card token. */
    private const SESSION = 'gift_profile_cards';

    public function store(Request $request, CurrentMarket $current, TasteChoiceReader $reader): JsonResponse
    {
        $validated = $request->validate([
            'choices' => ['required', 'array', 'max:'.(TasteDeck::ROUNDS * 2)],
            'choices.*.shown' => ['required', 'array', 'min:1', 'max:2'],
            'choices.*.shown.*' => ['integer'],
            'choices.*.picked' => ['nullable', 'integer'],
            'choices.*.verdict' => ['nullable', 'string', 'in:like,dislike'],
            'name' => ['nullable', 'string', 'max:40'],
        ]);

        $profile = GiftProfile::fromProfile(
            TasteProfiler::fromConfig()->profile($reader->read($validated['choices'], $current->get())),
        );

        abort_if(GiftProfile::isEmpty($profile), 422, __('site.gift.taste.nothing_to_save'));

        $key = Str::random(40);
        $name = trim((string) ($validated['name'] ?? ''));
        $owner = Owner::fromRequest($request);

        $card = $this->create([
            'market' => $current->value(),
            'name' => $name === '' ? null : $name,
            'profile' => $profile,
            'owner_user_id' => $owner->user?->id,
            'owner_key_hash' => hash('sha256', $key),
            'last_opened_at' => now(),
        ]);

        $request->session()->put(self::SESSION.'.'.$card->token, $key);

        return response()->json([
            'url' => url($current->url("gift/card/{$card->token}")),
            'summary' => GiftProfile::summary($profile),
            'remove' => $current->url("gift/card/{$card->token}"),
            'key' => $key,
        ]);
    }

    /** The card, as Find a gift with its answers filled in. */
    public function show(Request $request, CurrentMarket $current, string $market, string $token): Response
    {
        $card = GiftProfileCard::query()->where('token', $token)->first();

        if ($card === null) {
            throw new NotFoundHttpException;
        }

        // Kept alive while people open it; at most one write a day.
        if ($card->last_opened_at === null || $card->last_opened_at->lt(now()->subDay())) {
            $card->forceFill(['last_opened_at' => now()])->saveQuietly();
        }

        $summary = GiftProfile::summary($card->profile);
        $title = $card->name === null
            ? __('site.gift.card.title_anonymous')
            : __('site.gift.card.title_named', ['name' => $card->name]);

        app(PageMeta::class)->set(
            title: $title,
            description: $summary,
            robots: 'noindex, nofollow',
        );

        return Inertia::render('Gift/Wizard', [
            'options' => app(GiftController::class)->options(),
            // The visitor is reading somebody else's card, not choosing one
            // of their own people, so the "who" step is left out.
            'recipients' => [],
            'picks' => null,
            'brief' => GiftProfile::brief($card->profile),
            'recipientList' => null,
            'card' => [
                'title' => $title,
                'summary' => $summary,
                'makeOwn' => $current->url('gift/taste'),
                'remove' => $this->isMaker($request, $card) ? $current->url("gift/card/{$card->token}") : null,
            ],
        ]);
    }

    /** Take the card down: its maker, by key or by account. */
    public function destroy(Request $request, CurrentMarket $current, string $market, string $token): JsonResponse
    {
        $card = GiftProfileCard::query()->where('token', $token)->first();

        if ($card === null || ! $this->isMaker($request, $card, (string) $request->input('key', ''))) {
            // The same answer for "no such card" and "not yours", so the
            // endpoint cannot be used to find out which tokens exist.
            throw new NotFoundHttpException;
        }

        $card->delete();
        $request->session()->forget(self::SESSION.'.'.$token);

        return response()->json(['removed' => true, 'redirect' => $current->url('gift')]);
    }

    /** Whether this request is the card's maker: their key, their session, or their account. */
    private function isMaker(Request $request, GiftProfileCard $card, string $key = ''): bool
    {
        $key = $key !== '' ? $key : (string) $request->session()->get(self::SESSION.'.'.$card->token, '');

        if ($key !== '' && hash_equals($card->owner_key_hash, hash('sha256', $key))) {
            return true;
        }

        $user = Owner::fromRequest($request)->user;

        return $user !== null && $card->owner_user_id !== null && (int) $card->owner_user_id === (int) $user->id;
    }

    /** @param  array<string, mixed>  $attributes */
    private function create(array $attributes): GiftProfileCard
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return GiftProfileCard::create([...$attributes, 'token' => ShareCode::make()]);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 2) {
                    throw $e;
                }
            }
        }
    }
}
