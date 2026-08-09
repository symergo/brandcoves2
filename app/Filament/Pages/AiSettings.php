<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\AiUsage;
use App\Services\Ai\AiClient;
use App\Services\Settings\AiSettingsStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The AI switch, its credential, and the daily spend caps.
 *
 * ## Why this exists as a screen
 *
 * Everything here was an environment variable, which means every change is a
 * redeploy and the one person who can make it is whoever has Coolify open.
 * Turning generation off during an incident should not require a build.
 *
 * ## What it does not change
 *
 * **The invariant holds.** AI is still only ever called from a queued job — that
 * is enforced in `AiClient` and by an architecture test, and nothing on this page
 * touches it. Enabling AI here does not make a request able to spend money; it
 * makes the nightly jobs able to.
 *
 * ## The credential
 *
 * The stored key is never rendered. The field is always empty on load and an
 * empty submit means "leave it alone" — so a save that only changes a cap cannot
 * silently blank the key, and the key cannot end up in an HTML response, a
 * browser's form cache, or a screenshot of this page. What is shown instead is a
 * fingerprint: last four characters and a length, which answers the only question
 * anyone actually has.
 *
 * Stored encrypted with `APP_KEY`. Rotating that orphans it, and the fix is to
 * paste it again here.
 */
class AiSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'AI settings';

    protected static ?string $title = 'AI settings';

    protected string $view = 'filament.pages.ai-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $store = app(AiSettingsStore::class);

        $this->form->fill([
            'enabled' => (bool) config('brandcoves.ai.enabled'),
            'model' => (string) config('brandcoves.ai.model'),
            // Deliberately absent. See the class docblock.
            'api_key' => null,
            'default_daily_cap' => (int) config('brandcoves.ai.default_daily_cap'),
            'cap_daily_picks' => (int) config('brandcoves.ai.caps.daily_picks'),
            'cap_guide_copy' => (int) config('brandcoves.ai.caps.guide_copy'),
            'cap_gift_angles' => (int) config('brandcoves.ai.caps.gift_angles'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $store = app(AiSettingsStore::class);
        $fingerprint = $store->apiKeyFingerprint();

        return $schema
            ->components([
                Section::make('Generation')
                    ->description('With this off the whole site still works: Cove themes fall back to the curated rotation and guides to template copy. Nothing 500s, nothing goes blank.')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('AI generation enabled')
                            ->helperText($fingerprint === null
                                ? 'No API key is set, so this stays off whatever the switch says.'
                                : 'Turning this on lets the nightly jobs spend money, within the caps below.'),

                        Select::make('model')
                            ->options([
                                'claude-opus-5' => 'Opus 5 — best, most expensive',
                                'claude-sonnet-5' => 'Sonnet 5 — the default',
                                'claude-haiku-4-5-20251001' => 'Haiku 4.5 — cheapest, fastest',
                            ])
                            ->required()
                            ->helperText('Editorial copy is short and read by people, so Sonnet is the sensible default.'),
                    ])
                    ->columns(2),

                Section::make('API key')
                    ->description($fingerprint === null
                        ? 'Not set. Generation cannot run without one.'
                        : 'Currently '.$fingerprint.($store->isOverridden('api_key')
                            ? ' — set here, overriding the environment.'
                            : ' — from the environment.'))
                    ->schema([
                        TextInput::make('api_key')
                            ->label('Replace the key')
                            ->password()
                            ->revealable(false)
                            ->autocomplete('new-password')
                            ->placeholder('Leave empty to keep the current key')
                            // Never populated from storage, so the value cannot
                            // leave the server in a response.
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->helperText('Stored encrypted with APP_KEY. Rotating that key orphans this one — paste it again here if that happens.'),
                    ]),

                Section::make('Daily caps')
                    ->description('Calls per feature per day. The hard stop on spend: a runaway job hits the cap and logs, rather than billing until someone notices.')
                    ->schema([
                        TextInput::make('cap_daily_picks')
                            ->label('Daily Cove')
                            ->numeric()->minValue(0)->maxValue(10000)->required()
                            ->helperText('One theme plus seven blurbs per market per day, plus retries.'),

                        TextInput::make('cap_guide_copy')
                            ->label('Cove editorial')
                            ->numeric()->minValue(0)->maxValue(10000)->required(),

                        TextInput::make('cap_gift_angles')
                            ->label('Gift angles')
                            ->numeric()->minValue(0)->maxValue(10000)->required(),

                        TextInput::make('default_daily_cap')
                            ->label('Anything else')
                            ->numeric()->minValue(0)->maxValue(10000)->required()
                            ->helperText('Applies to a feature with no cap of its own — deliberately low, so a new caller is capped before anyone remembers to configure it.'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $values = [
            'enabled' => (bool) ($state['enabled'] ?? false),
            'model' => (string) $state['model'],
            'default_daily_cap' => (int) $state['default_daily_cap'],
            'cap_daily_picks' => (int) $state['cap_daily_picks'],
            'cap_guide_copy' => (int) $state['cap_guide_copy'],
            'cap_gift_angles' => (int) $state['cap_gift_angles'],
        ];

        // Only when something was typed. An empty field means "keep it", not
        // "clear it" — otherwise editing a cap would blank the credential.
        if (filled($state['api_key'] ?? null)) {
            $values['api_key'] = trim((string) $state['api_key']);
        }

        app(AiSettingsStore::class)->put($values);

        // So the fingerprint and the section descriptions reflect what was just
        // saved rather than what was loaded.
        $this->mount();

        Notification::make()
            ->title('Saved')
            ->body($values['enabled']
                ? 'Generation is on. The next scheduled job will use it.'
                : 'Generation is off. Themes and guides fall back to their curated copy.')
            ->success()
            ->send();
    }

    /**
     * Today's spend against the caps.
     *
     * On the same page as the caps, because a number you can change and a number
     * you cannot see are a bad pair.
     *
     * @return list<array{feature: string, used: int, cap: int}>
     */
    public function usageToday(): array
    {
        $rows = [];

        foreach (array_keys((array) config('brandcoves.ai.caps')) as $feature) {
            $cap = (int) (config("brandcoves.ai.caps.{$feature}") ?? config('brandcoves.ai.default_daily_cap'));

            $rows[] = [
                'feature' => $feature,
                'used' => (int) AiUsage::query()
                    ->where('feature_key', $feature)
                    ->whereDate('day', today())
                    ->sum('calls'),
                'cap' => $cap,
            ];
        }

        return $rows;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->keyBindings(['mod+s'])
                ->action('save'),

            /*
             * Proves the credential without generating anything.
             *
             * The alternative is finding out at 06:00 from a job that failed
             * silently — and "is the key right" is the only question this screen
             * cannot answer from stored state.
             */
            Action::make('test')
                ->label('Test the key')
                ->icon(Heroicon::OutlinedBolt)
                ->color('gray')
                ->action(function (): void {
                    $client = app(AiClient::class);

                    if (! $client->isEnabled()) {
                        Notification::make()
                            ->title('Not enabled')
                            ->body('Turn generation on and set a key first.')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        // A real call, and a trivially small one. It counts
                        // against the cap like anything else — a test that
                        // bypassed the cap would not be testing the thing that
                        // runs in production.
                        $client->json(
                            featureKey: 'gift_angles',
                            system: 'You reply with JSON and nothing else.',
                            prompt: 'Reply with {"ok":true}.',
                            schemaHint: ['ok' => 'boolean'],
                            maxTokens: 16,
                        );

                        Notification::make()->title('The key works')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('The call failed')
                            // The message, never the key — an exception from an
                            // HTTP client is exactly the kind of thing that
                            // echoes back what it was sent.
                            ->body(class_basename($e).': '.str_replace(
                                (string) config('brandcoves.ai.api_key'),
                                '[redacted]',
                                $e->getMessage(),
                            ))
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
