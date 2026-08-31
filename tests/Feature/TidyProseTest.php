<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `bc:tidy-prose`, which brings the archive into house style.
 *
 * Everything written from now on is already right - HouseStyle runs at the
 * write. This is the pass over what was published before the rule existed, and
 * the three properties worth holding are that it does nothing without `--write`,
 * that it settles (a second run finds nothing), and that it tells `**bold**` in
 * a rendered field apart from `**bold**` in a field printed as text.
 */
class TidyProseTest extends TestCase
{
    use RefreshDatabase;

    private function cove(CoveKind $kind, string $blurb, string $body): DailyPickSet
    {
        return DailyPickSet::create([
            'market' => Market::BeNl->value,
            'kind' => $kind->value,
            'slug' => 'a-cove-'.$kind->value,
            'theme_slug' => 'a-cove-'.$kind->value,
            'drop_date' => $kind === CoveKind::Daily ? '2026-08-01' : null,
            'theme_title' => 'A **loud** title',
            'theme_blurb' => $blurb,
            'body' => $body,
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
        ]);
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $cove = $this->cove(CoveKind::Guide, 'An intro — with a dash.', 'A body — with another.');

        $this->artisan('bc:tidy-prose')->assertSuccessful();

        $this->assertSame('A body — with another.', $cove->fresh()->body);
    }

    #[Test]
    public function it_replaces_em_dashes_with_a_spaced_hyphen(): void
    {
        $cove = $this->cove(CoveKind::Guide, 'An intro — with a dash.', 'A body — with another.');

        $this->artisan('bc:tidy-prose --write')->assertSuccessful();

        $fresh = $cove->fresh();

        $this->assertSame('An intro - with a dash.', $fresh->theme_blurb);
        $this->assertSame('A body - with another.', $fresh->body);
    }

    /**
     * The distinction the whole command turns on.
     *
     * `body` is rendered by CoveMarkup, so its emphasis becomes `<strong>` and
     * must survive. `theme_title` is printed as a React text node, so the
     * asterisks would reach the reader and have to come off.
     */
    #[Test]
    public function bold_survives_where_it_renders_and_is_stripped_where_it_cannot(): void
    {
        $cove = $this->cove(CoveKind::Guide, 'An **emphatic** intro.', 'A **bold** claim.');

        $this->artisan('bc:tidy-prose --write')->assertSuccessful();

        $fresh = $cove->fresh();

        $this->assertSame('A **bold** claim.', $fresh->body);
        // A guide's blurb is its opening paragraph, and GuideController renders it.
        $this->assertSame('An **emphatic** intro.', $fresh->theme_blurb);
        $this->assertSame('A loud title', $fresh->theme_title);
    }

    /**
     * The same column, the other way round.
     *
     * `theme_blurb` is a guide's intro paragraph and a Daily's standfirst,
     * because the fold gave both kinds one table. Only the article kinds render
     * it, so on a Daily the asterisks would show.
     */
    #[Test]
    public function a_daily_blurb_is_text_and_loses_its_asterisks(): void
    {
        $daily = $this->cove(CoveKind::Daily, 'A **loud** standfirst.', 'Ignored.');

        $this->artisan('bc:tidy-prose --write')->assertSuccessful();

        $this->assertSame('A loud standfirst.', $daily->fresh()->theme_blurb);
    }

    #[Test]
    public function an_faq_splits_its_question_from_its_answer(): void
    {
        $cove = $this->cove(CoveKind::Advice, 'Intro.', 'Body.');
        $cove->update(['faq' => [['q' => 'Is it **really** so?', 'a' => 'Yes — **always**.']]]);

        $this->artisan('bc:tidy-prose --write')->assertSuccessful();

        // assertEquals, not assertSame: Postgres hands `jsonb` back with its
        // own key order, and the order of `q` and `a` is not the assertion.
        $this->assertEquals(
            [['q' => 'Is it really so?', 'a' => 'Yes - **always**.']],
            $cove->fresh()->faq,
        );

        // And a second pass must leave it exactly as it is, which is the case
        // a rebuilt pair would break by re-ordering its own keys.
        $this->artisan('bc:tidy-prose --write')
            ->expectsOutputToContain('Nothing to tidy')
            ->assertSuccessful();
    }

    /** The plan is what a rebuild reads, so tidying only the edition would not last. */
    #[Test]
    public function it_tidies_the_plan_a_cove_is_rebuilt_from(): void
    {
        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Daily->value,
            'drop_date' => '2026-08-02',
            'title' => 'A plan',
            'editorial' => 'Written — by somebody.',
            'status' => 'approved',
        ]);

        $this->artisan('bc:tidy-prose --write')->assertSuccessful();

        $this->assertSame('Written - by somebody.', $plan->fresh()->editorial);
    }

    /** A second pass must find nothing, or the command never settles. */
    #[Test]
    public function it_is_idempotent(): void
    {
        $cove = $this->cove(CoveKind::Guide, 'An intro — here.', 'A body — here.');

        $this->artisan('bc:tidy-prose --write')->assertSuccessful();

        $touched = $cove->fresh()->updated_at;

        $this->artisan('bc:tidy-prose --write')
            ->expectsOutputToContain('Nothing to tidy')
            ->assertSuccessful();

        // Not merely "no visible change": an UPDATE that rewrote the same text
        // would still move updated_at, which is what the admin table sorts by.
        $this->assertEquals($touched, $cove->fresh()->updated_at);
    }

    #[Test]
    public function an_unknown_market_is_refused(): void
    {
        $this->artisan('bc:tidy-prose --market=zz --write')->assertFailed();
    }
}
