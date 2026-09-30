<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Services\Catalogue\TitleBrand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give offers without a brand the one their title starts with (TitleBrand).
 *
 * Ingestion does this for every offer it writes since 2026-09-30; this does it
 * once for the offers already stored, so the fill does not wait for each feed to
 * send each offer again. Only `products.brand` is written, and only where it is
 * empty: identity is never touched (see TitleBrand). The groups take the brand on
 * the next regrouping run, which rewrites any group whose display offer changed.
 *
 * A dry run unless `--write`.
 */
class FillBrandsCommand extends Command
{
    protected $signature = 'bc:fill-brands
        {--market= : Only this market. Every market by default}
        {--write : Store the brands. Without this it only reports}';

    protected $description = 'Fill in missing brands from the start of the title, where a source already named that brand';

    /** Offers read and written per round trip. */
    private const CHUNK = 2000;

    public function handle(TitleBrand $titleBrand): int
    {
        $market = $this->option('market') !== null ? Market::tryFrom((string) $this->option('market')) : null;

        if ($this->option('market') !== null && $market === null) {
            $this->error('Unknown market: '.$this->option('market'));

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');
        $seen = 0;
        $filled = 0;
        $brands = [];
        $samples = [];

        DB::table('products')
            ->select(['id', 'title'])
            ->where(fn ($q) => $q->whereNull('brand')->orWhere('brand', ''))
            ->when($market !== null, fn ($q) => $q->where('market', $market->value))
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows) use ($titleBrand, $write, &$seen, &$filled, &$brands, &$samples): void {
                $updates = [];

                foreach ($rows as $row) {
                    $seen++;
                    $brand = $titleBrand->infer((string) $row->title);

                    if ($brand === null) {
                        continue;
                    }

                    $updates[$brand][] = (int) $row->id;
                    $brands[$brand] = ($brands[$brand] ?? 0) + 1;

                    if (count($samples) < 15 && random_int(1, 50) === 1) {
                        $samples[] = "{$brand}  <=  ".mb_substr((string) $row->title, 0, 80);
                    }
                }

                foreach ($updates as $brand => $ids) {
                    $filled += count($ids);

                    if ($write) {
                        DB::table('products')->whereIn('id', $ids)->where(fn ($q) => $q->whereNull('brand')->orWhere('brand', ''))->update(['brand' => $brand]);
                    }
                }
            });

        arsort($brands);

        $this->info(sprintf('%d offers without a brand; %d %s a brand (%d brands).',
            $seen, $filled, $write ? 'got' : 'would get', count($brands)));
        $this->line('Most filled: '.implode(', ', array_map(
            fn (string $b, int $n) => "{$b} {$n}",
            array_slice(array_keys($brands), 0, 15),
            array_slice(array_values($brands), 0, 15),
        )));

        foreach ($samples as $sample) {
            $this->line('  '.$sample);
        }

        if (! $write) {
            $this->comment('Dry run. Nothing written; add --write to store.');
        }

        return self::SUCCESS;
    }
}
