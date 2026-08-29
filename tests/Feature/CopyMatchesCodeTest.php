<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Http\Middleware\TrackAnonymousIdentity;
use App\Models\AnonymousIdentity;
use App\Models\ListQuiz;
use App\Models\ListQuizAttempt;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The places where the manual described something the code did not do.
 *
 * The Gift Cove's manual quotes the label on the screen — "press Share", "press
 * People" — which is a good rule and a fragile one: renaming a control silently
 * invalidates the step that names it, and only a human reading the page notices.
 * These tests are that human, for the handful of claims that can be checked
 * mechanically.
 */
class CopyMatchesCodeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_co_giver_panel_is_called_what_the_manual_calls_it(): void
    {
        /*
         * `collab_step1` says "press People". The tab read "Who else can see
         * this" — a sentence sitting in a row of one-word chips, and wrong for
         * a group list where the people are co-organisers rather than viewers.
         */
        foreach (['en', 'nl', 'fr', 'es'] as $locale) {
            $label = __('site.lists.collaborators', locale: $locale);

            $this->assertLessThan(
                20,
                mb_strlen($label),
                "The co-giver tab label in {$locale} is a sentence, not a chip: {$label}",
            );
        }
    }

    #[Test]
    public function the_collab_card_opens_the_form_its_first_step_describes(): void
    {
        // Card, form and step have to agree. The card used to open the
        // "for someone else" shape while the step said something else again.
        $this->actingAs(User::factory()->create())
            ->get('/be-nl/gift-cove')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('urls.lists'));

        $this->assertStringContainsString('new=group', file_get_contents(
            resource_path('js/Pages/GiftCove.tsx')
        ));
    }

    #[Test]
    public function the_lists_index_says_which_list_has_a_suggestion_waiting(): void
    {
        /*
         * The Gift Cove's suggestions card points here, and the index said
         * nothing about suggestions at all — so the card looked like it went
         * to the wrong page.
         */
        $owner = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        // A pending suggestion is an item with no `accepted_at`.
        WishlistItem::factory()->create([
            'wishlist_id' => $list->id,
            'accepted_at' => null,
        ]);

        $props = $this->actingAs($owner)->get('/be-nl/lists')->assertOk()->viewData('page')['props'];

        $mine = collect($props['lists'])->firstWhere('id', $list->id);

        $this->assertSame(1, $mine['suggestions']);
    }

    #[Test]
    public function a_suggestion_count_is_not_shown_on_somebody_elses_list(): void
    {
        // A suggestion is a message addressed to the owner. A collaborator
        // learning one arrived is a leak of the fact somebody is thinking about
        // this person.
        $owner = User::factory()->create();
        $mate = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'recipient_id' => Recipient::factory()->create(['owner_user_id' => $owner->id])->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
        ]);

        WishlistCollaborator::create([
            'wishlist_id' => $list->id,
            'user_id' => $mate->id,
            'role' => 'viewer',
        ]);

        WishlistItem::factory()->create(['wishlist_id' => $list->id, 'accepted_at' => null]);

        $props = $this->actingAs($mate)->get('/be-nl/lists?view=shared')->assertOk()->viewData('page')['props'];

        $theirs = collect($props['lists'])->firstWhere('id', $list->id);

        $this->assertNotNull($theirs);
        $this->assertNull($theirs['suggestions']);
    }

    #[Test]
    public function my_lists_holds_every_list_i_can_open_and_says_whose_each_one_is(): void
    {
        /*
         * My Lists used to mean "lists I own, of two of the three kinds". A
         * group list I started and a list somebody invited me to were both
         * absent, each reachable only from a nav entry you had to know existed
         * — so the page named after finding a list was the one place half of
         * them could not be found.
         *
         * Mixing them makes the label load-bearing: what I may do with my own
         * research list and with somebody else's wish list is not the same
         * thing, so every row has to say which it is.
         */
        $me = User::factory()->create();
        $friend = User::factory()->create(['name' => 'Sanne']);

        $ownGroup = Wishlist::factory()->create([
            'owner_user_id' => $me->id,
            'recipient_id' => Recipient::factory()->create(['owner_user_id' => $me->id])->id,
            'kind' => ListKind::Group,
            'market' => Market::BeNl,
        ]);

        $theirs = Wishlist::factory()->create([
            'owner_user_id' => $friend->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        WishlistCollaborator::create([
            'wishlist_id' => $theirs->id,
            'user_id' => $me->id,
            'role' => 'editor',
        ]);

        // A message addressed to its owner, which must not be counted onto my
        // copy of their card now that their card is on my page.
        WishlistItem::factory()->create(['wishlist_id' => $theirs->id, 'accepted_at' => null]);

        $props = $this->actingAs($me)->get('/be-nl/lists')->assertOk()->viewData('page')['props'];

        $rows = collect($props['lists']);

        $mine = $rows->firstWhere('id', $ownGroup->id);
        $this->assertNotNull($mine, 'A group list I own is missing from My Lists.');
        $this->assertFalse($mine['sharedWithMe']);
        $this->assertNull($mine['ownerName']);

        $invited = $rows->firstWhere('id', $theirs->id);
        $this->assertNotNull($invited, 'A list shared with me is missing from My Lists.');
        $this->assertTrue($invited['sharedWithMe']);
        $this->assertSame('Sanne', $invited['ownerName']);
        $this->assertSame('editor', $invited['role']);

        // Invariant: a pending suggestion is a message to the owner. Null
        // rather than zero, so nothing can render a count for it.
        $this->assertNull($invited['suggestions']);
    }

    #[Test]
    public function i_may_keep_more_than_one_wishlist_for_myself(): void
    {
        /*
         * The Gift Cove showed *the* default list and nothing else, so a person
         * with a wedding list and a list of things they want some day saw one
         * of them and had no sign the other was there. One list stays the
         * default — a one-tap save needs a single answer to "where did it go?"
         * — but being the default is not being the only one.
         *
         * Default first, because that is the one a save reaches without being
         * asked about, and the cards on that page act on whichever is first.
         */
        $me = User::factory()->create();

        foreach (['Bruiloft', 'Ooit eens'] as $title) {
            Wishlist::factory()->create([
                'owner_user_id' => $me->id,
                'kind' => ListKind::Mine,
                'market' => Market::BeNl,
                'title' => $title,
            ]);
        }

        $props = $this->actingAs($me)->get('/be-nl/gift-cove')->assertOk()->viewData('page')['props'];

        $wishlists = collect($props['wishlists']);

        $this->assertCount(2, $wishlists);
        $this->assertSame([true], $wishlists->pluck('isDefault')->filter()->values()->all());
        $this->assertTrue($wishlists->first()['isDefault']);
        $this->assertEqualsCanonicalizing(
            ['Bruiloft', 'Ooit eens'],
            $wishlists->pluck('title')->all(),
        );
    }

    #[Test]
    public function the_occasion_panel_is_called_what_the_manual_calls_it(): void
    {
        /*
         * `registry_step1` quotes the label on the button. It said "press
         * Registry" — a word for the artefact rather than for what you are
         * doing to the list — and the control is now "Special occasion", which
         * is what somebody adding a wedding date to their own wish list thinks
         * they are doing. Rename one without the other and the manual sends
         * people hunting for a button they are looking straight at.
         */
        foreach (['en', 'nl', 'fr', 'es'] as $locale) {
            $label = __('site.registry.badge', locale: $locale);
            $step = __('site.gift_cove.registry_step1', locale: $locale);

            $this->assertStringContainsString(
                $label,
                $step,
                "The occasion step in {$locale} does not name the button: {$step}",
            );
        }
    }

    #[Test]
    public function an_anonymous_suggestion_is_attributed_rather_than_unsigned(): void
    {
        /*
         * `suggestions_step2` promises "with the name of whoever sent it". A
         * suggestion from an anonymous cookie identity has no name, arrived
         * with `from: null`, and rendered nothing at all — a message from
         * nobody, which is worse than one from somebody unnamed.
         */
        $owner = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);
        $visitor = AnonymousIdentity::create(['last_seen_at' => now()]);

        $this->withCookie(TrackAnonymousIdentity::COOKIE, (string) $visitor->getKey())
            ->post("/be-nl/l/{$list->share_token}/suggest", ['group_id' => $group->id])
            ->assertRedirect();

        $props = $this->actingAs($owner)
            ->get("/be-nl/lists/{$list->id}")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(1, $props['suggestions']);
        $this->assertNull($props['suggestions'][0]['from']);

        // The component renders `suggestions.from_anonymous` in that case, and
        // the string has to exist in every language for it to.
        foreach (['en', 'nl', 'fr', 'es'] as $locale) {
            $this->assertNotSame(
                'site.suggestions.from_anonymous',
                __('site.suggestions.from_anonymous', locale: $locale),
            );
        }
    }

    #[Test]
    public function a_second_quiz_attempt_is_refused_and_the_first_score_stands(): void
    {
        /*
         * "One go each, otherwise the score means nothing" — the docblock said
         * exactly that and the code used `updateOrCreate`, so a replay silently
         * overwrote the first score. `quiz.play_again` is the refusal message,
         * and it had never been wired to anything.
         */
        $owner = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        $quiz = ListQuiz::create([
            'wishlist_id' => $list->id,
            'market' => Market::BeNl,
            'share_token' => (string) Str::uuid(),
            'rounds' => [
                ['answer' => 1, 'options' => [1, 2, 3, 4]],
                ['answer' => 2, 'options' => [1, 2, 3, 4]],
            ],
        ]);

        $player = AnonymousIdentity::create(['last_seen_at' => now()]);
        $cookie = (string) $player->getKey();

        $this->withCookie(TrackAnonymousIdentity::COOKIE, $cookie)
            ->post("/be-nl/q/{$quiz->share_token}", ['answers' => [1, 2]])
            ->assertRedirect();

        $first = ListQuizAttempt::query()->firstOrFail();

        $this->withCookie(TrackAnonymousIdentity::COOKIE, $cookie)
            ->post("/be-nl/q/{$quiz->share_token}", ['answers' => [9, 9]])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, ListQuizAttempt::query()->count());
        $this->assertSame($first->score, ListQuizAttempt::query()->firstOrFail()->score);
    }

    #[Test]
    public function the_quiz_back_link_is_not_labelled_share(): void
    {
        // It goes to the shared list. `lists.share` is the name of a different
        // control on a different page, and it was labelling this one.
        $play = file_get_contents(resource_path('js/Pages/Quiz/Play.tsx'));

        $this->assertStringNotContainsString("{t('lists.share')}", $play);
        $this->assertStringContainsString("{t('lists.view_list')}", $play);
    }
}
