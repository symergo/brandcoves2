<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Market;
use App\Http\Controllers\Controller;
use App\Models\ProductGroup;
use App\Services\Editorial\HouseStyle;
use App\Services\Editorial\UntitledProducts;
use App\Services\Gift\Giftability;
use App\Services\Gift\GiftTags;
use App\Services\Gift\TaggingQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gift-friendly product titles and gift tags, written outside and posted in.
 *
 * The feed's title stays where it is; what a visitor reads is
 * `product_groups.display_title` when one has been written, and the
 * mechanical cleaner otherwise. Nothing in the application writes this column
 * — no job, no model call — which is the point: the titles are authored, the
 * way Coves are, by whoever holds a publish key. See
 * docs/features/display-titles.md for what a good one looks like.
 *
 * The write sits under the publish ability rather than write, because a title
 * reaches every reader on the next request. "Nothing under write can reach a
 * reader" is the promise the route groups make, and this would break it.
 */
class ProductTitleController extends Controller
{
    /** Titles per write. Enough for a batch, small enough that a 422 names something findable. */
    private const BATCH = 200;

    public function __construct(private readonly UntitledProducts $untitled) {}

    /**
     * The products on an editorial surface that still carry the feed's title.
     */
    public function untitled(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market' => ['required', 'string', Rule::in(Market::values())],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $market = Market::from($data['market']);
        $rows = $this->untitled->list($market, (int) ($data['limit'] ?? 200), isset($data['after']) ? (int) $data['after'] : null);

        return response()->json([
            'market' => $market->value,
            'count' => count($rows),
            'data' => $rows,
        ]);
    }

    /**
     * The products on an editorial surface with no gift tags yet, and the
     * vocabulary to tag them from, in one call.
     */
    public function untagged(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market' => ['required', 'string', Rule::in(Market::values())],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $market = Market::from($data['market']);
        $rows = $this->untitled->list(
            $market,
            (int) ($data['limit'] ?? 200),
            isset($data['after']) ? (int) $data['after'] : null,
            UntitledProducts::MISSING_TAGS,
        );

        return response()->json([
            'market' => $market->value,
            'count' => count($rows),
            'vocabulary' => GiftTags::vocabulary(),
            'data' => $rows,
        ]);
    }

