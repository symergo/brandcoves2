<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyPickSet;
use App\Services\Guides\CoveMarkup;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every Cove van de dag this market has published, newest first.
 *
 * Until 2026-10-02 the daily column had no index: `/tips` is today's edition,
 * and the past ones were reachable only through the six cards beside it or by
 * their own address. The owner asked for the "Cove van de dag" entry to open an
 * archive of all of them instead, so the menu, the home page card and the
 * /coves band now link here, and today's edition is the first card.
 *
 * `/tips/archive` in every market, like `tips` itself: one word that reads in
 * all four languages, under a segment that is already English. It is declared
 * before the edition route, so an edition can never be addressed as "archive".
 *
 * Only published editions, which is also what keeps tomorrow's out: a Daily is
 * published at its drop time and `published()` compares with now.
 */
class DailyArchiveController extends Controller
{
    /**
     * Thirty per page: ten rows of three on a desktop, a month of editions.
     * The page is a list of titles with a small picture each, so a month fits
     * comfortably; more would make the second page the place nobody reaches.
     */
    private const PER_PAGE = 30;

    /** Pictures per card: a row of small squares says what the edition held. */
    private const IMAGES = 4;

    public function __invoke(CurrentMarket $current, string $market, string $cove): Response|RedirectResponse
    {
        $marketEnum = $current->get();

        // A retired spelling of the segment reaches the current one in one hop.
        if ($cove !== $marketEnum->coveSegment()) {
            return redirect($marketEnum->covePath('archive'), 301);
        }

        $editions = DailyPickSet::query()
            ->daily()
            ->forMarket($marketEnum)
            ->published()
            ->orderByDesc('drop_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['id', 'kind', 'slug', 'drop_date', 'theme_title', 'theme_blurb'])
            ->withQueryString();

        $images = $this->images($editions->getCollection()->pluck('id')->all());
        $markup = app(CoveMarkup::class);

        $canonical = url($marketEnum->covePath('archive'));

        app(PageMeta::class)->set(
            title: __('site.daily_archive.seo_title'),
            description: __('site.daily_archive.seo_description'),
            // Each page is its own canonical: page two lists other editions
            // than page one, and pointing it at page one would hide them.
            canonical: $editions->currentPage() > 1 ? $canonical.'?page='.$editions->currentPage() : $canonical,
        );

        return Inertia::render('Daily/Archive', [
            'editions' => $editions->getCollection()->map(fn (DailyPickSet $set): array => [
                'id' => $set->id,
                'title' => $set->theme_title,
                'intro' => $markup->plain($set->theme_blurb),
                'url' => $current->url($set->kind->path((string) $set->slug, $marketEnum)),
                'date' => $set->drop_date->toDateString(),
                'isToday' => $set->drop_date->isToday(),
                'images' => $images[$set->id] ?? [],
            ])->values()->all(),
            'pagination' => [
                'current' => $editions->currentPage(),
                'last' => $editions->lastPage(),
                'prev' => $editions->previousPageUrl(),
                'next' => $editions->nextPageUrl(),
            ],
        ]);
    }

    /**
     * The first few pictures of each edition, in the order the edition shows them.
     *
     * One query for the whole page rather than one per card. A pick whose
     * product has left the catalogue has no group and simply drops out.
     *
     * @param  list<int>  $setIds
     * @return array<int, list<string>>
     */
    private function images(array $setIds): array
    {
        if ($setIds === []) {
            return [];
        }

        $rows = DB::table('daily_picks')
            ->join('product_groups', 'product_groups.id', '=', 'daily_picks.group_id')
            ->whereIn('daily_picks.set_id', $setIds)
            ->whereNotNull('product_groups.image_url')
            ->orderBy('daily_picks.set_id')
            ->orderBy('daily_picks.rank')
            ->get(['daily_picks.set_id', 'product_groups.image_url']);

        $out = [];

        foreach ($rows as $row) {
            $set = (int) $row->set_id;

            if (count($out[$set] ?? []) < self::IMAGES) {
                $out[$set][] = (string) $row->image_url;
            }
        }

        return $out;
    }
}
