<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Availability;
use Database\Factories\PriceHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rolling price samples. Drives sparklines, the 30-day median behind discount
 * badges, and price-drop alerts.
 *
 * One row per product per day, enforced by a unique index — ingestion runs
 * hourly and 24 identical rows a day across a 60k catalogue is 500M rows a year.
 */
class PriceHistory extends Model
{
    /** @use HasFactory<PriceHistoryFactory> */
    use HasFactory;

    protected $table = 'price_history';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'availability' => Availability::class,
            'captured_at' => 'datetime',
            'captured_on' => 'date',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
