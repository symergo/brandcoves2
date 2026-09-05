<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\GuideTopic;
use App\Models\User;
use App\Services\Cove\PlanDrafter;
use App\Services\Cove\SeasonalSeries;
use App\Services\Cove\YearCalendar;
use App\Services\Guides\SeasonalTopics;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * The editorial year, as a year.
 *
 * Everything the site knows about what is coming lived in three config files and
 * appeared on no screen. The planner lists what has already been drafted, which
 * answers "what are we publishing" and not "what is coming and have we done
 * anything about it" — and the second question is the one somebody sits down
 * with in September to think about Christmas.
 *
 * So this draws the whole year for one market: season windows month by month,
 * named days marked on their dates, and against each of them what is planned. It
 * recurs, because the calendar does — every entry is `MM-DD` and the moving
 * observances are computed per year, so 2029 is already drawn and the planner
 * will fill it in as the horizon reaches it.
 *
 * ## It writes, unlike the other read-only panels
 *
 * `MarketTrends` is deliberately read-only because its buttons would spend a
 * rate-limited API budget. Nothing here does: laying a season out and drafting a
 * day both read rows and write rows, cost nothing, and produce **drafts** that a
 * person still has to curate and approve. Being able to act on the thing you are
 * looking at is the entire difference between a calendar and a wall chart.
 *
 * Nothing here publishes. That stays where it was — an editor approves a plan,
 * and `PublishDueCoves` honours it on the day.
 */
class CoveCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Cove calendar';

    protected static ?string $title = 'Cove calendar';

    /** Above the planner: the year comes before the rows drafted out of it. */
    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.pages.cove-calendar';

    public string $market = '';

    public int $year = 0;

    public function mount(): void
    {
        $this->market = Market::default()->value;
        $this->year = CarbonImmutable::today()->year;
    }

    public function selected(): Market
    {
        return Market::tryFrom($this->market) ?? Market::default();
    }

    /**
     * Which years the switcher offers.
     *
     * This year and the next two. The planner draws 120 days ahead, so the year
     * after next is always further out than anything that has been drafted —
     * which is the point of showing it: the calendar is the same every year, and
     * being able to look at 2029 is what makes it a recurring calendar rather
     * than a report on this one.
     *
     * Last year is offered too, because "what did we do about Halloween" is a
     * question worth answering before deciding what to do about the next one.
     *
     * @return list<int>
     */
    public function years(): array
    {
        $now = CarbonImmutable::today()->year;

        return [$now - 1, $now, $now + 1, $now + 2];
    }

    /** @return list<array<string, mixed>> */
    public function months(): array
    {
        return app(YearCalendar::class)->for($this->selected(), $this->year);
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        return app(YearCalendar::class)->summary($this->selected(), $this->year);
    }

    /**
     * Lay a season out, or bring it round for the window it is heading for.
     *
     * One button for both because they are one editorial event a year apart, and
     * `SeasonalSeries::plan()` is the one place that knows which applies. The
     * topics are seeded first: on an environment where the nightly pass has not
     * run, the row this button needs does not exist yet, and "nothing happened"
     * would be the least useful possible answer.
     */
    public function planSeason(string $topic): void
    {
        $market = $this->selected();

        app(SeasonalTopics::class)->seed($market);

        $row = GuideTopic::query()
            ->where('market', $market->value)
            ->where('origin', 'seasonal')
            ->where('topic', $topic)
            ->first();

        if ($row === null) {
            Notification::make()
                ->title('That season is not in this market')
                ->body('It is limited to other markets in config/cove_seasons.php.')
                ->warning()
                ->send();

            return;
        }

        $touched = app(SeasonalSeries::class)->plan($row, $this->author());

        if ($touched === []) {
            /*
             * Two different reasons and they need different sentences. A season
             * already scheduled for the window it is heading for is the healthy
             * steady state; one the catalogue cannot fill is a fact about supply
             * that no amount of pressing this button will change.
             */
            Notification::make()
                ->title($row->plan_id === null ? 'Nothing to plan yet' : 'Already scheduled')
                ->body($row->plan_id === null
                    ? 'The catalogue in '.$market->value.' cannot fill a single part of this season yet. '
                        .'It stays in the queue and will be offered again as stock changes.'
                    : 'Its parts are already dated inside the window they are heading for. '
                        .'Nothing needed changing.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title(count($touched).' part(s) scheduled')
            ->body('Drafts, not publications. Curate each one in the Cove planner, then approve it — '
                .'an approved part goes live on the day it is due.')
            ->success()
            ->send();
    }

    /**
     * Draft the Daily Cove for one named day.
     *
     * Narrow on purpose: `bc:plan-coves` fills every day in a 120-day window,
     * and this fills exactly the one somebody clicked. A person looking at
     * "14 February — nothing planned" wants that day, not four months of them.
     */
    public function planDay(string $date): void
    {
        $market = $this->selected();
        $day = CarbonImmutable::parse($date);

        $result = app(PlanDrafter::class)->draftOn(CoveKind::Daily, $market, $day, $this->author());

        if ($result === null) {
            Notification::make()
                ->title('Nothing drafted')
                ->body('Something is already planned for '.$day->translatedFormat('j F Y').' in '
                    .$market->value.', or the calendar has no theme for it.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Drafted with '.$result->items()->count().' suggested product(s)')
            ->body('Curate it in the Cove planner, then approve.')
            ->success()
            ->send();
    }

    private function author(): ?User
    {
        return Auth::user() instanceof User ? Auth::user() : null;
    }
}
