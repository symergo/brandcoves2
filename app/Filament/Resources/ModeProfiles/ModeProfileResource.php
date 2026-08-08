<?php

declare(strict_types=1);

namespace App\Filament\Resources\ModeProfiles;

use App\Filament\Resources\ModeProfiles\Pages\ListModeProfiles;
use App\Models\ModeProfileRecord;
use App\Services\Discover\ModeRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Tune a discovery mode without a deploy.
 *
 * The profiles themselves live in `config/discovery.php`, reviewed like code.
 * A row here overrides individual fields — which is the whole reason ranking
 * weights ever get tuned: if changing λ from 0.6 to 0.75 requires a pull
 * request and a deploy, nobody does it after looking at a week of reaction
 * data, and the numbers stay at whatever they were guessed at on day one.
 *
 * Only the fields you fill in are applied. An override that changes λ changes
 * λ, and every other field keeps following the config.
 */
class ModeProfileResource extends Resource
{
    protected static ?string $model = ModeProfileRecord::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Discovery modes';

    protected static ?string $modelLabel = 'mode override';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Which mode')
                ->description('Must match a key declared in config/discovery.php. A row whose key is not declared is ignored.')
                ->schema([
                    Select::make('key')
                        ->required()
                        ->options(fn () => collect(array_keys((array) config('discovery.modes')))
                            ->mapWithKeys(fn (string $k) => [$k => $k])
                            ->all())
                        ->unique(ignoreRecord: true),

                    Toggle::make('enabled')
                        ->helperText('Leave blank to follow the config. A disabled mode disappears from the dial and its URL 404s.'),
                ]),

            Section::make('Scoring')
                ->description('score = relevance^α · unexpectedness^β · novelty^γ · quality, then MMR at λ, then ε-greedy exploration. Leave a field blank to keep the configured value.')
                ->schema([
                    TextInput::make('scoring.alpha')
                        ->numeric()->minValue(0)->maxValue(3)->step(0.05)
                        ->label('α — relevance')
                        ->helperText('0 removes the term entirely (x⁰ = 1).'),

                    TextInput::make('scoring.beta')
                        ->numeric()->minValue(0)->maxValue(3)->step(0.05)
                        ->label('β — unexpectedness'),

                    TextInput::make('scoring.gamma')
                        ->numeric()->minValue(0)->maxValue(3)->step(0.05)
                        ->label('γ — novelty'),

                    TextInput::make('scoring.lambda')
                        ->numeric()->minValue(0)->maxValue(1)->step(0.05)
                        ->label('λ — diversity')
                        ->helperText('Higher breaks up near-duplicates harder. Low is right when the visitor asked for something specific.'),

                    TextInput::make('scoring.epsilon')
                        ->numeric()->minValue(0)->maxValue(0.5)->step(0.01)
                        ->label('ε — exploration')
                        ->helperText('Share of slots given to a random candidate. Zero means the ranker never shows anything it is unsure about, so nothing new ever collects a reaction and the weights can never be learned.'),
                ])
                ->columns(2),

            Section::make('Why')
                ->description('Six months from now nobody remembers why ε is 0.15.')
                ->schema([
                    Textarea::make('note')->rows(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->badge()->searchable(),

                // The effective value, not the override — what the site is
                // actually doing is the thing an operator needs to see.
                TextColumn::make('effective')
                    ->label('In effect')
                    ->state(function (ModeProfileRecord $record): string {
                        $registry = app(ModeRegistry::class);

                        if (! $registry->has($record->key)) {
                            return 'disabled';
                        }

                        $p = $registry->get($record->key);

                        return sprintf(
                            'α %.2f · β %.2f · γ %.2f · λ %.2f · ε %.2f',
                            $p->alpha, $p->beta, $p->gamma, $p->lambda, $p->epsilon
                        );
                    })
                    ->fontFamily('mono'),

                IconColumn::make('enabled')->boolean()->label('Override enabled'),
                TextColumn::make('note')->limit(40)->toggleable(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->headerActions([
                CreateAction::make(),

                Action::make('flush')
                    ->label('Apply now')
                    ->icon(Heroicon::OutlinedArrowPath)
                    // Profiles are cached for a minute. That is short enough to
                    // be invisible in normal use and long enough that someone
                    // tuning a weight wonders whether they broke something.
                    ->action(function (): void {
                        app(ModeRegistry::class)->forget();

                        Notification::make()
                            ->title('Mode profiles reloaded')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ListModeProfiles::route('/')];
    }
}
