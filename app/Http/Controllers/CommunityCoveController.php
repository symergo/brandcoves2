<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Wishlist;
use App\Services\Cove\CommunityCoves;
use App\Services\Cove\SavedCoves;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use App\Support\ListName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Community Coves: lists their owners published, for anybody to find, save
 * and copy. See docs/features/community-coves.md.
 *
 * What a stranger may see of a list is decided in `CommunityCoves`, not here;
 * this controller only chooses which of those payloads a page gets.
 */
class CommunityCoveController extends Controller
{
    public function index(Request $request, CurrentMarket $current, CommunityCoves $coves): Response
    {
        $sort = $request->query('sort') === 'saved' ? 'saved' : 'new';
        $page = $coves->listing($current->get(), $sort);

        app(PageMeta::class)->set(
            title: __('site.community.index_title'),
            description: __('site.community.index_description'),
            // Page 2 onwards is its own page, as on search (seo.md); the sort
            // is a view of the same listing.
            canonical: $page->currentPage() > 1
                ? url($current->url('coves/community')).'?page='.$page->currentPage()
                : url($current->url('coves/community')),
            /*
             * Indexable, by the owner's rule that every public page is
             * (seo.md, 2026-09-12): it is a listing like /coves, with our own
             * heading and intro. The Cove pages it links to are the ones held
             * back until they earn it (CommunityCoves::isIndexable).
             */
            robots: null,
        );

        return Inertia::render('Coves/Community', [
            'coves' => collect($page->items())->map(fn (Wishlist $list) => $coves->card($list))->values()->all(),
            'sort' => $sort,
            'links' => [
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
        ]);
    }

    public function show(CurrentMarket $current, CommunityCoves $coves, SavedCoves $saved, string $market, string $slug): Response
    {
        $list = $coves->find($current->get(), $slug);

        if ($list === null) {
            // Unpublished or taken down: say it is gone for good, so search
            // engines drop it, rather than that it never existed.
            throw $coves->existed($current->get(), $slug) ? new GoneHttpException : new NotFoundHttpException;
        }

        $items = $coves->presentItems($list);
        $describe = $coves->describe($list);
        $count = count($items);

        app(PageMeta::class)->set(
            title: (string) $list->public_title,
            description: __('site.community.meta_description', [
                'about' => $describe === [] ? __('site.community.a_list') : implode(', ', $describe),
                'count' => $count,
            ]),
            image: collect($items)->first(fn (array $item) => $item['image'] !== null)['image'] ?? null,
            canonical: url($coves->url($list)),
            robots: $coves->isIndexable($list) ? null : 'noindex, follow',
        );

        return Inertia::render('Coves/CommunityCove', [
            'cove' => [
                'title' => (string) $list->public_title,
                'about' => $describe,
                'by' => $coves->ownerName($list),
                'saves' => (int) $list->saves_count,
                'publishedAt' => $list->published_at?->toDateString(),
            ],
            'items' => $items,
            'save' => $saved->listButton($list, $current->url('')),
            'reportUrl' => $current->url('help').'#contact',
            'indexUrl' => $current->url('coves/community'),
        ]);
    }

    public function save(Request $request, CurrentMarket $current, CommunityCoves $coves, SavedCoves $saved, string $market, string $slug): RedirectResponse
    {
        $saved->saveList($request->user(), $this->published($coves, $current, $slug));

        return back();
    }

    public function unsave(Request $request, CurrentMarket $current, SavedCoves $saved, string $market, string $slug): RedirectResponse
    {
        // Letting go works whatever state the Cove is in: the bookmark is the
        // person's, even after its owner unpublished it.
        $list = Wishlist::query()->where('public_slug', $slug)->first();

        if ($list !== null) {
            $saved->unsaveList($request->user(), $list);
        }

        return back();
    }

    public function copy(Request $request, CurrentMarket $current, CommunityCoves $coves, SavedCoves $saved, string $market, string $slug): RedirectResponse
    {
        $list = $saved->copyListToList($request->user(), $this->published($coves, $current, $slug));

        return redirect()->to($current->url("lists/{$list->id}"))
            ->with(ListName::flash(ListName::mention('site.saved_coves.copied', (string) $list->title, $list->kind)));
    }

    /** Only a Cove on the site now can be saved or copied; anything else is a 404. */
    private function published(CommunityCoves $coves, CurrentMarket $current, string $slug): Wishlist
    {
        return $coves->find($current->get(), $slug) ?? throw new NotFoundHttpException;
    }
}
