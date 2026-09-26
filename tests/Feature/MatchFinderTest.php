<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\MatchRule;
use App\Enums\MatchStatus;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\MatchCandidate;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Identity\MatchFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The rules that fill the review queue, and the guards around them.
 *
 * Nothing here merges; these tests pin which pairs a person is asked about,
 * and above all which pairs they are never asked about.
 */
class MatchFinderTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create(['source' => Source::Awin->value, 'external_id' => 'shop', 'name' => 'Shop']);
    }

    private function group(string $title, string $kind = 'title', string $brand = 'LEGO', Market $market = Market::BeNl): ProductGroup
    {
        return ProductGroup::factory()->forMarket($market)->create([
            'identity_key' => $kind === 'ean' ? fake()->unique()->numerify('871#########') : 'k|'.fake()->unique()->uuid(),
            'identity_kind' => $kind,
            'title' => $title,
            'brand' => $brand,
        ]);
    }

    private function offer(ProductGroup $group, array $extra = []): Product
    {
        return Product::create([
            'source' => Source::Awin,
            'market' => $group->market,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => fake()->unique()->uuid(),
            'identity_kind' => $group->identity_kind->value,
            'identity_key' => $group->identity_key,
            'title' => $group->title,
            'price' => 1000,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            ...$extra,
        ]);
    }

    private function run_(bool $full = true): array
    {
        return app(MatchFinder::class)->run(Market::BeNl, $full);
    }

    private function pair(ProductGroup $x, ProductGroup $y): ?MatchCandidate
    {
        return MatchCandidate::query()
            ->where('group_a', min($x->id, $y->id))
            ->where('group_b', max($x->id, $y->id))
            ->first();
    }

    #[Test]
    public function offers_sharing_a_barcode_in_two_products_are_proposed(): void
    {
        $a = $this->group('Kinderstoel hout naturel');
        $b = $this->group('Houten kinderstoel');
        $this->offer($a, ['ean' => '0012345678905']);
        $this->offer($b, ['ean' => '12345678905']);

        $this->run_();

        $found = $this->pair($a, $b);
        $this->assertSame(MatchRule::Barcode, $found->rule);
        $this->assertSame('12345678905', $found->evidence);
        $this->assertSame(MatchStatus::Pending, $found->status);
    }

    #[Test]
    public function the_same_model_number_within_one_brand_is_proposed(): void
    {
        $a = $this->group('LEGO Ferrari 488 #42125');
        $b = $this->group('LEGO Technic Ferrari 488 GTE (42125)', 'ean');
        $otherBrand = $this->group('Cobi Ferrari 42125 bouwset', brand: 'Cobi');

        $this->run_();

        $this->assertSame(MatchRule::Model, $this->pair($a, $b)->rule);
        $this->assertSame('42125', $this->pair($a, $b)->evidence);
        $this->assertNull($this->pair($a, $otherBrand));
    }

    #[Test]
    public function a_feed_part_number_counts_as_a_model_number(): void
    {
        $a = $this->group('Sony draadloze koptelefoon zwart', brand: 'Sony');
        $b = $this->group('Sony noise cancelling hoofdtelefoon', brand: 'SONY');
        $this->offer($a, ['mpn' => 'WH-1000XM5']);
        $this->offer($b, ['mpn' => 'wh1000xm5']);

        $this->run_();

        $this->assertSame(MatchRule::Model, $this->pair($a, $b)?->rule);
    }

    #[Test]
    public function two_barcode_products_are_never_proposed(): void
    {
        // Two colours of one model: two barcodes, two products.
        $black = $this->group('Sony WH-1000XM5 zwart', 'ean', 'Sony');
        $silver = $this->group('Sony WH-1000XM5 zilver', 'ean', 'Sony');

        $this->run_();

        $this->assertNull($this->pair($black, $silver));
    }

    #[Test]
    public function similar_titles_within_one_brand_are_proposed(): void
    {
        $a = $this->group('LEGO Technic Ferrari 488 GTE');
        $b = $this->group('LEGO Ferrari 488 GTE Technic', 'ean');
        $unrelated = $this->group('LEGO Duplo boerderij met dieren');

        $this->run_();

        $this->assertSame(MatchRule::Title, $this->pair($a, $b)?->rule);
        $this->assertNull($this->pair($a, $unrelated));
    }

    #[Test]
    public function the_nightly_pass_starts_only_from_recent_products(): void
    {
        $old = $this->group('LEGO Technic Ferrari 488 GTE');
        $old->update(['first_seen_at' => now()->subMonth()]);
        $older = $this->group('LEGO Ferrari 488 GTE Technic');
        $older->update(['first_seen_at' => now()->subMonth()]);

        $this->run_(full: false);
        $this->assertNull($this->pair($old, $older));

        $this->run_(full: true);
        $this->assertNotNull($this->pair($old, $older));
    }

    #[Test]
    public function a_rejected_pair_is_never_proposed_again_and_merged_products_are_left_out(): void
    {
        $a = $this->group('LEGO Ferrari 488 #42125');
        $b = $this->group('LEGO Technic Ferrari 488 GTE (42125)');
        $gone = $this->group('LEGO Ferrari 488 GTE 42125 set');
        $gone->update(['merged_into_id' => $a->id]);

        $this->run_();
        $this->pair($a, $b)->update(['status' => MatchStatus::Rejected]);
        $this->run_();

        $this->assertSame(MatchStatus::Rejected, $this->pair($a, $b)->status);
        $this->assertNull($this->pair($a, $gone));
        $this->assertNull($this->pair($b, $gone));
    }

    #[Test]
    public function precision_counts_only_what_a_rule_proposed_and_a_person_decided(): void
    {
        $groups = collect(range(1, 8))->map(fn (int $i) => $this->group("Product {$i} xx"));
        $row = fn (int $x, int $y, MatchRule $rule, MatchStatus $status) => MatchCandidate::create([
            'market' => 'be-nl', 'group_a' => $groups[$x]->id, 'group_b' => $groups[$y]->id,
            'rule' => $rule, 'score' => 0.8, 'status' => $status,
        ]);

        $row(0, 1, MatchRule::Model, MatchStatus::Merged);
        $row(0, 2, MatchRule::Model, MatchStatus::Merged);
        $row(0, 3, MatchRule::Model, MatchStatus::Merged);
        $row(0, 4, MatchRule::Model, MatchStatus::Rejected);
        $row(0, 5, MatchRule::Model, MatchStatus::Pending);
        $row(0, 6, MatchRule::Manual, MatchStatus::Rejected);

        $precision = app(MatchFinder::class)->precision();

        $this->assertSame(4, $precision['model']['decided']);
        $this->assertSame(0.75, $precision['model']['precision']);
        $this->assertSame(1, $precision['model']['pending']);
        $this->assertNull($precision['title']['precision']);
        $this->assertArrayNotHasKey('manual', $precision);
    }
}
