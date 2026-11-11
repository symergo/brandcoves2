<?php

declare(strict_types=1);

use App\Models\DailyPickSet;
use App\Services\Cove\PickNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short product name per pick, read from the Cove's own text and shown on the
 * card instead of the shop title (owner, 2026-11-10). Filled at every build by
 * App\Services\Cove\PickNames; this migration fills it once for the Coves that
 * already exist, so their cards change with the deploy rather than at their
 * next rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_picks', function (Blueprint $table) {
            $table->string('short_name', 80)->nullable()->after('slug');
        });

        $names = app(PickNames::class);

        DailyPickSet::query()
            ->whereHas('picks')
            ->with('picks.group')
            ->chunkById(200, function ($coves) use ($names): void {
                foreach ($coves as $cove) {
                    $names->fill($cove);
                }
            });
    }

    public function down(): void
    {
        Schema::table('daily_picks', function (Blueprint $table) {
            $table->dropColumn('short_name');
        });
    }
};
