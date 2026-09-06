<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\ProductGroup;
use App\Support\CurrentMarket;
use Illuminate\Support\Facades\DB;

/**
 * hreflang alternates that actually resolve.
 *
 * ## The bug this exists to fix
 *
 * Swapping the market segment — `/be-nl/p/204/x` → `/be-fr/p/204/x` — is right
 * for a page whose content is market-independent, and wrong for every page
 * keyed on a database row. Product identity is **market-scoped by design**:
 * group 204 exists in `be-nl` and nowhere else, so four of the five alternates
 * emitted that way are links to 404s.
 *
 * Google treats hreflang as a mutual declaration. An alternate pointing at a
 * missing page is not merely ignored — the whole cluster is discarded, so the
 * pages that *do* have real translations lose the annotation too. On a
 * five-market site that is one of the more expensive mistakes available.
 *
 * So: market-independent paths swap the segment; keyed paths look the sibling
 * up and emit nothing when there is not one. A missing alternate costs nothing.
 *
 * ## Why these are ordered
 *
 * Every sibling lookup carries `orderBy('market')`. Postgres may return rows in
 * any order it likes and under load it does: a parallel test run emitted the
 * same two alternates as the serial run in the opposite order, failing an
 * assertion that had passed a moment earlier. That reads exactly like a flaky
 * runner — and `.githooks/pre-push` duly re-ran it serially, saw it pass, and
 * concluded the parallel runner had crashed. It had not. The output was simply
 * unordered, and the hook's rule cannot tell those two apart.
 *
 * hreflang does not care about order. Reproducible output does, because an
 * assertion that only usually holds is worse than no assertion.
 */
class Alternates
{
    /**
     * @return array<string, string> hreflang => absolute URL
     */
    public function for(string $path, Market $current): array
    {
        $path = '/'.trim($path, '/');
        $segments = explode('/', trim($path, '/'));

        // segments[0] is the market prefix on every public route.
        $kind = $segments[1] ?? null;

        /*
         * The Daily Cove segment is a word, not a literal: `tips` today, four
         * retired spellings that still resolve, and the legacy `daily`. This
         * arm read `'daily'` after the rename, so every edition fell through to
         * `swap()` and declared four cross-market twins that 404 — the exact
         * failure the class docblock warns about, on the one page type
         * published every day, in the head and in the sitemap both. Matched
         * against `Market::coveSegments()` so the next rename cannot repeat it.
         */
        if ($kind === 'daily' || ($kind !== null && in_array($kind, Market::coveSegments(), true))) {
            return $this->daily($segments, $current);
        }

        return match ($kind) {
            'p' => $this->product($segments, $current),
            'guides' => $this->guide($segments, $current),
            'shops' => $this->shop($segments, $current),
            'gift-ideas' => $this->persona($segments, $current),
            // Home, search, discover, gift, surprise, lists: the same page in
            // another market, and the segment swap is exactly right.
            default => $this->swap($path),
        };
    }

    /**
     * Product alternates for a whole page of groups, in one query.
     *
     * `for()` is the right shape for a page rendering one product and the wrong
     * shape for a sitemap: it costs two queries per URL, so a 5,000-URL sitemap
     * file ran ten thousand of them and took fifty seconds to build — past the
     * proxy's thirty-second timeout, so every crawler that asked for it got a
     * 500. The file generated perfectly from the CLI, which is why it looked
     * fine.
     *
     * One query here, keyed by identity — which is what "the same product"
     * means across markets. The id differs per market and is meaningless
     * across one.
     *
     * @param  array<int, string>  $identityByGroupId  group id => identity_key
     * @return array<int, array<string, string>> group id => (hreflang => URL)
     */
    public function forProducts(array $identityByGroupId): array
    {
        if ($identityByGroupId === []) {
            return [];
        }

        $byIdentity = [];

        ProductGroup::query()
            ->whereIn('identity_key', array_values(array_unique($identityByGroupId)))
            ->presentable()
            ->get(['id', 'market', 'slug', 'identity_key'])
            ->each(function (ProductGroup $sibling) use (&$byIdentity): void {
                $byIdentity[$sibling->identity_key][$sibling->market->hrefLang()] =
                    url("/{$sibling->market->value}/p/{$sibling->id}/{$sibling->slug}");
            });

        $out = [];

        foreach ($identityByGroupId as $groupId => $identity) {
            $alternates = $byIdentity[$identity] ?? [];

            // Same rule as the per-page path: a product with no sibling anywhere
            // is a single-market page, and a self-referential annotation alone is
            // pointless noise.
            $out[$groupId] = count($alternates) > 1 ? $alternates : [];
        }

        return $out;
    }

