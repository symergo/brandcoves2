<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\ApiToken;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a Daily Cove tells a search engine (owner, 2026-11-24: "the titles of the daily
 * coves, they don't look very descriptive", then "Do all"): a search phrase in front of
 * the theme in the <title>, a written description, and a repeated theme pointing at its
 * first edition.
 */
class DailySeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['giftcoves.robots_allow' => true]);
    }

    #[Test]
    public function a_daily_with_a_search_phrase_and_a_description_shows_them_to_search_engines(): void
    {
        $this->edition('sur-deux-roues', 'Sur deux roues', '2026-11-23', Market::BeFr, [
            'focus_keyphrase' => 'accessoires vélo à offrir',
            'meta_description' => 'Panier, support de compteur et sacoche: des accessoires vélo à offrir.',
            'theme_blurb' => 'Mostly the things attached to it.',
        ]);

        $response = $this->get('/be-fr/tips/sur-deux-roues')->assertOk();
        $html = (string) $response->getContent();

        // The <title> is set from the page data (`seoTitle`) when the page renders.
        $this->assertSame('Accessoires vélo à offrir : Sur deux roues', $response->viewData('page')['props']['edition']['seoTitle']);
        $this->assertStringContainsString('<meta name="description" content="Panier, support de compteur et sacoche: des accessoires vélo à offrir."', $html);
        // The heading keeps the editorial name.
        $this->assertStringContainsString('Sur deux roues', $html);
    }

    #[Test]
    public function without_them_it_reads_as_before(): void
    {
        $this->edition('in-de-auto', 'In de auto', '2026-11-22', Market::BeNl, ['theme_blurb' => 'De helft waar niemand aan denkt.']);

        $response = $this->get('/be-nl/tips/in-de-auto')->assertOk();
        $html = (string) $response->getContent();

        $this->assertSame('In de auto — cadeautips', $response->viewData('page')['props']['edition']['seoTitle']);
        $this->assertStringContainsString('<meta name="description" content="De helft waar niemand aan denkt."', $html);
    }

    #[Test]
    public function a_repeated_theme_names_its_first_edition_as_canonical(): void
    {
        $this->edition('naar-binnen', 'Naar binnen', '2026-11-01', Market::BeNl);
        $this->edition('naar-binnen-2', 'Naar binnen', '2026-11-03', Market::BeNl);
        $this->edition('in-de-auto', 'In de auto', '2026-11-04', Market::BeNl);

        $this->assertStringContainsString('<link rel="canonical" href="'.url('/be-nl/tips/naar-binnen').'"',
            (string) $this->get('/be-nl/tips/naar-binnen-2')->assertOk()->getContent());
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/be-nl/tips/naar-binnen').'"',
            (string) $this->get('/be-nl/tips/naar-binnen')->assertOk()->getContent());
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/be-nl/tips/in-de-auto').'"',
            (string) $this->get('/be-nl/tips/in-de-auto')->assertOk()->getContent());
    }

    #[Test]
    public function the_api_sets_them_on_a_daily_and_the_live_page_has_them_at_once(): void
    {
        $edition = $this->edition('sur-deux-roues', 'Sur deux roues', '2026-11-23', Market::BeFr);
        $plan = CovePlan::create([
            'market' => Market::BeFr->value,
            'kind' => CoveKind::Daily->value,
            'drop_date' => '2026-11-23',
            'title' => 'Sur deux roues',
            'status' => 'approved',
            'edition_id' => $edition->id,
        ]);

        $this->withToken(ApiToken::issue('claude', [ApiToken::WRITE, ApiToken::PUBLISH])['token'])
            ->patchJson("/api/editorial/coves/{$plan->id}", [
                'focusKeyphrase' => 'accessoires vélo à offrir',
                'metaDescription' => 'Panier, support de compteur et sacoche — des accessoires vélo à offrir.',
                'blurb' => 'Surtout ce qui s’y accroche.',
            ])
            ->assertOk();

        $edition->refresh();
        $this->assertSame('accessoires vélo à offrir', $edition->focus_keyphrase);
        // House style at the write: no em dash.
        $this->assertStringNotContainsString('—', (string) $edition->meta_description);
        $this->assertSame('accessoires vélo à offrir', $plan->refresh()->focus_keyphrase);
        // The blurb reaches the live page too: it was in English on a be-fr edition.
        $this->assertSame('Surtout ce qui s’y accroche.', $edition->theme_blurb);
    }

    #[Test]
    public function a_new_daily_takes_them_and_its_build_carries_them_to_the_page(): void
    {
        $this->withToken(ApiToken::issue('claude', [ApiToken::WRITE, ApiToken::READ])['token'])
            ->postJson('/api/editorial/coves', [
                'market' => Market::BeNl->value,
                'kind' => CoveKind::Daily->value,
                'date' => now()->addDays(3)->toDateString(),
                'title' => 'Op twee wielen',
                'focusKeyphrase' => 'cadeau voor fietser',
                'metaDescription' => 'Een fietsmand, een houder voor de fietscomputer en een dubbele fietstas.',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.focusKeyphrase', 'cadeau voor fietser');

        $plan = CovePlan::query()->firstOrFail();
        $this->assertSame('Een fietsmand, een houder voor de fietscomputer en een dubbele fietstas.', $plan->meta_description);

        // Still refused: a Daily is no article.
        $this->withToken(ApiToken::issue('claude2', [ApiToken::WRITE])['token'])
            ->postJson('/api/editorial/coves', [
                'market' => Market::BeNl->value,
                'kind' => CoveKind::Daily->value,
                'date' => now()->addDays(4)->toDateString(),
                'title' => 'Iets anders',
                'searchQuestions' => ['Wat geef je een fietser?'],
            ])
            ->assertStatus(422);
    }

    /** @param array<string, mixed> $extra */
    private function edition(string $slug, string $title, string $date, Market $market, array $extra = []): DailyPickSet
    {
        return DailyPickSet::create([
            'market' => $market->value,
            'kind' => CoveKind::Daily->value,
            'drop_date' => $date,
            'slug' => $slug,
            'theme_title' => $title,
            'theme_slug' => 'theme-'.$slug,
            'theme_source' => 'theme',
            'status' => PublishStatus::Published->value,
            'published_at' => CarbonImmutable::parse($date)->setTime(6, 0),
            ...$extra,
        ]);
    }
}
