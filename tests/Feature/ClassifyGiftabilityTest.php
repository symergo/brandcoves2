<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Jobs\ClassifyGiftability;
use App\Models\ProductGroup;
use App\Services\Gift\GiftabilityClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The giftability pass, writing to a real database.
 *
 * The classifier itself is covered by a golden file with 35 cases and no
 * database at all. This covers the part that golden file cannot: the bulk
 * write.
 *
 * That gap was not theoretical. `flush()` built a CASE expression and bound
 * booleans through PDO, which sends every parameter as text — Postgres refuses
 * to coerce text into a boolean column inside a CASE. The whole pass failed on
 * the first chunk, and it reached staging because every test that touched this
 * job ran against an empty catalogue, so the write path never executed.
 */
class ClassifyGiftabilityTest extends TestCase
{
    use RefreshDatabase;

    private function group(string $title, int $price, ?string $category = null): ProductGroup
    {
        return ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'category' => $category,
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => 1,
            'in_stock' => true,
        ]);
    }

    #[Test]
    public function it_writes_both_verdicts_to_the_database(): void
    {
        $gift = $this->group('Sony WH-1000XM5 koptelefoon', 32999, 'Audio');
        $notGift = $this->group('HP 305XL inktcartridge zwart', 3299, 'Printers');

        (new ClassifyGiftability(Market::BeNl))->handle(app(GiftabilityClassifier::class));

        // The `false` case is the one that used to fail: PDO renders a PHP
        // false as an empty string, which Postgres rejects as a boolean.
        $this->assertTrue($gift->fresh()->giftable);
        $this->assertFalse($notGift->fresh()->giftable);
    }

    #[Test]
    public function the_reason_is_stored_so_a_rejection_is_explainable(): void
    {
        $this->group('HP 305XL inktcartridge zwart', 3299, 'Printers');

        (new ClassifyGiftability(Market::BeNl))->handle(app(GiftabilityClassifier::class));

        // "Why is this not in the gift results" is otherwise unanswerable over
        // 70,000 rows without re-running the whole pass.
        $this->assertSame(
            'consumable: cartridge',
            ProductGroup::query()->firstOrFail()->giftable_reason,
        );
    }

    #[Test]
    public function it_rewrites_a_previous_verdict_rather_than_leaving_it_stale(): void
    {
        $group = $this->group('Sony WH-1000XM5 koptelefoon', 32999, 'Audio');
        $group->forceFill(['giftable' => false, 'giftable_reason' => 'stale'])->save();

        (new ClassifyGiftability(Market::BeNl))->handle(app(GiftabilityClassifier::class));

        // A full pass, not an incremental one: the rules change more often than
        // the catalogue, so a partial pass leaves yesterday's verdict on most
        // rows with no way to tell which.
        $this->assertTrue($group->fresh()->giftable);
        $this->assertSame('ok', $group->fresh()->giftable_reason);
    }

    #[Test]
    public function a_chunk_larger_than_one_statement_still_writes_every_row(): void
    {
        // The job chunks at 1000. This is well under that, but it exercises the
        // multi-row VALUES join with a mix of verdicts, which is where the
        // type mismatch surfaced.
        for ($i = 0; $i < 40; $i++) {
            $this->group(
                $i % 2 === 0 ? "Bordspel variant {$i}" : "Inktcartridge variant {$i}",
                3999,
                'Div',
            );
        }

        (new ClassifyGiftability(Market::BeNl))->handle(app(GiftabilityClassifier::class));

        $this->assertSame(0, ProductGroup::query()->whereNull('giftable')->count());
        $this->assertSame(20, ProductGroup::query()->where('giftable', true)->count());
        $this->assertSame(20, ProductGroup::query()->where('giftable', false)->count());
    }
}
