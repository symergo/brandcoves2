<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\CoveKind;
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
use Illuminate\Support\Str;
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
 *
 * ## Why the buttons live in the sections
 *
 * They used to be five header actions in a row — Download, Check, Apply, Deploy,
 * Save webhook — above two unrelated sections, with nothing to say which button
 * belonged to which. "Download envelope" acts on the surface checkboxes fifty
 * pixels further down the page and read as a page-level operation; "Deploy" sat
 * next to it and is the one irreversible thing here. Each section now carries
 * its own actions, in the order you would do them.
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

        /*
         * Registered as well as placed in the footer.
         *
         * `footerActions()` only decides where an action is *drawn*;
         * `getActions()` is what resolves one by name when it is clicked. An
         * action that is only in the footer renders fine and then cannot mount
         * its confirmation modal — which would have shipped as "Apply upload
         * does nothing", the single worst button on this page to break.
         */
        $transfer = $this->transferActions();
        $deployment = $this->deployActions();

        return $schema
            ->components([
                Section::make('Content transfer')
                    // Keyed so its actions are addressable — by Filament, and
                    // by the tests that assert Apply stays hidden.
                    ->key('transfer')
                    ->description('Editorial does not regenerate the way the catalogue does — a Cove is AI-written, so having the far side write its own would spend the budget twice and produce different words. Product references travel as market plus identity key, because the integer ids differ per environment.')
                    ->schema([
                        CheckboxList::make('surfaces')
                            ->label('What to move')
                            ->options(self::surfaceLabels())
                            ->columns(2)
                            ->live()
                            // Changing the selection invalidates the dry run
                            // that is on screen. See $preview.
                            ->afterStateUpdated(fn () => $this->preview = null)
                            ->helperText('Nothing personal can be selected: the exporter works from an allowlist of surfaces, so a table added later is excluded by default rather than included by omission.'),

                        FileUpload::make('envelope')
                            ->label('Envelope to import')
                            ->acceptedFileTypes(['application/json', 'text/plain'])
                            ->storeFiles(false)
                            ->live()
                            ->afterStateUpdated(fn () => $this->preview = null)
                            ->helperText('Exported from another environment. Checking it is a dry run — nothing is written until you press Apply.'),
                    ])
                    ->registerActions($transfer)
                    ->footerActions($transfer),

                Section::make('Deploy')
                    ->key('deployment')
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
                    ])
                    ->registerActions($deployment)
                    ->footerActions($deployment),
            ])
            ->statePath('data');
    }

    /**
     * Export, then check, then apply — the order you actually do them in.
     *
     * @return list<Action>
     */
    private function transferActions(): array
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
                /*
                 * Only once a dry run has been read, and only while it still
                 * describes what is on screen — changing the file or the
                 * surfaces clears it. The drop list is the reason to run an
                 * import at all, so applying one that nobody has previewed
                 * defeats the design of the page.
                 */
                ->visible(fn (): bool => $this->preview !== null)
                ->requiresConfirmation()
                ->modalDescription('This writes the uploaded content into this environment. Re-running is safe — surfaces match on natural keys — but products this environment lacks stay dropped.')
                ->action('apply'),
        ];
    }

    /** @return list<Action> */
    private function deployActions(): array
    {
        return [
            Action::make('saveWebhook')
                ->label('Save webhook')
                ->color('gray')
                ->action('saveWebhook'),

            Action::make('deploy')
                ->label('Deploy')
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->color('warning')
                // A button that cannot work is worse than no button: it invites
                // a click and then explains itself in a toast.
                ->visible(fn (): bool => app(DeployTrigger::class)->isConfigured())
                ->requiresConfirmation()
                ->modalDescription('Redeploys this application from its tracked branch, whatever that branch currently points at.')
                ->action('deploy'),
        ];
    }

    /**
     * The surfaces an envelope can carry, labelled.
     *
     * Keyed rather than `array_combine(SURFACES, [...])`, which paired a list of
     * labels against a list of keys *by position* — so adding a surface anywhere
     * but the end silently relabelled every one after it, and the page would
     * have gone on looking correct while offering to move the wrong thing.
     *
     * A surface with no label here shows its own key rather than disappearing:
     * an unlabelled checkbox is a small problem, an uncheckable surface is a
     * feature that silently stopped existing.
     *
     * @return array<string, string>
     */
    private static function surfaceLabels(): array
    {
        $known = [
            'feeds' => 'Feeds — registration only, never switched on',
            'copy' => 'Copy bank',
            'guides' => 'Guides and their items',
            'topics' => 'Cove topics',
            'editions' => 'Coves and their picks — Daily, personas, guides',
            'plans' => 'Cove plans and their curated shortlists',
        ];

        return collect(ContentEnvelope::SURFACES)
            ->mapWithKeys(fn (string $s) => [$s => $known[$s] ?? $s])
            ->all();
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

    /**
     * What this environment holds, so the two sides can be compared before
     * moving anything.
     *
     * Broken out per kind rather than as one "editions" total. Since the fold,
     * every published page — a Daily, a persona, a buying guide, a seasonal one,
     * an advice article — is a row in the same table, and "412 editions" on both
     * sides can hide the fact that one of them has no guides at all.
     *
     * @return array<string, int>
     */
    public function contentCounts(): array
    {
        $byKind = DB::table('daily_pick_sets')
            ->selectRaw('kind, count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        $counts = [];

        foreach (CoveKind::cases() as $kind) {
            // Every kind is listed even at zero: a missing row reads as "not
            // measured", and a zero is the thing you are looking for.
            $counts[Str::plural($kind->label())] = (int) ($byKind[$kind->value] ?? 0);
        }

        return [
            ...$counts,
            'picks' => DB::table('daily_picks')->count(),
            'plans' => DB::table('cove_plans')->count(),
            'curated products' => DB::table('cove_plan_items')->count(),
            'topics' => DB::table('guide_topics')->count(),
            'copy' => DB::table('copy_templates')->count(),
            'feeds' => DB::table('feeds')->count(),
            'products' => DB::table('product_groups')->count(),
        ];
    }

    // --- Actions -------------------------------------------------------------

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

        /*
         * An empty surface is worth saying out loud.
         *
         * Exporting a fresh environment produces a valid envelope containing
         * nothing, which downloads and imports without complaint and leaves the
         * far side exactly as it was. The only symptom is somebody concluding
         * the import is broken.
         */
        $rows = collect((array) ($payload['surfaces'] ?? []))->map(
            fn ($rows) => is_countable($rows) ? count($rows) : 0
        );

        if ($rows->sum() === 0) {
            Notification::make()
                ->title('Nothing to export')
                ->body('The selected surfaces are empty in this environment.')
                ->warning()
                ->send();

            return null;
        }

        Notification::make()
            ->title('Envelope ready — '.$rows->sum().' row(s)')
            ->body($rows->map(fn (int $n, string $s) => "{$s}: {$n}")->implode(' · '))
            ->success()
            ->send();

        // The environment is in the filename because the mistake this prevents
        // is importing staging's editorial into production from a downloads
        // folder holding four of these.
        $name = 'giftcoves-content-'.app()->environment().'-'.now()->format('Y-m-d-His').'.json';

        return response()->streamDownload(fn () => print $json, $name, [
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
        $written = array_sum(array_map(fn (array $r) => $r['created'] + $r['updated'], $this->preview));

        /*
         * A dry run that drops references is a warning, not a success.
         *
         * It used to send `success` either way — the ternary picking between
         * 'success' and 'success' — so the one outcome this screen exists to
         * make you read announced itself in green and got clicked past.
         */
        $clean = $dropped === 0;

        Notification::make()
            ->title($dryRun
                ? 'Dry run complete — nothing written'
                : "Applied — {$written} row(s) written")
            ->body($clean
                ? 'Every product reference resolved in this environment.'
                : $dropped.' reference(s) had no product here and were dropped. The list is below.')
            ->{$clean ? 'success' : 'warning'}()
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
