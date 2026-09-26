<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\MatchRule;
use App\Enums\MatchStatus;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Filament\Pages\MatchReview;
use App\Filament\Resources\ProductGroups\Pages\ManageProductGroup;
use App\Models\MatchCandidate;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two admin screens for matching: the review queue and one product's page.
 *
 * Smoke-level, like the curation screen's test: the rules are pinned in
 * GroupMergerTest and MatchFinderTest. What this catches is a screen whose
 * buttons resolve to nothing, which looks exactly like one that works.
 */
class MatchReviewScreenTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create(['source' => Source::Awin->value, 'external_id' => 'shop', 'name' => 'Speelgoedwinkel']);
    }

    private function admin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password-for-testing']);
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    private function group(string $title, int $offers = 1): ProductGroup
    {
        $group = ProductGroup::factory()->create([
            'identity_key' => 'lego|'.fake()->unique()->uuid(),
            'identity_kind' => 'title',
            'title' => $title,
            'brand' => 'LEGO',
        ]);

        for ($i = 0; $i < $offers; $i++) {
            Product::create([
                'source' => Source::Awin, 'market' => $group->market, 'merchant_id' => $this->merchant->id,
                'group_id' => $group->id, 'external_id' => fake()->unique()->uuid(),
                'identity_kind' => 'title', 'identity_key' => $group->identity_key,
                'title' => $title.' offer '.$i, 'price' => 4000 + $i, 'currency' => 'EUR',
                'affiliate_url' => 'https://example.test/buy', 'availability' => Availability::InStock,
                'status' => ProductStatus::Active,
            ]);
        }

        return $group;
    }

    /** @return array{0: ProductGroup, 1: ProductGroup, 2: MatchCandidate} */
    private function pending(): array
    {
        $big = $this->group('LEGO Technic Ferrari 488 GTE', offers: 2);
        $small = $this->group('LEGO Ferrari 488 #42125');

        $candidate = MatchCandidate::create([
            'market' => 'be-nl', 'group_a' => min($big->id, $small->id), 'group_b' => max($big->id, $small->id),
            'rule' => MatchRule::Model, 'score' => 0.64, 'evidence' => '42125', 'status' => MatchStatus::Pending,
        ]);

        return [$big, $small, $candidate];
    }

    #[Test]
    public function the_queue_renders_both_products_and_the_precision(): void
    {
        $this->pending();

        $this->actingAs($this->admin())
            ->get('/admin/match-review')
            ->assertOk()
            ->assertSee('LEGO Technic Ferrari 488 GTE')
            ->assertSee('LEGO Ferrari 488 #42125')
            ->assertSee('Precision')
            ->assertSee('The same (M)');
    }

    #[Test]
    public function the_same_merges_into_the_product_with_more_offers(): void
    {
        [$big, $small, $candidate] = $this->pending();
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(MatchReview::class)->call('merge');

        $this->assertSame($big->id, $small->fresh()->merged_into_id);
        $this->assertSame(MatchStatus::Merged, $candidate->fresh()->status);
        $this->assertSame($admin->id, $candidate->fresh()->decided_by);
    }

    #[Test]
    public function the_kept_side_can_be_swapped(): void
    {
        [$big, $small] = $this->pending();

        Livewire::actingAs($this->admin())->test(MatchReview::class)->call('swap')->call('merge');

        $this->assertSame($small->id, $big->fresh()->merged_into_id);
    }

    #[Test]
    public function not_the_same_is_recorded_and_skip_is_not(): void
    {
        [$big, $small, $candidate] = $this->pending();
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(MatchReview::class)->call('skip');
        $this->assertSame(MatchStatus::Pending, $candidate->fresh()->status);

        Livewire::actingAs($admin)->test(MatchReview::class)->call('reject');
        $this->assertSame(MatchStatus::Rejected, $candidate->fresh()->status);
        $this->assertNull($small->fresh()->merged_into_id);
        $this->assertNull($big->fresh()->merged_into_id);
    }

    #[Test]
    public function a_product_page_renders_its_offers_and_merges_and_splits(): void
    {
        $winner = $this->group('LEGO Technic Ferrari 488 GTE', offers: 2);
        $loser = $this->group('LEGO Ferrari 488 #42125');
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/product-groups')->assertOk()->assertSee('LEGO Ferrari 488 #42125');

        $this->actingAs($admin)
            ->get("/admin/product-groups/{$loser->id}")
            ->assertOk()
            ->assertSee('LEGO Ferrari 488 #42125 offer 0')
            ->assertSee('Speelgoedwinkel');

        Livewire::actingAs($admin)
            ->test(ManageProductGroup::class, ['record' => $loser->id])
            ->callAction('merge', ['winner' => $winner->id])
            ->assertHasNoActionErrors();

        $this->assertSame($winner->id, $loser->fresh()->merged_into_id);
        $this->assertSame(3, $winner->fresh()->offer_count);

        $moved = $winner->offers()->orderBy('id')->first();

        Livewire::actingAs($admin)
            ->test(ManageProductGroup::class, ['record' => $winner->id])
            ->callAction('split', ['offers' => [$moved->id]])
            ->assertHasNoActionErrors();

        $this->assertNotSame($winner->id, $moved->fresh()->group_id);
        $this->assertSame(2, $winner->fresh()->offer_count);
    }

    #[Test]
    public function the_same_for_a_rule_merges_every_waiting_pair_of_that_rule_and_no_other(): void
    {
        // Owner's request, 2026-09-26: a "The same" button per rule row.
        [$big, $small, $candidate] = $this->pending();
        [$big2, $small2, $candidate2] = $this->pending();

        $otherA = $this->group('LEGO Creator Expert Bugatti', offers: 2);
        $otherB = $this->group('LEGO Bugatti Chiron');
        $titleRule = MatchCandidate::create([
            'market' => 'be-nl', 'group_a' => min($otherA->id, $otherB->id), 'group_b' => max($otherA->id, $otherB->id),
            'rule' => MatchRule::Title, 'score' => 0.7, 'status' => MatchStatus::Pending,
        ]);

        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/match-review')->assertOk()->assertSee('wire:confirm', false);

        // The queue runs synchronously in tests, so the job has finished here.
        Livewire::actingAs($admin)->test(MatchReview::class)->call('mergeRule', MatchRule::Model->value);

        $this->assertSame($big->id, $small->fresh()->merged_into_id);
        $this->assertSame($big2->id, $small2->fresh()->merged_into_id);
        $this->assertSame(MatchStatus::Merged, $candidate->fresh()->status);
        $this->assertSame(MatchStatus::Merged, $candidate2->fresh()->status);
        $this->assertSame($admin->id, $candidate->fresh()->decided_by);

        $this->assertSame(MatchStatus::Pending, $titleRule->fresh()->status, 'another rule is left alone');
        $this->assertNull($otherB->fresh()->merged_into_id);
    }

    #[Test]
    public function the_same_for_a_rule_respects_the_market_filter(): void
    {
        [, $small, $candidate] = $this->pending();
        $candidate->update(['market' => 'nl-nl']);

        Livewire::actingAs($this->admin())->test(MatchReview::class)
            ->set('market', 'be-nl')
            ->call('mergeRule', MatchRule::Model->value);

        $this->assertSame(MatchStatus::Pending, $candidate->fresh()->status);
        $this->assertNull($small->fresh()->merged_into_id);
    }
}
