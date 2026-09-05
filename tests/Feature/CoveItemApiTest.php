<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PickMode;
use App\Enums\PlanWriter;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\ApiToken;
use App\Models\CovePlan;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Curating a Cove over HTTP.
 *
 * Nine of the curation screen's eleven actions had no HTTP twin, so an outside
 * author could write *about* a shortlist and never change one. These pin the
 * three properties that make the new half safe rather than merely present:
 *
 * 1. it delegates to the same services the panel calls, so market scoping and
 *    the mirroring rules hold identically;
 * 2. it stops at the same line the prose endpoints stop at — an approved plan is
 *    a reviewed plan, and changing it is a publishing act;
 * 3. it only ever adds, never silently replaces, so a client that gets its own
 *    bookkeeping wrong cannot discard somebody's afternoon.
 */
class CoveItemApiTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::today()->setTime(12, 0));

        $this->merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
        ]);
    }

    #[Test]
    public function a_product_can_be_added_by_barcode(): void
    {
        /*
         * The portable handle. An id is per market *and* per environment — the
         * same barcode is group 3210 on production and 3921 on staging — so a
         * brief that carries EANs survives being run against the wrong host and
         * one that carries ids does not.
         */
        $plan = $this->plan();
        $group = $this->product('Sony koptelefoon', 24900, 'Sony');
        $group->update(['identity_key' => '4548736132580']);

        $this->withToken($this->key())
            ->postJson("/api/editorial/coves/{$plan->id}/items", [
                'ean' => '4548736132580',
                'note' => 'De enige met echte ruisonderdrukking.',
                'verdict' => 'Beste overall',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.items.0.product.id', $group->id)
            ->assertJsonPath('data.items.0.note', 'De enige met echte ruisonderdrukking.')
            ->assertJsonPath('data.items.0.verdict', 'Beste overall');
    }

    #[Test]
    public function a_product_from_another_market_is_refused(): void
    {
        // Invariant 2, enforced by `PlanCurator` and surfaced as a 422 rather
        // than a 500: the same product in two markets has different tax,
        // shipping and availability, so a Dutch group on a Belgian Cove would
        // show a price the reader cannot pay.
        $plan = $this->plan();
        $foreign = $this->product('Wireless headphones', 24900, 'Sony', Market::En);

        $this->withToken($this->key())
            ->postJson("/api/editorial/coves/{$plan->id}/items", ['groupId' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product');

        $this->assertSame(0, $plan->items()->count());
    }

    #[Test]
    public function a_misread_barcode_says_so_rather_than_reporting_a_miss(): void
    {
        $plan = $this->plan();

        $this->withToken($this->key())
            ->postJson("/api/editorial/coves/{$plan->id}/items", ['ean' => '4548736132581'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ean');
    }

    #[Test]
    public function the_order_and_the_words_are_written_in_one_call(): void
    {
        /*
         * One call rather than three, because they are one editorial act: a
         * curator decides the running order and the reasons together, and a
         * client making a request per item would spend a 20/min write budget on
         * a single page.
         */
        $plan = $this->plan();
        $items = $this->fill($plan, 3);

        $reversed = array_reverse(array_column($items, 'id'));

        $this->withToken($this->key())
            ->patchJson("/api/editorial/coves/{$plan->id}/items", [
                'order' => $reversed,
                'items' => [
                    ['id' => $items[0]['id'], 'copy' => 'Zit stevig, ook na een uur.'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $reversed[0]);

        // Ranks are renumbered from 1 rather than patched, so "move this up"
        // keeps meaning something after a dozen edits.
        $this->assertSame([1, 2, 3], $plan->items()->orderBy('rank')->pluck('rank')->all());

        $first = $plan->items()->whereKey($items[0]['id'])->first();
        $this->assertSame('Zit stevig, ook na een uur.', $first->copy);
        // And the brief that asked for it is untouched.
        $this->assertSame('Waarom deze erbij hoort.', $first->note);
    }

    #[Test]
    public function an_item_from_another_plan_is_refused_rather_than_skipped(): void
    {
        // A stale brief, not a typo: if one id is wrong the rest of what the
        // client believes about this plan is suspect too.
        $plan = $this->plan();
        $other = $this->plan('andere-gids');
        $this->fill($plan, 2);
        $strays = $this->fill($other, 1);

        $this->withToken($this->key())
            ->patchJson("/api/editorial/coves/{$plan->id}/items", [
                'order' => [$strays[0]['id']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    #[Test]
    public function suggest_tops_the_list_up_and_never_replaces_it(): void
    {
        $plan = $this->plan();
        $chosen = $this->product('Nichekoptelefoon', 51900, 'Grado');

        $this->withToken($this->key())
            ->postJson("/api/editorial/coves/{$plan->id}/items", [
                'groupId' => $chosen->id,
                'note' => 'Met opzet gekozen.',
            ])
            ->assertStatus(201);

        foreach (['Sony', 'Sennheiser', 'JBL', 'Philips', 'Marshall', 'AKG'] as $i => $brand) {
            $this->product("{$brand} koptelefoon", 5000 + $i * 4000, $brand);
        }

        $response = $this->withToken($this->key())
            ->postJson("/api/editorial/coves/{$plan->id}/suggest")
            ->assertOk();

        $this->assertGreaterThan(0, $response->json('added'));

        // The curated product keeps its place at the head and its note. A
        // top-up that reshuffled or re-noted would be undoing the curation it
        // was called to assist.
        $first = $plan->items()->orderBy('rank')->first();
        $this->assertSame($chosen->id, $first->group_id);
        $this->assertSame('Met opzet gekozen.', $first->note);
    }

    #[Test]
    public function suggest_says_when_the_catalogue_could_not_fill_the_page(): void
    {
        /*
         * A bare count cannot tell "the catalogue is thin here" from "the
         * request failed", and one of those is worth retrying. Same reasoning
         * as `DraftedPlans::shortfall`.
         */
        $plan = $this->plan();
        $this->product('Enige koptelefoon', 9900, 'Sony');

        $response = $this->withToken($this->key())
            ->postJson("/api/editorial/coves/{$plan->id}/suggest")
            ->assertOk();

        $this->assertNotNull($response->json('shortfall'));
    }

    #[Test]
    public function a_plan_with_nothing_to_search_on_is_told_that_rather_than_blamed_on_the_catalogue(): void
    {
        /*
         * Two causes look identical from a count of zero, and only one is about
         * the catalogue. A plan whose only search term is its title finds
         * nothing however full the shelf is, because a title is a headline.
         */
        $plan = $this->plan();
        $plan->update(['focus_keyphrase' => null, 'queries' => []]);

        foreach (['Sony', 'Sennheiser', 'JBL', 'Philips', 'Marshall', 'AKG'] as $i => $brand) {
            $this->product("{$brand} koptelefoon", 5000 + $i * 4000, $brand);
        }

        $response = $this->withToken($this->key())
            ->postJson("/api/editorial/coves/{$plan->id}/suggest")
            ->assertOk();

        $this->assertStringContainsString('nothing specific to search on', (string) $response->json('shortfall'));
    }

    #[Test]
    public function an_approved_plan_cannot_be_recurated_by_a_write_key(): void
    {
        /*
         * The same line the prose endpoints hold. Without it the draft/approve
         * split is decoration: draft a plan, wait for a person to approve it,
         * then change what is on it.
         */
        $plan = $this->plan();
        $items = $this->fill($plan, 2);
        $plan->update(['status' => 'approved']);

        $this->withToken($this->key())
            ->postJson("/api/editorial/coves/{$plan->id}/items", ['groupId' => $this->product('Extra', 9900, 'X')->id])
            ->assertStatus(403);

        $this->withToken($this->key())
            ->deleteJson("/api/editorial/coves/{$plan->id}/items/{$items[0]['id']}")
            ->assertStatus(403);

        $this->assertSame(2, $plan->items()->count());
    }

    #[Test]
    public function pick_mode_and_writer_change_without_touching_the_shortlist(): void
    {
        /*
         * The reason PATCH exists. `POST /coves` is an upsert of the whole plan
         * and replaces the item list wholesale, so flipping one switch through
         * it meant re-sending every product — and a client that got that
         * slightly wrong discarded a curator's work with a 200.
         */
        $plan = $this->plan();
        $this->fill($plan, 3);

        $this->withToken($this->key())
            ->patchJson("/api/editorial/coves/{$plan->id}", [
                'pickMode' => PickMode::Locked->value,
                'writer' => PlanWriter::Authored->value,
                'buildInstructions' => 'Kort houden.',
            ])
            ->assertOk()
            ->assertJsonPath('data.pickMode', 'locked')
            ->assertJsonPath('data.writer', 'authored');

        $this->assertSame(3, $plan->fresh()->items()->count());
        $this->assertSame('Kort houden.', $plan->fresh()->build_instructions);
    }

    #[Test]
    public function conflicts_are_reported_and_never_enforced(): void
    {
        $plan = $this->plan();
        $group = $this->product('Gedeelde koptelefoon', 9900, 'Sony');

        // Already spoken for on another plan in this market.
        $other = $this->plan('andere-gids');
        $other->items()->create(['group_id' => $group->id, 'rank' => 1]);

        // Adding it anyway is allowed: two Coves a month apart may both want
        // the same kettle, and a rule that refused would be wrong more often
        // than it was right.
        $this->withToken($this->key())
            ->postJson("/api/editorial/coves/{$plan->id}/items", ['groupId' => $group->id])
            ->assertStatus(201);

        $this->withToken($this->key())
            ->getJson("/api/editorial/coves/{$plan->id}/conflicts")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function key(): string
    {
        return ApiToken::issue('curator', [ApiToken::READ, ApiToken::WRITE])['token'];
    }

    private function plan(string $slug = 'beste-koptelefoons'): CovePlan
    {
        return CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Guide->value,
            'slug' => $slug,
            'title' => 'Beste koptelefoons',
            // A guide drafted from the topic queue always carries one, and the
            // ladder selector searches on it: a title is a headline, and no
            // product is called "Beste koptelefoons".
            'focus_keyphrase' => 'koptelefoon',
            'status' => 'draft',
        ]);
    }

    /** @return list<array{id: int}> */
    private function fill(CovePlan $plan, int $count): array
    {
        $items = [];

        for ($i = 0; $i < $count; $i++) {
            $group = $this->product("Koptelefoon {$plan->id}-{$i}", 9900 + $i * 1000, "Merk{$i}");

            $items[] = ['id' => $plan->items()->create([
                'group_id' => $group->id,
                'rank' => $i + 1,
                'note' => 'Waarom deze erbij hoort.',
            ])->id];
        }

        return $items;
    }

    private function product(string $title, int $price, string $brand, ?Market $market = null): ProductGroup
    {
        $market ??= Market::BeNl;

        $group = ProductGroup::create([
            'market' => $market,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'brand' => $brand,
            'category' => 'audio',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'surprise_score' => 60,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => $market,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'brand' => $brand,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }
}
