<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A list in one step (2026-09-26; docs/features/one-step-list.md).
 *
 * The screen asks one question, who the list is for, and sends only that plus
 * a name it filled in and the person may have cleared. So the server has to
 * make the right kind from the answer alone, and a name when none arrives.
 */
class OneStepListTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function for_me_makes_a_wish_list_with_the_default_name(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post('/be-nl/lists', ['title' => '']);

        $list = Wishlist::query()->where('owner_user_id', $owner->id)->sole();

        $response->assertRedirect("/be-nl/lists/{$list->id}")->assertSessionHas('new_list', true);
        $this->assertSame(ListKind::Mine, $list->kind);
        $this->assertSame('Verlanglijst', $list->title);
        $this->assertNull($list->recipient_id);
    }

    /**
     * The wizard's person cards (PersonPicker, consistency review round 3)
     * show the relationship in the reader's words and the next birthday with
     * its countdown, as Find a gift's cards do.
     */
    #[Test]
    public function the_wizard_offers_people_with_what_their_cards_show(): void
    {
        $this->travelTo('2026-03-01 10:00:00');
        $owner = User::factory()->create();
        Recipient::factory()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Ans',
            'relationship' => 'mother',
            'birthday' => '1960-03-11',
        ]);

        $this->actingAs($owner)->get('/be-nl/lists')
            ->assertInertia(fn ($page) => $page
                ->where('recipients.0.name', 'Ans')
                ->where('recipients.0.relationship', 'Mama')
                ->where('recipients.0.next.date', '2026-03-11')
                ->where('recipients.0.next.days', 10)
                ->where('recipients.0.next.kind', 'birthday'));
    }

    #[Test]
    public function someone_else_makes_a_gift_list_named_for_them(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post('/be-nl/lists', ['new_recipient' => 'Sara'])->assertRedirect();

        $list = Wishlist::query()->where('owner_user_id', $owner->id)->sole();

        $this->assertSame(ListKind::ForSomeone, $list->kind);
        $this->assertSame('Cadeaus voor Sara', $list->title);
        $this->assertSame('Sara', $list->recipient?->name);
    }

    #[Test]
    public function together_makes_a_group_list_named_for_them(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post('/be-nl/lists', [
            'new_recipient' => 'Sara',
            'together' => true,
        ])->assertRedirect();

        $list = Wishlist::query()->where('owner_user_id', $owner->id)->sole();

        $this->assertSame(ListKind::Group, $list->kind);
        $this->assertSame('Samen voor Sara', $list->title);
    }

    #[Test]
    public function a_person_i_already_have_names_the_list_too(): void
    {
        $owner = User::factory()->create();
        $person = Recipient::query()->create(['owner_user_id' => $owner->id, 'name' => 'Ada']);

        $this->actingAs($owner)->post('/en/lists', ['recipient_id' => $person->id])->assertRedirect();

        $this->assertSame(
            'Gifts for Ada',
            Wishlist::query()->where('owner_user_id', $owner->id)->sole()->title,
        );
    }

    #[Test]
    public function the_default_names_follow_the_market_language(): void
    {
        $owner = User::factory()->create();

        $expected = [
            'en' => ['Wish list', 'Gifts for Sara', 'Together for Sara'],
            'be-fr' => ['Liste d’envies', 'Cadeaux pour Sara', 'Ensemble pour Sara'],
            'es' => ['Lista de deseos', 'Regalos para Sara', 'Juntos para Sara'],
        ];

        foreach ($expected as $market => [$mine, $forSomeone, $group]) {
            $this->actingAs($owner)->post("/{$market}/lists", [])->assertRedirect();
            $this->actingAs($owner)->post("/{$market}/lists", ['new_recipient' => 'Sara'])->assertRedirect();
            $this->actingAs($owner)->post("/{$market}/lists", ['new_recipient' => 'Sara', 'together' => true])->assertRedirect();

            $titles = Wishlist::query()
                ->where('owner_user_id', $owner->id)
                ->where('market', $market)
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('title', 'kind')
                ->all();

            $this->assertSame($mine, $titles[ListKind::Mine->value] ?? null, $market);
            $this->assertSame($forSomeone, $titles[ListKind::ForSomeone->value] ?? null, $market);
            $this->assertSame($group, $titles[ListKind::Group->value] ?? null, $market);
        }
    }

    #[Test]
    public function a_typed_name_is_kept_as_typed(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post('/be-nl/lists', [
            'title' => '  Sara wordt 30  ',
            'new_recipient' => 'Sara',
        ])->assertRedirect();

        $this->assertSame('Sara wordt 30', Wishlist::query()->where('owner_user_id', $owner->id)->sole()->title);
    }

    #[Test]
    public function the_new_list_offers_its_settings_once(): void
    {
        /*
         * Occasion, sharing and asking for ideas moved off the create screen
         * to the list page. The page is told, once, that the list is new, so
         * it can offer them beside the add field.
         */
        $owner = User::factory()->create();

        $this->actingAs($owner)->post('/be-nl/lists', ['new_recipient' => 'Sara']);
        $list = Wishlist::query()->where('owner_user_id', $owner->id)->sole();

        $this->actingAs($owner)->get("/be-nl/lists/{$list->id}")
            ->assertInertia(fn ($page) => $page->where('flash.newList', true));

        $this->actingAs($owner)->get("/be-nl/lists/{$list->id}")
            ->assertInertia(fn ($page) => $page->where('flash.newList', false));
    }

    #[Test]
    public function a_birthday_set_on_the_list_page_reaches_the_person(): void
    {
        /*
         * The old wizard asked a new person's birthday while making the list.
         * The one step does not, so the occasion on the list page carries it:
         * "Birthday" with a date fills the person's birthday when it is blank,
         * and never overwrites one that is there.
         */
        $owner = User::factory()->create();

        $this->actingAs($owner)->post('/be-nl/lists', ['new_recipient' => 'Sara']);
        $list = Wishlist::query()->where('owner_user_id', $owner->id)->sole();

        $this->actingAs($owner)->patch("/be-nl/lists/{$list->id}", [
            'event_type' => 'birthday',
            'event_date' => '2026-11-03',
        ])->assertRedirect();

        $this->assertSame('11-03', $list->recipient()->firstOrFail()->birthday?->format('m-d'));

        $this->actingAs($owner)->patch("/be-nl/lists/{$list->id}", [
            'event_type' => 'birthday',
            'event_date' => '2026-12-24',
        ]);

        $this->assertSame('11-03', $list->recipient()->firstOrFail()->birthday?->format('m-d'));
    }

    #[Test]
    public function a_guest_gets_the_screen_and_signs_in_at_its_button(): void
    {
        /*
         * As before the change: the screen works signed out, and its button is
         * the sign-in, which remembers the answer and makes the list on the
         * way back (client side, in ListWizard). The endpoint itself stays
         * behind an account, because a list kept by a cookie is a draft.
         */
        foreach (['/be-nl/lists?new', '/be-nl/lists?new=mine', '/be-nl/lists?new=for_someone', '/be-nl/lists?new=group'] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->component('Lists/Index'));
        }

        $this->post('/be-nl/lists', ['new_recipient' => 'Sara'])->assertRedirect();
        $this->assertSame(0, Wishlist::query()->count());
    }

    #[Test]
    public function the_new_list_opens_on_the_add_field(): void
    {
        /*
         * A source check, because SSR does not run in the suite: the empty
         * list (the one the create lands on) renders the add panel open, and
         * the panel puts the cursor in its field when it opens. Together that
         * is "paste or search is the next thing", with no press between.
         */
        $show = (string) file_get_contents(resource_path('js/Pages/Lists/Show.tsx'));
        $add = (string) file_get_contents(resource_path('js/Components/AddProduct.tsx'));

        $this->assertMatchesRegularExpression('/<AddProduct[^>]*defaultOpen/', $show);
        // Unless a page opts out (`autoFocus={false}` on /for/{token}, where the
        // panel is the third section and arriving must not jump to it); the
        // list page does not, so the default must stay on.
        $this->assertStringContainsString('autoFocus = true,', $add);
        $this->assertStringContainsString('if (open && (autoFocus || !arrived.current)) field.current?.focus()', $add);
        $this->assertDoesNotMatchRegularExpression('/<AddProduct[^>]*autoFocus/', $show);
    }

    #[Test]
    public function the_button_and_the_home_page_use_one_name(): void
    {
        // "Create a Cove" on the home page, "Make a new list" on My Lists, and
        // one screen behind both: one name now, in every language.
        foreach (['en', 'nl', 'fr', 'es'] as $language) {
            $this->assertSame(
                trans('site.home.cta_create', [], $language),
                trans('site.lists.make_new', [], $language),
                $language,
            );
        }
    }
}
