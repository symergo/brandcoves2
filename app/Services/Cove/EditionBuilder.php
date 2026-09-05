<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PickMode;
use App\Enums\PlanWriter;
use App\Enums\PublishStatus;
use App\Enums\Source;
use App\Models\CovePlan;
use App\Models\CovePlanItem;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\PromptBank;
use App\Services\Cove\Selectors\Selectors;
use App\Services\Cove\Writers\GuideWriter;
use App\Services\Cove\Writers\Written;
use App\Services\Editorial\Allowlist;
use App\Services\Editorial\HouseStyle;
use App\Services\Guides\CoveMarkup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Assembles one day's edition: a puzzle, a themed set of finds, and a guide.
 *
 * The three beats are one machine, not three features on a page. The puzzle is
 * interesting *because* the product is unusual, which is the Serendipity
 * Engine's output; the guide answers what people searched for while looking at
 * exactly this sort of thing. See docs/features/daily-cove.md.
 */
class EditionBuilder
{
    private const FEATURE = 'daily_picks';

    /**
     * How much prose an edition may carry, in characters.
     *
     * Was 4000, which was a comfortable ceiling on "two or three paragraphs"
     * and a hard one on an article with a passage per product: seven finds at
     * 4000 characters is 570 each, and the cut lands mid-sentence in the last
     * one, taking its link token with it — so that product loses its card as
     * well as its paragraph.
     *
     * Applied to authored prose and generated prose alike, because the failure
     * is the same whoever wrote it.
     */
    private const EDITORIAL_LIMIT = 8000;

    public function __construct(
        private readonly AiClient $ai,
        private readonly ObservanceCalendar $calendar,
        private readonly CoveMarkup $markup,
        private readonly Selectors $selectors,
        private readonly GuideWriter $writer,
        private readonly PromptBank $prompts,
        private readonly CovePrompt $prompt,
    ) {}

