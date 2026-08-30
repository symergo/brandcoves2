<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\PublishStatus;
use App\Enums\Source;
use App\Models\CovePlan;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\PromptTemplate;
use App\Services\Ai\AiClient;
use App\Services\Cove\EditionBuilder;
use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The products are in the writing, and the cards follow the writing.
 *
 * A Daily Cove and a persona have paired a paragraph with the products it names
 * since they stopped being prose-then-grid. An article did not: its prose ran
 * as a wall of text and its shortlist as a ranked list underneath, so a
 * paragraph arguing for a grinder pointed at a card three screens down.
 *
 * Two changes, tested here.
 *
 * **The article carries the cards.** `[[product:N]]` in a guide's intro or body
 * places that product's card under the paragraph naming it, and the list below
 * renders only what the article did not reach — so it survives as a fallback
 * for a skipped product or a guide written before any of this, rather than
 * repeating what the reader has already scrolled past.
 *
 * **Every product gets written about, whoever chose it.** The rule used to flip
 * on curation: a curated Cove was told to cover all of them and an engine-picked
 * one to "pick two or three worth a sentence and let the rest speak for
 * themselves". That was right while a grid carried the remainder. It is wrong
 * now — a product no paragraph names has no card in the article and no sentence
 * anywhere, and drops to the foot of the page bare.
 */
class ProductCardsInProseTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
        ]);
    }

    // ── The page ──────────────────────────────────────────────────────────

    #[Test]
    public function a_guides_paragraph_carries_the_product_it_names(): void
    {
        $sony = $this->product('Sony koptelefoon', 32900, 'Sony');
        $jbl = $this->product('JBL koptelefoon', 14900, 'JBL');

        $guide = $this->guide(
            blurb: 'Twee die het waard zijn.',
            body: "De [[product:{$sony->id}]] is de stille.\n\nDe [[product:{$jbl->id}]] is de goedkope."
        );

        $this->pick($guide, $sony, 1);
        $this->pick($guide, $jbl, 2);

        $this->get('/be-nl/guides/beste-koptelefoons')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Guides/Show')
                // One card per paragraph, in the order the article discusses
                // them — which is the order the writer chose, not the ranking.
                ->where('guide.body.0.groupIds', [$sony->id])
                ->where('guide.body.1.groupIds', [$jbl->id])
                // The intro named neither, so it pairs nothing and renders as
                // an ordinary paragraph.
                ->where('guide.intro.0.groupIds', [])
                /*
                 * The whole shortlist still ships. The page works out what the
                 * list should render; shrinking this server-side to "the
                 * leftovers" would also shrink the ItemList built from it, and
                 * under-report a page that does rank all of them.
                 */
                ->has('items', 2)
            );
    }

    #[Test]
    public function the_list_renders_only_what_the_article_did_not_reach(): void
    {
        $sony = $this->product('Sony koptelefoon', 32900, 'Sony');
        $jbl = $this->product('JBL koptelefoon', 14900, 'JBL');

        $guide = $this->guide(body: "Alleen over de [[product:{$sony->id}]].");

        $this->pick($guide, $sony, 1);
        $this->pick($guide, $jbl, 2);

        $props = $this->get('/be-nl/guides/beste-koptelefoons')->viewData('page')['props'];

        $named = collect([...$props['guide']['intro'], ...$props['guide']['body']])
            ->flatMap(fn (array $block) => $block['groupIds'])
            ->all();

        /*
         * The filter itself lives in the React page — this pins the data it
         * filters on. A guide whose article skipped the JBL must still show it,
         * because the alternative is a product on the shortlist that appears
         * nowhere on the page it was shortlisted for.
         */
        $this->assertSame([$sony->id], $named);
        $this->assertNotContains($jbl->id, $named);
        $this->assertCount(2, $props['items']);
    }

    #[Test]
    public function a_product_is_never_carded_twice_across_intro_and_body(): void
    {
        $sony = $this->product('Sony koptelefoon', 32900, 'Sony');

        $guide = $this->guide(
            blurb: "De [[product:{$sony->id}]] leidt.",
            body: "Terug naar de [[product:{$sony->id}]]."
        );

        $this->pick($guide, $sony, 1);

        $this->get('/be-nl/guides/beste-koptelefoons')
            ->assertInertia(fn ($page) => $page
                // Intro first, because that is reading order: the card belongs
                // where the reader meets the product, not where it is mentioned
                // again.
                ->where('guide.intro.0.groupIds', [$sony->id])
                ->where('guide.body.0.groupIds', [])
            );
    }

    #[Test]
    public function an_advice_article_still_renders_as_prose(): void
    {
        $guide = $this->guide(
            kind: CoveKind::Advice,
            slug: 'hoe-lees-je-een-retourbeleid',
            body: "Een betaalde review leest anders.\n\nEn een 'van'-prijs is meestal fictie."
        );

        $this->get('/be-nl/guides/hoe-lees-je-een-retourbeleid')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('guide.kind', 'advice')
                // No shortlist, so every block falls straight through the
                // pairing and the page is prose top to bottom. This is what
                // makes the change safe to apply to every kind.
                ->where('guide.body.0.groupIds', [])
                ->where('guide.body.1.groupIds', [])
                ->has('items', 0)
            );
    }

    // ── The instruction ───────────────────────────────────────────────────

    #[Test]
    public function an_uncurated_guide_is_told_to_cover_every_product(): void
    {
        $this->shelf();

        $systems = $this->captureSystemPrompts();

        app(EditionBuilder::class)->buildArticle($this->plan());

        $sent = implode("\n", $systems->getArrayCopy());

        /*
         * Nobody curated this shortlist — the engine picked it. Before, that
         * was the case told to "pick two or three worth a sentence", which now
         * leaves four products with nothing written about them anywhere.
         */
        $this->assertStringContainsString('EVERY product', $sent);
        $this->assertStringContainsString('One product per paragraph', $sent);
        $this->assertStringNotContainsString('two or three worth a sentence', $sent);
    }

    #[Test]
    public function the_rule_survives_an_edited_prompt_template(): void
    {
        $this->shelf();

        /*
         * The paragraph rule is a description of how the page renders, not
         * house style, so it is appended in code rather than living in the
         * editable template. An edit that dropped it would empty the article of
         * products and push all seven back into the list beneath it — with no
         * error, and no symptom until somebody read the page.
         */
        PromptTemplate::create([
            'slot' => 'cove.guide',
            'system' => 'Write it in limericks. Mention a product if you feel like it.',
        ]);

        $systems = $this->captureSystemPrompts();

        app(EditionBuilder::class)->buildArticle($this->plan());

        $sent = implode("\n", $systems->getArrayCopy());

        // The editor's voice arrived, and so did the rule they cannot delete.
        $this->assertStringContainsString('limericks', $sent);
        $this->assertStringContainsString('One product per paragraph', $sent);
    }

    #[Test]
    public function a_curated_guide_is_told_the_order_and_the_reasons_as_well(): void
    {
        $this->shelf();
        $chosen = $this->product('Reiskoptelefoon', 24900, 'Bose');

        $plan = $this->plan();
        $plan->items()->create([
            'group_id' => $chosen->id,
            'rank' => 1,
            'note' => 'Vouwt plat in een rugzak',
        ]);

        $systems = $this->captureSystemPrompts();

        app(EditionBuilder::class)->buildArticle($plan->fresh());

        $sent = implode("\n", $systems->getArrayCopy());

        // Curation no longer decides *whether* every product is covered. What
        // it adds is the order somebody chose and the reason they chose it.
        $this->assertStringContainsString('EVERY product', $sent);
        $this->assertStringContainsString('in the order given', $sent);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function captureSystemPrompts(): ArrayObject
    {
        $systems = new ArrayObject;

        $this->mock(AiClient::class, function ($mock) use ($systems) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('json')->andReturnUsing(
                function (string $feature, string $system) use ($systems) {
                    $systems->append($system);

                    return ['title' => 'Koptelefoons', 'intro' => 'Een selectie.'];
                }
            );
        });

        return $systems;
    }

    private function guide(
        ?string $blurb = 'Waar het over gaat.',
        ?string $body = null,
        CoveKind $kind = CoveKind::Guide,
        string $slug = 'beste-koptelefoons',
    ): DailyPickSet {
        return DailyPickSet::create([
            'market' => Market::BeNl,
            'kind' => $kind,
            'slug' => $slug,
            'theme_title' => 'Beste koptelefoons',
            'theme_slug' => $slug,
            'theme_blurb' => $blurb,
            'body' => $body,
            'status' => PublishStatus::Published,
            'published_at' => '2026-08-20',
        ]);
    }

    private function pick(DailyPickSet $guide, ProductGroup $group, int $rank): DailyPick
    {
        return DailyPick::create([
            'set_id' => $guide->id,
            'group_id' => $group->id,
            'rank' => $rank,
            'slug' => $group->slug,
            'blurb' => 'Korte terugvalregel.',
            'verdict' => 'Beste voor onderweg',
        ]);
    }

    private function plan(): CovePlan
    {
        return CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Guide->value,
            'slug' => 'beste-koptelefoons',
            'title' => 'Beste koptelefoons',
            'focus_keyphrase' => 'koptelefoon',
            'status' => 'approved',
        ]);
    }

    /** Enough of a shelf that the guide floor is clearable. */
    private function shelf(): void
    {
        foreach (['Sony', 'Sennheiser', 'JBL', 'Philips', 'Marshall', 'AKG'] as $i => $brand) {
            $this->product("{$brand} koptelefoon", 5000 + $i * 4000, $brand);
        }
    }

    private function product(string $title, int $price, string $brand): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'brand' => $brand,
            'category' => 'audio',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => 2,
            'in_stock' => true,
            'giftable' => true,
            'worth_showing' => true,
            'surprise_score' => 50,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }
}
