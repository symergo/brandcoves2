<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CatalogueController;
use App\Http\Controllers\Api\CovePlanController;
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
            Route::get('/coves/{plan}', [CovePlanController::class, 'show']);

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
