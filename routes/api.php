<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CatalogueController;
use App\Http\Controllers\Api\CoveBriefController;
use App\Http\Controllers\Api\CoveDraftController;
use App\Http\Controllers\Api\CovePlanController;
use App\Http\Controllers\Api\CoveQueueController;
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
             * Prose back, and only prose.
             *
             * Narrower than POST /coves on purpose: that one replaces the item
             * list wholesale, so an agent sending only words there can empty a
             * curated shortlist. This cannot touch membership or rank.
             */
            Route::post('/coves/{plan}/editorial', [CoveQueueController::class, 'store']);
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
