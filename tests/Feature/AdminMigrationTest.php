<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Filament\Pages\Migration;
use App\Models\DailyPickSet;
use App\Models\PageBlock;
use App\Models\PageBlockVariant;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Content\ContentEnvelope;
use App\Services\Ops\ConfigReport;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The migration screen: moving work between environments without a shell.
 *
 * The tests that matter here are the ones about what the screen refuses to do.
 * Everything it *can* do is already covered by {@see ContentPromotionTest} —
 * this page is a face on `ContentEnvelope`, deliberately, so there is one set of
 * rules rather than two.
 */
class AdminMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_exports_an_envelope_as_a_download(): void
    {
        $product = ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'identity_key' => 'ean:1010101010101',
        ]);

        $guide = DailyPickSet::create([
            'market' => Market::BeNl,
            // An edition since the fold: guides travel in the editions surface.
            'kind' => CoveKind::Guide,
            'slug' => 'beste-blenders',
            'theme_title' => 'Beste blenders',
            'theme_slug' => 'beste-blenders',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $guide->picks()->create([
            'group_id' => $product->id,
            'rank' => 1,
            'slug' => $product->slug.'-'.$product->id,
        ]);

        $component = Livewire::actingAs($this->admin())
            ->test(Migration::class)
            ->set('data.surfaces', ['editions'])
            ->call('export');

        // Asserted through the actual download payload rather than the return
        // value: the bytes the browser receives are the thing under test, and a
        // streamed response that never writes its body would still look fine
        // from the outside.
        $download = $component->effects['download'] ?? null;

        $this->assertNotNull($download, 'the export produced no download');

        $decoded = json_decode(base64_decode((string) $download['content']), true);

        $this->assertSame(ContentEnvelope::VERSION, $decoded['version']);
        $this->assertSame('beste-blenders', $decoded['surfaces']['editions'][0]['slug']);

        // The identity, never the local id — that is the entire point of the
        // envelope, and a download is the last place it could regress unnoticed.
        $this->assertSame(
            'ean:1010101010101',
            $decoded['surfaces']['editions'][0]['picks'][0]['product']['identity_key'],
        );
    }

    #[Test]
    public function the_config_report_shows_presence_and_never_a_value(): void
    {
        /*
         * The screen renders straight into HTML, so a value that reached it
         * would also reach a screenshot, a browser cache and anyone standing
         * behind the person reading it. Lengths answer the only question
         * anybody actually has.
         */
        $secrets = [
            (string) config('app.key'),
            (string) config('giftcoves.wishlist.claim_hash_secret'),
            (string) config('database.connections.pgsql.password'),
        ];

        $component = Livewire::actingAs($this->admin())
            ->test(Migration::class)
            ->assertOk()
            // Presence is what it may say, and it must say it — a page that
            // showed nothing would also pass a "no secrets" assertion.
            ->assertSee('APP_KEY');

        foreach (array_filter($secrets) as $secret) {
            $component->assertDontSee($secret);
        }
    }

    #[Test]
    public function the_report_reads_the_config_paths_the_app_actually_uses(): void
    {
        /*
         * These pointed at `connectors.sources.bol` and `connectors.sources.amazon`,
         * and there is no `sources` level. The wrong paths resolved to null, so
         * bol reported MISSING on every environment including ones where it
         * demonstrably works, and Amazon read as "off" everywhere — which
         * quietly downgraded its credentials from required to optional.
         *
         * Both directions are asserted, because a wrong path passes any test
         * that only checks the unset case: null is indistinguishable from
         * "genuinely not configured" until you set it and it still says null.
         */
        $report = app(ConfigReport::class);

        config([
            'giftcoves.connectors.bol.client_id' => null,
            'giftcoves.connectors.bol.client_secret' => null,
            'giftcoves.connectors.amazon.access_key' => null,
        ]);

        $this->assertContains('BOL_CLIENT_ID', $report->failures());

        config([
            'giftcoves.connectors.bol.client_id' => 'a-client-id',
            'giftcoves.connectors.bol.client_secret' => 'a-secret',
        ]);

        $this->assertNotContains(
            'BOL_CLIENT_ID',
            $report->failures(),
            'bol is configured and the report still calls it missing',
        );

        // Amazon's credentials are only required while Amazon is on, so the
        // enabled flag has to resolve too — a null there makes them optional
        // forever and hides a genuinely missing key.
        config(['giftcoves.connectors.amazon.enabled' => true]);

        $this->assertContains('AMAZON_ACCESS_KEY', $report->failures());
    }

    #[Test]
    public function apply_is_hidden_until_something_has_been_checked(): void
    {
        /*
         * The drop list is the reason to run an import at all — production's
         * catalogue is smaller than staging's, so some picks have no
         * counterpart. Allowing a write before that list has been produced
         * would hide it behind a fait accompli.
         */
        Livewire::actingAs($this->admin())
            ->test(Migration::class)
            ->assertActionHidden(TestAction::make('apply')->schemaComponent('transfer'));
    }

    #[Test]
    public function changing_the_selection_withdraws_the_apply_button(): void
    {
        /*
         * A dry run describes one file and one set of surfaces. Leaving Apply
         * live after either changes lets you preview envelope A, swap to B, and
         * write B without anybody having seen its drop list — which is the exact
         * outcome the preview gate exists to prevent, reached by a route that
         * looks like normal use.
         */
        $component = Livewire::actingAs($this->admin())
            ->test(Migration::class)
            ->set('preview', ['guides' => ['created' => 1, 'updated' => 0, 'dropped' => []]])
            ->assertActionVisible(TestAction::make('apply')->schemaComponent('transfer'));

        $component
            ->set('data.surfaces', ['plans'])
            ->assertActionHidden(TestAction::make('apply')->schemaComponent('transfer'));
    }

    #[Test]
    public function the_export_button_refuses_an_empty_environment(): void
    {
        /*
         * An empty export is a valid envelope containing nothing. It downloads,
         * it imports, it changes nothing on the far side, and the only symptom
         * is somebody concluding the importer is broken.
         *
         * "Empty" needs help now: page templates are seeded by a migration, so
         * every environment has blocks from the moment it exists and no database
         * is ever empty on its own. Clearing them is what puts this test back in
         * the state it was written for.
         */
        PageBlockVariant::query()->delete();
        PageBlock::query()->delete();

        Livewire::actingAs($this->admin())
            ->test(Migration::class)
            ->call('export')
            ->assertNotified('Nothing to export');

        $this->assertSame(0, DB::table('daily_pick_sets')->count());
    }

    #[Test]
    public function the_content_counts_break_the_editorial_table_down_by_kind(): void
    {
        /*
         * Since the fold every published page lives in one table, so a single
         * "412 editions" on each side can hide the fact that one environment
         * has no guides at all — which is precisely what you open this page to
         * find out.
         */
        $counts = Livewire::actingAs($this->admin())
            ->test(Migration::class)
            ->instance()
            ->contentCounts();

        foreach (CoveKind::cases() as $kind) {
            $this->assertArrayHasKey(Str::plural($kind->label()), $counts);
        }
    }
}
