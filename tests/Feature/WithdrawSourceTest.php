<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Withdrawing the offers a source left behind.
 *
 * Turning a connector off stops new offers arriving and does nothing about the
 * ones already stored — they stay `active`, keep being served, and stop being
 * refreshed. This command is the second half, and every test here is about it
 * touching exactly the rows it was aimed at and no others.
 */
class WithdrawSourceTest extends TestCase
{
    use RefreshDatabase;

    private function offer(Market $market, Source $source, ProductStatus $status = ProductStatus::Active): Product
    {
        return Product::create([
            'source' => $source->value,
            'external_id' => $source->value.'-'.$market->value.'-'.random_int(1, 999999),
            'market' => $market->value,
            'title' => 'Test offer',
            'price' => 4900,
            'affiliate_url' => 'https://example.test/offer',
            'availability' => Availability::InStock->value,
            'status' => $status->value,
        ]);
    }

    private function statusOf(Product $product): string
    {
        return (string) $product->fresh()->getRawOriginal('status');
    }

    #[Test]
    public function it_refuses_while_the_source_still_serves_the_market(): void
    {
        // bol answers be-nl whenever it has credentials, so withdrawing there
        // would be undone by the next search — the operator would watch the
        // count drop and come back, with nothing reporting a problem.
        config()->set('giftcoves.connectors.bol.client_id', 'id');
        config()->set('giftcoves.connectors.bol.client_secret', 'secret');

        $offer = $this->offer(Market::BeNl, Source::Bol);

        $this->artisan('bc:withdraw-source', ['--market' => 'be-nl', '--source' => 'bol', '--write' => true])
            ->assertFailed();

        $this->assertSame(ProductStatus::Active->value, $this->statusOf($offer));
    }

    #[Test]
    public function force_overrides_the_guard(): void
    {
        config()->set('giftcoves.connectors.bol.client_id', 'id');
        config()->set('giftcoves.connectors.bol.client_secret', 'secret');

        $offer = $this->offer(Market::BeNl, Source::Bol);

        $this->artisan('bc:withdraw-source', [
            '--market' => 'be-nl', '--source' => 'bol', '--write' => true, '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(ProductStatus::Excluded->value, $this->statusOf($offer));
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        // The default, deliberately: this rewrites a market's whole catalogue
        // in one statement and the count is the thing worth reading first.
        $offer = $this->offer(Market::En, Source::Bol);

        $this->artisan('bc:withdraw-source', ['--market' => 'en', '--source' => 'bol'])
            ->assertSuccessful();

        $this->assertSame(ProductStatus::Active->value, $this->statusOf($offer));
    }

    #[Test]
    public function it_withdraws_only_the_market_and_source_it_was_aimed_at(): void
    {
        $target = $this->offer(Market::En, Source::Bol);
        $otherMarket = $this->offer(Market::NlNl, Source::Bol);
        $otherSource = $this->offer(Market::En, Source::Awin);

        // Stale means "fell out of a feed" — a fact about the merchant, not a
        // decision about the market. Overwriting it would lose that.
        $alreadyStale = $this->offer(Market::En, Source::Bol, ProductStatus::Stale);

        $this->artisan('bc:withdraw-source', ['--market' => 'en', '--source' => 'bol', '--write' => true])
            ->assertSuccessful();

        $this->assertSame(ProductStatus::Excluded->value, $this->statusOf($target));
        $this->assertSame(ProductStatus::Active->value, $this->statusOf($otherMarket));
        $this->assertSame(ProductStatus::Active->value, $this->statusOf($otherSource));
        $this->assertSame(ProductStatus::Stale->value, $this->statusOf($alreadyStale));
    }

    #[Test]
    public function restore_is_the_undo(): void
    {
        $offer = $this->offer(Market::En, Source::Bol);

        $this->artisan('bc:withdraw-source', ['--market' => 'en', '--source' => 'bol', '--write' => true])
            ->assertSuccessful();
        $this->assertSame(ProductStatus::Excluded->value, $this->statusOf($offer));

        // No guard on the way back: restoring a source that does not serve the
        // market is exactly what somebody reversing a mistake needs to do.
        $this->artisan('bc:withdraw-source', [
            '--market' => 'en', '--source' => 'bol', '--write' => true, '--restore' => true,
        ])->assertSuccessful();

        $this->assertSame(ProductStatus::Active->value, $this->statusOf($offer));
    }

    #[Test]
    public function running_it_twice_is_a_no_op(): void
    {
        // Every operational command in this project is safe to re-run, and this
        // one is most likely to be run twice — once on staging, once on
        // production, from the same shell history.
        $this->offer(Market::En, Source::Bol);

        $this->artisan('bc:withdraw-source', ['--market' => 'en', '--source' => 'bol', '--write' => true])
            ->assertSuccessful();

        $this->artisan('bc:withdraw-source', ['--market' => 'en', '--source' => 'bol', '--write' => true])
            ->expectsOutputToContain('Nothing to do.')
            ->assertSuccessful();
    }

    #[Test]
    public function an_unknown_market_or_source_is_refused_rather_than_guessed(): void
    {
        $this->artisan('bc:withdraw-source', ['--market' => 'de-de', '--source' => 'bol'])
            ->assertFailed();

        $this->artisan('bc:withdraw-source', ['--market' => 'en', '--source' => 'zalando'])
            ->assertFailed();
    }
}
