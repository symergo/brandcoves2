<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveScene;
use App\Enums\Market;
use App\Enums\OfflineIdeaStatus;
use App\Models\ApiToken;
use App\Models\CovePlan;
use App\Models\OfflineIdea;
use App\Services\Cove\EditionBuilder;
use App\Services\Gift\HasEverything;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\TasteBrief;
use App\Services\Ideas\OfflineIdeaPicker;
use App\Services\Search\GiftIntentParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\MakesGiftShelf;
use Tests\TestCase;

/**
 * A persona for someone who has everything (owner's request 8,
 * docs/features/has-everything.md): things that get used up or done before
 * more things to keep, approved offline ideas only, and the phrase read by
 * the search box in four languages.
 */
class HasEverythingTest extends TestCase
{
    use MakesGiftShelf;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::today()->setTime(12, 0));
    }

    #[Test]
    public function the_search_box_reads_the_phrase_in_every_language(): void
    {
        $parser = app(GiftIntentParser::class);

        $nl = $parser->parse('cadeau voor papa die alles al heeft', Market::BeNl);
        $this->assertTrue($nl->isGift);
        $this->assertTrue($nl->hasEverything);
        $this->assertSame('father', $nl->relationship);
        // "alles" is not searched for.
        $this->assertSame('', $nl->rest);
        $this->assertTrue($nl->toBrief(Market::BeNl, 4)->hasEverything);

        $this->assertTrue($parser->parse('gift for a man who has everything', Market::En)->hasEverything);
        $this->assertTrue($parser->parse('cadeau pour quelqu\'un qui a déjà tout', Market::BeFr)->hasEverything);
        $this->assertTrue($parser->parse('regalo para alguien que lo tiene todo', Market::Es)->hasEverything);

        // A sign of a gift search on its own, and absent from an ordinary one.
        $this->assertTrue($parser->parse('heeft alles al', Market::BeNl)->isGift);
        $this->assertFalse($parser->parse('alles voor de tuin', Market::BeNl)->hasEverything);
    }

    #[Test]
    public function the_engine_prefers_what_gets_used_up_or_done(): void
    {
        $this->forbidAi();

        $usedUp = collect([
            $this->giftable('Proeverij Belgische bieren', 3500),
            $this->giftable('Workshop sushi maken voor twee', 6900),
            $this->giftable('Chocolade pralines luxe doos', 2500),
            $this->giftable('Thee selectie in houten kist', 2900),
        ])->pluck('id')->all();

        // Things to keep, some tagged for the interest the brief also names.
        foreach (range(1, 6) as $i) {
            $this->giftable("Bluetooth speaker model {$i}", 4000 + $i, ['interest:music']);
        }

        $engine = app(SuggestionEngine::class);

        $picks = $engine->suggest(new TasteBrief(market: Market::BeNl, limit: 4, hasEverything: true));

        $this->assertEqualsCanonicalizing($usedUp, array_map(fn ($s) => $s->group->id, $picks));
        $this->assertTrue(collect($picks)->every(fn ($s) => $s->consumable));
        // The slot names no interest, so no card calls it one.
        $this->assertTrue(collect($picks)->every(fn ($s) => $s->matchedInterests === []));

        // With an interest too, the used-up things still lead, and the
        // interest fills the seats they leave.
        $mixed = $engine->suggest(new TasteBrief(market: Market::BeNl, interests: ['music'], limit: 6, hasEverything: true));
        $ids = array_map(fn ($s) => $s->group->id, $mixed);

        $this->assertEqualsCanonicalizing($usedUp, array_slice($ids, 0, 4));
        $this->assertCount(6, $ids);

        // And a brief without the flag is the old brief.
        $plain = $engine->suggest(new TasteBrief(market: Market::BeNl, interests: ['music'], limit: 4));
        $this->assertSame([], array_intersect($usedUp, array_map(fn ($s) => $s->group->id, $plain)));
    }

    #[Test]
    public function the_word_list_matches_used_up_things_and_not_ordinary_ones(): void
    {
        $words = app(HasEverything::class);

        $this->assertTrue($words->matches('Proeverijpakket speciaalbieren'));
        $this->assertTrue($words->matches('Cadeaubon wellness'));
        $this->assertTrue($words->matches('Luxury chocolate truffles'));
        $this->assertTrue($words->matches('Thee', 'Levensmiddelen'));
        $this->assertFalse($words->matches('The Beatles vinyl'));
        $this->assertFalse($words->matches('Theelepel zilver'));
        $this->assertFalse($words->matches('Gourmetstel 8 personen'));
    }

    #[Test]
    public function only_approved_offline_ideas_are_offered_and_the_done_ones_fit(): void
    {
        $workshop = $this->idea('Een workshop koken', OfflineIdeaStatus::Approved, ['recipient:father']);
        $this->idea('Een rode sjaal', OfflineIdeaStatus::Approved, ['interest:fashion']);
        $this->idea('Een proeverij van Belgische bieren', OfflineIdeaStatus::Pending, []);
        $this->idea('Een ballonvaart', OfflineIdeaStatus::Rejected, []);
        $this->idea('Een workshop keramiek', OfflineIdeaStatus::Approved, [], Market::NlNl);

        $ideas = app(OfflineIdeaPicker::class)->forBrief(new TasteBrief(market: Market::BeNl, hasEverything: true));

        $this->assertSame([['id' => $workshop->id, 'title' => 'Een workshop koken']], $ideas);

        // Without the flag, an untagged-for-this brief finds nothing, as before.
        $this->assertSame([], app(OfflineIdeaPicker::class)->forBrief(new TasteBrief(market: Market::BeNl)));
    }

    #[Test]
    public function a_gift_search_for_someone_who_has_everything_is_answered_so(): void
    {
        $this->forbidAi();

        $tasting = $this->giftable('Proeverij Belgische bieren', 3500);
        $this->giftable('Bluetooth speaker', 3900, ['interest:music']);

        $this->get('/be-nl/search?q='.urlencode('cadeau voor papa die alles al heeft'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('intent.chips.1.label', 'Heeft alles al')
                ->where('results.items', fn ($items) => collect($items)->pluck('id')->first() === $tasting->id));

        // Counted as demand for a persona, as the reading alone.
        $row = DB::table('gift_search_demand')->sole();
        $this->assertTrue((bool) $row->has_everything);
        $this->assertSame('father', $row->relationship);
        $this->assertSame('', $row->interest);
    }

    #[Test]
    public function a_persona_drawn_as_has_everything_is_planned_and_shown_like_the_others(): void
    {
        $this->forbidAi();

        foreach (['Proeverij Belgische bieren' => 1500, 'Chocolade pralines doos' => 1200, 'Thee selectie kist' => 1900,
            'Workshop sushi maken' => 4500, 'Wijnpakket rood' => 3900, 'Cadeaubon massage' => 5000,
            'Proefpakket koffiebonen' => 2200, 'Geurkaars vijg' => 2400] as $title => $price) {
            $this->giftable($title, $price);
        }
        foreach (range(1, 6) as $i) {
            $this->giftable("Bluetooth speaker {$i}", 1500 + $i, ['interest:music']);
        }

        $this->idea('Een workshop koken', OfflineIdeaStatus::Approved, []);

        // In the planner, with no brief and no search terms: the drawing is the brief.
        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => 'persona',
            'slug' => 'wie-alles-al-heeft',
            'title' => 'Wie alles al heeft',
            'status' => 'approved',
            'writer' => 'authored',
            'editorial' => 'Voor wie niets meer nodig heeft.',
            'scene' => CoveScene::HasEverything->value,
        ]);

        $this->assertTrue($plan->tasteBrief(4)?->hasEverything);

        $edition = app(EditionBuilder::class)->buildPersona($plan);
        $this->assertNotNull($edition);

        $words = app(HasEverything::class);
        $picked = $edition->picks()->with('group')->get()->pluck('group');

        $this->assertNotEmpty($picked);
        $this->assertTrue($picked->every(fn ($group) => $words->matches($group->title, $group->category)));

        $props = $this->get('/be-nl/gift-ideas/wie-alles-al-heeft')->assertOk()->viewData('page')['props'];

        // The offline idea shows, and every tab holds only used-up things.
        $this->assertSame(['Een workshop koken'], array_column($props['offlineIdeas'], 'title'));

        foreach ($props['budgets'] as $band) {
            foreach ($band['items'] as $item) {
                $this->assertTrue($words->matches($item['title']), $item['title']);
            }
        }

        // A persona written with search terms keeps choosing by them.
        $plan->update(['queries' => ['speaker']]);
        $this->assertNull($plan->fresh()->tasteBrief(4));
    }

    #[Test]
    public function the_editorial_api_stores_the_flag_on_a_persona_brief(): void
    {
        $key = ApiToken::issue('test key', [ApiToken::READ, ApiToken::WRITE])['token'];

        $this->withToken($key)
            ->postJson('/api/editorial/coves', [
                'market' => 'be-nl',
                'kind' => 'persona',
                'slug' => 'wie-alles-al-heeft',
                'title' => 'Wie alles al heeft',
                'brief' => ['hasEverything' => true, 'relationship' => 'father'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.brief.hasEverything', true)
            ->assertJsonPath('data.brief.relationship', 'father');

        // Stored only when set, like every other field of a brief.
        $this->assertSame([], (new TasteBrief(market: Market::BeNl))->toArray());
        $this->assertTrue(TasteBrief::fromArray(['hasEverything' => true], Market::BeNl)->hasEverything);
    }

    /** @param list<string> $tags */
    private function idea(string $title, OfflineIdeaStatus $status, array $tags, Market $market = Market::BeNl): OfflineIdea
    {
        return OfflineIdea::create([
            'market' => $market->value,
            'key' => md5($title.$market->value),
            'title' => $title,
            'status' => $status->value,
            'tags' => $tags,
            'owners' => 5,
        ]);
    }
}
