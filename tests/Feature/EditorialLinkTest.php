<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\DailyPickSet;
use App\Services\Editorial\Allowlist;
use App\Services\Guides\CoveMarkup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The link tokens that point at our own pages.
 *
 * `[[page:...]]` is the one token kind with no per-article allowlist — the
 * config is the allowlist. That makes `config('giftcoves.linkable_pages')` a
 * promise about the router, and a promise nothing else checks: renaming a route
 * would leave every article that linked to it pointing at a 404, silently,
 * forever, because the token still resolves and the URL it produces is only
 * wrong at the far end.
 */
class EditorialLinkTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_linkable_page_is_a_real_route(): void
    {
        $markup = app(CoveMarkup::class);

        foreach (array_keys((array) config('giftcoves.linkable_pages')) as $key) {
            $html = $markup->render("[[page:{$key}]]", Market::BeNl, [])['html'];

            preg_match('/href="([^"]+)"/', $html, $m);

            $this->assertNotEmpty($m, "[[page:{$key}]] did not resolve to a link at all");

            /*
             * Route resolution, not a 2xx.
             *
             * `/daily` and `/surprise` legitimately 404 on an empty database —
             * they need an edition and a catalogue, and neither exists in a
             * fresh test schema. That is a data condition, not a broken link.
             *
             * What this has to catch is the failure that is otherwise silent:
             * a route renamed out from under the config, leaving every article
             * that used the token pointing at nothing, forever, with the token
             * still resolving happily because it only checks the config.
             */
            $matched = collect(Route::getRoutes()->getRoutesByMethod()['GET'] ?? [])
                ->contains(fn (RoutingRoute $route) => $route->matches(
                    Request::create($m[1], 'GET'),
                    includingMethod: false,
                ));

            $this->assertTrue($matched, "[[page:{$key}]] resolves to {$m[1]}, which no route matches");
        }
    }

    #[Test]
    public function an_unknown_page_key_degrades_to_plain_text(): void
    {
        $result = app(CoveMarkup::class)->render(
            'Zie [[page:verzonnen|onze dealpagina]].',
            Market::BeNl,
            [],
        );

        // Not a broken link and not a visible token. The sentence still reads.
        $this->assertSame('Zie onze dealpagina.', $result['html']);
        $this->assertSame(['page:verzonnen'], $result['rejected']);
    }

    #[Test]
    public function a_guide_link_resolves_only_for_a_published_guide_in_this_market(): void
    {
        $live = $this->guide('gepubliceerd', 'Gepubliceerd');
        $this->guide('concept', 'Concept', PublishStatus::Draft);
        $this->guide('other-market', 'Other market', market: Market::En);

        $allowed = app(Allowlist::class)->guideSlugs(Market::BeNl);

        $this->assertSame([$live->slug], $allowed);

        $result = app(CoveMarkup::class)->render(
            '[[guide:gepubliceerd]] [[guide:concept]] [[guide:other-market]]',
            Market::BeNl,
            ['guides' => $allowed],
        );

        // A draft is a 404 for a reader and an indexed dead end for a crawler;
        // a slug in another market is a different site as far as a link goes.
        $this->assertSame(1, $result['links']);
        $this->assertSame(['guide:concept', 'guide:other-market'], $result['rejected']);
    }

    #[Test]
    public function an_article_may_not_link_to_itself(): void
    {
        $guide = $this->guide('zichzelf', 'Zichzelf');

        // A loop the reader has to notice to escape, and a self-referential
        // internal link a crawler learns nothing from.
        $this->assertNotContains(
            $guide->slug,
            app(Allowlist::class)->guideSlugs(Market::BeNl, $guide->id),
        );
    }

    #[Test]
    public function plain_strips_tokens_for_the_places_that_are_not_html(): void
    {
        /*
         * A meta description, a FAQPage answer, a card blurb. Every one of them
         * would otherwise print `[[page:search]]` — at a reader, or worse at a
         * crawler reading the structured data literally.
         */
        $this->assertSame(
            'Zie zoeken en de gids.',
            app(CoveMarkup::class)->plain('Zie [[page:search|zoeken]] en [[guide:x|de gids]].'),
        );

        // The value when no label was given — the same fallback render() uses.
        $this->assertSame('Sony maakt ze.', app(CoveMarkup::class)->plain('[[brand:Sony]] maakt ze.'));
    }

    /**
     * A guide at a known slug.
     *
     * An edition since the fold: the `/guides` space is `daily_pick_sets` rows,
     * and the link allowlist reads them there.
     */
    private function guide(
        string $slug,
        string $title,
        PublishStatus $status = PublishStatus::Published,
        Market $market = Market::BeNl,
    ): DailyPickSet {
        return DailyPickSet::create([
            'market' => $market->value,
            'kind' => CoveKind::Guide->value,
            'slug' => $slug,
            'theme_title' => $title,
            'theme_slug' => $slug,
            'status' => $status->value,
            'published_at' => $status === PublishStatus::Published ? now() : null,
        ]);
    }
}