    /**
     * Alternates for a whole sitemap file's worth of non-product paths.
     *
     * `for()` per URL costs a query or two each; a chunk carries five hundred
     * editorial URLs, and it resolved every one of them on a cold cache. The
     * paths are sorted by kind and each kind is answered in one query — the
     * same shape `forProducts()` takes, for the same reason.
     *
     * @param  list<string>  $paths
     * @return array<string, array<string, string>> path => (hreflang => URL)
     */
    public function forPaths(array $paths, Market $current): array
    {
        $bySegment = [];

        foreach ($paths as $path) {
            $segments = explode('/', trim($path, '/'));
            $bySegment[$segments[1] ?? ''][] = [$path, $segments[2] ?? null];
        }

        $out = [];

        foreach ($bySegment as $kind => $entries) {
            $slugs = array_values(array_filter(array_column($entries, 1)));

            $paired = match (true) {
                $kind === 'guides' => $this->forCoves(['guide', 'seasonal', 'advice'], 'guides', $slugs),
                $kind === 'shops' => $this->forCoves([CoveKind::Shop->value], 'shops', $slugs),
                $kind === 'gift-ideas' => $this->forCoves([CoveKind::Persona->value], 'gift-ideas', $slugs),
                $kind === 'daily' || in_array($kind, Market::coveSegments(), true) => $this->forDailies($current, $slugs),
                default => [],
            };

            foreach ($entries as [$path, $slug]) {
                // A slugless path is the index of its section, and a kind this
                // method does not batch is a swap: both are the per-path answer,
                // which costs no query.
                $out[$path] = $slug === null || ! array_key_exists($slug, $paired)
                    ? $this->for($path, $current)
                    : $paired[$slug];
            }
        }

        return $out;
    }

    /**
     * Cove alternates for a list of slugs, in one query — the batched twin of
     * guide(), shop() and persona(), with their rule: the same slug in another
     * published market is the same Cove.
     *
     * @param  list<string>  $kinds
     * @param  list<string>  $slugs
     * @return array<string, array<string, string>> slug => (hreflang => URL)
     */
    public function forCoves(array $kinds, string $prefix, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $bySlug = [];

        DB::table('daily_pick_sets')
            ->whereIn('slug', $slugs)
            ->whereIn('kind', $kinds)
            ->where('status', PublishStatus::Published->value)
            ->orderBy('market')
            ->get(['market', 'slug'])
            ->each(function ($row) use (&$bySlug, $prefix): void {
                $market = Market::tryFrom((string) $row->market);

                if ($market !== null && $market->isPublished()) {
                    $bySlug[(string) $row->slug][$market->hrefLang()] = url("/{$market->value}/{$prefix}/{$row->slug}");
                }
            });

        $out = [];

        foreach ($slugs as $slug) {
            $alternates = $bySlug[$slug] ?? [];
            $out[$slug] = count($alternates) > 1 ? $alternates : [];
        }

        return $out;
    }

    /**
     * Daily alternates for a list of this market's slugs, in two queries — the
     * batched twin of daily(): paired by date, addressed by each market's slug.
     *
     * @param  list<string>  $slugs
     * @return array<string, array<string, string>> slug => (hreflang => URL)
     */
    public function forDailies(Market $current, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $dateOf = DB::table('daily_pick_sets')
            ->where('market', $current->value)
            ->whereIn('slug', $slugs)
            ->pluck('drop_date', 'slug')
            ->map(fn ($date) => (string) $date);

        $byDate = [];

        DB::table('daily_pick_sets')
            ->whereIn('drop_date', array_values(array_unique($dateOf->all())))
            ->where('status', PublishStatus::Published->value)
            ->whereNotNull('slug')
            ->orderBy('market')
            ->get(['market', 'slug', 'drop_date'])
            ->each(function ($row) use (&$byDate): void {
                $market = Market::tryFrom((string) $row->market);

                if ($market !== null && $market->isPublished()) {
                    $byDate[(string) $row->drop_date][$market->hrefLang()] = url($market->covePath((string) $row->slug));
                }
            });

        $out = [];

        foreach ($slugs as $slug) {
            $alternates = isset($dateOf[$slug]) ? ($byDate[$dateOf[$slug]] ?? []) : [];
            $out[$slug] = count($alternates) > 1 ? $alternates : [];
        }

        return $out;
    }

