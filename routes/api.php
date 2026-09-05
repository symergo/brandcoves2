<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CatalogueController;
use App\Http\Controllers\Api\CoveBriefController;
use App\Http\Controllers\Api\CoveCalendarController;
use App\Http\Controllers\Api\CoveDraftController;
use App\Http\Controllers\Api\CoveItemController;
use App\Http\Controllers\Api\CovePlanController;
use App\Http\Controllers\Api\CoveQueueController;
use App\Http\Controllers\Api\CoveStageController;
use App\Http\Controllers\Api\EditionController;
use App\Http\Controllers\Api\EditorialIndexController;
use App\Http\Controllers\Api\GuideEditorialController;
use App\Models\ApiToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Editorial API
|--------------------------------------------------------------------------
|
| Machine access to the writing surfaces: Daily Coves and buying guides. It
| exists so an author — a person on a laptop, or Claude — can research, draft
| and read back content without a shell on the server, which is both a
| convenience and a security improvement: a key that can write a draft is a far
| smaller thing to hand out than SSH.
|
| No route here is prefixed with {market}. The public site resolves the market
| from the URL because a visitor is in one; an author works across all five and
| passes it explicitly, which is also what stops a write landing in the wrong
| one by inheriting a default.
|
| See docs/features/editorial-api.md.
|
*/

