<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\Guide;
use App\Models\ProductGroup;
use App\Services\Seo\OgImage;
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
        $guide = Guide::create([
            'market' => Market::BeNl->value,
            'slug' => 'concept',
            'title' => 'Nog niet klaar',
            'status' => PublishStatus::Draft->value,
        ]);

        $this->get("/be-nl/og/guide/{$guide->slug}.png")->assertNotFound();
    }

    #[Test]
    public function the_endpoint_will_not_render_text_from_the_request(): void
    {
        /*
         * The reason every route takes an id or a slug. An endpoint that draws
         * arbitrary words on a Brandcoves-branded card is an impersonation tool
         * with a URL, and our own domain would serve the screenshot.
         */
        $this->get('/be-nl/og/default.png?title=Brandcoves+recommends+this+scam')
            ->assertOk();

        $withText = $this->get('/be-nl/og/default.png?title=Brandcoves+recommends+this+scam')->getContent();
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
