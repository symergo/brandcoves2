<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromptTemplates\Pages;

use App\Filament\Resources\PromptTemplates\PromptTemplateResource;
use App\Models\PromptTemplate;
use App\Services\Ai\PromptBank;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Every prompt the site has, not only the ones somebody has overridden.
 *
 * ## Why the list used to look empty
 *
 * `prompt_templates` holds **overrides**, and it is deliberately not seeded — a
 * stale prompt produces plausible output, which is worse than an obviously
 * missing one, so a slot with no row uses the prompt the site shipped with. That
 * is the right storage design and it made a bad list: the table read straight
 * off the model, so the normal state of this screen was empty, and the only way
 * to find out which prompts exist was to read `PromptBank::slots()` in the
 * source.
 *
 * So the rows are the **registry** now, and an override is an attribute of a
 * row rather than the reason a row exists. Every slot is listed, saying whether
 * each half is shipped or overridden, and editing one writes the override
 * underneath.
 *
 * ## Orphans stay visible
 *
 * A stored row whose slot the code stopped declaring is listed too, marked. It
 * is inert — `PromptBank::override()` checks the slot against the allowlist
 * before reading a row — but somebody wrote it, and hiding it would leave a
 * rename's casualties unreachable and unexplained. Same call
 * `CopyTemplate::isOrphaned()` made.
 */
class ListPromptTemplates extends ListRecords
{
    protected static string $resource = PromptTemplateResource::class;

    protected function getHeaderActions(): array
    {
        // Nothing to create: every prompt already exists. Overriding one is an
        // action on its row.
        return [];
    }

