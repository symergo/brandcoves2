<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Content\ContentEnvelope;
use App\Services\Ops\ConfigReport;
use App\Services\Ops\DeployTrigger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

/**
 * Moving work between environments, from the admin rather than a shell.
 *
 * Three things that used to need SSH, Coolify credentials, or both:
 *
 * - **What is running here** — the build, the migration, and whether the config
 *   actually arrived.
 * - **Content transfer** — editorial out of one environment and into another.
 * - **Deploy** — redeploy this application from the tracked branch.
 *
 * ## Why a file rather than a direct push
 *
 * Content moves as a downloaded envelope that somebody uploads on the other
 * side. A one-click push would need this environment to hold a credential for
 * the other one and to be able to write to it over the network — a standing
 * capability, always live, so that the convenience exists on the day somebody
 * clicks it by mistake. A file passes through a person, and the dry run makes
 * that person read the drop list first.
 *
 * The commands remain the fast path for anyone with a shell; this exists so
 * having a shell is not a requirement. `ContentEnvelope` does the actual work in
 * both cases, so there is one set of rules rather than two.
 */
class Migration extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Migration';

    protected static ?string $title = 'Migration';

    protected string $view = 'filament.pages.migration';

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * The last dry run, kept on the component so the page can render it.
     *
     * @var array<string, array{created:int, updated:int, dropped:list<string>}>|null
     */
    public ?array $preview = null;

    public function mount(): void
    {
        $this->form->fill([
            'surfaces' => ContentEnvelope::SURFACES,
            'webhook' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $deploy = app(DeployTrigger::class);

        return $schema
            ->components([
                Section::make('Content transfer')
                    ->description('Editorial does not regenerate the way the catalogue does — a guide is AI-written, so having the far side write its own would spend the budget twice and produce different words. Product references travel as market plus identity key, because the integer ids differ per environment.')
                    ->schema([
                        CheckboxList::make('surfaces')
                            ->label('What to move')
                            ->options(array_combine(ContentEnvelope::SURFACES, [
                                'Feeds — registration only, never switched on',
                                'Copy bank',
                                'Guides and their items',
                                'Guide topics',
                                'Daily Cove editions and picks',
                                'Cove plans',
                            ]))
                            ->columns(2)
                            ->helperText('Nothing personal can be selected: the exporter works from an allowlist of surfaces, so a table added later is excluded by default rather than included by omission.'),

                        FileUpload::make('envelope')
                            ->label('Envelope to import')
                            ->acceptedFileTypes(['application/json', 'text/plain'])
                            ->storeFiles(false)
                            ->helperText('Exported from another environment. Checking it is a dry run — nothing is written until you press Apply.'),
                    ]),

                Section::make('Deploy')
                    ->description($deploy->isConfigured()
                        ? 'Redeploys this application from its tracked branch. It cannot choose a commit — that stays in Coolify, where the audit trail is.'
                        : 'No webhook set. Coolify → this application → Webhooks → Deploy Webhook.')
                    ->schema([
                        TextInput::make('webhook')
                            ->label('Coolify deploy webhook')
                            ->password()
                            ->revealable(false)
                            ->autocomplete('new-password')
                            ->placeholder($deploy->isConfigured() ? 'Set — leave empty to keep it' : 'https://coolify.example.com/api/v1/deploy?uuid=…')
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->helperText('A per-application webhook, deliberately not an API token: the worst this secret can do if it leaks is redeploy the current commit. Stored encrypted with APP_KEY.'),
                    ]),
            ])
            ->statePath('data');
    }

    // --- What is running here ------------------------------------------------

    /**
     * @return array<string, list<array{key: string, set: bool, required: bool, display: string, note: ?string}>>
     */
    public function configGroups(): array
    {
        return app(ConfigReport::class)->groups();
    }

    /** @return list<string> */
    public function configFailures(): array
    {
        return app(ConfigReport::class)->failures();
    }

    /** @return list<array{key: string, label: string, env: string, visible: bool}> */
    public function awinAccounts(): array
    {
        return app(ConfigReport::class)->awinAccounts();
    }

    /** @return array<string, string> */
    public function buildInfo(): array
    {
        $stamp = base_path('BUILD_STAMP');

        return [
            'environment' => app()->environment(),
            'branch' => (string) env('COOLIFY_BRANCH', 'local'),
            'built' => is_readable($stamp) ? trim((string) file_get_contents($stamp)) : 'dev',
            'migration' => (string) (DB::table('migrations')->orderByDesc('id')->value('migration') ?? 'none'),
        ];
    }

    /** @return array{at: string, ok: bool}|null */
    public function lastDeploy(): ?array
    {
        return app(DeployTrigger::class)->last();
    }

    /** What this environment holds, so the two sides can be compared before moving anything. */
    public function contentCounts(): array
    {
        return [
            'guides' => DB::table('guides')->count(),
            'guide items' => DB::table('guide_items')->count(),
            'topics' => DB::table('guide_topics')->count(),
            'editions' => DB::table('daily_pick_sets')->count(),
            'picks' => DB::table('daily_picks')->count(),
            'plans' => DB::table('cove_plans')->count(),
            'copy' => DB::table('copy_templates')->count(),
            'feeds' => DB::table('feeds')->count(),
            'products' => DB::table('product_groups')->count(),
        ];
    }

    // --- Actions -------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Download envelope')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action('export'),

            Action::make('check')
                ->label('Check upload')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray')
                ->action('check'),

            Action::make('apply')
                ->label('Apply upload')
                ->icon(Heroicon::OutlinedCheck)
                ->color('danger')
                // Only offered once a dry run has been read. The drop list is
                // the reason to run this at all, so writing before seeing it
                // would defeat the design.
                ->visible(fn (): bool => $this->preview !== null)
                ->requiresConfirmation()
                ->modalDescription('This writes the uploaded content into this environment. Re-running is safe — surfaces match on natural keys — but products this environment lacks stay dropped.')
                ->action('apply'),

            Action::make('deploy')
                ->label('Deploy')
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->color('warning')
                ->visible(fn (): bool => app(DeployTrigger::class)->isConfigured())
                ->requiresConfirmation()
                ->modalDescription('Redeploys this application from its tracked branch, whatever that branch currently points at.')
                ->action('deploy'),

            Action::make('saveWebhook')
                ->label('Save webhook')
                ->color('gray')
                ->action('saveWebhook'),
        ];
    }

    public function export(): ?StreamedResponse
    {
        $surfaces = array_values((array) ($this->form->getState()['surfaces'] ?? []));

        if ($surfaces === []) {
            Notification::make()->title('Pick at least one surface')->warning()->send();

            return null;
        }

        try {
            $payload = app(ContentEnvelope::class)->export($surfaces);
        } catch (Throwable $e) {
            Notification::make()->title('Export failed')->body($e->getMessage())->danger()->send();

            return null;
        }

        $json = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $name = 'giftcoves-content-'.app()->environment().'-'.now()->format('Y-m-d-His').'.json';

        return response()->streamDownload(fn () => print ($json), $name, [
            'Content-Type' => 'application/json',
        ]);
    }

    public function check(): void
    {
        $this->runImport(dryRun: true);
    }

    public function apply(): void
    {
        $this->runImport(dryRun: false);
    }

    private function runImport(bool $dryRun): void
    {
        $state = $this->form->getState();
        $upload = $state['envelope'] ?? null;

        if (blank($upload)) {
            Notification::make()->title('No envelope uploaded')->warning()->send();

            return;
        }

        $file = is_array($upload) ? reset($upload) : $upload;
        $raw = is_object($file) && method_exists($file, 'get') ? (string) $file->get() : null;

        if ($raw === null) {
            Notification::make()->title('Could not read the upload')->danger()->send();

            return;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            Notification::make()
                ->title('Not a valid envelope')
                ->body('The file is not JSON: '.json_last_error_msg())
                ->danger()
                ->send();

            return;
        }

        $surfaces = array_values(array_intersect(
            array_values((array) ($state['surfaces'] ?? [])),
            array_keys((array) ($decoded['surfaces'] ?? [])),
        ));

        if ($surfaces === []) {
            Notification::make()
                ->title('Nothing to do')
                ->body('The envelope holds none of the surfaces selected above.')
                ->warning()
                ->send();

            return;
        }

        try {
            $this->preview = app(ContentEnvelope::class)->import($decoded, $surfaces, $dryRun);
        } catch (Throwable $e) {
            Notification::make()->title('Import failed')->body($e->getMessage())->danger()->send();

            return;
        }

        $dropped = array_sum(array_map(fn (array $r) => count($r['dropped']), $this->preview));

        Notification::make()
            ->title($dryRun ? 'Dry run complete — nothing written' : 'Applied')
            ->body($dropped === 0
                ? 'Every product reference resolved in this environment.'
                : $dropped.' reference(s) had no product here and were dropped. The list is below.')
            ->{$dryRun ? 'success' : 'success'}()
            ->send();
    }

    public function deploy(): void
    {
        $result = app(DeployTrigger::class)->trigger();

        Notification::make()
            ->title($result['ok'] ? 'Deployment queued' : 'Deploy failed')
            ->body($result['message'])
            ->{$result['ok'] ? 'success' : 'danger'}()
            ->send();
    }

    public function saveWebhook(): void
    {
        $url = $this->form->getState()['webhook'] ?? null;

        if (blank($url)) {
            Notification::make()
                ->title('Nothing to save')
                ->body('The field is empty, which means keep the current webhook.')
                ->warning()
                ->send();

            return;
        }

        app(DeployTrigger::class)->setWebhook((string) $url);

        $this->mount();

        Notification::make()->title('Webhook saved')->success()->send();
    }
}
