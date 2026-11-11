<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Services\Cove\PickNames;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A product without a display title gets the short name a Cove's text gives
 * it (owner, 2026-11-11). See App\Services\Cove\PickNames.
 */
class PickNamesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_product_without_a_display_title_gets_the_name_from_the_text(): void
    {
        $ninja = $this->group('NINJA AF200EU Foodi Dual Zone AIRFRYER 7 6 L', 'Ninja');
        $lego = $this->group('LEGO 21367 Ideas Tintin Maanraket - Bouwset voor volwassenen', 'LEGO');
        $cove = $this->guide("De [[product:{$ninja->id}|Ninja Foodi Dual Zone]] wint.

En de [[product:{$lego->id}|LEGO Ideas Tintin Maanraket]].", [$ninja, $lego]);

        $this->assertSame(2, app(PickNames::class)->fill($cove));

        $this->assertSame('Ninja Foodi Dual Zone', $ninja->fresh()->display_title);
        $this->assertSame('LEGO Ideas Tintin Maanraket', $lego->fresh()->display_title);
    }

    #[Test]
    public function an_existing_display_title_is_never_replaced(): void
    {
        $ninja = $this->group('NINJA AF200EU Foodi Dual Zone AIRFRYER 7 6 L', 'Ninja', displayTitle: 'Ninja Dual Zone airfryer 7,6 L');
        $cove = $this->guide("De [[product:{$ninja->id}|Ninja Foodi Dual Zone]] wint.", [$ninja]);

        $this->assertSame(0, app(PickNames::class)->fill($cove));
        $this->assertSame('Ninja Dual Zone airfryer 7,6 L', $ninja->fresh()->display_title);

        // And a second Cove naming it differently does not rename it either:
        // the first name a product gets is its name.
        $other = $this->group('Philips Airfryer XL HD9270/90', 'Philips');
        $first = $this->guide("De [[product:{$other->id}|Philips Airfryer XL]].", [$other], 'eerste');
        $second = $this->guide("De [[product:{$other->id}|grote Philips-airfryer]].", [$other], 'tweede');
        app(PickNames::class)->fill($first);
        app(PickNames::class)->fill($second);
        $this->assertSame('Philips Airfryer XL', $other->fresh()->display_title);
    }

    #[Test]
    public function a_label_that_does_not_name_the_product_is_not_used(): void
    {
        $ninja = $this->group('NINJA AF200EU Foodi Dual Zone AIRFRYER 7 6 L', 'Ninja');
        // "the odd one" is a fine phrase in a sentence and no name for a product.
        $cove = $this->guide("Dan [[product:{$ninja->id}|the odd one]], en later [[product:{$ninja->id}|airfryer van Ninja]].", [$ninja]);

        app(PickNames::class)->fill($cove);

        // The first label that shares a word with the product wins, capitalised.
        $this->assertSame('Airfryer van Ninja', $ninja->fresh()->display_title);

        $vague = $this->group('Sony WH-1000XM5 Draadloze Koptelefoon', 'Sony');
        app(PickNames::class)->fill($this->guide("Neem [[product:{$vague->id}|deze]].", [$vague], 'vaag'));
        $this->assertNull($vague->fresh()->display_title);
    }

    #[Test]
    public function the_guide_card_shows_it(): void
    {
        $ninja = $this->group('NINJA AF200EU Foodi Dual Zone AIRFRYER 7 6 L', 'Ninja');
        $cove = $this->guide("De [[product:{$ninja->id}|Ninja Foodi Dual Zone]] wint.", [$ninja]);
        app(PickNames::class)->fill($cove);

        $this->get('/be-nl/guides/beste-airfryers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('items.0.title', 'Ninja Foodi Dual Zone'));
    }

    /** @param  list<ProductGroup>  $groups */
    private function guide(string $body, array $groups, string $slug = 'beste-airfryers'): DailyPickSet
    {
        $cove = DailyPickSet::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Guide->value,
            'slug' => $slug,
            'theme_title' => 'De beste airfryers',
            'theme_slug' => $slug,
            'theme_blurb' => 'Welke past bij jou?',
            'body' => $body,
            'status' => PublishStatus::Published->value,
            'published_at' => CarbonImmutable::now()->subHour(),
        ]);

        foreach ($groups as $rank => $group) {
            DailyPick::create(['set_id' => $cove->id, 'group_id' => $group->id, 'rank' => $rank + 1, 'slug' => $group->slug]);
        }

        return $cove->fresh();
    }

    private function group(string $title, string $brand, ?string $displayTitle = null): ProductGroup
    {
        static $i = 0;
        $i++;

        return ProductGroup::create([
            'market' => Market::BeNl->value,
            'identity_key' => "names-{$i}",
            'identity_kind' => 'title',
            'title' => $title,
            'display_title' => $displayTitle,
            'slug' => "names-{$i}",
            'brand' => $brand,
            'category' => 'Keuken',
            'image_url' => "https://example.test/{$i}.jpg",
            'min_price' => 9999,
            'max_price' => 9999,
            'offer_count' => 1,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'worth_showing' => true,
        ]);
    }
}
