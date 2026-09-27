<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Filament\Resources\GuideTopics\Pages\ListGuideTopics;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Jobs\RefreshTopicQueue;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Admin screens that did expensive work on every keystroke or every click.
 *
 * See docs/features/speed.md, "Shop page and admin".
 */
class AdminQueryCostTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_products_search_uses_ilike_on_the_title_so_the_trigram_index_serves_it(): void
    {
        /*
         * Filament's default search wrote `lower(title) like '%x%'`, which no
         * index serves: a full read of the offers table per keystroke, 668 ms
         * on production. `ilike` on the bare column is served by
         * products_title_trgm_idx.
         */
        $shop = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Koffiehuis',
            'domain' => 'koffiehuis.be',
        ]);

        $grinder = $this->offer($shop, 'Koffiemolen 100% RVS');
        $kettle = $this->offer($shop, 'Waterkoker');

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($this->admin())
            ->test(ListProducts::class)
            ->searchTable('KOFFIEMOLEN')
            ->assertCanSeeTableRecords([$grinder])
            ->assertCanNotSeeTableRecords([$kettle]);

        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));

        $this->assertMatchesRegularExpression('/"title"(::text)? ilike/', $sql);
        $this->assertStringNotContainsString('lower("title")', $sql);

        // A typed % is a percent sign, not a wildcard.
        Livewire::actingAs($this->admin())
            ->test(ListProducts::class)
            ->searchTable('100%')
            ->assertCanSeeTableRecords([$grinder])
            ->assertCanNotSeeTableRecords([$kettle]);
    }

    #[Test]
    public function the_shop_name_is_not_searched_the_shop_filter_is_for_that(): void
    {
        $shop = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Koffiehuis',
            'domain' => 'koffiehuis.be',
        ]);

        $kettle = $this->offer($shop, 'Waterkoker');

        Livewire::actingAs($this->admin())
            ->test(ListProducts::class)
            ->searchTable('Koffiehuis')
            ->assertCanNotSeeTableRecords([$kettle]);
    }

    #[Test]
    public function refreshing_the_topic_queue_queues_one_job_per_market(): void
    {
        /*
         * It ran the search-log mine and the seasonal seed for every market
         * inside the web request.
         */
        Queue::fake();

        Livewire::actingAs($this->admin())
            ->test(ListGuideTopics::class)
            ->callAction('refresh')
            ->assertNotified('Queue refresh queued');

        Queue::assertPushed(RefreshTopicQueue::class, count(Market::cases()));

        foreach (Market::cases() as $market) {
            Queue::assertPushed(RefreshTopicQueue::class, fn (RefreshTopicQueue $job) => $job->market === $market);
        }
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function offer(Merchant $shop, string $title): Product
    {
        return Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $shop->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'brand' => 'Merk',
            'price' => 2999,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
        ]);
    }
}
