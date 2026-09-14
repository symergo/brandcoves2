<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an imported Amazon page said about itself.
 *
 * `amazon_products` was created to hold a DECISION and explicitly not a
 * catalogue: "no price, no availability, no description, no image". This adds a
 * description, an image and a barcode, so it is worth being exact about what
 * changed and what did not.
 *
 * ## What changed, and on whose say-so
 *
 * The owner asked, on 2026-09-14, for the browser extension to save an Amazon
 * page's title, description, image link, category and EAN/UPC — and said
 * explicitly **not the price, yet**. That is a deliberate narrowing, and it is
 * the right field to hold back: price and availability are what the Associates
 * agreement binds to a 24-hour refresh, and a stale price is the one mistake
 * that is both visible to a shopper and actionable by Amazon.
 *
 * ## What did NOT change
 *
 * Amazon still does not enter the catalogue. `Source::allowsCatalogueStorage()`
 * is untouched and still false, so nothing here reaches `products`, the search
 * index, the offer comparison, a wishlist, a chart or an email. Those eight
 * call sites each encode their own compliance reasoning and flipping one
 * boolean would re-enable all of them at once, silently. This table stays what
 * it was: a place we keep what we know about an ASIN, off to one side.
 *
 * Read `docs/features/amazon-compliance.md` before building on this. The audit
 * there lists mirroring as restriction 3, and these columns sit against it —
 * knowingly, and only for the fields the owner named.
 *
 * ## Why the barcode is the valuable column
 *
 * `identity_key` has been on this table since it was created, described as "the
 * bridge" that points an ASIN at the product group other shops' offers hang
 * off — and nothing could ever fill it, because Amazon does not publish
 * barcodes through any interface we had. A product page prints one. With it, a
 * pasted Amazon link can resolve to a GiftCoves product page instead of a dead
 * end, which is the feature `SearchController::productFor()` was written for
 * and has never been able to do.
 *
 * Expand-only: every column is nullable and nothing reads them yet, so a
 * rollback to the previous release meets a schema it can still read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amazon_products', function (Blueprint $table) {
            // What the page said, kept as stated. There is no Amazon API
            // configured to re-fetch from, so unlike bol the page IS the
            // source here — which is exactly why `imported_from` records
            // where it came from and `imported_at` records when.
            $table->text('description')->nullable();
            $table->text('image_url')->nullable();
            $table->string('ean', 20)->nullable();
            $table->text('imported_from')->nullable();
            $table->timestampTz('imported_at')->nullable();

            // The lookup that makes the bridge worth having: given a barcode
            // from a feed, which ASIN is the same physical product?
            $table->index('ean');
        });
    }

    public function down(): void
    {
        Schema::table('amazon_products', function (Blueprint $table) {
            $table->dropIndex(['ean']);
            $table->dropColumn(['description', 'image_url', 'ean', 'imported_from', 'imported_at']);
        });
    }
};
