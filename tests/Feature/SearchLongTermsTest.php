<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Http\Controllers\SearchController;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Support\SearchUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The pills add a word to the term, and the next page offers pills again:
 * crawlers followed them to terms of twenty words (2026-11-13). The chain
 * ends at four words, and what was already collected is answered cheaply.
 */
class SearchLongTermsTest extends TestCase
{
    use RefreshDatabase;

    private const LONG = 'draadloze ruisonderdrukkende bluetooth sport oordopjes zwart waterdicht lichtgewicht oplaadcase';

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = Merchant::firstOrCreate(
            ['source' => Source::Awin->value, 'external_id' => 'longshop'],
            ['name' => 'Longshop BE'],
        );

        foreach ([
            'Draadloze ruisonderdrukkende bluetooth sport oordopjes zwart waterdicht lichtgewicht oplaadcase',
            'Draadloze ruisonderdrukkende bluetooth sport koptelefoon zwart waterdicht lichtgewicht oplaadcase',
        ] as $i => $title) {
            $group = ProductGroup::create([
                'market' => Market::BeNl->value,
                'identity_key' => "long-{$i}",
                'identity_kind' => 'title',
                'title' => $title,
                'slug' => "long-{$i}",
                'brand' => 'Testmerk',
                'category' => 'Audio',
                'image_url' => "https://example.test/long-{$i}.jpg",
                'min_price' => 4900,
                'max_price' => 4900,
                'offer_count' => 1,
                'merchant_count' => 1,
                'in_stock' => true,
            ]);

            Product::create([
                'source' => Source::Awin->value,
                'external_id' => "long-{$i}",
                'market' => Market::BeNl->value,
                'merchant_id' => $merchant->id,
                'group_id' => $group->id,
                'title' => $title,
                'brand' => 'Testmerk',
                'merchant_category' => 'Audio',
                'price' => 4900,
                'affiliate_url' => "https://example.test/long-{$i}",
                'availability' => Availability::InStock->value,
                'status' => ProductStatus::Active->value,
            ]);
        }
    }

    private function asAVisitor(string $term): TestResponse
    {
        return $this->withCookie((string) config('session.cookie'), 'a-visitor')
            ->get('/be-nl/search?'.http_build_query(['q' => $term]));
    }

    #[Test]
    public function the_pills_stop_at_four_words(): void
    {
        $this->assertNotSame([], $this->asAVisitor('draadloze ruisonderdrukkende bluetooth')
            ->assertOk()->viewData('page')['props']['terms']);

        $four = $this->asAVisitor('draadloze ruisonderdrukkende bluetooth sport')->assertOk()->viewData('page')['props'];

        $this->assertSame(SearchController::PILLS_UNTIL, 4);
        $this->assertSame([], $four['terms']);
        // The page itself still answers
        $this->assertNotSame([], $four['results']['items']);
    }

    #[Test]
    public function a_term_longer_than_a_question_is_not_indexed(): void
    {
        config(['giftcoves.robots_allow' => true]);

        $this->asAVisitor('draadloze ruisonderdrukkende bluetooth sport oordopjes zwart')->assertOk()->assertDontSee('noindex', false);
        $this->asAVisitor('draadloze ruisonderdrukkende bluetooth sport oordopjes zwart waterdicht')->assertOk()->assertSee('noindex, follow', false);
    }

    #[Test]
    public function a_very_long_term_is_not_searched_for_a_crawler(): void
    {
        $short = SearchUrl::for(Market::BeNl, 'draadloze ruisonderdrukkende bluetooth sport');

        // A browser name without the site's cookie: the crawler that hides its name
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/145.0.0.0 Safari/537.36')
            ->get(SearchUrl::for(Market::BeNl, self::LONG))->assertRedirect($short);

        // One that says what it is
        $this->withHeader('User-Agent', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.4; +https://openai.com/gptbot)')
            ->get('/be-nl/search?'.http_build_query(['q' => self::LONG]))->assertRedirect($short);

        // Eight words is still a search
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/145.0.0.0 Safari/537.36')
            ->get('/be-nl/search?'.http_build_query(['q' => 'draadloze ruisonderdrukkende bluetooth sport oordopjes zwart waterdicht lichtgewicht']))
            ->assertOk();
    }

    #[Test]
    public function a_person_on_the_site_and_a_search_engine_are_searched_as_asked(): void
    {
        $this->asAVisitor(self::LONG)->assertOk()->assertInertia(fn ($page) => $page->where('q', self::LONG));

        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
            ->get('/be-nl/search?'.http_build_query(['q' => self::LONG]))->assertOk();
    }
}
