<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Services\Cove\ObservanceCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Cove calendar.
 *
 * Daily Coves only. A themed Cove is evergreen — it earns traffic over years,
 * and dating it to one day in April would make it read as stale for the other
 * 364.
 */
class ObservanceTest extends TestCase
{
    private function calendar(): ObservanceCalendar
    {
        return app(ObservanceCalendar::class);
    }

    #[Test]
    public function a_fixed_date_is_found(): void
    {
        $observance = $this->calendar()->on(CarbonImmutable::parse('2026-04-11'), Market::BeNl);

        $this->assertNotNull($observance);
        $this->assertSame('pets', $observance->key);
        $this->assertContains('hondenmand', $observance->queries);
    }

    #[Test]
    public function it_recurs_every_year(): void
    {
        // Keyed on MM-DD, so the calendar does not need maintaining annually.
        foreach (['2026-06-03', '2027-06-03', '2031-06-03'] as $date) {
            $this->assertSame(
                'bicycle',
                $this->calendar()->on(CarbonImmutable::parse($date), Market::BeNl)?->key,
            );
        }
    }

    #[Test]
    public function an_ordinary_day_has_no_observance(): void
    {
        // Most days are ordinary, and the edition falls back to a theme.
        $this->assertNull($this->calendar()->on(CarbonImmutable::parse('2026-07-14'), Market::BeNl));
    }

    #[Test]
    public function a_moving_date_is_resolved_for_the_year(): void
    {
        /*
         * Mother's Day is the second Sunday of May, which is a different
         * calendar date every year. Treating it as fixed is the classic way to
         * be a day wrong, once a year, in public.
         */
        $this->assertSame(
            'mothers_day',
            $this->calendar()->on(CarbonImmutable::parse('2026-05-10'), Market::NlNl)?->key,
        );

        // 2027's second Sunday is the 9th, not the 10th.
        $this->assertNull($this->calendar()->on(CarbonImmutable::parse('2027-05-10'), Market::NlNl));
        $this->assertSame(
            'mothers_day',
            $this->calendar()->on(CarbonImmutable::parse('2027-05-09'), Market::NlNl)?->key,
        );
    }

    #[Test]
    public function an_observance_only_applies_where_it_means_something(): void
    {
        $sinterklaas = CarbonImmutable::parse('2026-12-05');

        // Sinterklaas is a Dutch and Belgian event and nothing at all in Spain.
        // Pretending otherwise is the kind of detail that makes a site feel
        // machine-made.
        $this->assertSame('sinterklaas', $this->calendar()->on($sinterklaas, Market::NlNl)?->key);
        $this->assertNull($this->calendar()->on($sinterklaas, Market::Es));
    }

    #[Test]
    public function the_title_follows_the_market_language(): void
    {
        $observance = $this->calendar()->on(CarbonImmutable::parse('2026-04-11'), Market::BeFr);

        $this->assertNotNull($observance);
        // One calendar, five markets: the copy is a translation key.
        $this->assertSame('Pour l’animal qui dirige la maison', $observance->title(Market::BeFr));
        $this->assertSame('Voor het dier dat je huis bestuurt', $observance->title(Market::BeNl));
    }

    #[Test]
    public function a_missing_blurb_is_null_rather_than_a_dotted_key(): void
    {
        $observance = $this->calendar()->on(CarbonImmutable::parse('2026-09-05'), Market::En);

        $this->assertNotNull($observance);
        // 'coffee' has a title and deliberately no blurb.
        $this->assertNull($observance->blurb(Market::En));
    }

    #[Test]
    public function upcoming_observances_can_be_listed(): void
    {
        $upcoming = $this->calendar()->upcoming(CarbonImmutable::parse('2026-04-01'), Market::BeNl, 30);

        $this->assertArrayHasKey('2026-04-11', $upcoming);
        $this->assertArrayHasKey('2026-04-22', $upcoming);
    }
}
