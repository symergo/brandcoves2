<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Market;
use App\Models\MailTemplate;
use App\Services\Mail\MailTemplates;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
 * The words in an email, without a deploy.
 *
 * Page copy has been an editor's since page templates shipped; email copy was
 * still a pull request, a build and a release for one sentence. This closes the
 * strangest split in the product — the words on a screen somebody chose to open
 * were editable, and the words arriving uninvited in their inbox were not.
 *
 * ## One template, one language, at a time
 *
 * Not a grid of four bodies. Dutch and French prose do not decompose the same
 * way, and a screen that asks for all four at once makes the missing ones look
 * like work in progress rather than the deliberate default they are. Pick a
 * template, pick a language, see what it says now, change it or leave it.
 *
 * ## The shipped copy is always there to fall back to
 *
 * Saving writes an override; the toggle turns it off and the email reads exactly
 * as it always did, without losing what was written. An editor with second
 * thoughts at 11pm should not have to reconstruct the previous version from a
 * screenshot.
 *
 * ## What this screen cannot break
 *
 * The button, its destination, the fallback URL line. Those are supplied by the
 * mailable and are the parts that fail silently — an email whose button went
 * missing is one nobody can act on. And two templates are not offered at all:
 * the Cove digest, whose content is products rather than prose, and the Secret
 * Santa assignment, whose one job is to reveal a name.
 */
class EmailTemplates extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Email templates';

    protected static ?string $title = 'Email templates';

    protected string $view = 'filament.pages.email-templates';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'key' => array_key_first(MailTemplates::KEYS),
            'language' => 'en',
        ]);

        $this->load();
    }

    /**
     * Read whichever template the two selects now name.
     *
     * Called on mount and whenever either select changes, so the fields always
     * show the thing they are labelled with. Without it, switching language
     * would leave the previous language's body on screen and save it under the
     * new one — the worst kind of silent edit.
     */
    public function load(): void
    {
        $key = (string) ($this->data['key'] ?? '');
        $language = (string) ($this->data['language'] ?? 'en');

        $stored = MailTemplate::query()
            ->where('key', $key)
            ->where('language', $language)
            ->first();

        $shipped = app(MailTemplates::class)->shipped($key, $language);

        $this->data['subject'] = $stored?->subject ?? $shipped['subject'];
        $this->data['body'] = $stored?->body ?? $shipped['body'];
        $this->data['enabled'] = $stored?->enabled ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Which email')
                    ->schema([
                        Select::make('key')
                            ->label('Template')
                            ->options(collect(MailTemplates::KEYS)
                                ->map(fn (array $spec): string => $spec['label'])
                                ->all())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->load()),

                        Select::make('language')
                            ->label('Language')
                            ->options(collect(Market::cases())
                                ->mapWithKeys(fn (Market $m): array => [$m->language() => $m->language()])
                                ->all())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->load()),
                    ])
                    ->columns(2),

                Section::make('The words')
                    ->description('Markdown. The placeholders below are filled when the email is sent; a name that is not on that list stays on the page as written, so a typo is visible rather than silent.')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Use this instead of the shipped wording')
                            ->helperText('Off leaves the email exactly as it ships, and keeps what is written here.'),

                        TextInput::make('subject')
                            ->label('Subject')
                            ->required()
                            ->maxLength(200),

                        Textarea::make('body')
                            ->label('Body')
                            ->required()
                            ->rows(10)
                            ->maxLength(4000),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        MailTemplate::updateOrCreate(
            ['key' => (string) $state['key'], 'language' => (string) $state['language']],
            [
                'subject' => (string) $state['subject'],
                'body' => (string) $state['body'],
                'enabled' => (bool) ($state['enabled'] ?? false),
            ],
        );

        // The store caches every override for an hour; an editor must not have
        // to wait to see their own change.
        app(MailTemplates::class)->flush();

        Notification::make()
            ->title('Saved')
            ->body(($state['enabled'] ?? false)
                ? 'This email now uses your wording.'
                : 'Saved, but switched off — the email still uses the shipped wording.')
            ->success()
            ->send();
    }

    /** Reset to the shipped wording without deleting anything. */
    public function useShipped(): void
    {
        $shipped = app(MailTemplates::class)->shipped(
            (string) ($this->data['key'] ?? ''),
            (string) ($this->data['language'] ?? 'en'),
        );

        $this->data['subject'] = $shipped['subject'];
        $this->data['body'] = $shipped['body'];
    }

    /**
     * The placeholders this template can fill, for the view.
     *
     * @return list<string>
     */
    public function placeholders(): array
    {
        return MailTemplates::KEYS[$this->data['key'] ?? '']['placeholders'] ?? [];
    }
}
