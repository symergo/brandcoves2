<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Recipient;
use App\Models\SecretSantaGroup;
use App\Models\Wishlist;
use App\Services\Search\RecentSearches;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request, CurrentMarket $current): Response
    {
        /*
         * No catalogue counters.
         *
         * They were scaffolding — three COUNT(*) queries per homepage request,
         * on the largest table we have, to render a stat row that told a
         * shopper how big our warehouse is. Removed with the section that
         * displayed them; see the note in `Home.tsx`.
         */
        /*
         * The home page had no PageMeta at all, so it shipped with no meta
         * description and no og:title — the one page most likely to be linked
         * from outside was the one whose social card had no words on it.
         */
        app(PageMeta::class)->set(
            title: __('site.home.title'),
            description: __('site.home.seo_description'),
            canonical: url($current->url()),
        );

        return Inertia::render('Home', [
            /*
             * Today's Cove, on the front page.
             *
             * The thing that makes someone come back tomorrow should not be one
             * click deep. A visitor who lands on the home page and sees a dated
             * edition with real finds learns that this site changes; one who
             * sees a search box learns it is a search engine.
             */
            'today' => $this->today($current),

            /*
             * The gifting band.
             *
             * The homepage said "you don't know what you want, you know who it's
             * for" and then offered exactly one way to act on it. Everything
             * else gifting can do — a list somebody else fills in, a Secret
             * Santa, a quiz over a list — was reachable only by already knowing
             * the URL, which is how v1 shipped its gift finder unlinked and
             * unreachable for two months.
             *
             * Counts rather than prose where the visitor already has something:
             * "3 lists" is a reason to click and "Make a list" is not, once the
             * lists exist.
             */
            'gifting' => $this->gifting($request, $current),

            /*
             * The shelf of people, which the front page never showed.
             *
             * `coves` below is `articles()` — guides, seasonal guides and
             * advice — so a gift persona appeared on `/gift-ideas`, on `/coves`
             * and in the sitemap, and nowhere a first-time visitor would meet
             * one. On a market whose only other Coves are advice articles that
             * made the front page look like a consumer-rights blog.
             *
             * Its own band rather than six more rows in that one. The articles
             * band promises "long reads around a theme" and prints a monthly
             * search volume per card; a persona is neither — it is a person to
             * shop for, it has no search volume, and it is drawn rather than
             * described. Mixing them would have needed the intro to stop saying
             * what the cards are.
             */
            'personas' => $this->personas($current),

            // The evergreen half. Coves earn their traffic over years, so the
            // front page is where a first-time visitor discovers the archive
            // exists at all.
            'coves' => $this->coves($current),

            /*
             * What people have been searching for, with pictures.
             *
             * A cache read, never a computation. `search_log` stores queries
             * and not the products they returned, so this band only exists
             * because RefreshRecentSearches resolves those searches hourly and
             * caches the result — putting six searches on the busiest page on
             * the site is exactly the cost the catalogue counters were removed
             * for.
             *
             * Empty until the first run of that job, and on any market with no
             * search history. The band does not render at all in that case,
             * rather than showing an empty shelf.
             */
            'recentSearches' => app(RecentSearches::class)->for($current->get()),
        ]);
    }

    /**
     * The four ways in, and what this visitor already has.
     *
     * @return array<string, mixed>
     */
    private function gifting(Request $request, CurrentMarket $current): array
    {
        $owner = Owner::fromRequest($request);
        $user = $request->user();

        return [
            // Anonymous-first, exactly like the lists themselves: someone who
            // saved a product before signing up should see it here.
            'lists' => $owner->exists()
                ? $owner->scope(Wishlist::query())
                    ->where('market', $current->value())
                    ->count()
                : 0,

            'people' => $owner->exists()
                ? $owner->scope(Recipient::query())->count()
                : 0,

            // Signed-in only: a group has to belong to somebody who can be
            // reached when the draw happens.
            'santaGroups' => $user === null
                ? 0
                : SecretSantaGroup::query()
                    ->where('market', $current->value())
                    ->where('owner_user_id', $user->id)
                    ->count(),

            // A registry, if this visitor has one. Null is the ordinary case,
            // and the card then explains what one is instead of naming it.
            'registry' => $this->registry($owner, $current),

            'urls' => [
                'gift' => $current->url('gift'),
                'lists' => $current->url('lists'),
                'santa' => $current->url('santa'),
            ],
        ];
    }

    /**
     * The next occasion the visitor is buying towards, on any list of theirs.
     *
     * **This was "the visitor's own registry", and the query has not changed —
     * the world under it has.** It has always looked for `event_type` rather
     * than for a kind, on the correct reasoning that a registry is not a fourth
     * kind of list. Once an occasion could be set on a list *about somebody
     * else*, that same query started returning gift lists, and the card calling
     * one "your registry" would tell somebody their research about their father
     * was a wedding list of their own.
     *
     * So the card is about the occasion now, which is the more useful nudge in
     * any case: a birthday you are shopping for beats a registry most people
     * never create. `kind` rides along so the copy can tell the two apart.
     *
     * The **soonest upcoming** one, not the newest. A registry is a date people
     * are buying towards, and last summer's wedding is not the one you are
     * still adding to — while a list with a date in the past is exactly the one
     * that should stop occupying the front page. Past dates are excluded
     * outright; a registry with no date at all still counts, because the
     * occasion alone is enough to make it one.
     *
     * Carries no claim state of any kind. This is the owner's own front page
     * (invariant #4), so it says what the list is *for* and never how much of
     * it has been bought.
     *
     * @return array<string, mixed>|null
     */
    private function registry(Owner $owner, CurrentMarket $current): ?array
    {
        if (! $owner->exists()) {
            return null;
        }

        $registry = $owner->scope(Wishlist::query())
            ->with('recipient')
            ->where('market', $current->value())
            ->whereNotNull('event_type')
            ->where(fn ($q) => $q
                ->whereNull('event_date')
                ->orWhere('event_date', '>=', now()->toDateString()))
            // Nulls last, so a dated registry outranks an undated one rather
            // than losing to it on an ORDER BY that treats null as smallest.
            ->orderByRaw('event_date IS NULL, event_date')
            ->first();

        if ($registry === null) {
            return null;
        }

        return [
            'title' => $registry->displayTitle(),
            'occasion' => $registry->event_type->label(),
            'date' => $registry->event_date?->toDateString(),

            /*
             * Whose occasion it is, so the card can say "Dad's birthday" rather
             * than implying the visitor is the one being bought for. Null on a
             * wish list of their own, where the answer is them.
             */
            'for' => $registry->kind->isForSomeoneElse()
                ? $registry->recipient?->name
                : null,
            'url' => $current->url("lists/{$registry->id}"),
        ];
    }

    /** @return array<string, mixed>|null */
    private function today(CurrentMarket $current): ?array
    {
        $edition = DailyPickSet::query()
            ->forMarket($current->get())
            // daily(), and not for tidiness: a persona has no drop date, and
            // Postgres sorts DESC with NULLS FIRST — so without this the newest
            // gift persona is served as today's edition, on the front page.
            ->daily()
            ->published()
            ->with(['picks.group'])
            ->orderByDesc('drop_date')
            ->first();

        if ($edition === null) {
            return null;
        }

        return [
            'theme' => $edition->theme_title,
            'blurb' => $edition->theme_blurb,
            'date' => $edition->drop_date->toDateString(),
            'label' => $edition->drop_date->format('j M'),
            'url' => $current->url('daily'),
            // A few, not all: the front page is an invitation to the edition,
            // not a copy of it.
            'finds' => $edition->picks
                // In stock, like everywhere else: the front page is the first
                // thing a visitor sees, and an unbuyable product is a worse
                // first impression than one fewer card.
                ->filter(fn (DailyPick $pick) => $pick->group !== null && $pick->group->in_stock)
                ->take(4)
                ->map(fn (DailyPick $pick) => [
                    'id' => $pick->group->id,
                    'title' => $pick->group->title,
                    'image' => $pick->group->image_url,
                    'price' => $pick->group->min_price,
                    'url' => $current->url("p/{$pick->group->id}/{$pick->group->slug}"),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Gift personas, newest first.
     *
     * Three, not six: the grid is three wide, and this band sits above the
     * articles one on a page that already carries five sections. A full row is
     * enough to say the shelf exists, which is what the front page owes it —
     * "All gift ideas" carries the rest.
     *
     * Ordered by `published_at` like the shelf at `/gift-ideas`, and for the
     * same reason: a persona has no date, and that stamp is set once at first
     * build and never refreshed by a rebuild. Anything else would reshuffle the
     * front page whenever a persona's products were refreshed, which is
     * movement no reader could account for.
     *
     * @return list<array<string, mixed>>
     */
    private function personas(CurrentMarket $current): array
    {
        return DailyPickSet::query()
            ->forMarket($current->get())
            ->personas()
            ->published()
            // The count below walks the picks, so they are loaded rather than
            // counted one persona at a time.
            ->with(['picks.group'])
            ->orderByDesc('published_at')
            ->limit(3)
            ->get()
            ->map(fn (DailyPickSet $persona) => [
                'title' => $persona->theme_title,
                'blurb' => $persona->theme_blurb,
                'url' => $current->url('gift-ideas/'.$persona->slug),
                /*
                 * The drawing, not a product photograph — the same choice the
                 * shelf makes. A cover taken from the first buyable find makes
                 * a row of *people* look like a row of products, and changes
                 * face whenever stock does.
                 */
                'scene' => $persona->scene?->value,
                // In stock only. A count that includes what nobody can buy is a
                // promise the page does not keep.
                'findCount' => $persona->picks
                    ->filter(fn ($pick) => $pick->group !== null && $pick->group->in_stock)
                    ->count(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function coves(CurrentMarket $current): array
    {
        return DailyPickSet::query()
            ->forMarket($current->get())
            ->articles()
            ->published()
            ->orderByDesc('published_at')
            ->limit(6)
            ->get(['id', 'kind', 'slug', 'theme_title', 'theme_blurb', 'source_volume'])
            ->map(fn (DailyPickSet $guide) => [
                'title' => $guide->theme_title,
                'intro' => $guide->theme_blurb,
                'url' => $current->url($guide->kind->path((string) $guide->slug)),
                // Why it exists, and a fact no competitor has.
                'searches' => $guide->source_volume,
            ])
            ->all();
    }
}
