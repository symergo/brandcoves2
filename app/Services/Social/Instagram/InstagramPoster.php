<?php

declare(strict_types=1);

namespace App\Services\Social\Instagram;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\DailyPickSet;
use App\Models\InstagramPost;
use App\Services\Guides\CoveMarkup;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Decides what goes on GiftCoves' Instagram account and posts it (owner,
 * 2026-11-10): the day's Daily Cove, and each newly published guide once.
 *
 * - **The picture** is the Cove's own social card, as a JPEG
 *   (`/{market}/og/daily/{date}.jpg`, `/{market}/og/guide/{slug}.jpg`): the
 *   same card a shared link shows, drawn from the Cove's own text.
 * - **The caption** is the Cove's title and blurb, tokens flattened to words,
 *   then a line pointing at the site and the market's hashtags
 *   (`site.instagram.*`). No model call: the words were already written and
 *   approved for the page.
 * - **Once each**: a row in `instagram_posts` per Cove. Posted is final; a
 *   failure is retried on later runs until `max_attempts`.
 * - **Guides from the last `guide_window_days` only**, oldest first, so turning
 *   this on posts this week's guides in order instead of the whole archive.
 */
final class InstagramPoster
{
    public function __construct(
        private readonly InstagramClient $client,
        private readonly CoveMarkup $markup,
    ) {}

    public function market(): Market
    {
        return Market::tryFrom((string) config('giftcoves.instagram.market')) ?? Market::BeNl;
    }

    /** Today's published Daily Cove, if it has not been posted yet. */
    public function dueDaily(): ?DailyPickSet
    {
        return $this->unposted(
            DailyPickSet::query()
                ->forMarket($this->market())
                ->daily()
                ->published()
                ->whereDate('drop_date', now()->toDateString())
        )->first();
    }

    /** @return Collection<int, DailyPickSet> guides to post, oldest first */
    public function dueGuides(?int $limit = null): Collection
    {
        $days = (int) config('giftcoves.instagram.guide_window_days', 7);

        return $this->unposted(
            DailyPickSet::query()
                ->forMarket($this->market())
                ->whereIn('kind', array_map(
                    fn (CoveKind $k) => $k->value,
                    array_values(array_filter(CoveKind::cases(), fn (CoveKind $k) => $k->isArticle())),
                ))
                ->published()
                ->whereNotNull('slug')
                ->where('published_at', '>=', now()->subDays($days))
                ->orderBy('published_at')
                ->orderBy('id')
        )->limit($limit ?? (int) config('giftcoves.instagram.guides_per_run', 1))->get();
    }

    /**
     * Post one Cove and record the outcome. Never throws: the row says what
     * happened, and one refused post must not stop the next.
     */
    public function post(DailyPickSet $cove): InstagramPost
    {
        $row = InstagramPost::query()->firstOrNew(['daily_pick_set_id' => $cove->id]);
        $caption = $this->caption($cove);

        $row->fill([
            'market' => $cove->market->value,
            'caption' => $caption,
            'attempts' => (int) $row->attempts + 1,
        ]);

        try {
            $row->media_id = $this->client->publish($this->imageUrl($cove), $caption);
            $row->status = InstagramPost::POSTED;
            $row->error = null;
            $row->posted_at = now();
        } catch (Throwable $e) {
            report($e);
            $row->status = InstagramPost::FAILED;
            $row->error = Str::limit($e->getMessage(), 1000);
        }

        $row->save();

        return $row;
    }

    /** The JPEG version of the Cove's social card; Instagram takes no PNG. */
    public function imageUrl(DailyPickSet $cove): string
    {
        $path = $cove->kind === CoveKind::Daily
            ? 'og/daily/'.$cove->drop_date->toDateString().'.jpg'
            : "og/guide/{$cove->slug}.jpg";

        return $this->absolute($cove, $path);
    }

    /** Where the Cove is read, written out because Instagram captions do not link. */
    public function pageUrl(DailyPickSet $cove): string
    {
        $path = $cove->kind === CoveKind::Daily
            ? $cove->market->coveSegment().'/'.$cove->drop_date->toDateString()
            : "guides/{$cove->slug}";

        return $this->absolute($cove, $path);
    }

    public function caption(DailyPickSet $cove): string
    {
        $language = $cove->market->language();
        $title = trim((string) $cove->theme_title);
        $blurb = trim($this->markup->plain($cove->theme_blurb));
        $more = __(
            $cove->kind === CoveKind::Daily ? 'site.instagram.more_daily' : 'site.instagram.more_guide',
            ['url' => preg_replace('#^https?://#', '', $this->pageUrl($cove))],
            $language,
        );

        $parts = array_filter([
            $title,
            $blurb !== '' && $blurb !== $title ? $blurb : null,
            $more,
            __('site.instagram.hashtags', [], $language),
        ], fn (?string $part) => $part !== null && $part !== '');

        // Instagram's limit is 2,200 characters; a blurb is at most 300.
        return Str::limit(implode("\n\n", $parts), 2200, '');
    }

    /**
     * Not yet posted, or failed fewer times than the limit.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @return T
     */
    private function unposted($query)
    {
        $max = (int) config('giftcoves.instagram.max_attempts', 3);

        return $query->whereNotExists(function ($sub) use ($max): void {
            $sub->selectRaw('1')
                ->from('instagram_posts')
                ->whereColumn('instagram_posts.daily_pick_set_id', 'daily_pick_sets.id')
                ->where(fn ($q) => $q->where('status', InstagramPost::POSTED)->orWhere('attempts', '>=', $max));
        });
    }

    private function absolute(DailyPickSet $cove, string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.$cove->market->value.'/'.ltrim($path, '/');
    }
}
