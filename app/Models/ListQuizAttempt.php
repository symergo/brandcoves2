<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListQuizAttempt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['answers' => 'array', 'played_on' => 'date'];
    }

    /** @return BelongsTo<ListQuiz, $this> */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(ListQuiz::class, 'quiz_id');
    }
}
