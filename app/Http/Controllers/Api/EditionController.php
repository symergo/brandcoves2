<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\BuildDailyEdition;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Services\Editorial\LinkCheck;
use App\Services\Editorial\ProductLookup;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What actually got published.
 *
 * The half of the loop that makes the rest usable. An author who writes a plan
 * and never sees the result is writing blind: they cannot tell whether their
 * pins survived, whether the engine filled the remaining slots with something
 * that contradicts their paragraph, or whether the product they linked to is on
 * the page at all.
 *
 * The link report is the sharpest part. It runs the published prose through the
 * real renderer against the real allowlist, so `unresolved` is not a guess — it
 * is the exact list of phrases that a reader sees as plain text where a link
 * was intended.
 *
 * Unlike the public page this shows future and unpublished editions: an author
 * building tomorrow's Cove needs to read it today, and the reason the public
 * route hides them — guessing tomorrow's puzzle by URL — does not apply to a
 * holder of an editorial key.
 */
class EditionController extends Controller
{
    public function __construct(
        private readonly ProductLookup $lookup,
        private readonly LinkCheck $links,
    ) {}

    public function show(string $market, string $date): JsonResponse
    {
        $resolved = CatalogueController::market($market);
        $day = $this->date($date);

        $edition = DailyPickSet::query()
            ->where('market', $resolved->value)
            ->daily()
            ->whereDate('drop_date', $day->toDateString())
            ->with(['picks.group', 'featured'])
            ->first();

        if ($edition === null) {
            throw new NotFoundHttpException(
                "No edition for {$resolved->value} on {$day->toDateString()}. Build one, or check the date."
            );
        }

        $groups = $edition->picks
            ->map(fn (DailyPick $pick) => $pick->group)
            ->filter()
            ->values();

        return response()->json([
            'data' => [
                'id' => $edition->id,
                'market' => $edition->market->value,
                'date' => $edition->drop_date->toDateString(),
                'status' => $edition->status->value,
                'publishedAt' => $edition->published_at?->toIso8601String(),
                'url' => '/'.$edition->market->value.'/daily/'.$edition->slug,

                'theme' => [
                    'title' => $edition->theme_title,
                    'blurb' => $edition->theme_blurb,
                    /*
                     * Where the day's identity came from. 'planned' means an
                     * approved plan won; 'observance' a named day; 'theme' the
                     * evergreen rotation; 'ai' or 'curated' means nothing was
                     * planned. An author who expected 'planned' and reads 'ai'
                     * has found their plan unapproved, and that is the single
                     * most likely reason a Cove did not come out as written.
                     */
                    'source' => $edition->theme_source,
                ],

                'editorial' => [
                    'text' => $edition->editorial,
                    'source' => $edition->editorial_source,
                    'links' => $this->links->against($edition->editorial, $edition->market, $groups),
                ],

                'finds' => $edition->picks
                    ->filter(fn (DailyPick $pick) => $pick->group !== null)
                    ->map(fn (DailyPick $pick) => [
                        'rank' => $pick->rank,
                        'product' => $this->lookup->describe($pick->group),
                    ])
                    ->values()
                    ->all(),

                /*
                 * The Cove this edition points a reader at.
                 *
                 * `featured_cove_id` replaced `guide_id` at the fold — a guide
                 * is a `daily_pick_sets` row now — and this was the last reader
                 * of the old relation, which is what kept the `guides` table
                 * alive a release longer than the fold intended.
                 */
                'guide' => $edition->featured === null ? null : [
                    'id' => $edition->featured->id,
                    'title' => $edition->featured->theme_title,
                    'url' => '/'.$edition->market->value.'/'
                        .$edition->featured->kind->path((string) $edition->featured->slug, $edition->market),
                ],
            ],
        ]);
    }

    /**
     * Rebuild a date without going through a plan.
     *
     * For the case where a plan is already approved and something downstream
     * changed — a feed landed, a product came back into stock — and the edition
     * should be assembled again. Idempotent: the builder updates the edition
     * for a date in place.
     */
    public function build(string $market, string $date): JsonResponse
    {
        $resolved = CatalogueController::market($market);
        $day = $this->date($date);

        BuildDailyEdition::dispatch($resolved, $day->toDateString());

        return response()->json([
            'message' => 'Build queued.',
            'market' => $resolved->value,
            'date' => $day->toDateString(),
            'readBack' => "/api/editorial/editions/{$resolved->value}/{$day->toDateString()}",
        ], 202);
    }

    private function date(string $date): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('Y-m-d', $date);

        if ($parsed === false) {
            throw new NotFoundHttpException("'{$date}' is not a date. Use YYYY-MM-DD.");
        }

        return $parsed;
    }
}
