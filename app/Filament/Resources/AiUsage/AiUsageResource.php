<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiUsage;

use App\Filament\Resources\AiUsage\Pages\ListAiUsage;
use App\Models\AiUsage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * What the models cost, per feature, per day.
 *
 * Read-only. This table is the reason every AI caller has to register a
 * `feature_key`: a feature with no key is invisible here, and invisible spend
 * is the kind that gets noticed by an invoice.
 *
 * The columns worth watching are `errors` and the cap. A feature that is at its
 * cap every day is either under-provisioned or in a retry loop, and those look
 * identical until you check whether the errors column is moving.
 */
class AiUsageResource extends Resource
{
    protected static ?string $model = AiUsage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'AI usage';

    // Explicit, because the directory and the model share a name and Filament
    // derives `ai-usage/ai-usages` from the pair.
    protected static ?string $slug = 'ai-usage';

    protected static ?string $modelLabel = 'day';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('day')->date()->sortable(),
                TextColumn::make('feature_key')->badge()->searchable(),

                TextColumn::make('calls')
                    ->numeric()
                    ->sortable()
                    ->description(fn (AiUsage $r) => 'cap '.(
                        config("brandcoves.ai.caps.{$r->feature_key}")
                            ?? config('brandcoves.ai.default_daily_cap')
                    ))
                    // At the cap is not necessarily wrong, but it means the
                    // feature stopped early today and used its fallback.
                    ->color(fn (AiUsage $r) => $r->calls >= (int) (
                        config("brandcoves.ai.caps.{$r->feature_key}")
                            ?? config('brandcoves.ai.default_daily_cap')
                    ) ? 'warning' : 'gray'),

                TextColumn::make('errors')
                    ->numeric()
                    ->sortable()
                    // Failed calls still count against the cap — otherwise a
                    // persistently failing feature retries forever at full
                    // cost. So a high errors count next to a maxed calls count
                    // means the budget was spent on nothing.
                    ->color(fn (AiUsage $r) => $r->errors > 0 ? 'danger' : 'gray'),

                TextColumn::make('input_tokens')->numeric()->toggleable(),
                TextColumn::make('output_tokens')->numeric()->toggleable(),
            ])
            ->defaultSort('day', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListAiUsage::route('/')];
    }
}
