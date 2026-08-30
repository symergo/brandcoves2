<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Source;
use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => Source::class,
            'enabled' => 'boolean',
            'trusts_reference_price' => 'boolean',
        ];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<Feed, $this> */
    public function feeds(): HasMany
    {
        return $this->hasMany(Feed::class);
    }

    /**
     * The shop's name as a shopper should read it.
     *
     * Feeds name an advertiser per country, so the catalogue holds "Coolblue
     * BE", "DreamLand BE", "Action BE-NL". That suffix is the network's
     * bookkeeping: which advertiser account the offers came from. It is not
     * part of the shop's name, and it is information the visitor already has —
     * every page they are looking at is one market, chosen in the switcher, and
     * every price on it is in that market's currency. Repeated down a row of
     * shop chips and again across every lane header it is pure noise, and it
     * costs real width in a 224px column where the name is already truncating.
     *
     * Only a trailing standalone country code goes, optionally bracketed and
     * optionally a pair joined by a hyphen. Nothing else is touched: "bol.com"
     * keeps its dot, and a shop whose name genuinely ends in a word is safe
     * because a word is not a two-letter code.
     *
     * Display only. `merchants.name` keeps the feed's spelling, because that is
     * what identifies the advertiser account when a feed is being debugged.
     */
    public function displayName(): string
    {
        return self::withoutCountrySuffix($this->name);
    }

    /**
     * The same rule, for a name that arrived as a string.
     *
     * Several surfaces read a merchant name off a join rather than a hydrated
     * model — `$offer->merchantName` on the brand and wishlist pages — and they
     * have to fold it the same way, or the same shop is "Coolblue" in one list
     * and "Coolblue BE" in the next.
     */
    public static function withoutCountrySuffix(string $name): string
    {
        $trimmed = preg_replace(
            '/\s*[\(\[\-–|]?\s*\b[a-z]{2}(-[a-z]{2})?\b\s*[\)\]]?\s*$/i',
            '',
            $name,
        );

        // Never return an empty string: a merchant actually called "BE" would
        // otherwise lose its whole name and render as a blank column header.
        return $trimmed === null || trim($trimmed) === '' ? $name : trim($trimmed);
    }

    /**
     * The shop's favicon, for the merchant badge on offer rows.
     *
     * Derived from the merchant's own domain. Falls back to null rather than to
     * the affiliate network's icon — showing Awin's logo for Coolblue, Krefel
     * and everyone else makes the store lanes useless.
     */
    public function faviconUrl(): ?string
    {
        if ($this->logo_url !== null && $this->logo_url !== '') {
            return $this->logo_url;
        }

        return $this->domain !== null && $this->domain !== ''
            ? 'https://'.$this->domain.'/favicon.ico'
            : null;
    }
}
