<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\ProductGroup;
use App\Services\Connectors\Bol\BolConnector;
use App\Services\Connectors\Offer;
use App\Services\Editorial\ProductLookup;
use App\Services\Search\BrandAttribution;
use Illuminate\Support\Facades\DB;

/**
 * A page of bol products, as seen in a browser, turned into catalogue rows.
 *
 * The caller is the Chrome extension: somebody browsing bol.com finds a shelf
 * worth having — a category, a search, a curated list — and sends what is on
 * the screen here in one request. What arrives is what a *page* can tell us,
 * which is a product id and usually a title, occasionally a barcode. What gets
 * stored is what **bol's own API** says about those products, which is the
 * whole design: the page is a way of choosing, never a source of facts.
 *
 * That distinction is not fussiness. A scraped price is wrong within the hour,
 * a scraped title carries the page's promotional wrapping, and a scraped link
 * earns no commission. Every row written here comes from the same connector,
 * the same {@see OfferUpserter} and the same grouping as a shopper's live
 * search, so an imported product is not a second class of thing: same id, same
 * offer comparison, same `/go/` affiliate redirect.
 *
 * ## Getting from a page id to a catalogue record
 *
 * Harder than it looks, and measured against the live API on 2026-09-14 rather
 * than assumed:
 *
 *  - `GET /products/{id}` is keyed on the **EAN**. A `bolProductId` — the
 *    number in every bol.com product URL — answers 400.
 *  - Searching for a `bolProductId` as a term returns **zero** results.
 *
 * So the id in the URL, the one thing every page reliably carries, cannot be
 * looked up directly. Two routes out, in this order:
 *
 *  1. **The barcode**, when the page gave us one. A product page carries it in
 *     its specifications and its JSON-LD; this is an exact identity and needs
 *     one call.
 *  2. **The title**, otherwise — search for it, then keep the result whose
 *     `bolProductId` equals the one scraped from the link. Note what that is
 *     and is not: the search is only a way of *enumerating* candidates, and the
 *     id comparison is exact. A near-miss on the title does not produce a
 *     near-miss product, it produces nothing. Measured at rank 1 for 8 of 8
 *     titles on a live listing page.
 *
 * Anything neither route resolves is reported per product rather than dropped.
 * A silent shortfall is the failure mode here — somebody exports a shelf of
 * twenty and gets fourteen — so every id comes back with a reason.
 */
class BolPageImport
{
    /**
     * How many products one page may send.
     *
     * A bol listing page shows at most a few dozen; the ceiling exists so a
     * malformed or hostile client cannot turn one request into hundreds of
     * upstream calls. Title resolution is one bol search per product, and that
     * is the real cost of this endpoint.
     */
    public const MAX_PRODUCTS = 60;

    public function __construct(
        private readonly BolConnector $bol,
        private readonly OfferUpserter $upserter,
        private readonly IncomingGrouper $grouper,
        private readonly BrandAttribution $attribution,
        private readonly ProductLookup $lookup,
    ) {}

    /**
     * @param  list<array{productId: string, ean?: string|null, title?: string|null}>  $candidates
     * @return array{market: string, requested: int, imported: int, results: list<array<string, mixed>>}
     */
    public function import(Market $market, array $candidates): array
    {
        $candidates = $this->deduplicate($candidates);

        $results = [];
        /** @var array<string, Offer> $offers keyed by the page id we were asked for */
        $offers = [];

        foreach ($candidates as $candidate) {
            $pageId = $candidate['productId'];

            $offer = $this->resolve($market, $candidate);

            if ($offer === null) {
                $results[$pageId] = [
                    'productId' => $pageId,
                    'title' => $candidate['title'] ?? null,
                    'status' => $this->bol->isCoolingDown() ? 'rate_limited' : 'unresolved',
                ];

                continue;
            }

            if ($offer->price === null) {
                /*
                 * bol knows the product and is not selling it.
                 *
                 * There is no `available` flag in the payload — the presence of
                 * an offer block IS the availability signal — so this is the
                 * only shape "out of stock" takes. Worth telling the curator
                 * apart from "we could not find it": one is worth retrying next
                 * week, the other never will be.
                 */
                $results[$pageId] = [
                    'productId' => $pageId,
                    'title' => $offer->title,
                    'status' => 'unavailable',
                ];

                continue;
            }

            $offers[$pageId] = $offer;
            $results[$pageId] = [
                'productId' => $pageId,
                'title' => $offer->title,
                'status' => 'imported',
            ];
        }

        if ($offers !== []) {
            $this->store($market, array_values($offers));
            $results = $this->attachGroups($market, $results, $offers);
        }

        $ordered = array_values($results);

        return [
            'market' => $market->value,
            'requested' => count($candidates),
            'imported' => count(array_filter($ordered, fn (array $r) => $r['status'] === 'imported')),
            'results' => $ordered,
        ];
    }

