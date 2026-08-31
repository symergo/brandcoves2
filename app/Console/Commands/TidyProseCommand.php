<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Services\Editorial\HouseStyle;
use App\Services\Guides\CoveMarkup;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Bring already-published prose into house style.
 *
 * {@see HouseStyle} runs at every write, so everything generated from now on is
 * already right. This is for the archive: hundreds of paragraphs written before
 * the rule existed, sitting in published pages nobody is going to re-read by
 * hand.
 *
 * ## Why a command and not a migration
 *
 * A migration runs once, on a schema. This runs on *content*, it is idempotent
 * - a second pass finds nothing, because a string with no em dash and no
 * asterisk pairs comes back unchanged - and it has a dry run. All three matter
 * for a change that rewrites text a person may have edited: the dry run is how
 * you see the blast radius before it happens, and idempotence is what makes it
 * safe to run again after the next import.
 *
 * It is also the one shape that works for a production database this repo never
 * holds a copy of. A migration would have to be right first time against data
 * nobody has looked at.
 *
 * ## What decides `prose` from `plain`
 *
 * Whether the field has a renderer downstream. An editorial, a body and an FAQ
 * answer pass {@see CoveMarkup}, so `**bold**` becomes
 * `<strong>` and is left alone here. A title, a verdict, a card blurb and an
 * FAQ question are printed as React text nodes, so the asterisks would show and
 * are taken off.
 *
 * `theme_blurb` and `cove_plans.blurb` are the one field that is both, and the
 * kind decides: a Daily or a persona prints it as a text node, every article
 * kind renders it as the intro paragraph. Getting that backwards is not
 * cosmetic - it either strips emphasis an article meant or leaves asterisks on
 * a column's standfirst.
 *
 * The legacy `guides` and `guide_items` tables are deliberately not touched.
 * Nothing has read them since the fold (`2026_08_30_000100_a_guide_is_a_cove`),
 * and rewriting rows on their way to being dropped is work with no reader.
 */
class TidyProseCommand extends Command
{
    protected $signature = 'bc:tidy-prose
        {--market= : Only this market. Every market by default}
        {--write : Apply the rewrite. Without this it only reports}';

    protected $description = 'Rewrite stored editorial in house style: em dashes out, stray ** off the fields that cannot render it.';

    public function handle(): int
    {
        $market = null;

        if (filled($this->option('market'))) {
            $market = Market::tryFrom((string) $this->option('market'));

            if ($market === null) {
                $this->components->error('Unknown market. One of: '.implode(', ', Market::values()));

                return self::FAILURE;
            }
        }

        $write = (bool) $this->option('write');

        $changed = $this->coves($market, $write)
            + $this->picks($market, $write)
            + $this->plans($market, $write)
            + $this->planItems($market, $write);

        $this->newLine();

        if ($changed === 0) {
            $this->components->info('Nothing to tidy. Everything stored is already in house style.');

            return self::SUCCESS;
        }

        $this->components->info(($write ? 'Rewrote ' : 'Would rewrite ').$changed.' field(s).');

        if (! $write) {
            $this->line('  Nothing was written. Re-run with --write to apply.');
        }

        return self::SUCCESS;
    }

    /** The editorial table: Coves of every kind. */
    private function coves(?Market $market, bool $write): int
    {
        return $this->walk(
            'daily_pick_sets',
            ['id', 'market', 'kind', 'theme_title', 'theme_blurb', 'editorial', 'body', 'meta_description', 'faq'],
            $market,
            $write,
            fn (object $row): array => [
                'theme_title' => HouseStyle::plain($row->theme_title),
                'theme_blurb' => $this->blurb($row->kind, $row->theme_blurb),
                'editorial' => HouseStyle::prose($row->editorial),
                'body' => HouseStyle::prose($row->body),
                'meta_description' => HouseStyle::plain($row->meta_description),
                'faq' => $this->faq($row->faq),
            ],
        );
    }

    /** A find's card copy. Both fields are text nodes under the card. */
    private function picks(?Market $market, bool $write): int
    {
        return $this->walk(
            'daily_picks',
            ['id', 'blurb', 'verdict'],
            $market,
            $write,
            fn (object $row): array => [
                'blurb' => HouseStyle::plain($row->blurb),
                'verdict' => HouseStyle::plain($row->verdict),
            ],
            // No market column of its own: a pick belongs to a set.
            marketVia: fn (Builder $query, Market $m) => $query->whereIn(
                'set_id',
                DB::table('daily_pick_sets')->where('market', $m->value)->select('id'),
            ),
        );
    }

