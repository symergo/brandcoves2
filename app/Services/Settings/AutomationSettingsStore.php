<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PlanWriter;
use App\Models\ConnectorSetting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Which editorial stages run unattended, per market and per kind.
 *
 * The same pipeline an instruction drives, on a second trigger: the scheduler
 * instead of somebody asking. One stage runner, two callers — a third
 * implementation of "curate this plan" would disagree with both.
 *
 * ## The grid, and why it is a grid
 *
 * Five stages × six kinds × five markets is 150 switches, which is a list
 * nobody can read. It is stored and edited as a **grid per market** — kinds
 * down, stages across — so the thirty cells of one market fit on a screen and
 * the shape of the domain shows in the shape of the grid: the disabled cells
 * are exactly where a kind has no automatic source.
 *
 * ## Deploy day must change nothing
 *
 * The switches ship as a **data migration** rather than as a code default,
 * seeded to reproduce what the scheduler does today: `daily` on for every
 * market that builds one, everything else off. A code default would make "what
 * is running" a question about which release you are on, and the answer would
 * change under somebody the first time they deployed.
 *
 * ## Auto-publish is not one category
 *
 * A `daily` already publishes unattended — that is the row shipping switched
 * on, and it is what `BuildDailyEdition` has always done. But `buildArticle()`
 * refuses anything a person has not approved, so for persona, guide, seasonal,
 * advice, shop and brand, turning `approve` on is **genuinely new**: it is what
 * `PlanDrafter`'s docblock calls a content farm with a nicer interface. Every
 * non-daily `approve` cell ships off, and the screen says in words what turning
 * one on means.
 *
 * `PublishDueCoves` is not absorbed by any of this. It already honours a
 * person's approval on the day they scheduled it, carrying logic that belongs
 * to seasons rather than to automation — `built_for`, the window guard, series
 * ordering on catch-up. The `build` switch **gates** it rather than replacing
 * it.
 */
class AutomationSettingsStore
{
    public const SOURCE = 'automation';

    private const CACHE_KEY = 'bc:settings:automation';

    /**
     * The stages, in the order they run.
     *
     * `write` is a three-way rather than a switch, because the question is not
     * whether prose happens but **who writes it** — and that answer settles a
     * race the `writer` field was already needed for: the batch write stage
     * picks only `builder` plans and `GET /coves/queue` hands out only
     * `authored` ones, so the two can never target the same plan.
     */
    public const STAGES = ['plan', 'curate', 'write', 'approve', 'build'];

    /** What `write` may be set to. */
    public const WRITERS = ['off', 'builder', 'external'];

    /**
     * Is this stage meaningful for this kind at all?
     *
     * A disabled cell is not a missing feature; it is the domain saying so.
     * `PlanDrafter` refuses to draft advice, shop and brand — nothing in the
     * catalogue or the search log proposes an opinion about how to shop — and a
     * kind with no products has nothing to curate.
     */
    public static function applies(string $stage, CoveKind $kind): bool
    {
        return match ($stage) {
            'plan' => ! in_array($kind, [CoveKind::Advice, CoveKind::Shop], true),
            'curate' => $kind->targetItems() > 0,
            default => true,
        };
    }

    /** Why a cell is disabled, for the screen to show rather than leave blank. */
    public static function whyNot(string $stage, CoveKind $kind): ?string
    {
        if (self::applies($stage, $kind)) {
            return null;
        }

        return $stage === 'plan'
            ? 'Nothing in the data proposes one of these. Claude brings the topic, or you type it.'
            : 'This kind carries no products — its substance is the writing.';
    }

    /**
     * Is a stage switched on for this market and kind?
     *
     * Defaults to **off** for everything the migration did not seed, which is
     * the only safe direction: a switch nobody has considered must not be one
     * that publishes.
     */
    public function enabled(string $stage, Market $market, CoveKind $kind): bool
    {
        if (! self::applies($stage, $kind)) {
            return false;
        }

        if ($stage === 'write') {
            return $this->writer($market, $kind) !== 'off';
        }

        return ($this->stored()[self::key($stage, $market, $kind)] ?? '0') === '1';
    }

