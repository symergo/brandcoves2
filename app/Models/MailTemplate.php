<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An editor's version of one email, in one language.
 *
 * The shipped copy in `lang/{language}/site.php` is the default and is never
 * removed: this overlays it. A row that is absent, or `enabled = false`, means
 * the email reads exactly as it always did — which is what makes the override
 * safe to hand over, and what makes "put it back" a checkbox rather than an
 * archaeology exercise.
 *
 * @property string $key
 * @property string $language
 * @property string $subject
 * @property string $body
 * @property bool $enabled
 */
class MailTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
