<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a cached card is allowed to outlive.
 *
 * The card is expensive and stable, so it is cached for a month. The question
 * that matters is what invalidates it — and the first version of this got it
 * wrong in a way that was invisible until it was in public.
 */
class OgImageCacheTest extends TestCase
{
    use RefreshDatabase;

    private function product(): ProductGroup
    {
        return ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'title' => 'Sony WH-1000XM5',
        ]);
    }

    #[Test]
    public function a_card_is_rendered_once_and_then_served_from_the_cache(): void
    {
        $group = $this->product();

        $key = 'og:'.config('brandcoves.commit_sha').':product:'.$group->id.':'.$group->updated_at?->timestamp;

        $this->assertNull(Cache::get($key));

        $this->get("/be-nl/og/p/{$group->id}.png")->assertOk();

        // Asserted through the cache rather than by timing a second request,
        // which is flaky on a loaded machine.
        $this->assertNotNull(Cache::get($key));
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

        config(['brandcoves.commit_sha' => 'aaaaaaa']);
        $this->get("/be-nl/og/p/{$group->id}.png")->assertOk();

        config(['brandcoves.commit_sha' => 'bbbbbbb']);

        $this->assertNull(
            Cache::get('og:bbbbbbb:product:'.$group->id.':'.$group->updated_at?->timestamp),
            'the new build must not start out holding the old build\'s card',
        );

        $this->get("/be-nl/og/p/{$group->id}.png")->assertOk();

        $this->assertNotNull(
            Cache::get('og:bbbbbbb:product:'.$group->id.':'.$group->updated_at?->timestamp),
        );
    }
}