    /**
     * The tagging queue: products saved to a wish list, or new in the
     * catalogue, that nobody has judged yet. The admin's Product tagging page
     * hands out a prompt that reads this. See TaggingQueue.
     */
    public function toTag(Request $request, TaggingQueue $queue): JsonResponse
    {
        $data = $request->validate([
            'market' => ['required', 'string', Rule::in(Market::values())],
            'source' => ['required', 'string', Rule::in(TaggingQueue::SOURCES)],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $market = Market::from($data['market']);
        $days = (int) ($data['days'] ?? 7);
        $rows = $queue->list(
            $market,
            $data['source'],
            $days,
            (int) ($data['limit'] ?? 200),
            isset($data['after']) ? (int) $data['after'] : null,
        );

        return response()->json([
            'market' => $market->value,
            'source' => $data['source'],
            'days' => $days,
            'waiting' => $queue->count($market, $data['source'], $days),
            'count' => count($rows),
            'vocabulary' => GiftTags::vocabulary(),
            'data' => $rows,
        ]);
    }

    /**
     * Write gift tags, all or nothing, replacing what a product had.
     *
     * Replacing rather than merging, so a wrong tag can be taken off by
     * sending the set without it; an empty set clears. A tag outside the
     * vocabulary refuses the batch and names it, the same as a foreign id:
     * a vocabulary that grows by typo is not a vocabulary.
     *
     * An entry may also carry `giftable`, the editor's verdict on whether the
     * product is a present at all. It lands in `giftable_override`, which the
     * classification pass lets win, and in the verdict columns at once so the
     * gift engine reads it on the next request. `null` withdraws it, and the
     * rules decide again on their next pass. Left out, nothing about it
     * changes. See docs/features/giftability.md.
     */
    public function storeTags(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market' => ['required', 'string', Rule::in(Market::values())],
            'tags' => ['required', 'array', 'min:1', 'max:'.self::BATCH],
            'tags.*.id' => ['required', 'integer'],
            'tags.*.tags' => ['present', 'array', 'max:20'],
            'tags.*.tags.*' => ['string', 'max:40'],
            'tags.*.giftable' => ['sometimes', 'nullable', 'boolean'],
        ]);

        $market = Market::from($data['market']);

        $unknown = GiftTags::unknown(array_merge([], ...array_column($data['tags'], 'tags')));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'tags' => 'Not in the vocabulary: '.implode(', ', $unknown).'. Nothing was written. The vocabulary is in GET /products/untagged.',
            ]);
        }

        $sets = [];
        $verdicts = [];

        foreach ($data['tags'] as $entry) {
            $sets[(int) $entry['id']] = GiftTags::normalise($entry['tags']);

            if (array_key_exists('giftable', $entry)) {
                $verdicts[(int) $entry['id']] = $entry['giftable'] === null ? null : (bool) $entry['giftable'];
            }
        }

        $ids = array_keys($sets);

        $known = ProductGroup::query()
            ->forMarket($market)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        $strangers = array_values(array_diff($ids, $known));

        if ($strangers !== []) {
            throw ValidationException::withMessages([
                'tags' => "Not products in {$market->value}: ".implode(', ', $strangers).'. Nothing was written.',
            ]);
        }

        DB::transaction(function () use ($sets, $verdicts, $market): void {
            foreach ($sets as $id => $tags) {
                // gift_tags_at says "judged", which empty tags cannot: it is
                // what takes a product out of the tagging queue.
                $columns = ['gift_tags' => json_encode($tags), 'gift_tags_at' => now()];

                if (array_key_exists($id, $verdicts)) {
                    $columns['giftable_override'] = $verdicts[$id];

                    if ($verdicts[$id] !== null) {
                        $verdict = Giftability::byEditor($verdicts[$id]);
                        $columns['giftable'] = $verdict->giftable;
                        $columns['worth_showing'] = $verdict->worthShowing;
                        $columns['giftable_reason'] = $verdict->reason;
                    }
                }

                ProductGroup::query()
                    ->forMarket($market)
                    ->whereKey($id)
                    ->update($columns);
            }
        });

        $groups = ProductGroup::query()
            ->forMarket($market)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get(['id', 'title', 'display_title', 'gift_tags', 'giftable', 'giftable_override']);

        return response()->json([
            'market' => $market->value,
            'count' => $groups->count(),
            'data' => $groups->map(fn (ProductGroup $group): array => [
                'id' => $group->id,
                'displayTitle' => $group->displayTitle(),
                'tags' => $group->giftTags(),
                'giftable' => $group->giftable,
                'giftableByEditor' => $group->giftable_override,
            ])->all(),
        ]);
    }

    /**
     * Write display titles, all or nothing.
     *
     * One foreign id refuses the whole batch and names it, on the same
     * reasoning `ProductLookup::rejectUnusable()` gives: a batch that half
     * lands is a batch whose author does not know what landed. `null` clears
     * a title, so a bad one can be withdrawn without inventing a better one.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market' => ['required', 'string', Rule::in(Market::values())],
            'titles' => ['required', 'array', 'min:1', 'max:'.self::BATCH],
            'titles.*.id' => ['required', 'integer'],
            'titles.*.title' => ['present', 'nullable', 'string', 'max:200'],
        ]);

        $market = Market::from($data['market']);

        $titles = [];

        foreach ($data['titles'] as $entry) {
            $title = $entry['title'] === null ? null : trim((string) HouseStyle::plain($entry['title']));

            if ($title !== null && mb_strlen($title) < 3) {
                throw ValidationException::withMessages([
                    'titles' => "A title of fewer than three characters is not a title (id {$entry['id']}).",
                ]);
            }

            if ($title !== null && mb_strlen($title) > 80) {
                throw ValidationException::withMessages([
                    'titles' => "A title over 80 characters is the feed's problem again (id {$entry['id']}, ".mb_strlen($title).').',
                ]);
            }

            $titles[(int) $entry['id']] = $title;
        }

        $ids = array_keys($titles);

        $known = ProductGroup::query()
            ->forMarket($market)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        $strangers = array_values(array_diff($ids, $known));

        if ($strangers !== []) {
            throw ValidationException::withMessages([
                'titles' => "Not products in {$market->value}: ".implode(', ', $strangers).'. Nothing was written.',
            ]);
        }

        DB::transaction(function () use ($titles, $market): void {
            foreach ($titles as $id => $title) {
                ProductGroup::query()
                    ->forMarket($market)
                    ->whereKey($id)
                    ->update(['display_title' => $title]);
            }
        });

        $groups = ProductGroup::query()
            ->forMarket($market)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get(['id', 'title', 'brand', 'display_title']);

        return response()->json([
            'market' => $market->value,
            'count' => $groups->count(),
            'data' => $groups->map(fn (ProductGroup $group): array => [
                'id' => $group->id,
                'title' => $group->title,
                'displayTitle' => $group->displayTitle(),
                'written' => $group->display_title !== null,
            ])->all(),
        ]);
    }
}
