<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GuideItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuideItem extends Model
{
    /** @use HasFactory<GuideItemFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['unavailable' => 'boolean'];
    }

    /** @return BelongsTo<Guide, $this> */
    public function guide(): BelongsTo
    {
        return $this->belongsTo(Guide::class);
    }

    /** @return BelongsTo<ProductGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }
}