    /**
     * The barcode first, the title second.
     *
     * @param  array{productId: string, ean?: string|null, title?: string|null}  $candidate
     */
    private function resolve(Market $market, array $candidate): ?Offer
    {
        $ean = $this->normaliseEan($candidate['ean'] ?? null);

        if ($ean !== null) {
            $offer = $this->bol->fetchByEan($ean, $market);

            if ($offer !== null) {
                return $offer;
            }
        }

        $title = trim((string) ($candidate['title'] ?? ''));

        return $title !== ''
            ? $this->byTitle($market, $title, $candidate['productId'])
            : null;
    }

    /**
     * Enumerate with the title, decide with the id.
     *
     * The returned offer is only ever one whose `externalId` is exactly the id
     * scraped from the link, so a title that matches loosely — and on bol many
     * do, where one product name covers six colourways — cannot substitute a
     * different product for the one somebody pointed at.
     */
    private function byTitle(Market $market, string $title, string $pageId): ?Offer
    {
        foreach ($this->bol->search($title, $market, 24) as $offer) {
            if ($offer->externalId === $pageId) {
                return $offer;
            }
        }

        return null;
    }

    /**
     * 13 digits, or nothing.
     *
     * A page can hand over anything it found next to the word "EAN" — spaces,
     * a dash, a 12-digit UPC. Rather than guess, this accepts what bol's
     * product endpoint accepts and lets everything else fall through to the
     * title route, which needs no barcode to be right.
     */
    private function normaliseEan(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        return strlen($digits) === 13 ? $digits : null;
    }

    /**
     * Write the offers and make them countable.
     *
     * Brands are attributed before the upsert, not after, and that ordering is
     * load-bearing: bol returns no brand at all, and an offer with no usable
     * barcode has no identity without one, because `IdentityResolver`'s
     * fallback key is brand plus normalised title. Filling it in first is what
     * lets an imported product join the same card as the same product from
     * another shop rather than sitting alone.
     *
     * @param  list<Offer>  $offers
     */
    private function store(Market $market, array $offers): void
    {
        $offers = $this->attribution->fromCatalogue($offers);

        $this->upserter->upsert($offers);

        $this->grouper->attach($market, array_map(fn (Offer $o) => $o->externalId, $offers));
    }

    /**
     * Tell the caller what each product became.
     *
     * The group is the point of the whole exercise — it is what a visitor sees
     * and what an editorial plan links to — so the extension shows its id and
     * its URL rather than reporting a bare success. Described through
     * {@see ProductLookup::describe} so this endpoint's idea of a product is
     * the same one the rest of the editorial API has.
     *
     * @param  array<string, array<string, mixed>>  $results
     * @param  array<string, Offer>  $offers
     * @return array<string, array<string, mixed>>
     */
    private function attachGroups(Market $market, array $results, array $offers): array
    {
        $groupIdByExternalId = DB::table('products')
            ->where('market', $market->value)
            ->where('source', Source::Bol->value)
            ->where('status', ProductStatus::Active->value)
            ->whereIn('external_id', array_map(fn (Offer $o) => $o->externalId, $offers))
            ->whereNotNull('group_id')
            ->pluck('group_id', 'external_id');

        $groups = ProductGroup::query()
            ->whereIn('id', $groupIdByExternalId->values()->unique()->all())
            ->get()
            ->keyBy('id');

        foreach ($offers as $pageId => $offer) {
            $groupId = $groupIdByExternalId[$offer->externalId] ?? null;
            $group = $groupId !== null ? $groups->get($groupId) : null;

            if ($group === null) {
                /*
                 * Written, but not grouped — which means no identity could be
                 * resolved for it: no barcode from bol, and no brand for the
                 * fallback key. The offer exists and is real; it simply has no
                 * card of its own yet, and the nightly grouper will not invent
                 * one either. Reported as its own status so nobody goes looking
                 * for a product page that was never going to exist.
                 */
                $results[$pageId]['status'] = 'ungrouped';

                continue;
            }

            $results[$pageId]['group'] = $this->lookup->describe($group);
        }

        return $results;
    }

    /**
     * One entry per page id, first spelling wins.
     *
     * A bol listing page links the same product from its image, its title and
     * its "compare" control, so the raw scrape repeats itself several times
     * over. Collapsing here rather than in the extension keeps the endpoint
     * safe against any client, and first-wins because the earliest occurrence
     * is the one whose title came from the heading rather than from an image's
     * alt text.
     *
     * @param  list<array{productId: string, ean?: string|null, title?: string|null}>  $candidates
     * @return list<array{productId: string, ean?: string|null, title?: string|null}>
     */
    private function deduplicate(array $candidates): array
    {
        $byId = [];

        foreach ($candidates as $candidate) {
            $id = trim($candidate['productId']);

            if ($id === '' || isset($byId[$id])) {
                continue;
            }

            $candidate['productId'] = $id;
            $byId[$id] = $candidate;
        }

        return array_values(array_slice($byId, 0, self::MAX_PRODUCTS));
    }
}