    public function table(Table $table): Table
    {
        return $table
            /*
             * The registry is the data source, not the table.
             *
             * An Eloquent query can only return rows that exist, and the whole
             * point here is the slots that have none.
             */
            ->records(fn (?string $search, array $sort): Collection => $this->rows($search, $sort))
            ->columns([
                TextColumn::make('slot')
                    ->badge()
                    ->searchable()
                    ->sortable()
                    ->color(fn (array $record): string => $record['orphaned'] ? 'danger' : 'gray')
                    ->description(fn (array $record): string => $record['label']),

                TextColumn::make('system')
                    ->label('Rules')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state)
                    ->color(fn (string $state): string => $state === 'overridden' ? 'warning' : 'gray'),

                TextColumn::make('user_template')
                    ->label('Brief')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state)
                    ->color(fn (string $state): string => $state === 'overridden' ? 'warning' : 'gray'),

                TextColumn::make('state')
                    ->label('')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'off' => 'danger',
                        'overridden' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        // "Off" is not the same as "shipped": the words are still
                        // in the table, they are just not being used.
                        'off' => 'switched off — shipped prompt in use',
                        'overridden' => 'in use',
                        default => 'shipped',
                    }),

                TextColumn::make('author')->label('By')->toggleable()->placeholder('—'),
                TextColumn::make('updated_at')->label('Changed')->dateTime()->sortable()->placeholder('—'),
            ])
            ->filters([
                Filter::make('overridden')
                    ->label('Only the ones we have changed')
                    ->toggle(),

                Filter::make('orphaned')
                    ->label('Slots the code no longer asks for')
                    ->toggle(),
            ])
            ->recordActions([
                $this->editAction(),
                $this->resetAction(),
            ])
            /*
             * `ListRecords` wires a click-the-row handler typed to an Eloquent
             * model, and these rows are arrays off the registry. Left in place it
             * is a 500 the moment the page renders — so the row is not clickable
             * and the actions on it are how you open one.
             */
            ->recordAction(null)
            ->recordUrl(null)
            ->emptyStateHeading('No prompts are declared')
            ->emptyStateDescription('PromptBank::slots() is empty, which should not happen — every Cove kind declares one.')
            ->defaultSort('slot')
            ->paginated(false);
    }

    /**
     * Write, or rewrite, this slot's override.
     *
     * Pre-filled with the shipped prompt when there is no override yet, so the
     * first edit starts from the real thing rather than a blank textarea — which
     * is the difference between rewording a prompt and inventing one.
     */
    private function editAction(): Action
    {
        return Action::make('edit')
            ->label(fn (array $record): string => $record['row'] === null ? 'Override' : 'Edit')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalWidth('5xl')
            ->modalHeading(fn (array $record): string => $record['label'])
            ->fillForm(fn (array $record): array => [
                'system' => $record['row']?->system ?? PromptBank::shipped($record['slot'])['system'],
                'user_template' => $record['row']?->user_template ?? PromptBank::shipped($record['slot'])['user_template'],
                'notes' => $record['row']?->notes,
                'enabled' => $record['row']?->enabled ?? true,
            ])
            ->schema(fn (array $record): array => $this->overrideSchema($record['slot']))
            ->action(function (array $data, array $record): void {
                $slot = $record['slot'];

                /*
                 * A field cleared back to the shipped text is not an override.
                 *
                 * Storing it would work and would rot: the shipped prompt is
                 * improved in code from time to time, and a row holding a copy
                 * of last year's version silently pins this slot to it. Only a
                 * genuine difference is kept.
                 */
                $shipped = PromptBank::shipped($slot);

                $system = trim((string) ($data['system'] ?? ''));
                $user = trim((string) ($data['user_template'] ?? ''));

                $attributes = [
                    'system' => ($system === '' || $system === trim((string) $shipped['system'])) ? null : $system,
                    'user_template' => ($user === '' || $user === trim((string) $shipped['user_template'])) ? null : $user,
                    'notes' => $data['notes'] ?? null,
                    'enabled' => (bool) ($data['enabled'] ?? true),
                    'author_id' => Auth::id(),
                ];

                // Both halves back to shipped, and nothing to say about why:
                // that is a reset, and leaving an all-null row behind would show
                // as "overridden" while changing nothing.
                if ($attributes['system'] === null && $attributes['user_template'] === null && blank($attributes['notes'])) {
                    PromptTemplate::query()->where('slot', $slot)->delete();

                    Notification::make()->title('Back to the shipped prompt')->success()->send();

                    return;
                }

                PromptTemplate::query()->updateOrCreate(['slot' => $slot], $attributes);

                Notification::make()->title('Saved')->body('Live on the next Cove written for this slot.')->success()->send();
            });
    }

    private function resetAction(): Action
    {
        return Action::make('reset')
            ->label('Reset to shipped')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->link()
            // Nothing to reset on a slot nobody has touched, and a button that
            // does nothing teaches people to distrust the screen.
            ->visible(fn (array $record): bool => $record['row'] !== null)
            ->requiresConfirmation()
            ->modalDescription('Deletes this override. The slot goes back to the prompt the site shipped with, and what is written here is not recoverable.')
            ->action(function (array $record): void {
                // Deleting *is* the undo, exactly as clearing a value in AI
                // settings is. There is no third state between "the shipped
                // prompt" and "mine".
                $record['row']->delete();

                Notification::make()->title('Back to the shipped prompt')->success()->send();
            });
    }

    /** @return array<int, Section> */
    private function overrideSchema(string $slot): array
    {
        return [
            Section::make('The rules and the voice')
                ->description('Appended to, never replacing, the link-token contract — that is added in code so an edit here cannot stop the writer producing internal links.')
                ->schema([
                    Textarea::make('system')
                        ->label('System prompt')
                        ->rows(16)
                        ->columnSpanFull()
                        ->helperText('Pre-filled with the prompt the site ships with — edit it. Leave it exactly as it came, or clear it, to keep using the shipped one.'),
                ])
                ->footerActions([
                    Action::make('resetSystem')
                        ->label('Start again from the shipped prompt')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('gray')
                        ->action(fn ($set) => $set('system', PromptBank::shipped($slot)['system'])),
                ]),

            Section::make('The brief')
                ->description('Composed from named blocks. Available here: '.$this->placeholderHelp($slot))
                ->schema([
                    Textarea::make('user_template')
                        ->label('User prompt')
                        ->rows(14)
                        ->columnSpanFull()
                        /*
                         * The most valuable thing on the screen.
                         *
                         * A template is assembled from data, so one that has
                         * lost {finds} asks the model to write about nothing —
                         * and a model asked to write about nothing writes a
                         * plausible article about products that are not on the
                         * page. Rejected at save rather than discovered at 06:00.
                         */
                        ->rule(fn () => function (string $attribute, mixed $value, callable $fail) use ($slot): void {
                            try {
                                PromptBank::validate($slot, is_string($value) ? $value : null);
                            } catch (InvalidArgumentException $e) {
                                $fail($e->getMessage());
                            }
                        })
                        ->helperText('An empty block leaves no gap behind. Leave this as it came, or clear it, to keep using the shipped layout.'),

                    Textarea::make('notes')
                        ->label('Why this was changed')
                        ->rows(2)
                        ->columnSpanFull(),

                    Toggle::make('enabled')
                        ->label('Use what is written here')
                        ->helperText('Off means the shipped prompt is used, without losing what is written here.'),
                ])
                ->footerActions([
                    Action::make('resetUser')
                        ->label('Start again from the shipped layout')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('gray')
                        ->action(fn ($set) => $set('user_template', PromptBank::shipped($slot)['user_template'])),
                ]),
        ];
    }

    private function placeholderHelp(string $slot): string
    {
        if (! array_key_exists($slot, PromptBank::slots())) {
            return 'none — the code no longer asks for this slot.';
        }

        ['allowed' => $allowed, 'required' => $required] = PromptBank::placeholders($slot);

        return '{'.implode('}, {', $allowed).'}. Required: {'.implode('}, {', $required).'}.';
    }

    /**
     * One row per declared slot, plus any stored row the registry has forgotten.
     *
     * @param  array{0: ?string, 1: ?string}  $sort
     * @return Collection<string, array<string, mixed>>
     */
    private function rows(?string $search, array $sort): Collection
    {
        $stored = PromptTemplate::query()->with('author')->get()->keyBy('slot');

        $slots = PromptBank::slots();

        foreach ($stored as $slot => $row) {
            // A rename's casualty. Listed, marked, and reachable — deleting it
            // automatically would throw away something somebody wrote.
            $slots[$slot] ??= 'No longer used by the code';
        }

        $rows = collect($slots)->map(function (string $label, string $slot) use ($stored) {
            $row = $stored->get($slot);
            $declared = array_key_exists($slot, PromptBank::slots());

            return [
                'slot' => $slot,
                'label' => $label,
                'orphaned' => ! $declared,
                'row' => $row,
                'system' => filled($row?->system) ? 'overridden' : 'shipped',
                'user_template' => filled($row?->user_template) ? 'overridden' : 'shipped',
                'state' => match (true) {
                    $row === null => 'shipped',
                    ! $row->enabled => 'off',
                    filled($row->system) || filled($row->user_template) => 'overridden',
                    default => 'shipped',
                },
                'author' => $row?->author?->email,
                'updated_at' => $row?->updated_at,
            ];
        })->values();

        $search = trim((string) $search);

        if ($search !== '') {
            $needle = mb_strtolower($search);

            $rows = $rows->filter(fn (array $row) => str_contains(mb_strtolower($row['slot']), $needle)
                || str_contains(mb_strtolower($row['label']), $needle));
        }

        foreach ($this->tableFilters ?? [] as $name => $state) {
            $rows = match ($name) {
                'overridden' => ($state['isActive'] ?? false)
                    ? $rows->filter(fn (array $row) => $row['row'] !== null)
                    : $rows,
                'orphaned' => ($state['isActive'] ?? false)
                    ? $rows->filter(fn (array $row) => $row['orphaned'])
                    : $rows,
                default => $rows,
            };
        }

        [$column, $direction] = [$sort[0] ?? 'slot', $sort[1] ?? 'asc'];

        return $rows
            ->sortBy(fn (array $row) => $row[$column] ?? null, SORT_NATURAL | SORT_FLAG_CASE, $direction === 'desc')
            ->values()
            ->keyBy('slot');
    }
}
