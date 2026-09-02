<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Services\Seo\OgImage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which cards are cached, and what a cached one is allowed to outlive.
 *
 * Two separate questions, and the first one is newer. Product cards are **not**
 * cached; every other card is. These assertions used to run against the product
 * endpoint because it was the convenient one to build a record for, which is
 * exactly why they had to move: the endpoint they were written against is now
 * the one endpoint that never caches.
 *
 * The key semantics are asserted through the Daily Cove card instead. Nothing
 * about them is specific to a Cove — {@see OgImageController::card()} is one
 * method — so this is the same coverage against a card that still has a key.
 *
 * These assert through the *renderer*, by counting how often it runs, rather
 * than by rebuilding the cache key. A test that reconstructs the key passes as
 * long as it agrees with the controller, including when both are wrong
 * together, which is exactly what happened here.
 */
class OgImageCacheTest extends TestCase
{
    use RefreshDatabase;

    /** A published edition in the past, addressable at a stable URL. */
    private const DATE = '2026-08-08';

    private function edition(): DailyPickSet
    {
        return DailyPickSet::create([
            'market' => Market::BeNl->value,
            'drop_date' => self::DATE,
            'theme_title' => 'Alles voor de barbecue',
            'theme_slug' => 'barbecue',
            'theme_source' => 'theme',
            'status' => PublishStatus::Published->value,
            'published_at' => CarbonImmutable::parse(self::DATE)->setTime(6, 0),
        ]);
    }

    private function url(): string
    {
        return '/be-nl/og/daily/'.self::DATE.'.png';
    }

