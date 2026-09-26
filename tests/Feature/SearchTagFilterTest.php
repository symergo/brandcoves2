<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Search filters for who it is for, what they love and the occasion
 * (roadmap step 4, part 3): `?for=`, `?interest=`, `?occasion=`, matched
 * against the editors' tags and the crowd's.
 */
class SearchTagFilterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_interest_filter_keeps_only_what_is_tagged_for_it(): void
    {
        $knife = $this->group('Mes van staal', ['interest:cooking']);
        $crowd = $this->group('Mes met houten heft', [], ['interest:cooking']);
        $this->group('Mes voor de tuin', ['interest:gardening']);

        $this->get('/be-nl/zoek/mes?interest=cooking')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('results.items', fn ($items) => collect($items)->pluck('id')->sort()->values()->all()
                    === collect([$knife->id, $crowd->id])->sort()->values()->all())
                ->where('filters.interest', 'cooking')
                ->where('tagFilters.0.label', 'Koken'));
    }

    #[Test]
    public function kinds_combine_and_values_of_one_kind_are_either(): void
    {
        $forDad = $this->group('Mes voor papa', ['interest:cooking', 'recipient:father']);
        $this->group('Mes voor mama', ['interest:cooking', 'recipient:mother']);
        $forDadGardening = $this->group('Mes voor de tuin', ['interest:gardening', 'recipient:father']);

        // Father AND (cooking OR gardening).
        $this->get('/be-nl/zoek/mes?for=father&interest[]=cooking&interest[]=gardening')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'results.items',
                fn ($items) => collect($items)->pluck('id')->sort()->values()->all()
                    === collect([$forDad->id, $forDadGardening->id])->sort()->values()->all(),
            ));
    }

    #[Test]
    public function values_outside_the_vocabulary_are_ignored(): void
    {
        $this->group('Mes van staal', ['interest:cooking']);
        $this->group('Mes voor de tuin', ['interest:gardening']);

        $this->get('/be-nl/zoek/mes?interest=underwater-basket-weaving&for=uncle')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('results.total', 2)
                ->where('tagFilters', []));
    }

    #[Test]
    public function a_filtered_page_canonicalises_to_the_bare_term_and_each_chip_drops_itself(): void
    {
        $this->group('Mes van staal', ['interest:cooking', 'occasion:christmas']);

        $response = $this->get('/be-nl/zoek/mes?interest=cooking&occasion=christmas')->assertOk();

        $response->assertSee('<link rel="canonical" href="'.url('/be-nl/zoek/mes').'"', escape: false);

        $props = $response->viewData('page')['props'];
        $this->assertSame('/be-nl/zoek/mes?occasion=christmas', $props['tagFilters'][0]['without']);
        $this->assertSame('/be-nl/zoek/mes?interest=cooking', $props['tagFilters'][1]['without']);
    }

    #[Test]
    public function a_filter_needs_no_term(): void
    {
        $knife = $this->group('Mes van staal', ['interest:cooking', 'recipient:father']);
        $this->group('Tuinhandschoenen', ['interest:gardening']);

        // The gift landing page's "search everything tagged for them" link.
        $this->get('/be-nl/search?for=father&interest=cooking')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('landing', null)
                ->where('results.items', fn ($items) => collect($items)->pluck('id')->all() === [$knife->id])
                ->where('tagFilters.0.label', 'voor papa'));
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $crowd
     */
    private function group(string $title, array $tags, array $crowd = []): ProductGroup
    {
        $merchant = Merchant::query()->firstOrCreate(
            ['source' => Source::Awin->value, 'external_id' => 'shop'],
            ['name' => 'Shop'],
        );

        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(6)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(4)),
            'category' => 'Keuken',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => 2500,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'gift_tags' => $tags,
            'crowd_tags' => $crowd,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(6)),
            'identity_kind' => 'ean',
            'title' => $title,
            'merchant_category' => 'Keuken',
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
