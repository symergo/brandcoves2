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
 * The short product name a Cove's text gives each pick, shown on its card
 * (owner, 2026-11-10). See App\Services\Cove\PickNames.
 */
class PickNamesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_link_label_in_the_text_becomes_the_cards_name(): void
    {
        $ninja = $this->group('NINJA AF200EU Foodi Dual Zone AIRFRYER 7 6 L', 'Ninja');
        $lego = $this->group('LEGO 21367 Ideas Tintin Maanraket - Bouwset voor volwassenen', 'LEGO');
        $cove = $this->guide("De [[product:{$ninja->id}|Ninja Foodi Dual Zone]] wint.\n\nEn de [[product:{$lego->id}|LEGO Ideas Tintin Maanraket]].", [$ninja, $lego]);

        $this->assertSame(2, app(PickNames::class)->fill($cove));

        $this->assertSame(['Ninja Foodi Dual Zone', 'LEGO Ideas Tintin Maanraket'], $cove->picks()->orderBy('rank')->pluck('short_name')->all());
    }

    #[Test]
    public function a_label_that_does_not_name_the_product_is_not_used(): void
    {
        $ninja = $this->group('NINJA AF200EU Foodi Dual Zone AIRFRYER 7 6 L', 'Ninja');
        // "the odd one" is a fine phrase in a sentence and no name for a card.
        $cove = $this->guide("Dan [[product:{$ninja->id}|the odd one]], en later [[product:{$ninja->id}|airfryer van Ninja]].", [$ninja]);

        app(PickNames::class)->fill($cove);

        // The first label that shares a word with the product wins, capitalised.
        $this->assertSame('Airfryer van Ninja', $cove->picks()->value('short_name'));
    }

    #[Test]
    public function a_product_the_text_no_longer_names_falls_back_to_its_title(): void
    {
        $ninja = $this->group('NINJA AF200EU Foodi Dual Zone AIRFRYER 7 6 L', 'Ninja');
        $cove = $this->guide("De [[product:{$ninja->id}|Ninja Foodi Dual Zone]].", [$ninja]);
        app(PickNames::class)->fill($cove);

        $cove->update(['body' => 'Een tekst zonder link.']);
        app(PickNames::class)->fill($cove->fresh());

        $pick = $cove->picks()->first();
        $this->assertNull($pick->short_name);
        $this->assertSame($ninja->displayTitle(), $pick->cardTitle());
    }

    #[Test]
    public function the_guide_card_shows_the_short_name(): void
    {
        $ninja = $this->group('NINJA AF200EU Foodi Dual Zone AIRFRYER 7 6 L', 'Ninja');
        $cove = $this->guide("De [[product:{$ninja->id}|Ninja Foodi Dual Zone]] wint.", [$ninja]);
        app(PickNames::class)->fill($cove);

        $this->get('/be-nl/guides/beste-airfryers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('items.0.title', 'Ninja Foodi Dual Zone'));
    }

    /** @param  list<ProductGroup>  $groups */
    private function guide(string $body, array $groups): DailyPickSet
    {
        $cove = DailyPickSet::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Guide->value,
            'slug' => 'beste-airfryers',
            'theme_title' => 'De beste airfryers',
            'theme_slug' => 'beste-airfryers',
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

    private function group(string $title, string $brand): ProductGroup
    {
        static $i = 0;
        $i++;

        return ProductGroup::create([
            'market' => Market::BeNl->value,
            'identity_key' => "names-{$i}",
            'identity_kind' => 'title',
            'title' => $title,
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
