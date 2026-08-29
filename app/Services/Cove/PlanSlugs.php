<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\Market;
use App\Models\CovePlan;
use Illuminate\Support\Str;

/**
 * One slug namespace per market, and the one place that respects it.
 *
 * The partial unique index on `cove_plans (market, slug)` covers every dateless
 * kind at once — a persona and a guide cannot share an address even though they
 * live at different paths. That rule is only useful if every writer of a slug
 * knows about it, and until this class there were two: `TopicPlanner` suffixed
 * on collision, and the drafter that came later would have had to reimplement
 * the same loop or start handing out slugs that fail to insert.
 *
 * Suffixed, never dropped. A collision means somebody has already claimed the
 * obvious name, and answering that by refusing to create the plan loses the
 * idea; answering it by overwriting loses their page.
 */
final class PlanSlugs
{
    /**
     * The nearest free slug to `$base` in this market.
     *
     * `$base` is slugged here rather than by the caller, so a title, a topic
     * word and a translated label can all be handed over raw.
     */
    public function free(Market $market, string $base): string
    {
        $slug = Str::slug($base);

        if ($slug === '') {
            // Nothing survived slugging — a title that is entirely punctuation,
            // or a script Str::slug() transliterates away. A plan still needs an
            // address, and "cove" is at least a valid one a person will rename.
            $slug = 'cove';
        }

        if (! $this->taken($market, $slug)) {
            return $slug;
        }

        $n = 2;

        while ($this->taken($market, $slug.'-'.$n)) {
            $n++;
        }

        return $slug.'-'.$n;
    }

    private function taken(Market $market, string $slug): bool
    {
        return CovePlan::query()
            ->where('market', $market->value)
            ->where('slug', $slug)
            ->exists();
    }
}
