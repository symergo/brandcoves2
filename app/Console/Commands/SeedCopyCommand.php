<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Models\CopyTemplate;
use App\Services\Seo\CopyBank;
use App\Services\Seo\CopySlots;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Import the shipped language strings as the first variant of each copy slot.
 *
 * An empty admin screen is a feature nobody uses. This fills it with the copy
 * the site is already rendering, so an editor's first action is rewriting a real
 * sentence rather than inventing one against a blank textarea — and so the
 * variants they add sit next to something to compare against.
 *
 * Idempotent and non-destructive: a slot that already has any row is left
 * completely alone, whatever state it is in. Re-running after someone has edited
 * their copy must not put the shipped version back.
 *
 * ## `--replace`, and why it has to exist
 *
 * That guarantee has a consequence nobody notices until it bites: **once a slot
 * is in the bank, rewriting its language file changes nothing anywhere it has
 * been seeded.** The brand pages were rewritten to describe the brand rather
 * than the pricing, the tests passed, staging deployed — and staging kept
 * serving the old sentences out of the database.
 *
 * `--replace` deletes the chosen slots' rows and re-imports them. It is
 * destructive by definition and therefore opt-in, narrowed with `--surface`, and
 * `--dry-run` reports what it would remove. Losing an editor's work silently
 * would be worse than the problem it solves, so it says how many rows it is
 * about to delete and requires confirmation outside a dry run.
 */
class SeedCopyCommand extends Command
{
    protected $signature = 'bc:seed-copy
        {--language= : One language (nl, fr, en, es). Omit for all of them.}
        {--surface= : One surface (search, brand). Omit for all of them.}
        {--replace : DESTRUCTIVE. Replace existing rows for the chosen slots.}
        {--force : Skip the confirmation. For a deploy shell with no tty.}
        {--dry-run : Report what would be written and write nothing.}';

    protected $description = 'Import the shipped page copy into the editable copy bank.';

    public function handle(): int
    {
        $languages = $this->languages();

        if ($languages === []) {
            $this->error('Unknown language. Valid: '.implode(', ', $this->allLanguages()));

            return self::FAILURE;
        }

        $only = $this->option('surface');

        if ($only !== null && ! array_key_exists($only, CopySlots::surfaces())) {
            $this->error('Unknown surface. Valid: '.implode(', ', array_keys(CopySlots::surfaces())));

            return self::FAILURE;
        }

        if ($this->option('replace') && ! $this->option('dry-run') && ! $this->confirmReplacement($only)) {
            return self::FAILURE;
        }

        $written = 0;
        $skipped = 0;
        $removed = 0;
        $missing = [];

        foreach ($languages as $language) {
            foreach (CopySlots::all() as $key => $definition) {
                $surface = $definition['surface'];
                $slot = $definition['slot'];

                if ($only !== null && $surface !== $only) {
                    continue;
                }

                $exists = CopyTemplate::query()
                    ->where('surface', $surface)
                    ->where('slot', $slot)
                    ->where('language', $language)
                    ->exists();

                if ($exists && ! $this->option('replace')) {
                    // Somebody may have edited this. Re-running must never put
                    // the shipped version back on top of their work.
                    $skipped++;

                    continue;
                }

                if ($exists) {
                    $removed += $this->option('dry-run')
                        ? $this->rowsFor($surface, $slot, $language)->count()
                        : $this->rowsFor($surface, $slot, $language)->delete();
                }

                $bodies = $this->bodiesFor($surface, $slot, $language);

                if ($bodies === []) {
                    $missing[] = "{$key} / {$language}";

                    continue;
                }

                foreach ($bodies as $index => $body) {
                    if (! $this->option('dry-run')) {
                        CopyTemplate::create([
                            'surface' => $surface,
                            'slot' => $slot,
                            'language' => $language,
                            'body' => $body,
                            // Equal weights: the shipped variants have no
                            // measured performance between them, and pretending
                            // otherwise would bake a guess into the data.
                            'weight' => 1,
                            'enabled' => true,
                            'note' => $index === 0
                                ? 'Imported from the language file.'
                                : 'Imported alternative from the language file.',
                        ]);
                    }

                    $written++;
                }
            }
        }

        if (! $this->option('dry-run')) {
            CopyBank::flush();
        }

        $this->components->info(sprintf(
            '%s %d variant(s); %s %d row(s); left %d existing slot(s) alone.',
            $this->option('dry-run') ? 'Would write' : 'Wrote',
            $written,
            $this->option('dry-run') ? 'would remove' : 'removed',
            $removed,
            $skipped,
        ));

        if ($missing !== []) {
            /*
             * A slot in the registry with no line in the language file. Worth
             * naming rather than silently skipping: it means the page renders an
             * empty string there, which is a bug in one of the two lists.
             */
            $this->components->warn('No language string for: '.implode(', ', array_slice($missing, 0, 20)));
        }

        return self::SUCCESS;
    }

    /** @return Builder<CopyTemplate> */
    private function rowsFor(string $surface, string $slot, string $language)
    {
        return CopyTemplate::query()
            ->where('surface', $surface)
            ->where('slot', $slot)
            ->where('language', $language);
    }

    /**
     * Say how much is about to be lost, then ask.
     *
     * An editor's rewrite is not recoverable from this command, so the count is
     * the number that matters and it is shown before the question rather than
     * after the damage.
     */
    private function confirmReplacement(?string $surface): bool
    {
        $count = CopyTemplate::query()
            ->when($surface !== null, fn ($q) => $q->where('surface', $surface))
            ->when($this->option('language') !== null, fn ($q) => $q->where('language', $this->option('language')))
            ->count();

        if ($count === 0 || $this->option('force')) {
            return true;
        }

        return $this->confirm(
            "This deletes {$count} existing copy row(s), including any edits made in the admin. Continue?",
            false,
        );
    }

    /**
     * The body the language file offers for this slot, if it has one.
     *
     * A list rather than a single string because it used to be one: the retired
     * `brand_intro.lead` shipped with four openings that a hash picked between,
     * and seeding turned those four into four variants. Nothing ships
     * alternatives now, and the shape stays because it is what a caller wants —
     * an empty list means "no line for this slot", which is the case worth
     * reporting.
     *
     * @return list<string>
     */
    private function bodiesFor(string $surface, string $slot, string $language): array
    {
        $namespace = CopySlots::namespaceFor($surface);
        $line = __("{$namespace}.{$slot}", [], $language);

        // A missing translation returns the key itself. Never store that: it
        // would render a dotted path to a reader, and it would look like
        // deliberate copy in the admin list.
        return is_string($line) && $line !== '' && ! str_contains($line, (string) $namespace)
            ? [$line]
            : [];
    }

    /** @return list<string> */
    private function languages(): array
    {
        $requested = $this->option('language');

        if ($requested === null) {
            return $this->allLanguages();
        }

        return in_array($requested, $this->allLanguages(), true) ? [(string) $requested] : [];
    }

    /** @return list<string> */
    private function allLanguages(): array
    {
        return array_values(array_unique(array_map(
            fn (Market $market) => $market->language(),
            Market::cases(),
        )));
    }
}
