<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\MatchRule;
use App\Enums\MatchStatus;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\CommunityAnswer;
use App\Models\IdentityAlias;
use App\Models\MatchCandidate;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Identity\GroupMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Merging two products moves everything that points at the loser, and loses
 * nothing a person wrote.
 *
 * One test per table that would collide on a unique constraint, because each
 * one fails differently: a list with both products, a Cove plan with both, an
 * alert on both, a community answer naming both.
 */
class GroupMergerTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create(['source' => Source::Awin->value, 'external_id' => 'shop', 'name' => 'Shop']);
    }

    private function group(string $key, string $kind = 'title', Market $market = Market::BeNl): ProductGroup
    {
        return ProductGroup::factory()->forMarket($market)->create([
            'identity_key' => $key,
            'identity_kind' => $kind,
        ]);
    }

    private function offer(ProductGroup $group, string $externalId, int $price): Product
    {
        return Product::create([
            'source' => Source::Awin,
            'market' => $group->market,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => $externalId,
            'identity_kind' => $group->identity_kind->value,
            'identity_key' => $group->identity_key,
            'title' => $group->title,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
        ]);
    }

    private function merger(): GroupMerger
    {
        return app(GroupMerger::class);
    }

    #[Test]
    public function offers_move_and_the_loser_points_at_the_winner(): void
    {
        $winner = $this->group('lego|technic ferrari 488');
        $loser = $this->group('lego|ferrari 488 42125');
        $this->offer($winner, 'w1', 5000);
        $cheap = $this->offer($loser, 'l1', 4500);

        $this->merger()->merge($loser, $winner);

        $this->assertSame($winner->id, $cheap->fresh()->group_id);
        $this->assertSame($winner->id, $loser->fresh()->merged_into_id);

        $winner->refresh();
        $this->assertSame(2, $winner->offer_count);
        // The loser's cheaper offer is now the winner's best one.
        $this->assertSame(4500, $winner->min_price);
        $this->assertSame($cheap->id, $winner->best_offer_id);

        // The loser drops out of every listing that wants stock and a price.
        $this->assertSame(0, $loser->fresh()->offer_count);
        $this->assertNull($loser->fresh()->min_price);

        $this->assertDatabaseHas('identity_aliases', [
            'market' => 'be-nl',
            'from_key' => 'lego|ferrari 488 42125',
            'to_key' => 'lego|technic ferrari 488',
        ]);
    }

    #[Test]
    public function aliases_and_merges_into_the_loser_follow_it_so_no_chain_forms(): void
    {
        $a = $this->group('brand|a product');
        $b = $this->group('brand|b product');
        $c = $this->group('brand|c product');

        $this->merger()->merge($a, $b);
        $this->merger()->merge($b, $c);

        // a → c directly, not a → b → c.
        $this->assertSame('brand|c product', IdentityAlias::query()->where('from_key', 'brand|a product')->value('to_key'));
        $this->assertSame($c->id, $a->fresh()->merged_into_id);
        $this->assertSame($c->id, $b->fresh()->merged_into_id);
    }

    #[Test]
    public function a_list_holding_both_keeps_one_item_with_both_notes_and_the_claim(): void
    {
        $winner = $this->group('brand|winner');
        $loser = $this->group('brand|loser');
        $list = Wishlist::factory()->create();

        $kept = WishlistItem::factory()->for($list)->of($winner)->create(['note' => 'Maat M']);
        $gone = WishlistItem::factory()->for($list)->of($loser)->create(['note' => 'In het blauw', 'priority' => 2]);
        DB::table('wishlist_items')->where('id', $gone->id)->update(['claimed_by_hash' => 'abc', 'claimed_by_name' => 'Tante', 'claimed_at' => now()]);

        // Another list with only the loser just moves.
        $other = WishlistItem::factory()->of($loser)->create();

        $this->merger()->merge($loser, $winner);

        $this->assertDatabaseMissing('wishlist_items', ['id' => $gone->id]);
        $row = DB::table('wishlist_items')->where('id', $kept->id)->first();
        $this->assertSame("Maat M\n\nIn het blauw", $row->note);
        $this->assertSame('abc', $row->claimed_by_hash);
        $this->assertSame(2, (int) $row->priority);
        $this->assertSame($winner->id, $other->fresh()->group_id);
    }

    #[Test]
    public function a_cove_plan_holding_both_keeps_the_winner_with_both_notes(): void
    {
        $winner = $this->group('brand|winner');
        $loser = $this->group('brand|loser');
        $planId = DB::table('cove_plans')->insertGetId(['title' => 'Plan', 'market' => 'be-nl', 'created_at' => now(), 'updated_at' => now()]);

        $kept = DB::table('cove_plan_items')->insertGetId(['plan_id' => $planId, 'group_id' => $winner->id, 'rank' => 1, 'note' => 'Stil']);
        $gone = DB::table('cove_plan_items')->insertGetId(['plan_id' => $planId, 'group_id' => $loser->id, 'rank' => 2, 'note' => 'Goedkoop']);

        $this->merger()->merge($loser, $winner);

        $this->assertDatabaseMissing('cove_plan_items', ['id' => $gone]);
        $this->assertSame("Stil\n\nGoedkoop", DB::table('cove_plan_items')->where('id', $kept)->value('note'));
    }

    #[Test]
    public function alerts_and_answer_picks_on_both_keep_one(): void
    {
        $winner = $this->group('brand|winner');
        $loser = $this->group('brand|loser');
        $user = User::factory()->create();
        $other = User::factory()->create();

        foreach (['price_alerts', 'restock_alerts'] as $table) {
            $extra = $table === 'price_alerts' ? ['baseline_price' => 1000] : [];
            DB::table($table)->insert([
                ['group_id' => $winner->id, 'user_id' => $user->id, ...$extra],
                ['group_id' => $loser->id, 'user_id' => $user->id, ...$extra],
                ['group_id' => $loser->id, 'user_id' => $other->id, ...$extra],
            ]);
        }

        $answer = CommunityAnswer::factory()->create();
        DB::table('community_answer_picks')->insert([
            ['answer_id' => $answer->id, 'group_id' => $winner->id, 'position' => 1],
            ['answer_id' => $answer->id, 'group_id' => $loser->id, 'position' => 2],
        ]);

        $this->merger()->merge($loser, $winner);

        foreach (['price_alerts', 'restock_alerts'] as $table) {
            $this->assertSame(0, DB::table($table)->where('group_id', $loser->id)->count(), $table);
            $this->assertSame(1, DB::table($table)->where('group_id', $winner->id)->where('user_id', $user->id)->count(), $table);
            $this->assertSame(1, DB::table($table)->where('group_id', $winner->id)->where('user_id', $other->id)->count(), $table);
        }

        $this->assertSame([$winner->id], DB::table('community_answer_picks')->pluck('group_id')->map(fn ($id) => (int) $id)->all());
    }

    #[Test]
    public function ids_inside_json_are_rewritten_in_order_without_duplicates(): void
    {
        $winner = $this->group('brand|winner');
        $loser = $this->group('brand|loser');
        $third = $this->group('brand|third');
        $user = User::factory()->create();

        $plan = DB::table('cove_plans')->insertGetId([
            'title' => 'Plan', 'market' => 'be-nl',
            'pinned_group_ids' => json_encode([$third->id, $loser->id, $winner->id]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $alert = DB::table('search_alerts')->insertGetId([
            'term' => 'lego', 'user_id' => $user->id, 'market' => 'be-nl',
            'seen_group_ids' => json_encode([(string) $loser->id]),
        ]);

        $this->merger()->merge($loser, $winner);

        $this->assertSame([$third->id, $winner->id], json_decode(DB::table('cove_plans')->where('id', $plan)->value('pinned_group_ids'), true));
        $this->assertSame([$winner->id], json_decode(DB::table('search_alerts')->where('id', $alert)->value('seen_group_ids'), true));
    }

    #[Test]
    public function the_editorial_on_the_loser_is_kept_when_the_winner_has_none(): void
    {
        $winner = $this->group('brand|winner');
        $loser = $this->group('brand|loser');
        $loser->update(['display_title' => 'De Ferrari van LEGO', 'gift_tags' => ['interest:cars']]);

        $this->merger()->merge($loser->fresh(), $winner);

        $winner->refresh();
        $this->assertSame('De Ferrari van LEGO', $winner->display_title);
        $this->assertSame(['interest:cars'], $winner->giftTags());
    }

    #[Test]
    public function the_queue_is_settled(): void
    {
        $winner = $this->group('brand|winner');
        $loser = $this->group('brand|loser');
        $pending = $this->group('brand|pending');
        $refused = $this->group('brand|refused');
        $admin = User::factory()->create();

        $pair = fn (ProductGroup $x, ProductGroup $y, MatchStatus $status, MatchRule $rule = MatchRule::Title) => MatchCandidate::create([
            'market' => 'be-nl', 'group_a' => min($x->id, $y->id), 'group_b' => max($x->id, $y->id),
            'rule' => $rule, 'score' => 0.7, 'status' => $status,
        ]);

        $merged = $pair($winner, $loser, MatchStatus::Pending, MatchRule::Model);
        $pair($loser, $pending, MatchStatus::Pending);
        $pair($loser, $refused, MatchStatus::Rejected);

        $this->merger()->merge($loser, $winner, $admin);

        $this->assertSame(MatchStatus::Merged, $merged->fresh()->status);
        $this->assertSame($admin->id, $merged->fresh()->decided_by);
        // The pending pair with the loser is gone; the next run proposes it
        // against the winner if it still holds.
        $this->assertFalse(MatchCandidate::query()->where('group_a', min($loser->id, $pending->id))->where('group_b', max($loser->id, $pending->id))->exists());
        // "Not the same" carries over to the winner, as a manual decision.
        $carried = MatchCandidate::query()->where('group_a', min($winner->id, $refused->id))->where('group_b', max($winner->id, $refused->id))->first();
        $this->assertSame(MatchStatus::Rejected, $carried->status);
        $this->assertSame(MatchRule::Manual, $carried->rule);
    }

    #[Test]
    public function products_in_two_markets_are_never_merged(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->merger()->merge($this->group('brand|x', market: Market::BeFr), $this->group('brand|y'));
    }

    #[Test]
    public function a_product_already_merged_cannot_be_merged_or_merged_into(): void
    {
        $a = $this->group('brand|a');
        $b = $this->group('brand|b');
        $this->merger()->merge($a, $b);

        $this->expectException(InvalidArgumentException::class);
        $this->merger()->merge($this->group('brand|c'), $a->fresh());
    }
}
