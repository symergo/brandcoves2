<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\CovePlan;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\PromptTemplate;
use App\Services\Ai\AiClient;
use App\Services\Ai\PromptBank;
use App\Services\Ai\Prompts\Defaults;
use App\Services\Cove\EditionBuilder;
use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The prompts are data now, and the fallback is what makes that safe.
 *
 * An editor who can change what the writer is told can also empty it, delete the
 * block that lists the products, or park a half-finished rewrite. None of those
 * may reach a build: an empty table, a blank field and a disabled row all resolve
 * to the prompt the site shipped with.
 */
class PromptBankTest extends TestCase
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

    #[Test]
    public function an_empty_table_sends_the_shipped_prompt(): void
    {
        $bank = app(PromptBank::class);

        // The normal state of this table, and the one every environment starts
        // in. Nothing about a build may depend on a row existing.
        $this->assertSame(Defaults::system('cove.guide'), $bank->system('cove.guide'));
        $this->assertStringContainsString('No prices at all', $bank->system('cove.guide'));
    }

    #[Test]
    public function a_blank_override_is_not_an_override(): void
    {
        PromptTemplate::create(['slot' => 'cove.guide', 'system' => '', 'user_template' => null]);

        // Clearing a field means "back to the shipped prompt", not "send the
        // model an empty system message".
        $this->assertSame(Defaults::system('cove.guide'), app(PromptBank::class)->system('cove.guide'));
    }

    #[Test]
    public function a_disabled_override_is_parked_rather_than_lost(): void
    {
        PromptTemplate::create([
            'slot' => 'cove.guide',
            'system' => 'a half-finished rewrite',
            'enabled' => false,
        ]);

        $this->assertSame(Defaults::system('cove.guide'), app(PromptBank::class)->system('cove.guide'));

        // And it is still there to come back to.
        $this->assertSame('a half-finished rewrite', PromptTemplate::query()->value('system'));
    }

    #[Test]
    public function a_row_for_an_unknown_slot_is_inert(): void
    {
        PromptTemplate::create(['slot' => 'cove.retired', 'system' => 'from a kind that no longer exists']);

        // The slot list lives in code, so a stale row cannot reach a caller that
        // no longer expects it. An unknown slot has no shipped text either, so
        // the honest answer is an empty string rather than somebody else's rules.
        $this->assertSame('', app(PromptBank::class)->system('cove.retired'));
    }

    #[Test]
    public function an_override_reaches_the_model(): void
    {
        PromptTemplate::create(['slot' => 'cove.guide', 'system' => 'Write it in limericks.']);

        $this->shelf();
        $systems = new ArrayObject;

        $this->mock(AiClient::class, function ($mock) use ($systems) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('json')->andReturnUsing(function (string $f, string $system) use ($systems) {
                $systems->append($system);

                return [];
            });
        });

        app(EditionBuilder::class)->buildArticle($this->plan());

        $sent = implode("\n", $systems->getArrayCopy());

        $this->assertStringContainsString('Write it in limericks.', $sent);

        /*
         * And the contract is still there. It is appended in code precisely so
         * an edited prompt cannot stop the writer producing internal links —
         * which would be invisible until somebody noticed the articles had gone
         * flat.
         */
        $this->assertStringContainsString('[[product:', $sent);
    }

    #[Test]
    public function a_kind_reads_its_own_slot(): void
    {
        PromptTemplate::create(['slot' => 'cove.daily', 'system' => 'FOR THE DAILY ONLY']);

        $this->shelf();
        $systems = new ArrayObject;

        $this->mock(AiClient::class, function ($mock) use ($systems) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('json')->andReturnUsing(function (string $f, string $system) use ($systems) {
                $systems->append($system);

                return [];
            });
        });

        app(EditionBuilder::class)->buildArticle($this->plan());

        // A guide must not pick up the column's prompt. That is the whole point
        // of a slot per kind.
        $this->assertStringNotContainsString('FOR THE DAILY ONLY', implode("\n", $systems->getArrayCopy()));
    }

    #[Test]
    public function the_shipped_prompt_is_readable_as_a_starting_point(): void
    {
        /*
         * What the admin screen pre-fills both fields with.
         *
         * An editor handed an empty textarea writes a *different* prompt rather
         * than a modified one, and loses the rules that stop the model inventing
         * prices and naming products that are not on the page.
         */
        $shipped = PromptBank::shipped('cove.guide');

        $this->assertStringContainsString('No prices at all', $shipped['system']);
        $this->assertStringContainsString('{finds}', $shipped['user_template']);

        // And what it offers is a template the validator would accept, or the
        // starting point would be one that cannot be saved.
        PromptBank::validate('cove.guide', $shipped['user_template']);

        foreach (array_keys(PromptBank::slots()) as $slot) {
            PromptBank::validate($slot, PromptBank::shipped($slot)['user_template']);
        }
    }

    #[Test]
    public function every_kind_has_its_own_prompt(): void
    {
        $systems = collect(array_keys(PromptBank::slots()))
            ->mapWithKeys(fn (string $slot) => [$slot => Defaults::system($slot)]);

        // The point of the change. A Daily and a persona shared one prompt, and
        // so did a buying guide and a seasonal one — and each pairing produced a
        // specific, repeatable failure rather than a vague loss of quality.
        $this->assertSame($systems->count(), $systems->unique()->count(), 'two kinds share a prompt');
        $this->assertEmpty($systems->filter(fn (string $s) => blank($s)), 'a slot has no prompt at all');
    }

    #[Test]
    public function a_persona_is_told_not_to_write_about_today(): void
    {
        /*
         * The failure the persona prompt exists to prevent: written by the
         * column's prompt it says "this week" and "today's finds" on a page that
         * is undated, evergreen and read for years.
         */
        $persona = Defaults::system('cove.persona');

        $this->assertStringContainsString('undated and permanent', $persona);
        $this->assertStringContainsString('Never write "today"', $persona);
    }

    #[Test]
    public function a_seasonal_guide_is_told_not_to_date_the_reader(): void
    {
        /*
         * Seasonal Coves are commissioned months before their window opens —
         * the search log cannot see a season coming — so "with Halloween almost
         * here" gets written in July and is wrong for eleven months.
         */
        $seasonal = Defaults::system('cove.seasonal');

        $this->assertStringContainsString('never date the reader', strtolower($seasonal));
        $this->assertStringContainsString('{season}', Defaults::user('cove.seasonal'));
    }

    #[Test]
    public function the_three_rules_that_protect_the_reader_are_in_every_article_prompt(): void
    {
        // Phrased identically on purpose: a model reads a re-phrased rule as a
        // different rule.
        foreach (['cove.daily', 'cove.persona', 'cove.guide', 'cove.seasonal'] as $slot) {
            $system = Defaults::system($slot);

            $this->assertStringContainsString('Only discuss the products listed below', $system, $slot);
            $this->assertStringContainsString('No prices at all', $system, $slot);
            $this->assertStringContainsString('invent a price, a rating', $system, $slot);
        }
    }

    #[Test]
    public function an_advice_prompt_never_asks_for_products(): void
    {
        $advice = Defaults::system('cove.advice');

        // A model handed "two sentences per item, maximum" with no items will
        // invent some to write them about.
        $this->assertStringNotContainsString('per item', $advice);
        $this->assertStringContainsString('there are no products to describe', $advice);
        $this->assertStringNotContainsString('{curated}', Defaults::user('cove.advice'));
    }

    #[Test]
    public function an_unknown_placeholder_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('{merchant}');

        PromptBank::validate('cove.guide', 'Language: {language} {finds} {merchant}');
    }

    #[Test]
    public function a_template_that_lost_its_products_is_refused(): void
    {
        /*
         * The failure this validation exists for. A brief with no product block
         * asks the model to write about nothing, and a model asked to write
         * about nothing writes a plausible article about products that are not
         * on the page — which reads fine and is entirely invented.
         */
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('{finds}');

        PromptBank::validate('cove.guide', 'Language: {language}. Write something nice.');
    }

    #[Test]
    public function an_empty_template_is_allowed_because_it_means_the_default(): void
    {
        PromptBank::validate('cove.guide', null);
        PromptBank::validate('cove.guide', '');

        $this->assertTrue(true, 'blank must not be treated as a broken template');
    }

    #[Test]
    public function editing_a_prompt_takes_effect_without_waiting_for_a_cache(): void
    {
        $bank = app(PromptBank::class);
        $shipped = Defaults::system('cove.guide');

        $this->assertSame($shipped, $bank->system('cove.guide'));

        $row = PromptTemplate::create(['slot' => 'cove.guide', 'system' => 'new rules']);

        // The model flushes on save, because a cached prompt that survives its
        // own edit is the failure an editor would report as "the screen does
        // not work".
        $this->assertSame('new rules', $bank->system('cove.guide'));

        $row->delete();

        $this->assertSame($shipped, $bank->system('cove.guide'));
    }

    #[Test]
    public function an_empty_block_leaves_no_hole_in_the_brief(): void
    {
        PromptTemplate::create([
            'slot' => 'cove.guide',
            'user_template' => "Topic: {topic}\n\n{direction}\n\n{curated}\n\n{finds}",
        ]);

        $rendered = app(PromptBank::class)->user('cove.guide', [
            'topic' => 'koptelefoon',
            'direction' => null,
            'curated' => null,
            'finds' => 'Shortlist:',
        ]);

        // Three blank lines where a shortlist would be is a prompt that reads as
        // though something failed to render — which is exactly what a model will
        // conclude too.
        $this->assertSame("Topic: koptelefoon\n\nShortlist:", $rendered);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

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

    private function shelf(): void
    {
        foreach (['Sony', 'Sennheiser', 'JBL', 'Philips', 'Marshall', 'AKG'] as $i => $brand) {
            $group = ProductGroup::create([
                'market' => Market::BeNl,
                'identity_key' => 'k'.bin2hex(random_bytes(5)),
                'identity_kind' => 'ean',
                'title' => "{$brand} koptelefoon",
                'slug' => 'p-'.bin2hex(random_bytes(3)),
                'brand' => $brand,
                'category' => 'audio',
                'image_url' => 'https://img.test/x.jpg',
                'min_price' => 5000 + $i * 4000,
                'merchant_count' => 2,
                'in_stock' => true,
                'giftable' => true,
                'worth_showing' => true,
            ]);

            Product::create([
                'source' => Source::Awin,
                'market' => Market::BeNl,
                'merchant_id' => $this->merchant->id,
                'group_id' => $group->id,
                'external_id' => 'e'.bin2hex(random_bytes(5)),
                'title' => "{$brand} koptelefoon",
                'price' => 5000 + $i * 4000,
                'currency' => 'EUR',
                'affiliate_url' => 'https://example.test/buy',
                'availability' => Availability::InStock,
                'status' => ProductStatus::Active,
                'identity_key' => $group->identity_key,
            ]);
        }
    }
}
