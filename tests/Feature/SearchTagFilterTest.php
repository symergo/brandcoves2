<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\MakesTaggedGroups;
use Tests\TestCase;

/**
 * Search filters for who it is for, what they love and the occasion
 * (roadmap step 4, part 3): `?for=`, `?interest=`, `?occasion=`, matched
 * against the editors' tags and the crowd's.
 */
class SearchTagFilterTest extends TestCase
{
    use MakesTaggedGroups;
    use RefreshDatabase;

    #[Test]
    public function an_interest_filter_keeps_only_what_is_tagged_for_it(): void
    {
        $knife = $this->taggedGroup('Mes van staal', ['interest:cooking']);
        $crowd = $this->taggedGroup('Mes met houten heft', [], crowd: ['interest:cooking']);
        $this->taggedGroup('Mes voor de tuin', ['interest:gardening']);

        $this->get('/be-nl/zoek/mes?interest=cooking')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('results.items', fn ($items) => collect($items)->pluck('id')->sort()->values()->all()
                    === collect([$knife->id, $crowd->id])->sort()->values()->all())
                ->where('filters.interest', 'cooking')
                ->where('tagFilters.0.label', 'Koken'));
    }

    #[Test]
    public function kinds_combine_and_values_of_one_kind_are_either(): void
    {
        $forDad = $this->taggedGroup('Mes voor papa', ['interest:cooking', 'recipient:father']);
        $this->taggedGroup('Mes voor mama', ['interest:cooking', 'recipient:mother']);
        $forDadGardening = $this->taggedGroup('Mes voor de tuin', ['interest:gardening', 'recipient:father']);

        // Father AND (cooking OR gardening).
        $this->get('/be-nl/zoek/mes?for=father&interest[]=cooking&interest[]=gardening')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'results.items',
                fn ($items) => collect($items)->pluck('id')->sort()->values()->all()
                    === collect([$forDad->id, $forDadGardening->id])->sort()->values()->all(),
            ));
    }

    #[Test]
    public function values_outside_the_vocabulary_are_ignored(): void
    {
        $this->taggedGroup('Mes van staal', ['interest:cooking']);
        $this->taggedGroup('Mes voor de tuin', ['interest:gardening']);

        $this->get('/be-nl/zoek/mes?interest=underwater-basket-weaving&for=uncle')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('results.items', 2)
                ->where('tagFilters', []));
    }

    #[Test]
    public function a_filtered_page_canonicalises_to_the_bare_term_and_each_chip_drops_itself(): void
    {
        $this->taggedGroup('Mes van staal', ['interest:cooking', 'occasion:christmas']);

        $response = $this->get('/be-nl/zoek/mes?interest=cooking&occasion=christmas')->assertOk();

        $response->assertSee('<link rel="canonical" href="'.url('/be-nl/zoek/mes').'"', escape: false);

        $props = $response->viewData('page')['props'];
        $this->assertSame('/be-nl/zoek/mes?occasion=christmas', $props['tagFilters'][0]['without']);
        $this->assertSame('/be-nl/zoek/mes?interest=cooking', $props['tagFilters'][1]['without']);
    }

    #[Test]
    public function a_filter_needs_no_term(): void
    {
        $knife = $this->taggedGroup('Mes van staal', ['interest:cooking', 'recipient:father']);
        $this->taggedGroup('Tuinhandschoenen', ['interest:gardening']);

        // The gift landing page's "search everything tagged for them" link.
        $this->get('/be-nl/search?for=father&interest=cooking')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('landing', null)
                ->where('results.items', fn ($items) => collect($items)->pluck('id')->all() === [$knife->id])
                ->where('tagFilters.0.label', 'voor papa'));
    }
}