    /**
     * The same physical product in other markets.
     *
     * Joined on `identity_key`, which is what "the same product" means here —
     * the GTIN, or the brand+title fallback. The id differs per market and is
     * meaningless across one.
     *
     * @param  list<string>  $segments
     * @return array<string, string>
     */
    private function product(array $segments, Market $current): array
    {
        $id = (int) ($segments[2] ?? 0);

        if ($id <= 0) {
            return $this->self($current, implode('/', $segments));
        }

        $group = ProductGroup::query()->find($id, ['id', 'identity_key', 'slug']);

        if ($group === null) {
            return [];
        }

        $alternates = [];

        $siblings = ProductGroup::query()
            ->where('identity_key', $group->identity_key)
            ->presentable()
            ->get(['id', 'market', 'slug']);

        foreach ($siblings as $sibling) {
            $alternates[$sibling->market->hrefLang()] =
                url("/{$sibling->market->value}/p/{$sibling->id}/{$sibling->slug}");
        }

        // A product with no sibling anywhere is a single-market page. Emitting
        // a self-referential annotation alone is pointless noise, so it gets
        // nothing at all.
        return count($alternates) > 1 ? $alternates : [];
    }

    /**
     * A guide with the same slug in another market.
     *
     * Slugs are written in the market's language, so this is usually empty —
     * "beste-koptelefoons" has no French twin unless one was generated. That is
     * the honest answer: two guides on the same topic in two languages are
     * translations of each other, and until the builder records that link we
     * cannot claim it.
     *
     * @param  list<string>  $segments
     * @return array<string, string>
     */
    private function guide(array $segments, Market $current): array
    {
        $slug = $segments[2] ?? null;

        if ($slug === null) {
            // The index page itself exists in every market.
            return $this->swap('/'.implode('/', $segments));
        }

        $rows = DB::table('daily_pick_sets')
            ->where('slug', $slug)
            // The article kinds. A persona could hold this slug in another
            // market and lives at a different path, so pairing the two would
            // point hreflang at a page about something else entirely.
            ->whereIn('kind', ['guide', 'seasonal', 'advice'])
            ->where('status', PublishStatus::Published->value)
            // Deterministic order — see 'Why these are ordered' above.
            ->orderBy('market')
            ->get(['market', 'slug']);

        $alternates = [];

        foreach ($rows as $row) {
            $market = Market::tryFrom((string) $row->market);

            if ($market !== null) {
                $alternates[$market->hrefLang()] = url("/{$market->value}/guides/{$row->slug}");
            }
        }

        return count($alternates) > 1 ? $alternates : [];
    }

    /**
     * The same Shop Cove in another market.
     *
     * A separate method rather than a widened `guide()` for the reason that one
     * already gives about personas: the two live at different paths, so pairing
     * a `/shops/{slug}` with a `/guides/{slug}` that happens to share a slug
     * would point hreflang at a page about something else. Shop Cove slugs are
     * derived from a shop's domain and the same shop keeps the same slug in
     * every market it trades in, which is exactly what makes them pairable.
     *
     * @param  array<int, string>  $segments
     * @return array<string, string>
     */
    private function shop(array $segments, Market $current): array
    {
        $slug = $segments[2] ?? null;

        if ($slug === null) {
            // The directory itself exists in every market.
            return $this->swap('/'.implode('/', $segments));
        }

        $rows = DB::table('daily_pick_sets')
            ->where('slug', $slug)
            ->where('kind', CoveKind::Shop->value)
            ->where('status', PublishStatus::Published->value)
            // Deterministic order — see 'Why these are ordered' above.
            ->orderBy('market')
            ->get(['market', 'slug']);

        $alternates = [];

        foreach ($rows as $row) {
            $market = Market::tryFrom((string) $row->market);

            if ($market !== null) {
                $alternates[$market->hrefLang()] = url("/{$market->value}/shops/{$row->slug}");
            }
        }

        return count($alternates) > 1 ? $alternates : [];
    }

