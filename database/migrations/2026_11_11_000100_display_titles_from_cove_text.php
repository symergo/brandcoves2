<?php

declare(strict_types=1);

use App\Models\DailyPickSet;
use App\Services\Cove\PickNames;
use Illuminate\Database\Migrations\Migration;

/**
 * Products without a display title get the short name a Cove's text gives
 * them (owner, 2026-11-11: "switch to display_title when empty. Use the
 * display title when it exists"). App\Services\Cove\PickNames does this at
 * every build; this runs it once over the Coves that already exist, so their
 * cards change with the deploy rather than at their next rebuild.
 *
 * Only empty display titles are written, so there is nothing to undo: `down()`
 * cannot tell these names from ones written by hand, and does not try.
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = app(PickNames::class);

        DailyPickSet::query()
            ->whereHas('picks')
            ->with('picks.group')
            ->orderBy('id')
            ->chunkById(200, function ($coves) use ($names): void {
                foreach ($coves as $cove) {
                    $names->fill($cove);
                }
            });
    }

    public function down(): void {}
};