    /**
     * Build (or rebuild) the edition for a date.
     *
     * Idempotent: re-running for the same day updates in place rather than
     * creating a second edition. The scheduler retries, redeploys interrupt
     * jobs, and an operator will run this by hand — none of those may produce
     * two editions for one Tuesday.
     */
    public function build(Market $market, ?CarbonImmutable $date = null, array $exclude = []): ?DailyPickSet
    {
        $date = $date ?? CarbonImmutable::today();
        $perDay = (int) config('giftcoves.picks.per_day');

        /*
         * An approved plan outranks the calendar, which outranks the model.
         *
         * In that order because it is the order of how much a human meant it: a
         * person who scheduled this day beats a recurring observance, which
         * beats a line generated at 06:00. Drafts are excluded — the clock
         * coming round is not a reason to publish someone thinking out loud.
         */
        $plan = CovePlan::approvedFor($market, $date);

        // A themed day gives the edition a shape the Serendipity Engine cannot
        // invent on its own, and a reason to open a shopping page that is
        // better than "Tuesday".
        // themeFor(), not on(): every date gets a theme. A named day if there is
        // one, an evergreen theme otherwise — an edition that opens with
        // "Today's picks" and nothing else is the reason nobody returns.
        $observance = $this->calendar->themeFor($date, $market);

        $finds = $this->finds($market, $perDay, $observance, $plan, $exclude);
        $liveFinds = $this->liveFinds($plan);

        if (count($finds) + count($liveFinds) < (int) config('giftcoves.picks.minimum')) {
            // A three-item edition is worse than no edition. Publishing a thin
            // one on a bad catalogue day trains people that the page is not
            // worth opening.
            Log::warning('Edition skipped: not enough finds', [
                'market' => $market->value,
                'found' => count($finds),
            ]);

            // Recorded on the plan where there is one, for the same reason the
            // article path records it: a quiet morning and a broken pipeline
            // look identical from every screen otherwise.
            if ($plan !== null) {
                $this->recordFailedBuild($plan, sprintf(
                    '%d of the %d finds a Cove needs. The catalogue could not fill it.',
                    count($finds) + count($liveFinds),
                    (int) config('giftcoves.picks.minimum'),
                ));
            }

            return null;
        }

        /*
         * An observance names the day; otherwise the model (or the curated
         * rotation) names it.
         *
         * The observance wins because it is a fact about the date that a reader
         * can recognise, and a generated line competing with "International Pet
         * Day" loses every time.
         */
        $theme = match (true) {
            $plan !== null => [
                'title' => $plan->title,
                'blurb' => $plan->blurb,
                'slug' => Str::slug($plan->title).'-'.$plan->id,
                'source' => 'planned',
            ],
            $observance !== null => [
                'title' => $observance->title($market),
                'blurb' => $observance->blurb($market),
                'slug' => $observance->slug(),
                // Recorded separately so the admin can see at a glance which
                // editions ran on a real occasion and which on the rotation.
                'source' => $observance->evergreen ? 'theme' : 'observance',
            ],
            default => $this->theme($market, $finds),
        };

        $editorial = $this->editorial($market, $finds, $observance, $theme['title'], $plan);

        return DB::transaction(function () use ($market, $date, $finds, $liveFinds, $theme, $editorial): DailyPickSet {
            /*
             * No slug here, deliberately.
             *
             * The model assigns one on create and nothing rewrites it after —
             * see DailyPickSet::booted(). Listing it in these update values
             * would rename the page on every rebuild, and rebuilding is routine.
             */
            $edition = DailyPickSet::updateOrCreate(
                ['market' => $market->value, 'kind' => CoveKind::Daily->value, 'drop_date' => $date->toDateString()],
                [
                    'theme_title' => $theme['title'],
                    'theme_blurb' => $theme['blurb'],
                    'theme_slug' => $theme['slug'],
                    'theme_source' => $theme['source'],
                    'editorial' => $editorial['text'],
                    'editorial_source' => $editorial['source'],
                    'featured_cove_id' => $this->featured($market)?->id,
                    'status' => PublishStatus::Published->value,
                    'published_at' => $date->setTimeFromTimeString(
                        (string) config('giftcoves.picks.drop_time')
                    ),
                ],
            );

            $this->writePicks($edition, $finds, $liveFinds);

            /*
             * Link the plan to what it became, and leave its status alone.
             *
             * The tempting move is `status = 'used'`, which is what the column
             * comment describes. It would be a bug: `approvedFor()` matches
             * 'approved' only, so the next rebuild of this date would not find
             * the plan and would quietly replace the author's title and prose
             * with generated ones. Rebuilding is routine — the scheduler
             * retries, a redeploy interrupts, an editor presses the button —
             * so an edition has to survive it unchanged.
             *
             * `edition_id` is the fact worth recording, and it is idempotent.
             *
             * When nothing planned this edition, one is minted here as a record
             * of what was published — `status = 'used'`, so it is never mistaken
             * for an instruction. That is what makes the planner able to
             * describe the past as well as the future, and it is why every
             * edition can be re-curated. See CovePlan::recordFor().
             */
            $record = CovePlan::recordFor($edition);

            // This build worked, so whatever the last one reported about a thin
            // catalogue no longer applies to this plan.
            $this->clearFailedBuild($record);

            DB::table('used_themes')->insertOrIgnore([
                'market' => $market->value,
                'theme_slug' => $theme['slug'],
                'used_on' => $date->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $edition;
        });
    }

    /**
     * Build (or rebuild) a gift persona.
     *
     * The same machine as a Daily Cove, and deliberately so — the finds, the
     * editorial pass, the picks and the reactions are all identical, and a
     * second builder would drift from this one within a month. Three things
     * differ, and each is a consequence of a persona having no date:
     *
     *   - It is addressed by a permanent slug, so `updateOrCreate` keys on that.
     *   - It has no observance, because there is no day for one to be about.
     *   - It claims no theme slot in `used_themes`, which is the *daily*
     *     rotation's memory. A persona consuming one would silently make a
     *     theme unavailable to the column for the next sixty days.
     *
     * Built on demand rather than by the clock: nothing schedules this, an
     * editor presses the button. Idempotent for the same reason `build()` is.
     */
    public function buildPersona(CovePlan $plan, array $exclude = []): ?DailyPickSet
    {
        if (! $plan->isPersona() || blank($plan->slug)) {
            Log::warning('Persona build skipped: not a persona, or no slug', ['plan' => $plan->id]);

            return null;
        }

        if ($plan->status !== 'approved') {
            // Same rule as the Daily. A draft is somebody thinking out loud,
            // and pressing a button is not a reason to publish it either.
            Log::info('Persona build skipped: plan is not approved', ['plan' => $plan->id]);

            return null;
        }

        $market = $plan->market;
        $finds = $this->finds($market, (int) config('giftcoves.picks.per_day'), null, $plan, $exclude);
        $liveFinds = $this->liveFinds($plan);

        if (count($finds) + count($liveFinds) < (int) config('giftcoves.picks.minimum')) {
            Log::warning('Persona skipped: not enough finds', [
                'plan' => $plan->id,
                'found' => count($finds) + count($liveFinds),
            ]);

            return null;
        }

        $editorial = $this->editorial($market, $finds, null, $plan->title, $plan);

        return DB::transaction(function () use ($market, $plan, $finds, $liveFinds, $editorial): DailyPickSet {
            $existing = DailyPickSet::query()
                ->where('market', $market->value)
                ->where('kind', CoveKind::Persona->value)
                ->where('slug', $plan->slug)
                ->first();

            $edition = DailyPickSet::updateOrCreate(
                ['market' => $market->value, 'kind' => CoveKind::Persona->value, 'slug' => $plan->slug],
                [
                    'theme_title' => $plan->title,
                    'theme_blurb' => $plan->blurb,
                    'theme_slug' => Str::slug($plan->title).'-'.$plan->id,
                    'theme_source' => 'planned',
                    // The drawing is authored, like the title and the blurb, so
                    // it travels with them. A rebuild refreshes it from the
                    // plan, which is what makes changing it in the curation
                    // screen and pressing Build the whole of that workflow.
                    'scene' => $plan->scene,
                    'editorial' => $editorial['text'],
                    'editorial_source' => $editorial['source'],
                    'status' => PublishStatus::Published->value,
                    /*
                     * First publication only.
                     *
                     * A rebuild refreshes the products and the prose; it does
                     * not republish the page. Stamping `now()` every time would
                     * make a two-month-old persona look new to a crawler on
                     * every product refresh, which is the fastest way to teach
                     * one to stop believing the date.
                     */
                    'published_at' => $existing?->published_at ?? now(),
                ],
            );

            $this->writePicks($edition, $finds, $liveFinds);

            $plan->forceFill(['edition_id' => $edition->id])->save();

            return $edition;
        });
    }

    /**
     * Build (or rebuild) an article: a buying guide, a seasonal one, or advice.
     *
     * The same shape as a persona — undated, addressed by a permanent slug,
     * published once and refreshed thereafter — and it exists as its own method
     * for the same reason `buildPersona` does: what differs is small, and what
     * differs is worth naming.
     *
     * Three things are unlike a Cove:
     *
     * **The page is a comparison.** Its products come from `LadderSelector` —
     * one per brand, in a price ladder — because a reader is choosing between
     * them. `finds()` already dispatches on the kind, so that is free here.
     *
     * **The prose is structured.** A title, an intro, how to choose, an FAQ, and
     * a line about each product, rather than two or three paragraphs about the
     * set. Written by `GuideWriter`, against the `guide_copy` cap.
     *
     * **Advice publishes with nothing on it.** Its substance is the prose;
     * demanding products would either block it or pad it with things the writing
     * is not about. `CoveKind::minimumItems()` returns zero for exactly that.
     */
    public function buildArticle(CovePlan $plan, array $exclude = []): ?DailyPickSet
    {
        /*
         * `writesBody()`, not `isArticle()`.
         *
         * A Shop Cove is written exactly like an advice article and deliberately
         * answers false to the URL-space question, so asking that one here left
         * it with no build path at all — planned, curated, approved, and then
         * nothing. See CoveKind::writesBody().
         */
        if (! $plan->kind->writesBody() || blank($plan->slug)) {
            Log::warning('Article build skipped: kind writes no body, or no slug', ['plan' => $plan->id]);

            return null;
        }

        if ($plan->status !== 'approved') {
            // Same rule as everything else. A draft is somebody thinking out
            // loud, and pressing a button is not a reason to publish it.
            Log::info('Article build skipped: plan is not approved', ['plan' => $plan->id]);

            return null;
        }

        $market = $plan->market;
        $finds = $this->finds($market, $plan->kind->targetItems(), null, $plan, $exclude);

        if (count($finds) < $plan->kind->minimumItems()) {
            /*
             * The catalogue looked able to fill this and is not now — stock
             * moves. Better to skip than to publish a three-item "best of",
             * which is a list with gaps and reads as one.
             */
            Log::warning('Article skipped: not enough products', [
                'plan' => $plan->id,
                'kind' => $plan->kind->value,
                'found' => count($finds),
                'needed' => $plan->kind->minimumItems(),
            ]);

            /*
             * Recorded on the plan, not only in the log.
             *
             * This refusal is correct and it was invisible: an approved plan
             * whose catalogue had gone thin looked exactly like one whose date
             * had not arrived yet, on every screen and in every API response.
             * For an unattended run that is the difference between "published"
             * and "nothing happened", reported as neither.
             */
            $this->recordFailedBuild($plan, sprintf(
                '%d of the %d products this kind needs. The catalogue could not fill it.',
                count($finds),
                $plan->kind->minimumItems(),
            ));

            return null;
        }

        $written = $this->article($plan, $finds);

        return DB::transaction(function () use ($market, $plan, $finds, $written): DailyPickSet {
            $existing = DailyPickSet::query()
                ->where('market', $market->value)
                ->where('kind', $plan->kind->value)
                ->where('slug', $plan->slug)
                ->first();

            $edition = DailyPickSet::updateOrCreate(
                ['market' => $market->value, 'kind' => $plan->kind->value, 'slug' => $plan->slug],
                [
                    /*
                     * The plan's own words beat the model's, field by field.
                     *
                     * An editor who titled the page meant it; a generated title
                     * replacing theirs on the next refresh is the failure the
                     * whole planner exists to prevent.
                     */
                    'theme_title' => $plan->title ?: $written->title,
                    'theme_blurb' => $plan->blurb ?: $written->intro,
                    'theme_slug' => $plan->slug,
                    'theme_source' => 'planned',
                    'body' => filled($plan->body) ? $plan->body : $written->body,
                    'faq' => filled($plan->faq) ? $plan->faq : $written->faq,
                    'meta_description' => $plan->meta_description
                        ?? Str::limit($this->markup->plain((string) ($plan->blurb ?: $written->intro)), 155, '') ?: null,
                    'focus_keyphrase' => $plan->focus_keyphrase,
                    'source_queries' => $plan->queries ?? [],
                    'editorial_source' => $written->source,
                    'season_from' => $plan->season_from,
                    'season_to' => $plan->season_to,
                    'status' => PublishStatus::Published->value,
                    // First publication only, exactly as for a persona: a
                    // rebuild refreshes the page, it does not republish it.
                    'published_at' => $existing?->published_at ?? now(),
                    // Rewriting is precisely what the freshness check wants to
                    // know about: the copy has just been looked at.
                    'last_checked_at' => now(),
                ],
            );

            $this->writePicks($edition, $finds, $this->liveFinds($plan), $this->itemCopy($plan, $finds, $written));

            /*
             * `built_for` is what stops this running again tomorrow, and what
             * lets it run again next spring.
             *
             * It records the date the plan was due when it was honoured.
             * `PublishDueCoves` builds a plan whose `drop_date` is later than
             * this, so a seasonal part re-dated into the coming window is due
             * again while an unmoved one is not. Null on a plan with no date at
             * all, which is every kind built on demand rather than by the clock.
             */
            $plan->forceFill([
                'edition_id' => $edition->id,
                'built_for' => $plan->drop_date?->toDateString(),
                /*
                 * This build worked, so whatever the last one reported no longer
                 * applies. A plan that was too thin in March and published in
                 * April must not still be showing April's editor a warning about
                 * March.
                 */
                'last_build_failed_at' => null,
                'last_build_note' => null,
            ])->save();

            return $edition;
        });
    }

    /**
     * Re-write an article's prose without touching its shortlist.
     *
     * The narrow half of a rebuild, and the two things it refuses are the whole
     * point:
     *
     * **It never re-chooses the products.** The shortlist is reloaded from the
     * picks that are already published, in their rank order. A guide that has
     * been curated must not silently acquire different products because
     * somebody asked for better sentences.
     *
     * **It never trades real editorial for the template.** If the model is off,
     * capped or failing, `GuideWriter` returns the shipped fallback — and
     * overwriting prose a reader is reading with a placeholder, because a
     * request timed out at 04:40, is a downgrade nobody asked for.
     *
     * Returns whether anything was rewritten, so `bc:refresh-cove-copy` can
     * report honestly rather than counting attempts as successes.
     */
    public function refreshCopy(DailyPickSet $edition): bool
    {
        $finds = $edition->picks()
            ->with('group')
            ->orderBy('rank')
            ->get()
            ->map(fn (DailyPick $pick) => $pick->group)
            ->filter()
            ->values();

        if ($finds->isEmpty()) {
            return false;
        }

        /*
         * Briefed from the plan when there is one.
         *
         * Every published Cove has a plan now, so a refresh gets the same
         * curator notes and build instructions the original build had — which
         * is the difference between rewriting the piece and regenerating a
         * stranger's.
         */
        $plan = $edition->plan ?? new CovePlan([
            'market' => $edition->market->value,
            'kind' => $edition->kind->value,
            'title' => $edition->theme_title,
            'focus_keyphrase' => $edition->focus_keyphrase,
            'queries' => $edition->source_queries ?? [],
        ]);

        $written = $this->writer->write(
            $plan,
            $finds->all(),
            $this->prompt->allowlist($edition->market, $finds->all()),
            $this->prompt->brief($edition->plan),
        );

        if (! $written->isFromModel()) {
            Log::info('Cove copy left alone: no model answer', [
                'edition' => $edition->id,
                'source' => $written->source,
            ]);

            return false;
        }

        return DB::transaction(function () use ($edition, $finds, $written): bool {
            $edition->forceFill([
                'theme_title' => $written->title ?? $edition->theme_title,
                'theme_blurb' => $written->intro ?? $edition->theme_blurb,
                'body' => $written->body ?? $edition->body,
                'meta_description' => $edition->meta_description
                    ?? Str::limit($this->markup->plain((string) $written->intro), 155, '') ?: null,
                'faq' => $written->faq ?? $edition->faq,
                'last_checked_at' => now(),
            ])->save();

            // Updated in place, by rank, rather than deleted and recreated: the
            // picks carry reader reactions, and re-inserting them would throw
            // those away to change a sentence.
            foreach ($finds as $index => $group) {
                $edition->picks()
                    ->where('rank', $index + 1)
                    ->update([
                        'blurb' => $written->items[$index]['copy'] ?? null,
                        'verdict' => $written->items[$index]['verdict'] ?? null,
                    ]);
            }

            return true;
        });
    }

    /**
     * Do this Cove again: new products, new words, same address.
     *
     * Not the rebuild that already exists. A rebuild is idempotent and
     * reproduces the same page — that is what makes a scheduler retry and a
     * redeploy safe. A redo is for the Cove that came out wrong, and it
     * deliberately throws away the inputs that would reproduce it.
     *
     * Four things had to be handled, and each of them is a way this would
     * otherwise silently do nothing:
     *
     * **The shortlist survives a rebuild.** That is the entire point of
     * `cove_plan_items`, so a redo has to clear it or the same products come
     * back at the top of the page.
     *
     * **Authored prose short-circuits the model.** `filled($plan->editorial)`
     * skips the writer completely, so "rewrite" with prose still on the plan
     * writes nothing.
     *
     * **Reselection is not automatically different.** A Daily naturally lands
     * elsewhere because of the ninety-day repeat memory. A persona and a guide
     * are not in that memory, and `LadderSelector` is deterministic — so what is
     * on the page right now is passed as an exclusion. Without it a redone guide
     * hands back the identical ladder, which is the failure this exists to
     * prevent.
     *
     * **`published_at` must not move.** It is preserved by the build methods
     * themselves (first publication only), so nothing is done here — but a
     * change that made a redo restamp it would re-date a page that has been live
     * for months and reshuffle every "newest first" shelf on the site.
     *
     * ## What it destroys
     *
     * The picks, and therefore `pick_reactions` — a redone Cove loses every
     * reader reaction it collected, and there is no undo. Callers must say so
     * before running this.
     */
    public function redo(CovePlan $plan, RedoOptions $options): ?DailyPickSet
    {
        $edition = $plan->edition;

        // What is on the page now, so the engine cannot hand it straight back.
        $exclude = $edition === null
            ? []
            : $edition->picks()->whereNotNull('group_id')->pluck('group_id')->all();

        $plan->forceFill([
            // The brief survives — title, blurb, queries, keyphrase and the
            // build instructions are the decisions, not the output. Only what
            // was written is discarded.
            'editorial' => null,
            'body' => null,
            'faq' => null,

            /*
             * And the writer goes back to the builder, because redo means
             * "write it again" and there is now nobody else to do that.
             *
             * Leaving it `authored` would say the plan is waiting on a person
             * who has already had their turn, and the prose it referred to has
             * just been deleted — so the page would rebuild with nothing in it.
             */
            'writer' => PlanWriter::Builder->value,
        ])->save();

        if ($options->reselect) {
            $plan->items()->delete();
        } else {
            /*
             * The shortlist stays, but what was *written* about it does not.
             *
             * `copy` is authored output exactly like `body` above — the sentence
             * printed under the card — so leaving it would redo the article and
             * publish it with last month's captions underneath. `note` and
             * `verdict` are the curator's, not the writer's, and they survive
             * for the same reason the title and the build instructions do.
             */
            $plan->items()->update(['copy' => null]);
        }

        $plan->refresh();

        return match (true) {
            // `writesBody()`, so a Shop Cove can be redone like every other
            // prose kind — `isArticle()` excludes it by design.
            $plan->kind->writesBody() => $this->buildArticle($plan, $exclude),
            $plan->isPersona() => $this->buildPersona($plan, $exclude),
            default => $plan->drop_date === null
                ? null
                : $this->build($plan->market, CarbonImmutable::instance($plan->drop_date), $exclude),
        };
    }

    /**
     * An article's prose, or the plan's own.
     *
     * Authored copy short-circuits the model completely, the same way it does
     * for a Cove: if someone wrote the piece, generating a second one and
     * throwing it away is spend with no output.
     *
     * **The plan says who writes it; `body` is only a floor.** This used to read
     * `filled($plan->body) && filled($plan->blurb)`, and the `&& blurb` half was
     * a bug — an author who sent a finished article and left the one-line blurb
     * to us got the model run over it anyway, spending against `guide_copy` and
     * replacing the title they chose, reported nowhere. The `writer` field is
     * the decision now. `filled($plan->body)` survives as a guard rather than as
     * the question: a plan marked authored *before* anybody has written it would
     * otherwise publish a page with nothing on it, and a generated article is a
     * far better answer to that than an empty one.
     *
     * @param  list<ProductGroup>  $finds
     */
    private function article(CovePlan $plan, array $finds): Written
    {
        /*
         * The plan says who writes it, rather than the builder guessing.
         *
         * This read `filled($plan->body) && filled($plan->blurb)`, and the
         * `&& blurb` half was the bug: an author who sent a finished body and no
         * blurb got the model run over their article anyway — real spend against
         * `guide_copy`, and a generated title replacing the one they chose, with
         * nothing anywhere reporting it. See App\Enums\PlanWriter.
         */
        if (! $plan->writer->callsModel() && filled($plan->body)) {
            return Written::planned('');
        }

        return $this->writer->write(
            $plan,
            $finds,
            /*
             * The article's own page is not linkable from inside it.
             *
             * On a first build the edition does not exist yet, so this changed
             * nothing; on every rebuild after that the guide was offered its own
             * slug and could link to itself — a loop for a reader and a dead end
             * for a crawler. Found by the test that asserts the served prompt and
             * the sent prompt are the same string, which is exactly the class of
             * bug that test exists for.
             */
            $this->prompt->allowlist($plan->market, $finds, excludeGuideId: $plan->edition_id),
            $this->prompt->brief($plan),
        );
    }

    /**
     * The line under each product, curator first.
     *
     * `cove_plan_items.verdict` is the "best for X" a person typed while looking
     * at the shortlist, and on an article that is rendered rather than merely
     * briefed — so it outranks whatever the model came up with for the same
     * product. Everywhere else on a plan the curator wins; there is no reason
     * this is the exception.
     *
     * The same is now true of the copy itself. `cove_plan_items.copy` is the
     * sentence a person or an external author wrote for that card, and when it
     * is there the model has not run at all — `article()` short-circuits on an
     * authored plan, `$written->items` comes back empty, and without this every
     * `daily_picks.blurb` was null. A guide written entirely by hand published
     * with blank cards under the paragraphs discussing them.
     *
     * **Curated values are looked up by group id; the model's by position.**
     * `$written->items` is positional and matches `$finds` because the model was
     * handed that list in that order. A plan's items are not: the shortlist
     * leads the edition but the engine's additions follow it, so entry N of
     * `$finds` is not item N of the plan. Reading the authored copy positionally
     * would attach one product's sentence to another's card, which is worse than
     * having none.
     *
     * Positional output, matching `writePicks()`: entry N describes find N.
     *
     * @param  list<ProductGroup>  $finds
     * @return list<array{copy: string|null, verdict: string|null}>
     */
    /**
     * Note that a build ran and produced no page.
     *
     * Cleared by the next build that works, so the column means "the last
     * attempt came to nothing" rather than "an attempt failed once". A plan that
     * was thin in March and published in April is not still carrying a warning
     * about March.
     */
    private function recordFailedBuild(CovePlan $plan, string $why): void
    {
        $plan->forceFill([
            'last_build_failed_at' => now(),
            'last_build_note' => Str::limit($why, 300, ''),
        ])->save();
    }

    /** A build worked, so whatever the last one said no longer applies. */
    private function clearFailedBuild(CovePlan $plan): void
    {
        if ($plan->last_build_failed_at === null) {
            return;
        }

        $plan->forceFill(['last_build_failed_at' => null, 'last_build_note' => null])->save();
    }

    private function itemCopy(CovePlan $plan, array $finds, Written $written): array
    {
        $items = $plan->items()->whereNotNull('group_id')->get();

        $verdicts = $items->whereNotNull('verdict')->pluck('verdict', 'group_id');
        $authored = $items->whereNotNull('copy')->pluck('copy', 'group_id');

        $copy = [];

        foreach ($finds as $index => $group) {
            $copy[] = [
                'copy' => $authored[$group->id] ?? ($written->items[$index]['copy'] ?? null),
                'verdict' => $verdicts[$group->id] ?? ($written->items[$index]['verdict'] ?? null),
            ];
        }

        return $copy;
    }

    /**
     * What the engine would put on this plan, offered for a person to change.
     *
     * The suggestion half of the curation loop. A plan that opens empty asks an
     * editor to invent seven products from nothing, which is the blank-page
     * problem and the reason the old pinned-products field went unused; a plan
     * that opens with the engine's guess asks them to *react*, which is a job
     * people are good at and fast at.
     *
     * It is the same selection the builder would make on the day — same themed
     * queries, same surprise ranking, same repeat memory — so accepting the
     * suggestion unchanged produces exactly the edition that would have been
     * published anyway. Curation can then only improve it.
     *
     * @param  list<int>  $exclude  Groups already spoken for by this run.
     * @return list<ProductGroup>
     */
    public function candidates(CovePlan $plan, int $count, array $exclude = []): array
    {
        $observance = $plan->drop_date === null
            ? null
            : $this->calendar->themeFor(CarbonImmutable::instance($plan->drop_date), $plan->market);

        /*
         * Asked as though the plan were open, on a clone that is never saved.
         *
         * `finds()` short-circuits a locked plan to its own shortlist, which is
         * right at build time and useless here: "suggest me some products" on a
         * locked plan would answer with the products it already has.
         */
        $asking = clone $plan;
        $asking->pick_mode = PickMode::Open;

        /*
         * Never the plan's own products back.
         *
         * `finds()` puts the curated items at the head of the list, which is
         * right when it is choosing an edition and wrong here: "suggest me some
         * products" answering with the ones already on the shortlist would fill
         * a top-up with things that cannot be added. Over-fetched by that many
         * so the caller still gets the count it asked for.
         */
        $already = $plan->items()->pluck('group_id')->filter()->all();

        $found = $this->finds(
            $plan->market,
            $count + count($already),
            $observance,
            $asking,
            $exclude,
        );

        return array_slice(
            array_values(array_filter(
                $found,
                fn (ProductGroup $group) => ! in_array($group->id, $already, true),
            )),
            0,
            $count,
        );
    }

    /**
     * Today's finds, from the Serendipity Engine, minus everything recently shown.
     *
     * @return list<ProductGroup>
     */
    private function finds(
        Market $market,
        int $count,
        ?Observance $observance = null,
        ?CovePlan $plan = null,
        array $exclude = [],
    ): array {
        /*
         * Curated products lead, and are exempt from the repeat memory.
         *
         * The entire point of curation is to override a score, so a pick the
         * ranker could veto would not be curation. If an editor wants to show
         * something again, that is a decision and not an accident — which is
         * exactly what the rolling memory exists to prevent for everything the
         * engine picks on its own.
         *
         * `presentable()` still applies: an out-of-stock, unpriced or imageless
         * card renders as broken whoever chose it, and the curation screen
         * warns about exactly this before the build runs.
         */
        $curated = $this->curated($market, $plan);

        /*
         * Locked: the shortlist is the edition, in the curator's order.
         *
         * Short-circuited here rather than inside a selector because it is not
         * a selection strategy at all — it is the absence of one, and it means
         * the same thing for every kind of Cove.
         */
        if ($plan?->pick_mode === PickMode::Locked) {
            return $curated->all();
        }

        /*
         * Everything below the curated lead is chosen per kind.
         *
         * A column wants surprise and category variety; a guide wants one
         * product per brand in a price ladder. Both have to account for what a
         * person already chose, which is why the curated list goes in rather
         * than being concatenated afterwards. See CoveSelector.
         *
         * A plan is always present in practice — every build mints one — but the
         * signature stays nullable for the theme-only path, and a Daily with no
         * plan is composed exactly as it always was.
         */
        $plan ??= new CovePlan([
            'market' => $market->value,
            'kind' => CoveKind::Daily->value,
            'queries' => $observance?->queries ?? [],
        ]);

        return $this->selectors->for($plan->kind)->select($plan, $curated, $count, $exclude);
    }

    /**
     * The curated shortlist, resolved to catalogue products in the curator's order.
     *
     * Ordering is done in PHP from the item ranks rather than by SQL, because
     * `whereIn` returns rows in whatever order Postgres finds them and the
     * order here is the editorial decision — it is what the article's first
     * paragraph will be about.
     *
     * @return Collection<int, ProductGroup>
     */
    private function curated(Market $market, ?CovePlan $plan): Collection
    {
        if ($plan === null) {
            return collect();
        }

        $items = $plan->items()->whereNotNull('group_id')->get();

        if ($items->isEmpty()) {
            return collect();
        }

        $groups = ProductGroup::query()
            ->forMarket($market)
            ->presentable()
            ->whereIn('id', $items->pluck('group_id')->all())
            ->get()
            ->keyBy('id');

        return $items
            ->map(fn (CovePlanItem $item) => $groups->get($item->group_id))
            ->filter()
            ->values();
    }

    /**
     * Curated picks from a source whose catalogue may not be mirrored.
     *
     * Held as the decision — which ASIN — and re-fetched at render, so they
     * never travel through `ProductGroup` and never reach the editorial prompt:
     * a `[[product:...]]` token resolves a group id, and these have none. The
     * consequence is deliberate and worth knowing — an unmirrorable pick
     * appears as a card in the edition, not as a sentence in the article.
     *
     * @return list<CovePlanItem>
     */
    private function liveFinds(?CovePlan $plan): array
    {
        if ($plan === null) {
            return [];
        }

        return $plan->items()
            ->whereNull('group_id')
            ->whereNotNull('external_id')
            ->get()
            ->all();
    }

    /**
     * Replace an edition's picks with what was just chosen.
     *
     * Delete-then-insert rather than a diff: a rebuild is routine and the ranks
     * are a total ordering, so reconciling them in place would be more code
     * defending an invariant the unique index already holds. Reactions are lost
     * with the row, which is the existing behaviour and the reason a rebuild is
     * a considered action rather than a cron that runs hourly.
     *
     * @param  list<ProductGroup>  $finds
     * @param  list<CovePlanItem>  $liveFinds
     */
    private function writePicks(DailyPickSet $edition, array $finds, array $liveFinds = [], array $copy = []): void
    {
        $edition->picks()->delete();
        $rank = 0;

        foreach ($finds as $index => $group) {
            DailyPick::create([
                'set_id' => $edition->id,
                'group_id' => $group->id,
                'rank' => ++$rank,
                'slug' => Str::slug($group->title).'-'.$group->id,
                /*
                 * Only an article writes per-product prose.
                 *
                 * A column's writing is about the set and lives in `editorial`;
                 * a guide argues product by product, and this is where that
                 * argument goes. Empty for a Daily, which is why the parameter
                 * defaults to nothing rather than being required.
                 */
                'blurb' => $copy[$index]['copy'] ?? null,
                'verdict' => $copy[$index]['verdict'] ?? null,
                'surprise_score' => $group->surprise_score,
                'score_breakdown' => $group->surprise_breakdown,
                'discount_percent' => $group->discountPercent(),
            ]);
        }

        foreach ($liveFinds as $item) {
            DailyPick::create([
                'set_id' => $edition->id,
                'amazon_asin' => $item->external_id,
                'rank' => ++$rank,
                // The slug is the decision's id and nothing from the product:
                // a title we may not store cannot appear in a URL either.
                'slug' => Str::slug((string) $item->source?->value).'-'.Str::slug((string) $item->external_id),
            ]);
        }
    }

    /**
     * The edition's long-form copy.
     *
     * This is what makes a Cove worth reading rather than scrolling. The finds
     * are the substance; the prose is what connects them, and connecting them
     * is a judgement a ranking function cannot make.
     *
     * Stored with its link tokens unresolved, so the anchors follow the market
     * the page is read in and a product that later disappears degrades to plain
     * text rather than leaving a dead link in a row nobody revisits.
     *
     * @param  list<ProductGroup>  $finds
     * @return array{text: string|null, source: string}
     */
    private function editorial(
        Market $market,
        array $finds,
        ?Observance $observance,
        string $title,
        ?CovePlan $plan = null,
    ): array {
        /*
         * Prose an author wrote wins outright, and skips the model entirely.
         *
         * Not a fallback and not a seed for the model to rewrite: a person (or
         * Claude, through the editorial API) who wrote this day's copy meant
         * those words, and a rebuild that quietly replaced them with generated
         * ones would make every rebuild a gamble on whether the article
         * survives. The plan is the source of truth precisely because the
         * edition is rebuilt routinely.
         *
         * It also means an authored Cove costs nothing in AI spend, which is
         * the invariant working in the direction it was meant to.
         */
        if ($plan !== null && ! $plan->writer->callsModel() && filled($plan->editorial)) {
            return [
                /*
                 * House style applies to authored prose too, and that is not a
                 * contradiction of the paragraph above. It is punctuation, not
                 * wording: the writer here is usually Claude through the
                 * editorial API, and an em dash it reached for is the habit
                 * this rule exists to correct, not a decision it made. The
                 * words are untouched. See {@see HouseStyle}.
                 */
                'text' => Str::limit(trim((string) HouseStyle::prose($plan->editorial)), self::EDITORIAL_LIMIT, ''),
                'source' => 'planned',
            ];
        }

        if (! $this->ai->isEnabled()) {
            /*
             * No filler.
             *
             * A templated paragraph that says nothing is worse than no
             * paragraph — it costs the reader time and teaches them to skip
             * the section permanently. The finds stand on their own.
             */
            return ['text' => null, 'source' => 'none'];
        }

        /*
         * The observance is deliberately not passed here.
         *
         * `allowlist()` accepts one and would add its queries to the linkable
         * searches, and no caller has ever supplied it — so the parameter is
         * dead and the observance's own words are not linkable. That may well be
         * worth changing; it is not worth changing inside a refactor whose whole
         * value is that the prompt served over HTTP is the prompt used here.
         */
        $allowed = $this->prompt->allowlist($market, $finds);
        $brief = $this->prompt->brief($plan);

        $kind = $plan?->kind ?? CoveKind::Daily;

        /*
         * Assembled by `CovePrompt`, which the editorial API also asks.
         *
         * One implementation, so an external author can be handed the prompt the
         * builder would have used — including a prompt-bank override — instead
         * of a copy of it maintained by hand in four files.
         */
        $system = $this->prompt->system($kind, $brief !== [], $allowed);
        $prompt = $this->prompt->user($kind, $market, $finds, $observance, $title, $brief, $plan);

        $text = $this->write($system, $prompt);

        /*
         * An article that names none of its products has failed at the thing
         * the page is for, so it is worth one more call — and exactly one.
         *
         * The check used to apply only to a curated Cove, on the reasoning that
         * an engine-picked one was allowed to write about two or three finds
         * and leave the rest to the grid. It is not allowed to any more: a
         * product no paragraph names gets no card in the article and no
         * sentence anywhere, so "named nothing at all" is now the same failure
         * on every kind. Curated ids are still what is checked when there are
         * some, because those are the ones somebody asked for by name.
         *
         * Not a loop. The daily cap is shared with the guides and the trends
         * pass, so a builder that argues with the model spends the budget every
         * other feature needs that day. If the second attempt is no better the
         * prose still publishes: it is about the right products, it merely did
         * not link them, and no prose at all is the worse outcome.
         */
        $wanted = $brief !== []
            ? array_column($brief, 'id')
            : array_map(fn (ProductGroup $group) => $group->id, $finds);

        if ($wanted !== [] && $text !== null && ! $this->mentionsAny($text, $wanted)) {
            Log::info('Cove editorial named none of its products; retrying once', [
                'market' => $market->value,
                'kind' => $kind->value,
                'curated' => count($brief),
                'products' => count($wanted),
            ]);

            $text = $this->write(
                $system,
                $prompt."\n\n".'Your previous attempt named none of the products. Write about them, '
                    .'each in its own paragraph, using its link token.',
            ) ?? $text;
        }

        return $text === null
            ? ['text' => null, 'source' => 'none']
            : ['text' => Str::limit($text, self::EDITORIAL_LIMIT, ''), 'source' => 'ai'];
    }

    /**
     * One call to the model. Null when it could not be reached.
     *
     * Extracted so the retry above is a second call rather than a second copy
     * of the error handling — the version of this that duplicated the try/catch
     * is the version where one of the two eventually stops reporting failures.
     */
    private function write(string $system, string $prompt): ?string
    {
        try {
            $response = $this->ai->json(
                self::FEATURE,
                $system,
                $prompt,
                schemaHint: ['editorial' => "First paragraph.\n\nSecond paragraph."],
                /*
                 * Raised from 1200 with the per-product rule below. The model
                 * now owes a paragraph for every find, and a response cut off
                 * at the ceiling does not write about the last products
                 * briefly — it does not reach them at all.
                 */
                maxTokens: 2200,
            );
        } catch (AiUnavailable $e) {
            Log::info('Cove editorial unavailable', ['reason' => $e->getMessage()]);

            return null;
        }

        $text = trim(strip_tags((string) HouseStyle::prose((string) ($response['editorial'] ?? ''))));

        return $text === '' ? null : $text;
    }

    /**
     * Did the article actually name any of these products?
     *
     * Tested on the link token, not on the title. The token is the only
     * unambiguous reference: a title fragment can appear by coincidence, and a
     * paragraph that describes the kettle without linking it renders as a
     * paragraph with no product beneath it — which is the failure being looked
     * for, not a near miss of it.
     *
     * @param  list<int>  $ids
     */
    private function mentionsAny(string $text, array $ids): bool
    {
        foreach ($ids as $id) {
            if (str_contains($text, '[[product:'.$id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Today's theme line.
     *
     * @param  list<ProductGroup>  $finds
     * @return array{title: string, blurb: string|null, slug: string, source: string}
     */
    private function theme(Market $market, array $finds): array
    {
        $fallback = $this->curatedTheme($market);

        if (! $this->ai->isEnabled()) {
            return $fallback;
        }

        try {
            $response = $this->ai->json(
                self::FEATURE,
                <<<'TXT'
                You name a daily set of unusual products found in a shopping
                catalogue. One short title and one sentence.

                Rules:
                - Describe what these things have in common, honestly. If they
                  have nothing in common, say that — "Seven things with nothing
                  in common" is a better title than a forced theme.
                - Name something concrete. The title must contain at least one
                  noun a person could plausibly type into a search box: the
                  occasion, the room, the category, or who it is for. "The last
                  day of the holidays" names a mood; "The last day of the school
                  holidays" names a thing. Both are honest, and the second is
                  findable.
                - Do not stuff in "gift", "present" or "buy". The page and its
                  address already say that, and a title that repeats it reads
                  like every other shopping site.
                - Never invent a product, a price or a claim about quality.
                - No exclamation marks, no "amazing", no "you won't believe".
                TXT,
                'Language: '.$market->language()."\n\nToday's finds:\n- ".
                    implode("\n- ", array_map(fn (ProductGroup $g) => $g->title, $finds))."\n\n".
                    'Avoid these recently used themes: '.implode(', ', $this->recentThemes($market)),
                schemaHint: ['title' => '...', 'blurb' => '...'],
                maxTokens: 300,
            );
        } catch (AiUnavailable $e) {
            Log::info('Theme unavailable, using curated rotation', ['reason' => $e->getMessage()]);

            return $fallback;
        }

        // `plain`, not `prose`: a theme title and its blurb are printed as
        // text nodes and never see the renderer, so `**` would show.
        $title = trim(strip_tags((string) HouseStyle::plain((string) ($response['title'] ?? ''))));

        if ($title === '') {
            return $fallback;
        }

        return [
            'title' => Str::limit($title, 80, ''),
            'blurb' => Str::limit(trim(strip_tags((string) HouseStyle::plain((string) ($response['blurb'] ?? '')))), 200, '') ?: null,
            'slug' => Str::slug($title),
            'source' => 'ai',
        ];
    }

    /** @return list<string> */
    private function recentThemes(Market $market): array
    {
        return DB::table('used_themes')
            ->where('market', $market->value)
            ->where('used_on', '>=', now()->subDays((int) config('giftcoves.picks.theme_memory_days')))
            ->pluck('theme_slug')
            ->all();
    }

    /**
     * The no-AI theme.
     *
     * Dated rather than random, so a rerun of the same day produces the same
     * edition — idempotence has to survive the fallback path too.
     *
     * @return array{title: string, blurb: string|null, slug: string, source: string}
     */
    private function curatedTheme(Market $market): array
    {
        $key = 'site.daily.themes.'.((int) CarbonImmutable::today()->dayOfYear % 7);
        $title = __($key, [], $market->language());

        return [
            'title' => $title,
            'blurb' => null,
            'slug' => Str::slug($title).'-'.CarbonImmutable::today()->format('W'),
            'source' => 'curated',
        ];
    }

    /**
     * The article this edition points its readers at.
     *
     * It used to *build* one: if a topic was ripe, the 06:00 job chose that
     * guide's products, wrote it and published it, all inside the Daily's build.
     * That is what made a guide the one page nobody could decide anything about.
     *
     * The topic queue now drafts a plan for a person to curate instead, so this
     * only picks what is already live. A guide a week is a healthier rate than a
     * guide a day anyway — topics ripen at the speed of search volume, not at
     * the speed of the calendar — and nothing about a Daily needed a *new* guide
     * every morning.
     *
     * The consequence worth stating: nothing publishes an article automatically
     * any more. That is the point, and it means a market with an empty planner
     * eventually has no guide to feature.
     */
    /**
     * A name for this edition that nothing in this market has taken.
     *
     * The slug namespace is unique per market across every kind, and a theme
     * recurs — "moederdag" comes round every year — so a collision is the normal
     * case rather than the exception. Suffixed with a counter, which reads
     * better in a URL than a date does and keeps the first year's edition on the
     * clean address.
     */
    private function freeSlug(Market $market, string $theme, CarbonImmutable $date): string
    {
        $base = Str::slug($theme) ?: 'cove-'.$date->toDateString();

        $taken = fn (string $candidate): bool => DailyPickSet::query()
            ->where('market', $market->value)
            ->where('slug', $candidate)
            ->exists();

        if (! $taken($base)) {
            return $base;
        }

        $n = 2;

        while ($taken($base.'-'.$n)) {
            $n++;
        }

        return $base.'-'.$n;
    }

    private function featured(Market $market): ?DailyPickSet
    {
        return DailyPickSet::query()
            ->where('market', $market->value)
            ->articles()
            ->where('status', PublishStatus::Published->value)
            ->latest('published_at')
            ->first();
    }
}
