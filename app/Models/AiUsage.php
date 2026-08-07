<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * AI spend, per feature, per day.
 *
 * Enforces a per-feature daily cap so a retry loop cannot run up a bill, and
 * makes usage visible in admin. A feature with no key registered here is
 * invisible, so every AI caller must declare one.
 */
class AiUsage extends Model
{
    protected $table = 'ai_usage';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['day' => 'date'];
    }

    public static function callsToday(string $featureKey): int
    {
        return (int) static::query()
            ->where('feature_key', $featureKey)
            ->whereDate('day', today())
            ->value('calls') ?? 0;
    }

    public static function withinCap(string $featureKey): bool
    {
        $cap = config("brandcoves.ai.caps.{$featureKey}")
            ?? config('brandcoves.ai.default_daily_cap');

        return self::callsToday($featureKey) < (int) $cap;
    }

    public static function record(string $featureKey, int $inputTokens, int $outputTokens, bool $failed = false): void
    {
        DB::table('ai_usage')->upsert(
            [[
                'feature_key' => $featureKey,
                'day' => today()->toDateString(),
                'calls' => 1,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'errors' => $failed ? 1 : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['feature_key', 'day'],
            [
                // A failed call still consumed budget and still counts against
                // the cap — otherwise a persistently failing feature retries
                // forever at full cost.
                'calls' => DB::raw('ai_usage.calls + 1'),
                'input_tokens' => DB::raw('ai_usage.input_tokens + excluded.input_tokens'),
                'output_tokens' => DB::raw('ai_usage.output_tokens + excluded.output_tokens'),
                'errors' => DB::raw('ai_usage.errors + excluded.errors'),
                'updated_at' => DB::raw('excluded.updated_at'),
            ],
        );
    }
}
