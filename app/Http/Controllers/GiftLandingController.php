<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GiftLanding;
use App\Models\ProductGroup;
use App\Services\Gift\BriefUrl;
use App\Services\Gift\GiftLandingCopy;
use App\Services\Gift\GiftLandingLinks;
use App\Services\Gift\Suggestion;
use App\Services\Gift\SuggestionEngine;
use App\Services\Seo\PageMeta;
use App\Services\Seo\StructuredData;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A gift landing page: `/{market}/gift-ideas/for/{recipient}/{interest}`.
 *
 * "Gift ideas for dad who loves cooking", answered by the suggestion engine
 * from the brief the address describes. Roadmap step 4, part 2; see
 * docs/features/gift-landing-pages.md.
 *
 * - **Only recorded pages exist.** PlanGiftLandingPages records the pairs the
 *   catalogue fills with eight products or more; anything else is a 404, so
 *   hundreds of thin combinations never reach the index.
 * - **No AI.** The engine is retrieval and arithmetic, and the words come
 *   from templates (GiftLandingCopy). Invariant 1.
 * - **Cached a day** per page and budget, keyed on the night's plan, so the
 *   engine runs once per page per day rather than once per visitor.
 * - **`?budget=` narrows, it does not make a new page.** The canonical is
 *   always the bare address, the way a filtered search canonicalises to
 *   its term.
 */
class GiftLandingController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentMarket $current,
        SuggestionEngine $engine,
        GiftLandingLinks $links,
        string $market,
        string $recipient,
        ?string $interest = null,
    ): Response|RedirectResponse {
        $marketEnum = $current->get();

        $type = BriefUrl::recipient($marketEnum, $recipient);
        $topic = $interest === null ? null : BriefUrl::interest($marketEnum, $interest);

        if ($type === null || ($interest !== null && $topic === null)) {
            throw new NotFoundHttpException;
        }

        $page = GiftLanding::lookup($marketEnum, $type, $topic);

        if ($page === null) {
            throw new NotFoundHttpException;
        }

        $budget = BriefUrl::budget(is_string($request->query('budget')) ? $request->query('budget') : null);
        $canonical = BriefUrl::path($marketEnum, $type, $topic);

        /*
         * Another language's words for the same page ("/be-fr/.../papa/koken")
         * land on this market's address. Permanent, because the address it
         * asked for will never be the right one here.
         */
        if ('/'.trim($request->path(), '/') !== $canonical) {
            return redirect()->to(BriefUrl::path($marketEnum, $type, $topic, $budget), 301);
        }

        $pageSize = (int) config('giftcoves.gift_landings.page_size', 24);
        $brief = $page->toBrief($pageSize);

        if ($budget !== null) {
            $brief = $brief->withBudget($budget[0], $budget[1]);
        }

        $ids = Cache::remember(
            sprintf('bc:gift-landing:%d:%d:%s', $page->id, $page->checked_at->getTimestamp(), BriefUrl::budgetParam($budget[0] ?? null, $budget[1] ?? null) ?? 'all'),
            (int) config('giftcoves.gift_landings.cache_ttl', 86400),
            fn (): array => array_map(fn (Suggestion $s) => $s->group->id, $engine->suggest($brief)),
        );

        $groups = ProductGroup::query()
            ->forMarket($marketEnum)
            ->presentable()
            ->whereIn('id', $ids === [] ? [0] : $ids)
            ->get()
            ->keyBy('id');

        // In the engine's order: the best fit first.
        $products = array_values(array_filter(array_map(fn (int $id) => $groups->get($id), $ids)));

        $copy = new GiftLandingCopy($marketEnum, $type, $topic);

        $this->seo($copy, $page, $current, $canonical, $links, count($products));

        return Inertia::render('GiftIdeas/Landing', [
            'heading' => $copy->heading(),
            'intro' => $copy->intro(),
            'isRecipientPage' => $topic === null,
            'products' => array_map($this->card(...), $products),
            'budget' => [
                'current' => BriefUrl::budgetParam($budget[0] ?? null, $budget[1] ?? null),
                'anyUrl' => $canonical,
                'options' => array_map(fn (array $band) => [
                    'min' => $band[0],
                    'max' => $band[1],
                    'param' => BriefUrl::budgetParam($band[0], $band[1]),
                    'url' => BriefUrl::path($marketEnum, $type, $topic, $band),
                ], BriefUrl::BUDGETS),
            ],
            'moreFor' => [
                'title' => $copy->moreFor(),
                'links' => $links->moreFor($marketEnum, $type, $topic),
                'hub' => $topic === null ? null : $links->hub($marketEnum, $type),
            ],
            'sameInterest' => $topic === null ? null : [
                'title' => $copy->sameInterest(),
                'links' => $links->sameInterest($marketEnum, $topic, $type),
            ],
            'finderUrl' => $current->url('gift'),
        ]);
    }

    private function seo(GiftLandingCopy $copy, GiftLanding $page, CurrentMarket $current, string $canonical, GiftLandingLinks $links, int $shown): void
    {
        $meta = app(PageMeta::class);

        $meta->set(
            title: $copy->title(),
            // The recorded count, not what this budget shows: the description
            // is about the page, which is what the canonical names.
            description: $copy->description(max($shown, $page->product_count)),
            canonical: url($canonical),
        );

        $meta->setAlternates($links->alternates($page->recipient, $page->interest));

        $trail = [
            ['name' => 'GiftCoves', 'url' => url($current->url())],
            ['name' => __('site.gift_ideas.title'), 'url' => url($current->url('gift-ideas'))],
        ];

        if ($page->interest !== null && ($hub = $links->hub($current->get(), $page->recipient)) !== null) {
            $trail[] = [
                'name' => (new GiftLandingCopy($current->get(), $page->recipient))->heading(),
                'url' => url($hub),
            ];
        }

        $trail[] = ['name' => $copy->heading(), 'url' => url($canonical)];

        $meta->addJsonLd(StructuredData::breadcrumbs($trail));
    }

    /**
     * The same card as a search result, so the page reads like the rest of
     * the site. Cents cross the wire as stored; the client formats them.
     *
     * @return array<string, mixed>
     */
    private function card(ProductGroup $group): array
    {
        return [
            'id' => $group->id,
            'title' => $group->displayTitle(),
            'slug' => $group->slug,
            'brand' => $group->brand,
            'image' => $group->image_url,
            'minPrice' => $group->min_price,
            'maxPrice' => $group->max_price,
            'offerCount' => $group->offer_count,
            'merchantCount' => $group->merchant_count,
            'inStock' => $group->in_stock,
            'discountPercent' => $group->discountPercent(),
        ];
    }
}
