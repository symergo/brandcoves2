<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Market;
use App\Enums\Vibe;
use App\Services\Gift\RejectionMemory;
use App\Services\Gift\TasteBrief;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The bucketing and the two caps.
 *
 * Both caps exist because a session store is visitor-controlled input: an
 * unbounded list of ids, in a session that travels with every request, is a way
 * to make somebody's whole browsing session progressively slower.
 */
class RejectionMemoryTest extends TestCase
{
    private function memory(): RejectionMemory
    {
        return new RejectionMemory(new Store('test', new ArraySessionHandler(120)));
    }

    private function brief(array $overrides = []): TasteBrief
    {
        return new TasteBrief(
            market: $overrides['market'] ?? Market::BeNl,
            interests: $overrides['interests'] ?? ['coffee'],
            vibe: $overrides['vibe'] ?? null,
            budgetMax: $overrides['budgetMax'] ?? 10000,
        );
    }

    #[Test]
    public function it_remembers_what_was_rejected(): void
    {
        $memory = $this->memory();
        $key = $memory->key($this->brief());

        $memory->remember($key, 1, 2, 3);

        $this->assertSame([1, 2, 3], $memory->all($key));
    }

    #[Test]
    public function remembering_the_same_id_twice_does_not_duplicate_it(): void
    {
        $memory = $this->memory();
        $key = $memory->key($this->brief());

        $memory->remember($key, 1, 2);
        $memory->remember($key, 2, 3);

        $this->assertSame([1, 2, 3], $memory->all($key));
    }

    #[Test]
    public function the_same_brief_answered_in_a_different_order_is_the_same_bucket(): void
    {
        // Otherwise re-answering the same six questions produces a fresh empty
        // memory and every rejection is forgotten.
        $memory = $this->memory();

        $this->assertSame(
            $memory->key($this->brief(['interests' => ['coffee', 'reading']])),
            $memory->key($this->brief(['interests' => ['reading', 'coffee']])),
        );
    }

    #[Test]
    public function a_different_brief_is_a_different_bucket(): void
    {
        $memory = $this->memory();

        $mother = $memory->key($this->brief(['interests' => ['gardening']]));
        $colleague = $memory->key($this->brief(['interests' => ['gaming']]));

        $this->assertNotSame($mother, $colleague);

        $memory->remember($mother, 1, 2, 3);

        $this->assertSame([], $memory->all($colleague));
    }

    #[Test]
    public function the_vibe_and_the_budget_are_part_of_the_brief(): void
    {
        $memory = $this->memory();

        $this->assertNotSame(
            $memory->key($this->brief()),
            $memory->key($this->brief(['vibe' => Vibe::Playful])),
        );

        $this->assertNotSame(
            $memory->key($this->brief()),
            $memory->key($this->brief(['budgetMax' => 2500])),
        );
    }

    #[Test]
    public function one_bucket_is_capped_and_keeps_the_newest(): void
    {
        // An old rejection matters less than a recent one when something has to
        // go, and something has to go: this list rides along in the session.
        $memory = $this->memory();
        $key = $memory->key($this->brief());

        $memory->remember($key, ...range(1, 80));

        $kept = $memory->all($key);

        $this->assertCount(60, $kept);
        $this->assertSame(80, end($kept));
        $this->assertNotContains(1, $kept);
    }

    #[Test]
    public function only_a_handful_of_briefs_are_kept(): void
    {
        $memory = $this->memory();

        $keys = [];

        foreach (['coffee', 'reading', 'gaming', 'fitness', 'travel', 'diy', 'music'] as $i => $interest) {
            $key = $memory->key($this->brief(['interests' => [$interest]]));
            $keys[$i] = $key;
            $memory->remember($key, $i + 1);
        }

        // The oldest fell out; the most recent survive.
        $this->assertSame([], $memory->all($keys[0]));
        $this->assertSame([7], $memory->all($keys[6]));
    }

    #[Test]
    public function forgetting_one_brief_leaves_the_others_alone(): void
    {
        $memory = $this->memory();

        $a = $memory->key($this->brief(['interests' => ['coffee']]));
        $b = $memory->key($this->brief(['interests' => ['reading']]));

        $memory->remember($a, 1);
        $memory->remember($b, 2);

        $memory->forget($a);

        $this->assertSame([], $memory->all($a));
        $this->assertSame([2], $memory->all($b));
    }

    #[Test]
    public function flushing_drops_everything(): void
    {
        // What "Start over" does, and the button says exactly that.
        $memory = $this->memory();

        $a = $memory->key($this->brief(['interests' => ['coffee']]));
        $b = $memory->key($this->brief(['interests' => ['reading']]));

        $memory->remember($a, 1);
        $memory->remember($b, 2);

        $memory->flush();

        $this->assertSame([], $memory->all($a));
        $this->assertSame([], $memory->all($b));
    }
}