    private function product(): ProductGroup
    {
        return ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'title' => 'Sony WH-1000XM5',
            'merchant_count' => 5,
            'min_price' => 27900,
        ]);
    }

    /** Counts renders so a test can tell "cached" from "rendered again". */
    private function countingRenderer(): OgImage
    {
        $counter = new class extends OgImage
        {
            public int $renders = 0;

            public function render(string $title, ?string $kicker = null, ?string $footnote = null): string
            {
                $this->renders++;

                return parent::render($title, $kicker, $footnote);
            }
        };

        $this->app->instance(OgImage::class, $counter);

        return $counter;
    }

    #[Test]
    public function a_product_card_is_never_cached(): void
    {
        /*
         * The reason this file was rewritten.
         *
         * Measured on production 2026-09-02: 113,626 product cards were holding
         * 6.21GB of Redis on an 11.7GB box, which exhausted memory, drove the
         * machine into swap and took the load average to 360.
         *
         * Volume was only half of it. A product card is fetched by a platform
         * once and then held by that platform for a week, so the entry was
         * written once and read almost never — the worst ratio in the system.
         * There are 293,770 product groups and something walks all of them.
         */
        $group = $this->product();
        $renderer = $this->countingRenderer();

        $this->get("/be-nl/og/p/{$group->id}.png")->assertOk();
        $this->get("/be-nl/og/p/{$group->id}.png")->assertOk();
        $this->get("/be-nl/og/p/{$group->id}.png")->assertOk();

        $this->assertSame(3, $renderer->renders, 'a product card must not be stored');
    }

    #[Test]
    public function an_uncached_product_card_still_tells_the_platforms_to_cache_it(): void
    {
        /*
         * Not caching server-side is a fact about our memory, and nothing the
         * platforms need to know. Their week-long copy is what keeps the render
         * count survivable now that every request draws: weakening these headers
         * would turn one render per platform per week into one render per fetch.
         */
        $group = $this->product();

        $this->get("/be-nl/og/p/{$group->id}.png")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'max-age=604800, public');
    }

    #[Test]
    public function a_product_card_that_is_redrawn_is_still_the_same_bytes(): void
    {
        // Rendering every time must not mean rendering *differently* every time:
        // an ETag that changed per request would defeat every revalidating
        // client, which is the other half of what makes the uncached path cheap.
        $group = $this->product();

        $first = $this->get("/be-nl/og/p/{$group->id}.png");
        $second = $this->get("/be-nl/og/p/{$group->id}.png");

        $this->assertSame(
            md5((string) $first->getContent()),
            md5((string) $second->getContent()),
        );

        $this->assertSame($first->headers->get('ETag'), $second->headers->get('ETag'));
    }

    #[Test]
    public function a_card_is_rendered_once_and_then_served_from_the_cache(): void
    {
        $this->edition();
        $renderer = $this->countingRenderer();

        $this->get($this->url())->assertOk();
        $this->get($this->url())->assertOk();

        // Asserted through the renderer rather than by timing a second request,
        // which is flaky on a loaded machine.
        $this->assertSame(1, $renderer->renders);
    }

    #[Test]
    public function editing_the_record_renders_a_new_card(): void
    {
        $edition = $this->edition();

        $before = (string) $this->get($this->url())->getContent();

        // A retitle has to reach the card, or a shared link keeps announcing the
        // old theme for a month.
        $edition->forceFill(['theme_title' => 'Alles voor de picknick'])->save();

        $after = (string) $this->get($this->url())->getContent();

        $this->assertNotSame(md5($before), md5($after));
    }

    #[Test]
    public function two_edits_inside_one_second_each_get_their_own_card(): void
    {
        /*
         * The first bug in the key.
         *
         * `updated_at` is `timestamp(0)` in Postgres — whole seconds — so two
         * edits in the same second are one value, and the second edit served
         * the first edit's card for the full month.
         *
         * Time is frozen rather than left to the clock: on a fast machine this
         * happened almost every run, and on a slow one almost never, which is
         * the worst possible failure mode for a regression test.
         */
        $this->freezeTime();

        $edition = $this->edition();

        $first = (string) $this->get($this->url())->getContent();

        $edition->forceFill(['theme_title' => 'Alles voor de picknick'])->save();
        $second = (string) $this->get($this->url())->getContent();

        $edition->forceFill(['theme_title' => 'Alles voor het strand'])->save();
        $third = (string) $this->get($this->url())->getContent();

        $this->assertSame(
            $edition->fresh()?->updated_at?->timestamp,
            $edition->updated_at?->timestamp,
            'the premise: all three edits share one updated_at',
        );

        $this->assertCount(3, array_unique([md5($first), md5($second), md5($third)]));
    }

    #[Test]
    public function a_write_that_never_touches_updated_at_still_renders_a_new_card(): void
    {
        /*
         * The second bug in the key, and the more expensive one.
         *
         * Half of what a card draws is written without going through the model:
         * a product's "14 shops · from € 279,00" comes from aggregates that
         * ingestion writes in bulk, and a guide's footnote counts its items.
         * Keyed on `updated_at`, a product that went from five shops to fourteen
         * went on announcing five to everyone it was shared with.
         *
         * Hashing the drawn text is what fixes it, and that is a property of the
         * key rather than of any one endpoint — so a raw write here stands in for
         * the bulk write there.
         */
        $edition = $this->edition();

        $before = (string) $this->get($this->url())->getContent();

        DB::table('daily_pick_sets')
            ->where('id', $edition->id)
            ->update(['theme_title' => 'Alles voor de picknick']);

        $this->assertSame(
            $edition->updated_at?->timestamp,
            $edition->fresh()?->updated_at?->timestamp,
            'the premise: the raw write left updated_at alone',
        );

        $after = (string) $this->get($this->url())->getContent();

        $this->assertNotSame(md5($before), md5($after));
    }

    #[Test]
    public function an_edit_the_card_does_not_draw_is_still_served_from_the_cache(): void
    {
        // The other direction. Keying on content means a column the card never
        // shows must not cost a re-render, or a bulk recategorisation would
        // redraw everything.
        $edition = $this->edition();
        $renderer = $this->countingRenderer();

        $this->get($this->url())->assertOk();

        // Drawn nowhere on the card, and not part of the URL either.
        $edition->forceFill(['theme_slug' => 'barbecue-en-picknick'])->save();

        $this->get($this->url())->assertOk();

        $this->assertSame(1, $renderer->renders);
    }

    #[Test]
    public function a_deploy_renders_a_new_card_even_though_the_record_is_unchanged(): void
    {
        /*
         * The bug this exists for.
         *
         * A card's content comes from the row *and* from the code and language
         * files that lay it out, and only the first of those moves updated_at.
         * The Daily Cove card first rendered during a container swap, picked up
         * a missing translation key, and cached "SITE.OG.DAILY" in 24pt amber
         * for thirty days — unclearable without shell access to the box.
         *
         * Keying on the commit costs one re-render per card per deploy and makes
         * a bad card impossible to inherit across one.
         */
        $this->edition();
        $renderer = $this->countingRenderer();

        config(['giftcoves.commit_sha' => 'aaaaaaa']);
        $this->get($this->url())->assertOk();
        $this->assertSame(1, $renderer->renders);

        config(['giftcoves.commit_sha' => 'bbbbbbb']);
        $this->get($this->url())->assertOk();

        $this->assertSame(2, $renderer->renders, 'the new build must not inherit the old build\'s card');
    }
}
