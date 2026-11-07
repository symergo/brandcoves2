<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\DailyPickSet;
use App\Services\Pages\Context\PageContext;
use Illuminate\Support\Facades\Cache;

/**
 * Our own reading about this term, linked: buying guides, advice articles,
 * seasonal guides and gift-idea pages whose title or blurb matches it (owner,
 * 2026-11-07: "I would also add links to shopping guides or other useful
 * content" in the copy under the search results).
 *
 * The "Coves hierover" row above the products did this with cards until the
 * same day; the owner took the row out, and asked for the reading in the copy
 * below instead, as links in a sentence.
 *
 * ## Which pages
 *
 * Evergreen ones: guides, advice, seasonal guides, personas and occasions. Not
 * the Daily Cove (dated, so a link from every page about the term ages), and
 * not shop or brand Coves (`:brand_links` covers brands). Published, this
 * market, at most three, the best match first: a sentence with more links than
 * that is a list.
 *
 * ## Matching
 *
 * Full text on the title (weight A) and the blurb (B), stemmed per market by
 * `bc_text_config()`, the same as the search itself, so "koffiemachines" finds
 * a guide titled "Een koffiemachine kiezen". Cached ten minutes per term: the
 * copy is drawn on every first page of a search, and a guide published a few
 * minutes late is no loss.
 *
 * Nothing found is `Value::nothing()`, so a block written around it is left
 * out rather than shown with a hole in its sentence.
 */
final class GuideLinks implements PlaceholderFunction
{
    private const LIMIT = 3;

    private const TTL = 600;

    private const KINDS = [CoveKind::Guide, CoveKind::Advice, CoveKind::Seasonal, CoveKind::Persona, CoveKind::Occasion];

    public function name(): string
    {
        return 'guide_links';
    }

    public function label(): string
    {
        return 'Guides about this, linked';
    }

    public function help(): string
    {
        return 'Our buying guides, advice and gift-idea pages whose title or blurb matches the term, at most three, each linked. Empty when none match, which hides the block.';
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
            ['label' => 'Een koffiemachine kiezen', 'url' => '#'],
            ['label' => 'Cadeaus voor de koffieliefhebber', 'url' => '#'],
        ]);
    }

    public function dependsOn(): array
    {
        return ['term'];
    }

    public function resolve(PageContext $context): Value
    {
        $term = trim((string) $context->fact('term'));

        if ($term === '') {
            return Value::nothing();
        }

        return Value::links(self::matching($context->market, $term));
    }

    /** @return list<array{label: string, url: string}> */
    public static function matching(Market $market, string $term): array
    {
        return Cache::remember(
            'guide-links:'.$market->value.':'.md5(mb_strtolower($term)),
            self::TTL,
            function () use ($market, $term): array {
                /*
                 * Each word as a prefix ("koffie:*"), all of them required.
                 * Dutch writes compounds as one word, so a whole-word match
                 * never saw "koffie" in "De koffiefanaat"; a prefix does, and
                 * the stem still folds "koffiemachines" onto "koffiemachine".
                 * Only letters and digits reach the query, so nothing a visitor
                 * typed can be read as tsquery syntax.
                 */
                $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($term)) ?: []));

                if ($words === []) {
                    return [];
                }

                $tsquery = implode(' & ', array_map(fn (string $word) => $word.':*', array_slice($words, 0, 6)));
                $vector = "setweight(to_tsvector(bc_text_config(?), coalesce(theme_title, '')), 'A')"
                    ." || setweight(to_tsvector(bc_text_config(?), coalesce(theme_blurb, '')), 'B')";
                $query = 'to_tsquery(bc_text_config(?), ?)';
                $bindings = [$market->value, $market->value, $market->value, $tsquery];

                return DailyPickSet::query()
                    ->forMarket($market)
                    ->published()
                    ->whereNotNull('slug')
                    ->whereIn('kind', array_map(fn (CoveKind $kind) => $kind->value, self::KINDS))
                    ->whereRaw("({$vector}) @@ {$query}", $bindings)
                    ->orderByRaw("ts_rank({$vector}, {$query}) DESC", $bindings)
                    ->orderByDesc('published_at')
                    ->limit(self::LIMIT)
                    ->get(['id', 'kind', 'slug', 'theme_title'])
                    ->map(fn (DailyPickSet $cove): array => [
                        'label' => (string) $cove->theme_title,
                        'url' => '/'.$market->value.'/'.$cove->kind->path((string) $cove->slug, $market),
                    ])
                    ->values()
                    ->all();
            },
        );
    }
}
