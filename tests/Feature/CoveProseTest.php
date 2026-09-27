<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\PublishStatus;
use App\Enums\Source;
use App\Jobs\RefreshBrandStats;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Cove\CoveProse;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A Cove's prose is rendered at build and stored, and the page reads it.
 *
 * The promise that makes storing it safe: a page rendered from the stored
 * prose is the same page, byte for byte, as one rendered live. Checked for
 * every kind of Cove page (a Daily, a persona, a guide, a brand page and a
 * shop page), each with prose that uses every token the allowlist governs.
 */
class CoveProseTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    /** @var list<ProductGroup> */
    private array $groups = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'testshop',
            'name' => 'Testshop',
            'domain' => 'testshop.be',
            'enabled' => true,
        ]);

        // A brand with enough products for a page, so `[[brand:Aurex]]`
        // resolves to it and the brand Cove has somewhere to render.
        for ($i = 0; $i < 4; $i++) {
            $this->groups[] = $this->product("Aurex koptelefoon model {$i}", 'Aurex', $i);
        }

        RefreshBrandStats::dispatchSync(Market::BeNl);

        // The article every piece below links to.
        $this->cove(CoveKind::Advice, 'andere-gids', 'Een andere gids', blurb: 'Over iets anders.');
    }

    /** @return array<string, array{0: string}> */
    public static function pages(): array
    {
        return [
            'a daily' => ['daily'],
            'a persona' => ['persona'],
            'a guide' => ['guide'],
            'a brand cove' => ['brand'],
            'a shop cove' => ['shop'],
        ];
    }

    #[Test]
    #[DataProvider('pages')]
    public function the_stored_prose_renders_the_page_exactly_as_a_live_render_does(string $which): void
    {
        [$cove, $url] = $this->make($which);

        app(CoveProse::class)->store($cove);
        $fresh = DailyPickSet::query()->with('picks.group')->find($cove->id);
        $this->assertNotNull($fresh->rendered_prose, 'the prose was stored');
        // And it matches the Cove as a page loads it, so the page uses it.
        $this->assertSame(app(CoveProse::class)->fingerprint($fresh), $fresh->rendered_prose['print']);

        Cache::flush();
        $stored = $this->snapshot($url);

        DailyPickSet::query()->whereKey($cove->id)->update(['rendered_prose' => null]);
        Cache::flush();
        $live = $this->snapshot($url);

        $this->assertSame($live, $stored);
        // And the tokens really were resolved, so the comparison compared links.
        $this->assertStringContainsString('<a href=\\"', $stored['props']);
    }

    #[Test]
    public function the_page_reads_the_stored_prose_rather_than_rendering_it(): void
    {
        [$cove, $url] = $this->make('guide');
        app(CoveProse::class)->store($cove);

        // Tampered with, fingerprint intact: if the page shows this, it read
        // the column instead of rendering.
        $prose = $cove->fresh()->rendered_prose;
        $prose['body'][0]['html'] = 'Uit de kolom.';
        DB::table('daily_pick_sets')->where('id', $cove->id)->update(['rendered_prose' => json_encode($prose)]);

        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('guide.body.0.html', 'Uit de kolom.'));
    }

    #[Test]
    public function an_edit_made_after_the_build_is_shown_at_once(): void
    {
        [$cove, $url] = $this->make('daily');
        app(CoveProse::class)->store($cove);

        // A plain query, as `bc:tidy-prose` writes: no model event fires, and
        // the stored prose no longer matches the text it was rendered from.
        DB::table('daily_pick_sets')->where('id', $cove->id)->update(['editorial' => 'Nieuwe tekst.']);

        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->where('edition.editorial.0.html', 'Nieuwe tekst.'));
    }

    #[Test]
    public function a_link_to_an_article_not_yet_published_is_not_frozen_as_plain_text(): void
    {
        [$cove, $url] = $this->make('daily', editorial: 'Lees straks [[guide:nog-niet|de nieuwe gids]].');
        app(CoveProse::class)->store($cove);

        // Stored, it would read as plain words for good. Not stored, the page
        // renders it itself, and the link appears once the article does.
        $this->assertNull($cove->fresh()->rendered_prose);

        $this->cove(CoveKind::Advice, 'nog-niet', 'De nieuwe gids');
        Cache::flush();

        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page->where(
            'edition.editorial.0.html',
            'Lees straks <a href="/be-nl/guides/nog-niet">de nieuwe gids</a>.',
        ));
    }

    #[Test]
    #[DataProvider('pages')]
    public function a_warm_cove_page_asks_the_database_less_than_a_live_render(string $which): void
    {
        [$cove, $url] = $this->make($which);

        // Live, as every view rendered before: everything else on the page
        // warm, only the prose worked out again.
        $this->get($url)->assertOk();
        $fresh = DailyPickSet::query()->with('picks.group')->find($cove->id);
        Cache::forget('bc:cove-prose:'.$cove->id.':'.$fresh->updated_at->getTimestamp().':'.app(CoveProse::class)->fingerprint($fresh));
        $live = $this->queries($url);

        // Stored, warm.
        app(CoveProse::class)->store($cove);
        $this->get($url)->assertOk();
        $stored = $this->queries($url);

        $this->assertLessThan($live, $stored, "stored {$stored} queries, live {$live}");
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return array{0: DailyPickSet, 1: string} */
    private function make(string $which, ?string $editorial = null): array
    {
        [$a, $b] = [$this->groups[0], $this->groups[1]];

        // Every token kind the allowlist governs, and one it rejects.
        $prose = "Eerst de [[product:{$a->id}|koptelefoon]] van [[brand:Aurex]], **echt**.\n\n"
            ."Dan de [[product:{$b->id}]]. Lees ook [[guide:andere-gids]] en zoek [[search:Audio|meer audio]].\n\n"
            .'Niet [[search:onbekend]], wel [[page:search|zoeken]].';

        $cove = match ($which) {
            'daily' => $this->cove(CoveKind::Daily, 'vandaag', 'Vandaag', editorial: $editorial ?? $prose),
            'persona' => $this->cove(CoveKind::Persona, 'de-luisteraar', 'De luisteraar', editorial: $editorial ?? $prose),
            'guide' => $this->cove(CoveKind::Guide, 'beste-koptelefoons', 'Beste koptelefoons',
                blurb: "Twee die het waard zijn, zoals de [[product:{$a->id}]].",
                body: $prose,
                faq: [['q' => 'Welke eerst?', 'a' => "De [[product:{$b->id}]], lees [[guide:andere-gids]].\n\nOf **niet**."]],
                queries: ['koptelefoon'],
            ),
            'brand' => $this->cove(CoveKind::Brand, 'aurex', 'Wat Aurex maakt',
                blurb: 'Zoek [[search:Audio|audio]].', body: $prose, links: ['Audio']),
            'shop' => $this->cove(CoveKind::Shop, 'testshop-be', 'Kopen bij Testshop',
                blurb: 'Zoek [[search:Audio|audio]].', body: $prose, links: ['Audio']),
        };

        if ($which !== 'brand') {
            foreach ([$a, $b] as $rank => $group) {
                DailyPick::create([
                    'set_id' => $cove->id,
                    'group_id' => $group->id,
                    'rank' => $rank + 1,
                    'slug' => $group->slug,
                    'blurb' => "Waarom de [[product:{$group->id}]]: [[brand:Aurex]] en [[search:koptelefoon]].",
                ]);
            }
        }

        $url = match ($which) {
            'daily' => Market::BeNl->covePath('vandaag'),
            'persona' => '/be-nl/gift-ideas/de-luisteraar',
            'guide' => '/be-nl/guides/beste-koptelefoons',
            'brand' => '/be-nl/brand/aurex',
            'shop' => '/be-nl/shops/testshop-be',
        };

        return [$cove->fresh(), $url];
    }

    /**
     * The page's props and its structured data, which between them carry
     * every piece of rendered prose.
     *
     * Encoded, because the JSON is what the browser receives, and because
     * two identical pages still hold different `errors` objects.
     *
     * @return array{props: string, jsonLd: list<string>}
     */
    private function snapshot(string $url): array
    {
        $response = $this->get($url)->assertOk();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', (string) $response->getContent(), $m);

        return ['props' => (string) json_encode($response->viewData('page')['props']), 'jsonLd' => $m[1]];
    }

    private function queries(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @param  list<array{q: string, a: string}>|null  $faq
     * @param  list<string>  $queries
     * @param  list<string>|null  $links
     */
    private function cove(
        CoveKind $kind,
        string $slug,
        string $title,
        ?string $blurb = 'Waar het over gaat.',
        ?string $editorial = null,
        ?string $body = null,
        ?array $faq = null,
        array $queries = [],
        ?array $links = null,
    ): DailyPickSet {
        return DailyPickSet::create([
            'market' => Market::BeNl->value,
            'kind' => $kind->value,
            'slug' => $slug,
            'theme_title' => $title,
            'theme_slug' => $slug,
            'theme_blurb' => $blurb,
            'editorial' => $editorial,
            'body' => $body,
            'faq' => $faq,
            'source_queries' => $queries,
            'link_categories' => $links,
            'drop_date' => $kind === CoveKind::Daily ? CarbonImmutable::today()->toDateString() : null,
            'status' => PublishStatus::Published->value,
            'published_at' => now()->subHour(),
        ]);
    }

    private function product(string $title, string $brand, int $i): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl->value,
            'identity_key' => "prose-{$i}",
            'identity_kind' => 'title',
            'title' => $title,
            'slug' => "aurex-koptelefoon-{$i}",
            'brand' => $brand,
            'category' => 'Audio',
            'image_url' => "https://example.test/{$i}.jpg",
            'min_price' => 9900 + $i * 1000,
            'max_price' => 12900,
            'offer_count' => 1,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'worth_showing' => true,
        ]);

        Product::create([
            'source' => Source::Awin->value,
            'external_id' => "prose-{$i}",
            'market' => Market::BeNl->value,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'title' => $title,
            'brand' => $brand,
            'merchant_category' => 'Audio',
            'price' => $group->min_price,
            'affiliate_url' => "https://example.test/{$i}",
            'availability' => Availability::InStock->value,
            'status' => ProductStatus::Active->value,
        ]);

        return $group;
    }
}
