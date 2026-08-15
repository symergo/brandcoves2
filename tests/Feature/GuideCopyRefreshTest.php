<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\Guide;
use App\Models\GuideItem;
use App\Models\ProductGroup;
use App\Services\Guides\GuideBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Getting prose back into guides that never got any.
 *
 * A published guide was written once and never revisited, so a guide built while
 * the model was unreachable kept its template copy permanently. Nothing about the
 * page said so: it renders, it just has no editorial in it.
 */
class GuideCopyRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'giftcoves.ai.enabled' => true,
            'giftcoves.ai.api_key' => 'sk-ant-test',
        ]);
    }

    private function guide(?string $body = null): Guide
    {
        $guide = Guide::create([
            'market' => Market::BeNl->value,
            'slug' => 'beste-koptelefoons',
            'title' => 'Beste koptelefoons',
            'intro' => 'Een selectie.',
            'body_md' => $body,
            'focus_keyphrase' => 'koptelefoons',
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
            'last_checked_at' => now()->subYear(),
        ]);

        foreach (range(1, 3) as $rank) {
            GuideItem::create([
                'guide_id' => $guide->id,
                'group_id' => ProductGroup::factory()->create()->id,
                'rank' => $rank,
                'editorial_copy' => null,
                'verdict' => null,
                'unavailable' => false,
            ]);
        }

        return $guide->fresh();
    }

    private function fakeCopy(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    // The block order that broke this in the first place.
                    ['type' => 'thinking', 'thinking' => ''],
                    ['type' => 'text', 'text' => json_encode([
                        'title' => 'De beste koptelefoons',
                        'intro' => 'Waar je op let bij een koptelefoon.',
                        'how_to_choose' => 'Kijk naar pasvorm en ruisonderdrukking.',
                        'faq' => [['q' => 'Bluetooth of kabel?', 'a' => 'Allebei prima.']],
                        'items' => [
                            ['verdict' => 'Beste voor forenzen', 'copy' => 'Compact en stil.'],
                            ['verdict' => 'Beste budget', 'copy' => 'Doet wat het moet.'],
                            ['verdict' => 'Beste voor thuis', 'copy' => 'Ruim en comfortabel.'],
                        ],
                    ])],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 400],
            ]),
        ]);
    }

    #[Test]
    public function a_guide_with_no_editorial_gets_some(): void
    {
        $guide = $this->guide();
        $this->fakeCopy();

        $this->assertTrue(app(GuideBuilder::class)->refreshCopy($guide));

        $guide->refresh();

        $this->assertSame('De beste koptelefoons', $guide->title);
        $this->assertSame('Kijk naar pasvorm en ruisonderdrukking.', $guide->body_md);
        $this->assertSame('Compact en stil.', $guide->items()->where('rank', 1)->value('editorial_copy'));
        $this->assertSame('Beste budget', $guide->items()->where('rank', 2)->value('verdict'));
    }

    #[Test]
    public function a_failed_call_leaves_the_existing_copy_alone(): void
    {
        // The point of the method. Trading real editorial for the template
        // because a model was briefly unreachable is a downgrade, and it would
        // happen on every run where the cap was already spent.
        $guide = $this->guide(body: 'Bestaande tekst die goed is.');
        $guide->items()->where('rank', 1)->update(['editorial_copy' => 'Bestaande zin.']);

        Http::fake(['api.anthropic.com/*' => Http::response([], 500)]);

        $this->assertFalse(app(GuideBuilder::class)->refreshCopy($guide));

        $guide->refresh();

        $this->assertSame('Bestaande tekst die goed is.', $guide->body_md);
        $this->assertSame('Beste koptelefoons', $guide->title);
        $this->assertSame('Bestaande zin.', $guide->items()->where('rank', 1)->value('editorial_copy'));
    }

    #[Test]
    public function the_shortlist_is_not_re_chosen(): void
    {
        /*
         * Only the words change. Re-picking products would reorder a page that
         * is already indexed, and the new copy would describe a guide nobody
         * ranked.
         */
        $guide = $this->guide();
        $before = $guide->items()->orderBy('rank')->pluck('group_id')->all();

        $this->fakeCopy();
        app(GuideBuilder::class)->refreshCopy($guide);

        $this->assertSame($before, $guide->items()->orderBy('rank')->pluck('group_id')->all());
    }

    #[Test]
    public function the_command_prefers_guides_that_have_no_editorial_at_all(): void
    {
        // A stale but real paragraph beats no paragraph, and the daily cap means
        // a run usually cannot serve both.
        $this->guide(body: 'Oud maar echt.');

        $empty = Guide::create([
            'market' => Market::BeNl->value,
            'slug' => 'beste-speakers',
            'title' => 'Beste speakers',
            'intro' => 'Een selectie.',
            'body_md' => null,
            'focus_keyphrase' => 'speakers',
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
            'last_checked_at' => now(),
        ]);
        GuideItem::create([
            'guide_id' => $empty->id,
            'group_id' => ProductGroup::factory()->create()->id,
            'rank' => 1,
            'unavailable' => false,
        ]);

        $this->fakeCopy();

        $this->artisan('bc:refresh-guide-copy', ['--limit' => 1])
            ->expectsOutputToContain('beste-speakers')
            ->assertSuccessful();

        $this->assertNotNull($empty->fresh()->body_md);
    }

    #[Test]
    public function an_exhausted_cap_stops_the_run_rather_than_burning_calls(): void
    {
        // Carrying on past the cap makes one failed call per remaining guide,
        // each logged as if the model had let us down.
        config(['giftcoves.ai.caps.guide_copy' => 0]);

        $this->guide();
        $this->fakeCopy();

        $this->artisan('bc:refresh-guide-copy')
            ->expectsOutputToContain('cap')
            ->assertSuccessful();

        Http::assertNothingSent();
    }
}
