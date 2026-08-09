<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A setting an administrator can change without a deploy.
 *
 * The table was created in Phase 0 for connector credentials and never used.
 * Its shape — `(source, key, encrypted_value)` — is exactly right for this, so
 * it becomes the general store: `source` is the subsystem ('ai', later a
 * connector), `key` is the setting.
 *
 * ## Everything is encrypted, including the booleans
 *
 * Not because `enabled = true` is a secret, but because a column that is
 * sometimes encrypted and sometimes not is a column someone eventually reads
 * raw. One rule, no exceptions, no branch to get wrong — and the one value that
 * genuinely matters (an API key) is covered by the same path as everything else.
 *
 * The cost is that these cannot be filtered in SQL. Acceptable: the whole set
 * for a source is a handful of rows, read once and cached.
 *
 * ## Encrypted with APP_KEY
 *
 * So a production dump restored on a laptop yields undecryptable noise, which is
 * the intent — `bc:scrub` deletes the table outright rather than trying to
 * anonymise it.
 *
 * @property string $source
 * @property string $key
 * @property mixed $encrypted_value
 */
class ConnectorSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // `encrypted:json`, not plain `encrypted`: a setting may be a bool,
            // an int or a string, and round-tripping through JSON is what keeps
            // `false` from coming back as the string "".
            'encrypted_value' => 'encrypted:json',
        ];
    }
}
