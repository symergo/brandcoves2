<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Enums\CoveKind;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Services\Pages\Context\PageContext;
use Illuminate\Support\Facades\Cache;

/**
 * Our Coves that hold products from these very results (owner, 2026-11-08:
 * "add some sections with links integrated to guides related to the search").
 *
 * `:guide_links` finds reading by its words, and most searches have no guide
 * titled after them. This finds them by what they contain: a gift-idea page,
 * an occasion, a guide or a Daily that picked one of the products on this
 * page, the ones holding most of them first. So the link always leads to a
 * page about something the visitor is looking at.
 *
 * One query, `daily_picks` by `group_id` (indexed), cached ten minutes per
 * set of products. A Cove `:guide_links` already names on this page is left
 * out, so the two sections never repeat a link.
 */
final class CoveLinks implements PlaceholderFunction
{
    private const LIMIT = 3;

    private const TTL = 600;

    private const KINDS = [CoveKind::Persona, CoveKind::Occasion, CoveKind::Guide, CoveKind::Seasonal, CoveKind::Daily];

    public function name(): string
    {
        return 'cove_links';
    }

    public function label(): string
    {
        return 'Coves holding these products, linked';
    }

    public function help(): string
    {
        return 'Our gift-idea pages, occasions, guides and Dailies that contain products from these results, the ones holding most of them first, at most three. Leaves out what :guide_links already names. Empty when none, which hides the block.';
    }

    public function level(): Level
    {
        return Level::Inline;
    }

    public function absent(): Absence
    {
        return Absence::Blank;
    }

    public function sample(): Value
    {
        return Value::links([
            ['label' => 'Cadeaus voor de muziekliefhebber', 'url' => '#'],
            ['label' => 'De beste koptelefoons', 'url' => '#'],
        ]);
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function resolve(PageContext $context): Value
    {
        $ids = array_values(array_filter(array_map(
            fn ($group) => $group instanceof ProductGroup ? $group->id : null,
            $context->items,
        )));

        if ($ids === []) {
            return Value::nothing();
        }

        sort($ids);
        $market = $context->market;

        $coves = Cache::remember(
            'cove-links:'.$market->value.':'.md5(implode(',', $ids)),
            self::TTL,
            fn (): array => DailyPickSet::query()
                ->forMarket($market)
                ->published()
                ->whereNotNull('daily_pick_sets.slug')
                ->whereIn('daily_pick_sets.kind', array_map(fn (CoveKind $kind) => $kind->value, self::KINDS))
                ->join('daily_picks', 'daily_picks.set_id', '=', 'daily_pick_sets.id')
                ->whereIn('daily_picks.group_id', $ids)
                ->groupBy('daily_pick_sets.id', 'daily_pick_sets.kind', 'daily_pick_sets.slug', 'daily_pick_sets.theme_title', 'daily_pick_sets.published_at')
                ->orderByRaw('count(*) desc')
                ->orderByDesc('daily_pick_sets.published_at')
                ->limit(self::LIMIT + 3)
                ->get(['daily_pick_sets.id', 'daily_pick_sets.kind', 'daily_pick_sets.slug', 'daily_pick_sets.theme_title'])
                ->map(fn (DailyPickSet $cove): array => [
                    'label' => (string) $cove->theme_title,
                    'url' => '/'.$market->value.'/'.$cove->kind->path((string) $cove->slug, $market),
                ])
                ->values()
                ->all(),
        );

        // Not what the "read more" section already links.
        $named = array_column((new GuideLinks)->resolve($context)->items, 'url');
        $coves = array_values(array_filter($coves, fn (array $cove) => ! in_array($cove['url'], $named, true)));

        return Value::links(array_slice($coves, 0, self::LIMIT));
    }
}