    /**
     * The same gift persona in another market.
     *
     * Missing until 2026-08-29, and silently: `gift-ideas` was not in the match
     * above, so a persona fell through to `swap()` and declared an alternate in
     * **every** published market without checking one existed. That is precisely
     * the bug in this class's docblock, on the one kind of page the class never
     * learned about — and it stayed invisible only because the shelf was empty
     * in all five markets.
     *
     * It is not a page that can be swapped. A persona is written per market and
     * two markets need not carry the same ones: `de-hondenmens` is a be-nl page
     * and `de-klusser` an nl-nl one, so four of the five alternates emitted for
     * either would have been 404s, and Google discards a whole cluster that
     * contains one — taking the honest pairs down with it.
     *
     * Paired on the slug, like a Shop Cove and unlike a Daily. There is no date
     * to pair on, and the slug is the address a persona is written to keep; two
     * markets carrying the same slug are carrying the same persona, which is
     * exactly the promise `/be-nl/gift-ideas/de-thuiskok` and its nl-nl twin
     * make. The prose behind them differs per market, as translations do.
     *
     * @param  array<int, string>  $segments
     * @return array<string, string>
     */
    private function persona(array $segments, Market $current): array
    {
        $slug = $segments[2] ?? null;

        if ($slug === null) {
            // The shelf itself exists in every market, empty or not.
            return $this->swap('/'.implode('/', $segments));
        }

        $rows = DB::table('daily_pick_sets')
            ->where('slug', $slug)
            ->where('kind', CoveKind::Persona->value)
            ->where('status', PublishStatus::Published->value)
            // Deterministic order — see 'Why these are ordered' above.
            ->orderBy('market')
            ->get(['market', 'slug']);

        $alternates = [];

        foreach ($rows as $row) {
            $market = Market::tryFrom((string) $row->market);

            // A persona can be built for a market that is not open yet, the
            // same way an edition can — see `daily()`. Declaring it invites a
            // crawler to a market deliberately not being indexed.
            if ($market !== null && $market->isPublished()) {
                $alternates[$market->hrefLang()] = url("/{$market->value}/gift-ideas/{$row->slug}");
            }
        }

        return count($alternates) > 1 ? $alternates : [];
    }

    /**
     * The same day's edition in another market.
     *
     * Editions are built per market per date, so the date *is* the shared key —
     * unlike a product id or a guide slug. Only markets that actually published
     * that day are listed; a build can be skipped on a thin catalogue day.
     *
     * @param  list<string>  $segments
     * @return array<string, string>
     */
    private function daily(array $segments, Market $current): array
    {
        $slug = $segments[2] ?? null;

        if ($slug === null) {
            return $this->swap('/'.implode('/', $segments));
        }

        /*
         * Paired by date, addressed by slug.
         *
         * The date is what makes two editions the same edition — every market
         * publishes one per day, about the same occasion — and the slug is
         * written in each market's own language, so it is exactly what cannot be
         * matched across them. Since the rename this needs both: find the date
         * from this market's slug, then find every market's slug for that date.
         */
        $date = DB::table('daily_pick_sets')
            ->where('market', $current->value)
            ->where('slug', $slug)
            ->value('drop_date');

        if ($date === null) {
            return [];
        }

        $rows = DB::table('daily_pick_sets')
            ->whereDate('drop_date', $date)
            ->where('status', PublishStatus::Published->value)
            ->whereNotNull('slug')
            // Deterministic order — see 'Why these are ordered' above.
            ->orderBy('market')
            ->get(['market', 'slug']);

        $alternates = [];

        foreach ($rows as $row) {
            $market = Market::tryFrom((string) $row->market);

            // A row can exist for a market that is not open yet — editions are
            // planned ahead of the market being published.
            if ($market !== null && $market->isPublished()) {
                // Each market's own word for the segment, not this one's — an
                // hreflang pointing at /es/cadeau-van-de-dag/... names a URL
                // that deliberately 404s.
                $alternates[$market->hrefLang()] = url($market->covePath($row->slug));
            }
        }

        return count($alternates) > 1 ? $alternates : [];
    }

    /** @return array<string, string> */
    private function swap(string $path): array
    {
        $alternates = [];

        // An unpublished market must not appear in hreflang. Declaring it tells
        // a crawler the page has a Spanish equivalent worth indexing, which is
        // the opposite of hiding it.
        foreach (Market::published() as $market) {
            $alternates[$market->hrefLang()] = url(CurrentMarket::swapMarketInPath($path, $market));
        }

        return $alternates;
    }

    /** @return array<string, string> */
    private function self(Market $current, string $path): array
    {
        return [$current->hrefLang() => url('/'.trim($path, '/'))];
    }

    /**
     * The x-default target.
     *
     * English when it is among the alternates. A crawler that cannot match any
     * of the declared locales needs somewhere to land, and the alternative —
     * omitting x-default — leaves that choice to a heuristic.
     *
     * @param  array<string, string>  $alternates
     */
    public function defaultFor(array $alternates): ?string
    {
        return $alternates[Market::En->hrefLang()] ?? (reset($alternates) ?: null);
    }
}
