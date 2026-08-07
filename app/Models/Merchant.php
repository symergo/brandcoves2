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
