<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Services\Seo\OgImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a cached card is allowed to outlive.
 *
 * The card is expensive and stable, so it is cached for a month. The question
 * that matters is what invalidates it — and the first two versions of this got
 * it wrong in ways that were invisible until they were in public.
 *
 * These assert through the *renderer*, by counting how often it runs, rather
 * than by rebuilding the cache key. A test that reconstructs the key passes as
 * long as it agrees with the controller, including when both are wrong
 * together, which is exactly what happened here.
 */
class OgImageCacheTest extends TestCase
{
    use RefreshDatabase;

    private function product(): ProductGroup
    {
        return ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'title' => 'Sony WH-1000XM5',
            // The footnote is drawn from these, so they are part of what the
            // key has to notice.
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
    public function a_card_is_rendered_once_and_then_served_from_the_cache(): void
    {
        $group = $this->product();
        $renderer = $this->countingRenderer();

        $this->get("/be-nl/og/p/{$group->id}.png")->assertOk();
        $this->get("/be-nl/og/p/{$group->id}.png")->assertOk();

        // Asserted through the renderer rather than by timing a second request,
        // which is flaky on a loaded machine.
        $this->assertSame(1, $renderer->renders);
    }

    #[Test]
    public function editing_the_record_renders_a_new_card(): void
    {
        $group = $this->product();

        $before = (string) $this->get("/be-nl/og/p/{$group->id}.png")->getContent();

        // A retitle has to reach the card, or a shared link keeps announcing the
        // old name for a month.
        $group->forceFill(['title' => 'Bose QuietComfort Ultra'])->save();

        $after = (string) $this->get("/be-nl/og/p/{$group->id}.png")->getContent();

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

        $group = $this->product();

        $first = (string) $this->get("/be-nl/og/p/{$group->id}.png")->getContent();

        $group->forceFill(['title' => 'Bose QuietComfort Ultra'])->save();
        $second = (string) $this->get("/be-nl/og/p/{$group->id}.png")->getContent();

        $group->forceFill(['title' => 'Sennheiser Momentum 4'])->save();
        $third = (string) $this->get("/be-nl/og/p/{$group->id}.png")->getContent();

        $this->assertSame(
            $group->fresh()?->updated_at?->timestamp,
            $group->updated_at?->timestamp,
            'the premise: all three edits share one updated_at',
        );

        $this->assertCount(3, array_unique([md5($first), md5($second), md5($third)]));
    }

    #[Test]
    public function an_aggregate_written_without_touching_the_record_renders_a_new_card(): void
    {
        /*
         * The second bug in the key, and the more expensive one.
         *
         * "14 shops · from € 279,00" is drawn from `merchant_count` and
         * `min_price`, which ingestion writes in bulk without moving
         * `updated_at`. A product that went from five shops to fourteen went on
         * announcing five to everyone it was shared with.
         */
        $group = $this->product();

        $before = (string) $this->get("/be-nl/og/p/{$group->id}.png")->getContent();

        DB::table('product_groups')
            ->where('id', $group->id)
            ->update(['merchant_count' => 14, 'min_price' => 24900]);

        $this->assertSame(
            $group->updated_at?->timestamp,
            $group->fresh()?->updated_at?->timestamp,
            'the premise: the aggregate write left updated_at alone',
        );

        $after = (string) $this->get("/be-nl/og/p/{$group->id}.png")->getContent();

        $this->assertNotSame(md5($before), md5($after));
    }

    #[Test]
    public function an_edit_the_card_does_not_draw_is_still_served_from_the_cache(): void
    {
        // The other direction. Keying on content means a column the card never
        // shows must not cost a re-render, or a bulk recategorisation would
        // redraw the entire catalogue.
        $group = $this->product();
        $renderer = $this->countingRenderer();

        $this->get("/be-nl/og/p/{$group->id}.png")->assertOk();

        $group->forceFill(['category' => 'audio/headphones'])->save();

        $this->get("/be-nl/og/p/{$group->id}.png")->assertOk();

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
        $group = $this->product();
        $renderer = $this->countingRenderer();

        config(['giftcoves.commit_sha' => 'aaaaaaa']);
        $this->get("/be-nl/og/p/{$group->id}.png")->assertOk();
        $this->assertSame(1, $renderer->renders);

        config(['giftcoves.commit_sha' => 'bbbbbbb']);
        $this->get("/be-nl/og/p/{$group->id}.png")->assertOk();

        $this->assertSame(2, $renderer->renders, 'the new build must not inherit the old build\'s card');
    }
}
