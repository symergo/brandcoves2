<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\PickMode;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Filament\Resources\CovePlans\Pages\CuratePlan;
use App\Models\CovePlan;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The screen an editor curates a Cove on.
 *
 * Smoke-level on purpose — the decisions worth pinning hard live in
 * CurationSearch and PlanCurator, which are tested directly. What this file
 * exists to catch is the failure that a service test cannot see: the panel
 * being decorative. A screen whose buttons resolve to nothing looks exactly
 * like one that works, right up until somebody tries to use what it produced.
 */
class CuratePlanScreenTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
        ]);
    }

    #[Test]
    public function a_non_admin_cannot_reach_it(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->user(admin: false))
            ->get("/admin/cove-plans/{$plan->id}/curate")
            ->assertForbidden();
    }

    #[Test]
    public function the_page_renders_both_panes_for_an_admin(): void
    {
        /*
         * A full HTTP render, not just a Livewire mount. The two are different
         * failures: a Blade error inside a panel page returns a 500 that no
         * service test can see, and the screen is only ever reached this way.
         */
        $plan = $this->plan();
        $plan->items()->create(['group_id' => $this->find('Kruidenpers')->id, 'rank' => 1]);

        $this->actingAs($this->user(admin: true))
            ->get("/admin/cove-plans/{$plan->id}/curate")
            ->assertOk()
            ->assertSee('The shortlist')
            ->assertSee('Find products')
            // The line that answers "is this four products the page, or will
            // something else appear next to them?"
            ->assertSee('lead the Cove', escape: false);
    }

    #[Test]
    public function an_admin_can_add_reorder_and_annotate(): void
    {
        $plan = $this->plan();
        $first = $this->find('Kruidenpers');
        $second = $this->find('Droogrek');

        $component = Livewire::actingAs($this->user(admin: true))
            ->test(CuratePlan::class, ['record' => $plan->id])
            ->call('add', 'group:'.$first->id)
            ->call('add', 'group:'.$second->id);

        $this->assertSame(
            [$first->id, $second->id],
            $plan->items()->pluck('group_id')->all(),
        );

        $items = $plan->items()->pluck('id')->all();

        $component->call('move', $items[1], -1);

        $this->assertSame(
            [$second->id, $first->id],
            $plan->fresh()->items()->pluck('group_id')->all(),
        );

        // The note is the reason the product is on the list, and it is what the
        // writer is handed. A screen that could not save one would leave the
        // whole feature at "a better multi-select".
        $component->set("notes.{$items[0]}", 'lead with this')
            ->call('saveNote', $items[0]);

        $this->assertSame('lead with this', $plan->items()->find($items[0])->note);
    }

    #[Test]
    public function it_warns_before_06_00_that_a_locked_plan_is_too_short(): void
    {
        /*
         * The whole point of saying it here. A locked plan under the floor
         * publishes nothing at all, and the only other signal is a line in the
         * log at six in the morning.
         */
        $plan = $this->plan(PickMode::Locked);
        $plan->items()->create(['group_id' => $this->find('Kruidenpers')->id, 'rank' => 1]);

        $page = new CuratePlan;
        $page->mount($plan->id);

        $this->assertStringContainsString('does not publish', (string) $page->warning());
    }

    #[Test]
    public function removing_is_undoable_and_puts_the_note_back_too(): void
    {
        /*
         * Undo rather than a confirmation dialog. A modal charges a click on
         * each of the six correct removals to protect the seventh — and could
         * never have restored the note and the position for the one somebody
         * actually meant to keep.
         */
        $plan = $this->plan();
        $product = $this->find('Kruidenpers');

        $item = $plan->items()->create([
            'group_id' => $product->id,
            'rank' => 1,
            'note' => 'lead with this',
        ]);

        $component = Livewire::actingAs($this->user(admin: true))
            ->test(CuratePlan::class, ['record' => $plan->id])
            ->call('removeItem', $item->id);

        $this->assertSame(0, $plan->items()->count());

        $component->call('undoRemove');

        $restored = $plan->items()->firstOrFail();

        $this->assertSame($product->id, $restored->group_id);
        $this->assertSame('lead with this', $restored->note);
    }

    #[Test]
    public function it_can_fill_an_empty_plan_from_the_engine(): void
    {
        // The blank page, solved on the screen: a plan made by hand in the
        // panel arrives empty, and inventing seven products from nothing is the
        // reason the old pinned-products field went unused.
        $plan = $this->plan();

        for ($i = 0; $i < 12; $i++) {
            $this->find("Bijzonder apparaat {$i}", surprise: 90 - $i);
        }

        Livewire::actingAs($this->user(admin: true))
            ->test(CuratePlan::class, ['record' => $plan->id])
            ->call('suggest');

        $this->assertSame(
            (int) config('giftcoves.picks.per_day'),
            $plan->items()->count(),
        );
    }

    #[Test]
    public function suggesting_tops_up_and_never_touches_what_is_already_there(): void
    {
        $plan = $this->plan();
        $chosen = $this->find('Handgekozen');
        $plan->items()->create(['group_id' => $chosen->id, 'rank' => 1, 'note' => 'mine']);

        for ($i = 0; $i < 12; $i++) {
            $this->find("Bijzonder apparaat {$i}", surprise: 90 - $i);
        }

        Livewire::actingAs($this->user(admin: true))
            ->test(CuratePlan::class, ['record' => $plan->id])
            ->call('suggest');

        $first = $plan->items()->first();

        $this->assertSame($chosen->id, $first->group_id, 'the suggestion displaced the curated product');
        $this->assertSame('mine', $first->note);
        $this->assertSame((int) config('giftcoves.picks.per_day'), $plan->items()->count());
    }

    #[Test]
    public function an_item_can_be_promoted_straight_to_the_front(): void
    {
        // "Open with this one" is the common edit, and six presses of an arrow
        // is the interface making a person do the arithmetic.
        $plan = $this->plan();

        $items = collect(['Een', 'Twee', 'Drie', 'Vier'])->map(
            fn (string $title, int $i) => $plan->items()->create([
                'group_id' => $this->find($title)->id,
                'rank' => $i + 1,
            ]),
        );

        Livewire::actingAs($this->user(admin: true))
            ->test(CuratePlan::class, ['record' => $plan->id])
            ->call('moveToTop', $items->last()->id);

        $this->assertSame($items->last()->id, $plan->items()->first()->id);
        $this->assertSame([1, 2, 3, 4], $plan->items()->pluck('rank')->all());
    }

    #[Test]
    public function the_mode_can_be_switched_while_looking_at_the_list(): void
    {
        $plan = $this->plan(PickMode::Open);

        Livewire::actingAs($this->user(admin: true))
            ->test(CuratePlan::class, ['record' => $plan->id])
            ->call('setPickMode', 'locked');

        $this->assertSame(PickMode::Locked, $plan->fresh()->pick_mode);
    }

    #[Test]
    public function it_says_what_the_cove_will_actually_publish(): void
    {
        // The question the first version could not answer: I have four
        // products — is that the page, or will something else appear?
        $plan = $this->plan(PickMode::Open);
        $plan->items()->create(['group_id' => $this->find('Een')->id, 'rank' => 1]);

        $page = new CuratePlan;
        $page->mount($plan->id);

        $this->assertStringContainsString('the engine adds', $page->summary());

        $plan->update(['pick_mode' => PickMode::Locked->value]);
        $page->mount($plan->id);

        $this->assertStringContainsString('nothing else', $page->summary());
    }

    #[Test]
    public function the_build_instructions_save_from_the_screen(): void
    {
        $plan = $this->plan();

        Livewire::actingAs($this->user(admin: true))
            ->test(CuratePlan::class, ['record' => $plan->id])
            ->set('instructions', 'Kort houden. Nadruk op nostalgie.')
            ->call('saveInstructions');

        $this->assertSame('Kort houden. Nadruk op nostalgie.', $plan->fresh()->build_instructions);
    }

    #[Test]
    public function it_says_when_instructions_will_not_be_read(): void
    {
        // Authored prose skips the model entirely, so a brief for it is read by
        // nobody. A field quietly doing nothing is worse than no field.
        $plan = $this->plan();

        $page = new CuratePlan;
        $page->mount($plan->id);
        $this->assertTrue($page->willBeWritten());

        $plan->update(['editorial' => 'Al geschreven.']);

        $page = new CuratePlan;
        $page->mount($plan->id);
        $this->assertFalse($page->willBeWritten());
    }

    private function plan(PickMode $mode = PickMode::Open): CovePlan
    {
        return CovePlan::create([
            'market' => Market::BeNl->value,
            'drop_date' => CarbonImmutable::tomorrow()->toDateString(),
            'title' => 'Gecureerd',
            'status' => 'draft',
            'pick_mode' => $mode->value,
        ]);
    }

    private function user(bool $admin): User
    {
        $user = User::create([
            'name' => 'Test',
            'email' => ($admin ? 'admin' : 'user').'@example.test',
            'password' => 'password-for-testing',
        ]);

        // is_admin is deliberately not mass-assignable: no request payload can
        // grant admin, and there is no self-service path to the panel.
        if ($admin) {
            $user->forceFill(['is_admin' => true])->save();
        }

        return $user;
    }

    private function find(string $title, float $surprise = 50): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => 2500,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'surprise_score' => $surprise,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'price' => 2500,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }
}