Route::prefix('editorial')
    // Auth first, so the throttle below can key on the token rather than the
    // IP — five markets' worth of writing from one CI box must not rate-limit
    // itself, and an unauthenticated flood must not consume a real key's budget.
    ->middleware(['api.token', 'throttle:editorial'])
    ->group(function () {
        // Self-describing root: abilities, markets and the writing contract.
        // Deliberately outside the ability gates — a key with none of them
        // should still be able to find out that it has none.
        Route::get('/', EditorialIndexController::class)->name('api.editorial.index');

        /*
         * Read: the grounding endpoints.
         *
         * Everything an author writes has to be anchored to a real product id
         * from here. This is the difference between an article about the
         * catalogue and an article about a catalogue the model imagined.
         */
        Route::middleware('api.ability:'.ApiToken::READ)->group(function () {
            Route::get('/products', [CatalogueController::class, 'products']);
            Route::get('/products/{group}', [CatalogueController::class, 'product']);
            Route::get('/topics', [CatalogueController::class, 'topics']);

            /*
             * The editorial year, drawn from the config rather than the database.
             *
             * Where a daily or seasonal run should start: it says what is coming
             * and what is unplanned, so a caller can point at a date instead of
             * asking for "the next N" and taking whatever the walk finds.
             */
            Route::get('/calendar', [CoveCalendarController::class, 'show']);

            Route::get('/coves', [CovePlanController::class, 'index']);

            /*
             * What needs writing, with everything needed to write it.
             *
             * Registered before /coves/{plan} or 'queue' is swallowed as a plan
             * id — which binds nothing and 404s, from a route that looks right.
             */
            Route::get('/coves/queue', [CoveQueueController::class, 'index']);

            Route::get('/coves/{plan}', [CovePlanController::class, 'show']);

            /*
             * The prompt this Cove would be written from.
             *
             * What makes "write it from the prompt in the database" real: the
             * assembled system and user messages `EditionBuilder` would send,
             * prompt-bank override included, so an edit to the voice in
             * Operations → Prompts governs an external author too. Registered
             * after `/coves/{plan}` because it is a longer path and binds no
             * word the wildcard could swallow.
             */
            Route::get('/coves/{plan}/brief', [CoveBriefController::class, 'show']);

            /*
             * Where else this plan's products are spoken for.
             *
             * Advisory and never a filter. The 90-day repeat memory protects
             * anything the engine picks and deliberately does not protect what a
             * person picks — overriding a score is the point of curating — so
             * telling the curator is the only defence there is.
             */
            Route::get('/coves/{plan}/conflicts', [CoveItemController::class, 'conflicts']);

            /*
             * What this plan actually became.
             *
             * `/editions/{market}/{date}` reads back a Daily and nothing else,
             * so every slug-addressed kind had no read-back at all and an author
             * was told to go and look at the public page. It also reports the
             * build outcome, which is the difference between "published" and
             * "nothing happened" for a run nobody is watching.
             */
            Route::get('/coves/{plan}/edition', [CoveBriefController::class, 'edition']);

            Route::get('/guides', [GuideEditorialController::class, 'index']);
            Route::get('/guides/{guide}', [GuideEditorialController::class, 'show']);

            Route::get('/editions/{market}/{date}', [EditionController::class, 'show']);
        });

        /*
         * Write: drafts only.
         *
         * Nothing in this group can reach a reader. A plan is created as a
         * draft and the builder ignores drafts; a guide is created unpublished
         * and the public route filters on published. The tighter throttle is
         * because these are the calls that cost database work and because a
         * writer in a loop is the realistic failure mode.
         */
        Route::middleware(['api.ability:'.ApiToken::WRITE, 'throttle:editorial-writes'])->group(function () {
            Route::post('/coves', [CovePlanController::class, 'store']);

            /*
             * Ideas, not prose: N draft plans from the sources that already
             * know what is worth writing about here.
             *
             * Registered before /coves/{plan} would matter if this were a GET;
             * it is a POST to a distinct path, and it sits here rather than
             * under `publish` because a draft is exactly what a writing key is
             * allowed to make. Nothing it creates can reach a reader.
             */
            Route::post('/coves/drafts', [CoveDraftController::class, 'store']);

            /*
             * One named day, and one season.
             *
             * The two write actions the Cove calendar screen has and the API did
             * not. `draftOn()` fills exactly the day asked for — the count form
             * above walks forward filling whatever it finds, which is right for
             * topping a queue up and wrong for "do 14 February". `plan()` lays a
             * season out or brings it round; one endpoint, because they are one
             * editorial event a year apart and the service knows which applies.
             */
            Route::post('/calendar/draft', [CoveCalendarController::class, 'draftDay']);
            Route::post('/seasons/{topic}/plan', [CoveCalendarController::class, 'planSeason']);

            /*
             * Prose back, and only prose.
             *
             * Narrower than POST /coves on purpose: that one replaces the item
             * list wholesale, so an agent sending only words there can empty a
             * curated shortlist. This cannot touch membership or rank.
             */
            Route::post('/coves/{plan}/editorial', [CoveQueueController::class, 'store']);

            /*
             * Curating from outside the panel.
             *
             * Nine of the curation screen's eleven actions had no HTTP twin, so
             * an outside author could write about a shortlist and never change
             * one. Every route here delegates to the service the Livewire screen
             * already calls — a second implementation of "add a product to a
             * plan" would disagree about market scoping and about what a rank
             * means, and only one of the two would be what the panel shows.
             *
             * Under `write` rather than `publish`: a shortlist on a draft is not
             * something a reader can see. The controller refuses a plan that has
             * already been approved, which is the same line the prose endpoints
             * hold.
             */
            /*
             * The plan's own settings, without the whole-plan upsert.
             *
             * `POST /coves` replaces the shortlist wholesale, so flipping
             * `pickMode` or `writer` through it meant re-sending every product
             * and risking somebody's curation on a client's bookkeeping.
             */
            Route::patch('/coves/{plan}', [CovePlanController::class, 'patch']);

            Route::post('/coves/{plan}/items', [CoveItemController::class, 'store']);
            Route::patch('/coves/{plan}/items', [CoveItemController::class, 'update']);
            Route::delete('/coves/{plan}/items/{item}', [CoveItemController::class, 'destroy']);
            Route::post('/coves/{plan}/suggest', [CoveItemController::class, 'suggest']);

            /*
             * One stage, over a set.
             *
             * A run to `build` costs about four writes per Cove and writes are
             * 20/min per token, so a thirty-Cove push spent most of an hour
             * being paced. Fewer, larger calls rather than a looser limit for
             * the keys that can reach a reader.
             *
             * Registered under `write` because `curate` belongs to a writing
             * key; the controller demands `publish` for `approve` and `build`
             * before it does any work, because one path serves three stages and
             * a route group cannot say that.
             */
            Route::post('/coves/stages/{stage}', [CoveStageController::class, 'run']);

            Route::post('/guides', [GuideEditorialController::class, 'store']);
        });

        /*
         * Publish: the calls that put something in front of people.
         *
         * Held apart so the common case — an automated writer that drafts and
         * a human who approves — is the default rather than something you have
         * to remember to configure.
         */
        Route::middleware(['api.ability:'.ApiToken::PUBLISH, 'throttle:editorial-writes'])->group(function () {
            Route::post('/coves/{plan}/approve', [CovePlanController::class, 'approve']);
            Route::post('/coves/{plan}/build', [CovePlanController::class, 'build']);
            Route::post('/guides/{guide}/publish', [GuideEditorialController::class, 'publish']);
            Route::post('/editions/{market}/{date}/build', [EditionController::class, 'build']);
        });
    });
