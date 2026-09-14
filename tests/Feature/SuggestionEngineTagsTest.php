<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Enums\Vibe;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\TasteBrief;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An editor's gift tags reach the Whisperer.
 *
 * A tag is a decision where a text match is a guess: the engine retrieves a
 * tagged product whether or not its title agrees, scores the tag at full
 * strength on its slot, and answers the occasion, recipient, vibe and values
 * questions from tags before it looks for words.
 */
class SuggestionEngineTagsTest extends TestCase
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

    /** @param list<string> $tags */
    private function giftable(string $title, int $price, array $tags = [], ?string $category = 'Divers'): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'category' => $category,
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'gift_tags' => $tags,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'merchant_category' => $category,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }

    private function engine(): SuggestionEngine
    {
        return app(SuggestionEngine::class);
    }

    #[Test]
    public function a_tagged_product_is_found_without_a_word_in_common(): void
    {
        // Nothing in "Hario V60 set" answers the coffee angle queries; the
        // editor's tag does. A decision must not need the guess to agree.
        $tagged = $this->giftable('Hario V60 set', 3500, ['interest:coffee']);
        $this->giftable('Wasmachine 8kg', 39999);

        $picks = $this->engine()->suggest(new TasteBrief(market: Market::BeNl, interests: ['coffee']));

        $this->assertSame([$tagged->id], array_map(fn ($p) => $p->group->id, $picks));
        $this->assertEqualsWithDelta(40.0, $picks[0]->breakdown['interest_fit'], 0.01);
        $this->assertSame('coffee', $picks[0]->primaryInterest);
    }

    #[Test]
    public function a_tag_on_the_first_interest_outranks_a_text_match_on_the_second(): void
    {
        $tagged = $this->giftable('Handgemaakte mok', 3500, ['interest:coffee']);
        $this->giftable('Smartwatch sport GPS', 3500);

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['coffee', 'tech'],
            limit: 2,
        ));

        $this->assertSame($tagged->id, $picks[0]->group->id);
    }

    #[Test]
    public function a_product_answering_more_of_the_brief_beats_one_answering_the_first_interest_only(): void
    {
        /*
         * The owner's ask: matching is vectorial. Four interests, three
         * products: one tagged for the first alone, one for the first two,
         * one for all four. Same price, same category, so interest fit is
         * the only difference — and coverage decides.
         */
        $first = $this->giftable('Alleen koffie', 3000, ['interest:coffee']);
        $two = $this->giftable('Koffie en koken', 3000, ['interest:coffee', 'interest:cooking']);
        $all = $this->giftable('Alles', 3000, ['interest:coffee', 'interest:cooking', 'interest:baking', 'interest:drinks']);
        $last = $this->giftable('Zonder koffie', 3000, ['interest:cooking', 'interest:baking', 'interest:drinks']);

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['coffee', 'cooking', 'baking', 'drinks'],
            limit: 4,
        ));

        $fit = [];

        foreach ($picks as $pick) {
            $fit[$pick->group->id] = round($pick->breakdown['interest_fit'], 1);
        }

        // Weights 1.0, 0.83, 0.67, 0.5; half best, half coverage. Three of
        // four without the first beats the first alone: more of the brief
        // wins, which is what vectorial means here.
        $this->assertSame(40.0, $fit[$all->id]);
        $this->assertSame(32.2, $fit[$two->id]);
        $this->assertSame(30.0, $fit[$last->id]);
        $this->assertSame(26.7, $fit[$first->id]);
        $this->assertSame($all->id, $picks[0]->group->id);
    }

    #[Test]
    public function an_occasion_tag_counts(): void
    {
        $forChristmas = $this->giftable('Adventskalender koffie', 3000, ['interest:coffee', 'occasion:christmas']);
        $plain = $this->giftable('Koffiebonen proefpakket', 3000, ['interest:coffee']);

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['coffee'],
            occasion: 'christmas',
            limit: 2,
        ));

        $this->assertSame($forChristmas->id, $picks[0]->group->id);
        $this->assertEqualsWithDelta(5.0, $picks[0]->breakdown['occasion'], 0.01);
        $this->assertEqualsWithDelta(2.25, $picks[1]->breakdown['occasion'], 0.01);
    }

    #[Test]
    public function the_recipient_tag_meets_the_brief(): void
    {
        $forMum = $this->giftable('Zijden sjaal', 4900, ['interest:fashion', 'recipient:mother']);
        $forDad = $this->giftable('Zijden das', 4900, ['interest:fashion', 'recipient:father']);
        $untagged = $this->giftable('Zijden zakdoek', 4900, ['interest:fashion']);

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['fashion'],
            relationship: 'mother',
            limit: 3,
        ));

        $fit = [];

        foreach ($picks as $pick) {
            $fit[$pick->group->id] = $pick->breakdown['recipient_fit'];
        }

        // Out of 5: for her, for somebody else, for nobody in particular.
        $this->assertEqualsWithDelta(5.0, $fit[$forMum->id], 0.01);
        $this->assertEqualsWithDelta(2.25, $fit[$forDad->id], 0.01);
        $this->assertEqualsWithDelta(2.5, $fit[$untagged->id], 0.01);
        $this->assertSame($forMum->id, $picks[0]->group->id);
    }

    #[Test]
    public function the_age_tag_meets_the_brief_too(): void
    {
        $forTeen = $this->giftable('Skateboard', 4900, ['interest:outdoors', 'age:13-17']);
        $forToddler = $this->giftable('Loopfiets', 4900, ['interest:outdoors', 'age:3-5']);

        // The giver picked the band on the wizard; the same string the
        // editor tagged with.
        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['outdoors'],
            ageBand: '13-17',
            limit: 2,
        ));

        $this->assertSame($forTeen->id, $picks[0]->group->id);
        $this->assertEqualsWithDelta(5.0, $picks[0]->breakdown['recipient_fit'], 0.01);
        $this->assertEqualsWithDelta(2.25, $picks[1]->breakdown['recipient_fit'], 0.01);
    }

    #[Test]
    public function vibe_and_values_tags_answer_before_the_title_does(): void
    {
        $tagged = $this->giftable('Mok', 2500, ['interest:coffee', 'vibe:playful', 'values:handmade']);
        $plain = $this->giftable('Mok blauw', 2500, ['interest:coffee']);

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['coffee'],
            vibe: Vibe::Playful,
            values: ['handmade'],
            limit: 2,
        ));

        $by = [];

        foreach ($picks as $pick) {
            $by[$pick->group->id] = $pick->breakdown;
        }

        $this->assertEqualsWithDelta(10.0, $by[$tagged->id]['vibe'], 0.01);
        $this->assertEqualsWithDelta(10.0, $by[$tagged->id]['values'], 0.01);
        $this->assertLessThan(10.0, $by[$plain->id]['vibe']);
        $this->assertLessThan(10.0, $by[$plain->id]['values']);
    }

    /**
     * A word somebody typed searches, and never pretends to be a tag.
     *
     * The "anything else?" box takes any word. A typed interest becomes a slot
     * whose only query is the word itself, and it must not be matched against
     * `interest:` tags: the tag vocabulary is closed, so `interest:schilderen`
     * cannot exist, and treating the word as a tag would mean a product tagged
     * for a *different* interest could never be reached by it while the typed
     * word silently scored as though it had been (owner's call, 2026-09-14).
     *
     * True by construction — `Interest::tryFrom` fails, so neither the pool's
     * tag branch nor the slot's tag check sees it — and pinned here because it
     * is now a rule rather than an accident.
     */
    #[Test]
    public function a_typed_interest_searches_but_is_never_matched_as_a_tag(): void
    {
        // Tagged for art, and its title says nothing about painting.
        $tagged = $this->giftable('Doos 24 stuks', 3000, ['interest:art'], 'Kunst');
        // Untagged, and the typed word is in the title.
        $byWord = $this->giftable('Schilderen op nummer', 3000, [], 'Hobby');

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['schilderen'],
            limit: 8,
        ));

        $found = array_map(fn ($pick) => $pick->group->id, $picks);

        $this->assertContains($byWord->id, $found, 'the typed word has to search');
        $this->assertNotContains($tagged->id, $found, 'a typed word must not be read as a tag');

        // And it names itself on the card rather than borrowing an interest.
        $pick = collect($picks)->firstWhere(fn ($p) => $p->group->id === $byWord->id);
        $this->assertSame([['kind' => 'interest', 'value' => 'schilderen']], $pick->fits());
    }

    /**
     * The owner's report, 2026-09-14: "painting and technique" came back as a
     * board of speakers, and a board that answers one interest has ignored
     * half the brief. The diversifier now costs an interest once it has had
     * its fair share of the board, so the second interest gets a turn even
     * when the first has better-scoring products left.
     */
    #[Test]
    public function a_board_spreads_across_the_interests_that_were_asked_for(): void
    {
        // Cooking wins on score everywhere: more of it, and priced squarely
        // in the middle of the budget. Without the spread it takes the board.
        for ($i = 0; $i < 12; $i++) {
            $this->giftable("Koekenpan {$i}", 8000 + $i, ['interest:cooking'], 'Pannen');
        }

        for ($i = 0; $i < 4; $i++) {
            $this->giftable("Penseel {$i}", 1200 + $i, ['interest:art'], 'Kunst');
        }

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['cooking', 'art'],
            budgetMax: 10000,
            limit: 8,
        ));

        $byInterest = [];

        foreach ($picks as $pick) {
            $byInterest[$pick->primaryInterest] = ($byInterest[$pick->primaryInterest] ?? 0) + 1;
        }

        $this->assertCount(8, $picks);
        $this->assertSame(4, $byInterest['cooking'] ?? 0, 'cooking took more than its share');
        $this->assertSame(4, $byInterest['art'] ?? 0, 'art never got its share');

        // An interest that cannot fill its share gives the seats back rather
        // than leaving the board short.
        $thin = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['cooking', 'gardening'],
            budgetMax: 10000,
            limit: 8,
        ));

        $this->assertCount(8, $thin, 'an interest with no products must not cost the board a card');
    }

    /**
     * The card names what a present has in common with the brief, rather than
     * saying that it has something (owner's call, 2026-09-14). Interests
     * first, then the taste, and only what was actually asked for.
     */
    #[Test]
    public function a_suggestion_carries_what_it_fits_with(): void
    {
        $this->giftable('Fluitketel', 4000, ['interest:cooking', 'preference:vintage', 'values:handmade'], 'Keuken');

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['cooking'],
            preferences: ['vintage', 'colourful'],
            limit: 1,
        ));

        $this->assertSame([
            ['kind' => 'interest', 'value' => 'cooking'],
            ['kind' => 'preference', 'value' => 'vintage'],
        ], $picks[0]->fits(), 'a pole nobody asked for, or that does not match, must not be listed');
    }

    /**
     * Taste is the question the vibe cannot ask (owner's call, 2026-09-14):
     * "modern or vintage" is not a stronger "useful or beautiful". It is
     * asked as pairs of opposites. Any one of the poles named matching is a
     * match, a tag beats a title word, and a brief that skipped the question
     * scores the same neutral half every skipped question does.
     */
    #[Test]
    public function a_preference_tag_beats_a_title_word_and_any_one_of_them_counts(): void
    {
        $tagged = $this->giftable('Kruk', 6000, ['interest:home', 'preference:vintage']);
        $byTitle = $this->giftable('Retro kruk', 6000, ['interest:home']);
        $plain = $this->giftable('Kruk grijs', 6000, ['interest:home']);

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['home'],
            preferences: ['vintage', 'natural'],
            limit: 3,
        ));

        $by = [];

        foreach ($picks as $pick) {
            $by[$pick->group->id] = $pick->breakdown['preference'];
        }

        $this->assertEqualsWithDelta(5.0, $by[$tagged->id], 0.01);
        // "retro" is one of Preference::Vintage's keywords, so the title carries it.
        $this->assertEqualsWithDelta(5.0, $by[$byTitle->id], 0.01);
        $this->assertEqualsWithDelta(2.0, $by[$plain->id], 0.01);

        // Not asked is not unmet: everything scores the neutral half.
        $unasked = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['home'],
            limit: 3,
        ));

        foreach ($unasked as $pick) {
            $this->assertEqualsWithDelta(2.5, $pick->breakdown['preference'], 0.01);
        }
    }
}
