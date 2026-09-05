<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Services\Seo\OgImage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The 1200×630 card a shared link turns into.
 *
 * Rendered pixels cannot be asserted on usefully, so these check the things that
 * actually break: the dimensions a platform reserves space for, the refusal to
 * render text from the request, and the market scoping.
 */
class OgImageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_card_is_a_png_at_the_size_every_platform_expects(): void
    {
        // 1200×630 is the size Facebook, X, LinkedIn and Slack all crop to. A
        // card at any other ratio gets cut somewhere unpredictable.
        $png = app(OgImage::class)->render('A title', 'Kicker', 'A footnote');

        $image = imagecreatefromstring($png);

        $this->assertNotFalse($image);
        $this->assertSame(1200, imagesx($image));
        $this->assertSame(630, imagesy($image));
    }

    #[Test]
    public function a_title_far_too_long_to_fit_still_renders(): void
    {
        // Feeds produce these. The layout shrinks, then truncates, and must not
        // throw or overflow the canvas either way.
        $png = app(OgImage::class)->render(str_repeat('Draadloze Ruisonderdrukkende Koptelefoon ', 12));

        $this->assertNotFalse(imagecreatefromstring($png));
    }

    #[Test]
    public function a_title_with_no_spaces_does_not_hang_the_wrapper(): void
    {
        // The wrapper breaks on whitespace, so a single enormous token is the
        // case that would loop forever if the guard were wrong.
        $png = app(OgImage::class)->render(str_repeat('A', 400));

        $this->assertNotFalse(imagecreatefromstring($png));
    }

    #[Test]
    public function the_default_card_is_served_per_market(): void
    {
        $response = $this->get('/be-nl/og/default.png');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('max-age', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function a_product_card_names_the_product(): void
    {
        $group = ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'title' => 'Sony WH-1000XM5',
            'merchant_count' => 4,
            'min_price' => 27900,
        ]);

        $this->get("/be-nl/og/p/{$group->id}.png")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    #[Test]
    public function a_product_from_another_market_is_not_rendered(): void
    {
        // Invariant 2 reaches even here: a card served under /be-nl/ that
        // describes a Dutch product would put a price nobody can pay in a
        // Belgian timeline.
        $group = ProductGroup::factory()->create(['market' => Market::NlNl]);

        $this->get("/be-nl/og/p/{$group->id}.png")->assertNotFound();
    }

    #[Test]
    public function an_unpublished_guide_has_no_card(): void
    {
        // A guide is a `daily_pick_sets` row since the fold; the legacy
        // `guides` table it used to be written to is gone.
        $guide = DailyPickSet::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Guide->value,
            'slug' => 'concept',
            'theme_title' => 'Nog niet klaar',
            'theme_slug' => 'concept',
            'status' => PublishStatus::Draft->value,
        ]);

        $this->get("/be-nl/og/guide/{$guide->slug}.png")->assertNotFound();
    }

    #[Test]
    public function a_daily_edition_card_is_addressed_by_date(): void
    {
        /*
         * Not "today". A platform caches the card it fetched when the link was
         * posted, and /daily is a different edition every morning — an undated
         * card would silently turn yesterday's shared post into today's theme.
         */
        $edition = $this->edition('2026-08-08');

        $this->get('/be-nl/og/daily/2026-08-08.png')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->get('/be-nl/og/daily/2026-08-09.png')->assertNotFound();

        // And the page points at the dated card even at its undated URL, which
        // is the half that makes the dating worth anything.
        $this->travelTo($edition->drop_date->addHours(12), function (): void {
            $this->get('/be-nl/tips')->assertSee('/be-nl/og/daily/2026-08-08.png', false);
        });
    }

    #[Test]
    public function tomorrows_edition_has_no_card_yet(): void
    {
        /*
         * The page refuses a future date because guessing tomorrow's puzzle by
         * URL would be an obvious hole in a daily game. A card is a URL that
         * renders the theme in 60pt type, so it has to refuse the same thing —
         * an image endpoint that skips a page's rules is the page's rules with
         * an extension on the end.
         */
        $this->edition(now()->addDay()->toDateString(), alreadyLive: true);
        $this->edition(now()->toDateString(), published: false);

        $this->get('/be-nl/og/daily/'.now()->addDay()->toDateString().'.png')->assertNotFound();
        $this->get('/be-nl/og/daily/'.now()->toDateString().'.png')->assertNotFound();
    }

    /**
     * @param  bool  $published  a draft edition, as one is between build and drop
     * @param  bool  $alreadyLive  publish it in the past even if it drops tomorrow,
     *                             so the future-date guard is what rejects it and
     *                             not the published_at check standing in for it
     */
    private function edition(string $date, bool $published = true, bool $alreadyLive = false): DailyPickSet
    {
        return DailyPickSet::create([
            'market' => Market::BeNl->value,
            'drop_date' => $date,
            'theme_title' => 'Alles voor de barbecue',
            'theme_slug' => 'barbecue-'.$date,
            'theme_source' => 'theme',
            'status' => $published ? PublishStatus::Published->value : PublishStatus::Draft->value,
            // Editions drop in the morning of their own date. Anchored there
            // rather than to "an hour ago", so a test that travels to the drop
            // date does not find the edition published in its own future.
            'published_at' => match (true) {
                ! $published => null,
                $alreadyLive => now()->subHour(),
                default => CarbonImmutable::parse($date)->setTime(6, 0),
            },
        ]);
    }

    #[Test]
    public function the_endpoint_will_not_render_text_from_the_request(): void
    {
        /*
         * The reason every route takes an id or a slug. An endpoint that draws
         * arbitrary words on a GiftCoves-branded card is an impersonation tool
         * with a URL, and our own domain would serve the screenshot.
         */
        $this->get('/be-nl/og/default.png?title=GiftCoves+recommends+this+scam')
            ->assertOk();

        $withText = $this->get('/be-nl/og/default.png?title=GiftCoves+recommends+this+scam')->getContent();
        $without = $this->get('/be-nl/og/default.png')->getContent();

        $this->assertSame(md5($without), md5($withText));
    }

    #[Test]
    public function a_page_declares_the_card_and_its_dimensions(): void
    {
        // Without width and height a platform has to fetch the file before it
        // can lay out the post, and some simply do not bother.
        $this->get('/be-nl')
            ->assertSee('og:image:width', false)
            ->assertSee('/be-nl/og/default.png', false)
            ->assertSee('summary_large_image', false);
    }
}