    /**
     * Who writes this kind in this market, unattended.
     *
     * `builder` spends `giftcoves.ai.caps` on this server. `external` marks
     * plans `authored` and leaves them for an agent on `GET /coves/queue`,
     * costing nothing here. `off` means nobody writes them automatically.
     */
    public function writer(Market $market, CoveKind $kind): string
    {
        $stored = $this->stored()[self::key('write', $market, $kind)] ?? 'off';

        return in_array($stored, self::WRITERS, true) ? $stored : 'off';
    }

    /** The `PlanWriter` an automatic run should mark plans with, if any. */
    public function writerFor(Market $market, CoveKind $kind): ?PlanWriter
    {
        return match ($this->writer($market, $kind)) {
            'builder' => PlanWriter::Builder,
            'external' => PlanWriter::Authored,
            default => null,
        };
    }

    /**
     * Every switch, as the grid the screen renders.
     *
     * @return array<string, array<string, string>>
     */
    public function grid(Market $market): array
    {
        $grid = [];

        foreach (CoveKind::cases() as $kind) {
            foreach (self::STAGES as $stage) {
                $grid[$kind->value][$stage] = $stage === 'write'
                    ? $this->writer($market, $kind)
                    : ($this->enabled($stage, $market, $kind) ? '1' : '0');
            }
        }

        return $grid;
    }

    /**
     * Save one market's grid.
     *
     * @param  array<string, array<string, string>>  $grid
     */
    public function putGrid(Market $market, array $grid): void
    {
        foreach ($grid as $kindValue => $stages) {
            $kind = CoveKind::tryFrom((string) $kindValue);

            if ($kind === null) {
                continue;
            }

            foreach ($stages as $stage => $value) {
                if (! in_array($stage, self::STAGES, true) || ! self::applies($stage, $kind)) {
                    continue;
                }

                $this->put($stage, $market, $kind, (string) $value);
            }
        }

        Cache::forget(self::CACHE_KEY);
    }

    private function put(string $stage, Market $market, CoveKind $kind, string $value): void
    {
        $key = self::key($stage, $market, $kind);

        /*
         * "Off" deletes the row rather than storing a zero.
         *
         * Same convention as `AiSettingsStore`: an absent row reads as the
         * default, so the table holds only what somebody has deliberately turned
         * on. It also makes "what is automated here" answerable by looking at
         * the rows rather than by filtering them.
         */
        $isOff = $stage === 'write' ? $value === 'off' : $value !== '1';

        if ($isOff) {
            ConnectorSetting::query()
                ->where('source', self::SOURCE)
                ->where('key', $key)
                ->delete();

            return;
        }

        ConnectorSetting::query()->updateOrCreate(
            ['source' => self::SOURCE, 'key' => $key],
            ['encrypted_value' => $stage === 'write' ? $value : '1'],
        );
    }

    /**
     * The allowlist, generated from the enums.
     *
     * Derived rather than listed, the way `PromptBank`'s slots derive from
     * `CoveKind::cases()`: a stray row cannot reach anything, and a seventh kind
     * needs no second place to remember.
     */
    public static function key(string $stage, Market $market, CoveKind $kind): string
    {
        return "{$stage}.{$market->value}.{$kind->value}";
    }

    /**
     * Every market that has at least one stage switched on, with what is on.
     *
     * Read by the planner header, which says out loud where pages publish
     * without a person. A setting that reaches readers and is visible only on
     * the screen that sets it is a setting somebody will forget is on.
     *
     * @return array<string, list<string>>
     */
    public function publishingMarkets(): array
    {
        $out = [];

        foreach (Market::cases() as $market) {
            foreach (CoveKind::cases() as $kind) {
                if ($this->enabled('approve', $market, $kind)) {
                    $out[$market->value][] = $kind->value;
                }
            }
        }

        return $out;
    }

    /**
     * The stored rows.
     *
     * @return array<string, string>
     */
    public function stored(): array
    {
        /*
         * The try wraps the cache call, not only the query — same reason as
         * `AiSettingsStore`: this is read from a scheduled job and from a panel,
         * and during a build or a fresh `migrate` there is no reachable database
         * at all. No rows means everything is off, which is the right answer in
         * every one of those cases.
         */
        try {
            return Cache::remember(self::CACHE_KEY, 3600, fn (): array => ConnectorSetting::query()
                ->where('source', self::SOURCE)
                ->get()
                ->mapWithKeys(fn (ConnectorSetting $s) => [$s->key => $s->encrypted_value])
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
