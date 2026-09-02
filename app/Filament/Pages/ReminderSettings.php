<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Notification;
use App\Services\Settings\ReminderSettingsStore;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * When an occasion reminder fires, and whether it also goes by email.
 *
 * ## Why this is a screen
 *
 * The windows were `const LEAD_DAYS = [14, 3]` on the job, so changing them was
 * a deploy — for a pair of numbers whose right value is a judgement about how
 * people shop rather than a fact about the code. That is exactly the sort of
 * thing you want to try, look at a month later, and change again without
 * booking a developer.
 *
 * ## What it cannot do
 *
 * It cannot decide *who* is reminded. The people who most need a nudge are
 * whoever claimed something off a list, and they are unreachable by design: a
 * claim is stored as a one-way hash so the list cannot say who made it
 * (invariant #4). No setting can undo that, and one that appeared to would be
 * worse than none.
 *
 * Email off leaves the in-app reminder standing. The notification row is written
 * either way and is the record; the toggle only decides whether it also travels.
 */
class ReminderSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Reminders';

    protected static ?string $title = 'Occasion reminders';

    protected string $view = 'filament.pages.reminder-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'lead_days' => ReminderSettingsStore::format(
                (array) config('giftcoves.reminders.lead_days', []),
            ),
            'email' => (bool) config('giftcoves.reminders.email', true),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $store = app(ReminderSettingsStore::class);

        return $schema
            ->components([
                Section::make('When they fire')
                    ->description('Days before the date. A single lead time has to be either too early to be useful or too late to be actionable, which is why there are several.')
                    ->schema([
                        TextInput::make('lead_days')
                            ->label('Days before')
                            ->required()
                            ->placeholder('30, 15, 2')
                            ->helperText(
                                'Comma separated, at most '.ReminderSettingsStore::MAX_LEADS.'. '
                                .'Sorted and de-duplicated on save. '
                                .($store->isOverridden('lead_days')
                                    ? 'Currently set here.'
                                    : 'Currently the shipped default.')
                            )
                            /*
                             * Validated as it is typed, with the same parser the
                             * job uses. A field that accepts "thirty" and then
                             * silently stores nothing is how a reminder stops
                             * firing without anybody being told.
                             */
                            ->rule(function () {
                                return function (string $attribute, mixed $value, callable $fail): void {
                                    if (ReminderSettingsStore::parseDays((string) $value) === []) {
                                        $fail('Give at least one number of days between 1 and 365.');
                                    }
                                };
                            }),
                    ]),

                Section::make('How they arrive')
                    ->description('The in-app inbox is read by somebody who came back to the site, and the whole premise of a reminder is that they have not.')
                    ->schema([
                        Toggle::make('email')
                            ->label('Also send by email')
                            ->helperText('The reminder is written to the inbox either way. This only decides whether it also travels — and it carries no list contents, only a date and a link.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $days = ReminderSettingsStore::parseDays((string) $state['lead_days']);

        app(ReminderSettingsStore::class)->put([
            // Stored in the cleaned form, so the field shows on reload exactly
            // what the job will read — not what was typed.
            'lead_days' => ReminderSettingsStore::format($days),
            'email' => (bool) ($state['email'] ?? false),
        ]);

        /*
         * Written into the running config as well as stored.
         *
         * The overlay is applied at boot, so without this the page would
         * re-render from the value this request booted with and appear not to
         * have saved.
         */
        config(['giftcoves.reminders.lead_days' => $days]);
        config(['giftcoves.reminders.email' => (bool) ($state['email'] ?? false)]);

        $this->mount();

        FilamentNotification::make()
            ->title('Saved')
            ->body('Reminders fire '.ReminderSettingsStore::format($days).' days before a date. The next scheduled run uses this.')
            ->success()
            ->send();
    }

    /**
     * What the current windows would catch, for the view.
     *
     * A settings screen with no evidence on it is a screen you change and then
     * go and look somewhere else to see whether it did anything. This is the
     * cheap version of that evidence: the reminders actually written in the last
     * week, by kind.
     *
     * @return list<array{kind: string, sent: int}>
     */
    public function recent(): array
    {
        return Notification::query()
            ->where('created_at', '>=', CarbonImmutable::now()->subWeek())
            ->whereIn('kind', ['occasion.birthday', 'occasion.exchange', 'occasion.list'])
            ->selectRaw('kind, count(*) as sent')
            ->groupBy('kind')
            ->orderBy('kind')
            ->get()
            ->map(fn ($row): array => ['kind' => (string) $row->kind, 'sent' => (int) $row->sent])
            ->all();
    }

    /** @return list<int> */
    public function windows(): array
    {
        return array_map('intval', (array) config('giftcoves.reminders.lead_days', []));
    }
}