    /**
     * The plan side, which is the one that actually matters for a rebuild.
     *
     * An edition is regenerated from its plan routinely, so tidying only the
     * published row would last until the next build. This is where authored
     * prose lives.
     */
    private function plans(?Market $market, bool $write): int
    {
        return $this->walk(
            'cove_plans',
            ['id', 'market', 'kind', 'title', 'blurb', 'editorial', 'body', 'meta_description', 'faq'],
            $market,
            $write,
            fn (object $row): array => [
                'title' => HouseStyle::plain($row->title),
                'blurb' => $this->blurb($row->kind, $row->blurb),
                'editorial' => HouseStyle::prose($row->editorial),
                'body' => HouseStyle::prose($row->body),
                'meta_description' => HouseStyle::plain($row->meta_description),
                'faq' => $this->faq($row->faq),
            ],
        );
    }

    /**
     * `verdict` only.
     *
     * `note` is the curator's reason for putting a product on the list. It is
     * read by the builder and handed to the model; no reader ever sees it, so
     * house style has nothing to do there.
     */
    private function planItems(?Market $market, bool $write): int
    {
        return $this->walk(
            'cove_plan_items',
            ['id', 'verdict'],
            $market,
            $write,
            fn (object $row): array => ['verdict' => HouseStyle::plain($row->verdict)],
            marketVia: fn (Builder $query, Market $m) => $query->whereIn(
                'plan_id',
                DB::table('cove_plans')->where('market', $m->value)->select('id'),
            ),
        );
    }

    /**
     * The field that is prose on an article and a text node on a column.
     *
     * `theme_blurb` is a Daily's standfirst and a guide's opening paragraph, in
     * the same column, because the fold gave both kinds one table. Only
     * `GuideController` runs it through the renderer, and only for the article
     * kinds - so on a Daily or a persona, `**` would reach the page as
     * asterisks and has to come off here.
     */
    private function blurb(?string $kind, ?string $value): ?string
    {
        $rendered = $kind !== null
            && ! in_array(CoveKind::tryFrom($kind), [CoveKind::Daily, CoveKind::Persona], true);

        return $rendered ? HouseStyle::prose($value) : HouseStyle::plain($value);
    }

    /**
     * A stored FAQ: a list of `{"q": ..., "a": ...}`.
     *
     * The question is a `<dt>` text node and the answer goes through the
     * renderer, so the pair splits the same way every other field does.
     * Returned as JSON because the walk writes with the query builder, which
     * does not know the model's `array` cast.
     */
    private function faq(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return $raw;
        }

        /*
         * Spread first, then override.
         *
         * Not `['q' => …, 'a' => …]` built fresh: Postgres hands `jsonb` back
         * with its keys in its own order, and rebuilding the pair would put
         * them back in a different one. The comparison below would then see a
         * change on every run, and the command would rewrite every FAQ in the
         * archive every time it was called - the one thing a tidy pass must not
         * do. Spreading keeps each pair's own key order, and anything else
         * stored alongside `q` and `a`.
         */
        $tidied = array_map(
            fn (array $pair): array => [
                ...$pair,
                'q' => HouseStyle::plain($pair['q'] ?? null),
                'a' => HouseStyle::prose($pair['a'] ?? null),
            ],
            $decoded,
        );

        if ($tidied === $decoded) {
            // Exactly what came out, so the caller's comparison sees no change.
            return $raw;
        }

        $encoded = json_encode($tidied, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? $raw : $encoded;
    }

    /**
     * Read, tidy, compare, write the difference.
     *
     * Only fields that actually changed are sent, so a run over an already-tidy
     * archive issues no UPDATEs at all and `updated_at` on every published Cove
     * does not jump to today. That matters more than it sounds: `updated_at` is
     * what the admin table sorts by, and a bulk touch would erase the record of
     * when anybody last edited anything.
     *
     * @param  list<string>  $columns
     * @param  callable(object): array<string, string|null>  $tidy
     * @param  null|callable(Builder, Market): mixed  $marketVia
     */
    private function walk(
        string $table,
        array $columns,
        ?Market $market,
        bool $write,
        callable $tidy,
        ?callable $marketVia = null,
    ): int {
        $query = DB::table($table)->select($columns)->orderBy('id');

        if ($market !== null) {
            $marketVia === null
                ? $query->where('market', $market->value)
                : $marketVia($query, $market);
        }

        $fields = 0;
        $rows = 0;

        $query->chunk(200, function ($chunk) use ($table, $tidy, $write, &$fields, &$rows): void {
            foreach ($chunk as $row) {
                $updates = [];

                foreach ($tidy($row) as $column => $tidied) {
                    if ($tidied !== $row->{$column}) {
                        $updates[$column] = $tidied;
                    }
                }

                if ($updates === []) {
                    continue;
                }

                $rows++;
                $fields += count($updates);

                if ($this->output->isVerbose()) {
                    $this->line("  {$table}#{$row->id}: ".implode(', ', array_keys($updates)));
                }

                if ($write) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            }
        });

        $this->components->twoColumnDetail(
            $table,
            $rows === 0 ? 'clean' : "{$fields} field(s) in {$rows} row(s)",
        );

        return $fields;
    }
}
