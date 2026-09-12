<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Mail\ListPriceDigestMail;
use App\Models\Notification;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Alerts\ListPriceWatch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * One mail a morning per person: what got cheaper on the lists they watch.
 *
 * Runs once a day after RefreshWishlistedProducts has re-fetched the live
 * offers, so a bol price is today's. Every watched list is seeded for items
 * added since the last pass (silently), classified, and its references moved;
 * then the lists with something to report are folded into one
 * ListPriceDigestMail per owner, plus one inbox notification per list.
 *
 * A person, not a list, is the unit of the mail: somebody watching three lists
 * should not get three mails at 07:40. The language is the owner's preferred
 * market's, falling back to the first list's, because the lists themselves
 * may hold products from several markets at once.
 *
 * Unique, as the other scheduled jobs are: a replayed schedule must not seed
 * twice or mail twice. See docs/features/list-price-watch.md.
 */
class SendListPriceDigests implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return 'send-list-price-digests';
    }

    public function handle(?ListPriceWatch $watch = null): void
    {
        // Injected by the queue; resolved here for the tests that run the job
        // by hand.
        $watch ??= app(ListPriceWatch::class);

        $lists = 0;
        $reported = 0;
        $mails = 0;

        Wishlist::query()
            ->whereNotNull('price_watch_percent')
            ->whereNotNull('owner_user_id')
            ->with('owner')
            ->orderBy('owner_user_id')
            ->orderBy('id')
            ->get()
            ->groupBy('owner_user_id')
            ->each(function (Collection $owned) use ($watch, &$lists, &$reported, &$mails): void {
                $owner = $owned->first()?->owner;

                if ($owner === null) {
                    return;
                }

                $market = Market::tryFrom((string) $owner->preferred_market) ?? $owned->first()->market;
                $language = $market->language();
                $sections = [];

                foreach ($owned as $list) {
                    $lists++;
                    $watch->seed($list);

                    $changes = $watch->changes($list);
                    $watch->apply($changes);

                    $section = $this->section($list, $changes, $language);

                    if ($section === null) {
                        continue;
                    }

                    $count = count($section['drops']) + count($section['back']);
                    $reported += $count;
                    $sections[] = $section;

                    Notification::create([
                        'user_id' => $list->owner_user_id,
                        'kind' => 'list_price_digest',
                        'title' => $section['title'],
                        'body' => null,
                        'url' => "/{$list->market->value}/lists/{$list->id}",
                        'payload' => ['list_id' => $list->id, 'count' => $count],
                    ]);
                }

                if ($sections === [] || blank($owner->email)) {
                    return;
                }

                Mail::to($owner)->send(new ListPriceDigestMail($market, $sections));
                $mails++;
            });

        Log::info('List price digests complete', [
            'lists' => $lists,
            'reported' => $reported,
            'mails' => $mails,
        ]);
    }

    /**
     * One list's part of the mail, or null when nothing on it is worth a line.
     *
     * Only `drop` and `back` are shown; `gone` and `up` moved a reference and
     * say nothing. Titles are the item's snapshot, the words the owner chose;
     * links are our own product page, never a shop.
     *
     * @param  list<array{item: WishlistItem, kind: string, was: int|null, now: int|null, percent: int|null}>  $changes
     * @return array{title: string, url: string, drops: list<array<string, mixed>>, back: list<array<string, mixed>>}|null
     */
    private function section(Wishlist $list, array $changes, string $language): ?array
    {
        $drops = [];
        $back = [];

        foreach ($changes as $change) {
            if ($change['kind'] !== 'drop' && $change['kind'] !== 'back') {
                continue;
            }

            $item = $change['item'];
            $line = [
                'title' => (string) $item->snapshot_title,
                'url' => url($item->snapshot_url ?? $item->group?->path() ?? "/{$list->market->value}/lists/{$list->id}"),
                'was' => $change['was'],
                'now' => $change['now'],
                'percent' => $change['percent'],
            ];

            if ($change['kind'] === 'drop') {
                $drops[] = $line;
            } else {
                $back[] = $line;
            }
        }

        if ($drops === [] && $back === []) {
            return null;
        }

        return [
            'title' => $list->displayTitle($language),
            'url' => url("/{$list->market->value}/lists/{$list->id}"),
            'drops' => $drops,
            'back' => $back,
        ];
    }
}
