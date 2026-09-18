<?php

declare(strict_types=1);

namespace Tests\Unit\Cove;

use App\Enums\CoveKind;
use App\Enums\CoveScene;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which drawings a kind may name.
 *
 * `CoveScene::forKind()` is asked by three callers that each do something
 * different with the answer: the planner's Drawing select offers it, the
 * editorial API refuses a scene outside it, and the advice seeder reports a
 * content file naming one. Asking rather than listing is the point of the
 * method, and it is also why all three break the same way when a kind has no
 * arm in it.
 */
class CoveSceneTest extends TestCase
{
    #[Test]
    public function every_kind_can_be_asked_what_it_may_be_drawn_as(): void
    {
        /*
         * Brand was added to `CoveKind` after this match was written and never
         * got an arm, so asking it threw an UnhandledMatchError. Both callers
         * turn that into a fatal a person meets rather than a validation
         * message: a 500 when a brand plan is sent a scene, and a planner form
         * that cannot render, because the Drawing select decides whether to
         * appear by asking this first.
         *
         * The enum is walked rather than the seven kinds named, so a kind added
         * later fails here, where the answer is one line, instead of on the
         * screen somebody opens.
         */
        foreach (CoveKind::cases() as $kind) {
            $this->assertIsArray(CoveScene::forKind($kind));
        }
    }

    #[Test]
    public function a_cove_about_an_entity_carries_no_drawing(): void
    {
        /*
         * Empty, exactly as a Daily is, and the emptiness is load-bearing in two
         * places: `CovePlanResource` hides the Drawing select when the list is
         * empty, and `CovePlanController` turns any scene sent for such a kind
         * into "carries no drawing, so it names no scene" rather than storing
         * it.
         *
         * An entity Cove is about a named shop or a named brand, and the name is
         * what the page prints. A drawing there would be a mark standing in for
         * something that is not missing.
         */
        $this->assertSame([], CoveScene::forKind(CoveKind::Shop));
        $this->assertSame([], CoveScene::forKind(CoveKind::Brand));
        $this->assertSame([], CoveScene::forKind(CoveKind::Daily));

        // And the kinds that do draw still do. An empty list everywhere would
        // satisfy the three assertions above and quietly take the field off
        // every screen that offers it.
        $this->assertNotSame([], CoveScene::forKind(CoveKind::Persona));
        $this->assertNotSame([], CoveScene::forKind(CoveKind::Advice));
    }

    #[Test]
    public function a_kind_with_no_vocabulary_still_has_a_default_to_render(): void
    {
        /*
         * Two different questions, and a kind may name no scene and still need
         * one drawn. The column is nullable, so the controller resolves null
         * through `defaultFor()` at render: a missing drawing must never be a
         * missing page.
         */
        $this->assertSame(CoveScene::Article, CoveScene::defaultFor(CoveKind::Brand));
        $this->assertSame(CoveScene::Someone, CoveScene::defaultFor(CoveKind::Persona));
    }
}
