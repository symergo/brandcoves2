<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Guide;
use App\Services\Cove\PriceHunt;
use App\Services\Seo\PageMeta;
use App\Services\Seo\StructuredData;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Daily Cove: one page a day, three beats.
 *
 * A guess, a themed set of finds, and a buying guide. Merged rather than kept
 * apart because each covers the other's hole — picks alone give no reason to
 * return once the novelty fades, and guides alone have no audience on the day
 * they publish. See docs/features/daily-cove.md.
 *
 * Every edition keeps a permanent URL. The archive is the SEO asset: ninety days
 * in, that is ninety indexed pages per market, each one a guide plus a set of
 * products plus a puzzle.
 */
class DailyCoveController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentMarket $current,
        PriceHunt $hunt,
        string $market,
        ?string $date = null,
    ): Response {
        $edition = $this->findEdition($current, $date);

        if ($edition === null) {
            throw new NotFoundHttpException;
        }

        $owner = Owner::fromRequest($request);
        $attempt = $hunt->existingAttempt($edition, $owner);

        $this->seo($edition, $current, $date !== null);

        return Inertia::render('Daily/Edition', [
            'edition' => [
                'id' => $edition->id,
                'date' => $edition->drop_date->toDateString(),
                'label' => $edition->drop_date->format('j M Y'),
                'theme' => $edition->theme_title,
                'blurb' => $edition->theme_blurb,
                'isToday' => $edition->drop_date->isToday(),
            ],

            /*
             * Beat 1. The answer is absent from this payload until the round is
             * over — not hidden in the UI, absent. A price sent "for later" is
             * a price anyone can read in DevTools, and one person doing that
             * ruins the shared-puzzle premise for everyone they post to.
             */
            'challenge' => $this->challenge($edition, $hunt, $attempt),

            'finds' => $this->finds($edition, $current),
            'guide' => $this->guide($edition, $current),
            'streak' => $hunt->streak($owner),
            'archive' => $this->archive($current, $edition),
        ]);
    }

    private function findEdition(CurrentMarket $current, ?string $date): ?DailyPickSet
    {
        $query = DailyPickSet::query()
            ->forMarket($current->get())
            ->published()
            ->with(['picks.group']);

        if ($date === null) {
            return $query->orderByDesc('drop_date')->first();
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $date);
        } catch (\Throwable) {
            return null;
        }

        // A future date is a 404, not an empty page. Guessing tomorrow's puzzle
        // by URL would be an obvious hole in a daily game.
        if ($parsed === false || $parsed->isFuture()) {
            return null;
        }

        return $query->where('drop_date', $parsed->toDateString())->first();
    }

    /** @return array<string, mixed>|null */
    private function challenge(DailyPickSet $edition, PriceHunt $hunt, $attempt): ?array
    {
        $group = $edition->challengeGroup;

        if ($group === null || $edition->challenge_price === null) {
            return null;
        }

        $state = $hunt->state($attempt, (int) $edition->challenge_price);

        return [
            'title' => $group->title,
            'brand' => $group->brand,
            'image' => $group->image_url,
            'category' => $group->category,
            'merchantCount' => $group->merchant_count,
            'maxAttempts' => PriceHunt::MAX_ATTEMPTS,
            ...$state,
            // Only once the round is over, so the link cannot be used to look
            // the answer up mid-round.
            'productUrl' => $state['finished']
                ? "/{$edition->market->value}/p/{$group->id}/{$group->slug}"
                : null,
            'community' => $state['finished'] ? $hunt->communityResult($edition) : null,
            'shareLabel' => $edition->drop_date->format('j M'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function finds(DailyPickSet $edition, CurrentMarket $current): array
    {
        return $edition->picks
            ->filter(fn (DailyPick $pick) => $pick->group !== null)
            ->map(fn (DailyPick $pick) => [
                'id' => $pick->id,
                'groupId' => $pick->group->id,
                'title' => $pick->group->title,
                'image' => $pick->group->image_url,
                'price' => $pick->group->min_price,
                'merchantCount' => $pick->group->merchant_count,
                'discountPercent' => $pick->discount_percent,
                'blurb' => $pick->blurb,
                'url' => $current->url("p/{$pick->group->id}/{$pick->group->slug}"),
                'mindblown' => $pick->mindblown_count,
                'meh' => $pick->meh_count,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function guide(DailyPickSet $edition, CurrentMarket $current): ?array
    {
        $guide = $edition->guide;

        if ($guide === null) {
            return null;
        }

        return [
            'title' => $guide->title,
            'intro' => $guide->intro,
            'url' => $current->url("guides/{$guide->slug}"),
            'itemCount' => $guide->items()->count(),
            // The demand that justified writing it. Shown because it is the
            // honest answer to "why this guide" and because it is a fact only
            // this site has.
            'searchVolume' => $guide->source_volume,
        ];
    }

    /**
     * Recent editions, for the archive strip.
     *
     * @return list<array<string, mixed>>
     */
    private function archive(CurrentMarket $current, DailyPickSet $edition): array
    {
        return DailyPickSet::query()
            ->forMarket($current->get())
            ->published()
            ->where('id', '!=', $edition->id)
            ->orderByDesc('drop_date')
            ->limit(7)
            ->get(['drop_date', 'theme_title'])
            ->map(fn (DailyPickSet $set) => [
                'date' => $set->drop_date->toDateString(),
                'label' => $set->drop_date->format('j M'),
                'theme' => $set->theme_title,
                'url' => $current->url('daily/'.$set->drop_date->toDateString()),
            ])
            ->all();
    }

    private function seo(DailyPickSet $edition, CurrentMarket $current, bool $isArchive): void
    {
        $url = $isArchive
            ? url($current->url('daily/'.$edition->drop_date->toDateString()))
            : url($current->url('daily'));

        $meta = app(PageMeta::class);

        $meta->set(
            title: $edition->theme_title,
            description: $edition->theme_blurb ?? __('site.daily.seo_description'),
            canonical: $url,
        );

        /*
         * Today's edition canonicalises to /daily, and its dated twin
         * canonicalises to itself. Without this the same content sits at two
         * URLs for a day and the archive copy competes with the live page for
         * the same query.
         */
        $meta->addJsonLd(StructuredData::breadcrumbs([
            ['name' => 'Brandcoves', 'url' => url($current->url())],
            ['name' => __('site.daily.title'), 'url' => url($current->url('daily'))],
            ['name' => $edition->theme_title, 'url' => $url],
        ]));
    }
}
