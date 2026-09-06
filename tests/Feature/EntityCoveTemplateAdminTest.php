<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\EditPageTemplate;
use App\Models\User;
use App\Services\Pages\Regions\EntityCoveRegions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The entity pages are reachable from the page-template screen.
 *
 * Declaring a region is not the same as somebody being able to write in it. The
 * screen builds its page list from the registry, so this ought to follow — but
 * "ought to follow" is what the retired `brand_intro` surface also did, and it
 * spent weeks accepting edits that reached no page.
 */
class EntityCoveTemplateAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    #[Test]
    public function both_entity_pages_are_offered(): void
    {
        $component = Livewire::actingAs($this->admin())->test(EditPageTemplate::class);

        foreach ([EntityCoveRegions::BRAND, EntityCoveRegions::SHOP] as $page) {
            $component->set('data.pageKey', $page);

            // The first region of that page, selected for you. Leaving a
            // previous page's region selected shows a screen with nothing on it
            // and no explanation of why.
            $this->assertSame('above_prose', $component->get('region'), "{$page} did not select a region");

            // Both ship empty, which is the point: a place, not a comeback.
            $this->assertSame([], $component->get('data.blocks'));

            $component->set('data.region', 'below_prose');
            $this->assertSame([], $component->get('data.blocks'));
        }
    }

    #[Test]
    public function the_page_names_read_as_pages_rather_than_as_keys(): void
    {
        /*
         * The labels are derived — `Str::headline('brand_cove')` — so this is
         * really asserting the keys were named for a person to read. A page
         * called "Brand Cove" in a dropdown is one an editor can pick with
         * confidence; `brand_cove` is one they guess at.
         */
        $this->assertSame('Brand Cove', Str::headline(EntityCoveRegions::BRAND));
        $this->assertSame('Shop Cove', Str::headline(EntityCoveRegions::SHOP));
    }
}
