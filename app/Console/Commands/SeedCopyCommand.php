<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Models\CopyTemplate;
use App\Services\Seo\CopyBank;
use App\Services\Seo\CopySlots;
use Illuminate\Console\Command;

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
 */
class SeedCopyCommand extends Command
{
    protected $signature = 'bc:seed-copy
        {--language= : One language (nl, fr, en, es). Omit for all of them.}
        {--dry-run : Report what would be written and write nothing.}';

    protected $description = 'Import the shipped page copy into the editable copy bank.';

    /**
     * Slots whose language file holds several alternatives.
     *
     * `brand_intro.lead` is the only one: it renders on every brand page, so it
     * shipped with four openings picked by a hash. Those four become four
     * variants here and the hash goes away.
     *
     * @var array<string, list<string>>
     */
    private const EXTRA_KEYS = [
        'brand_intro.lead' => ['lead_2', 'lead_3', 'lead_4'],
    ];

    public function handle(): int
    {
        $languages = $this->languages();

        if ($languages === []) {
            $this->error('Unknown language. Valid: '.implode(', ', $this->allLanguages()));

            return self::FAILURE;
        }

        $written = 0;
        $skipped = 0;
        $missing = [];

        foreach ($languages as $language) {
            foreach (CopySlots::all() as $key => $definition) {
                $surface = $definition['surface'];
                $slot = $definition['slot'];

                $exists = CopyTemplate::query()
                    ->where('surface', $surface)
                    ->where('slot', $slot)
                    ->where('language', $language)
                    ->exists();

                if ($exists) {
                    // Somebody may have edited this. Re-running must never put
                    // the shipped version back on top of their work.
                    $skipped++;

                    continue;
                }

                $bodies = $this->bodiesFor($surface, $slot, $language, $key);

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
            '%s %d variant(s); left %d existing slot(s) alone.',
            $this->option('dry-run') ? 'Would write' : 'Wrote',
            $written,
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

    /**
     * Every body the language file offers for this slot.
     *
     * @return list<string>
     */
    private function bodiesFor(string $surface, string $slot, string $language, string $key): array
    {
        $namespace = CopySlots::namespaceFor($surface);
        $bodies = [];

        foreach ([$slot, ...(self::EXTRA_KEYS[$key] ?? [])] as $langKey) {
            $line = __("{$namespace}.{$langKey}", [], $language);

            // A missing translation returns the key itself. Never store that:
            // it would render a dotted path to a reader, and it would look like
            // deliberate copy in the admin list.
            if (is_string($line) && $line !== '' && ! str_contains($line, $namespace)) {
                $bodies[] = $line;
            }
        }

        return $bodies;
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
