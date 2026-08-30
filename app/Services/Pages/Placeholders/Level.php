<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

/**
 * Where a placeholder function may be used.
 *
 * The distinction is not decoration. An inline function substitutes into a
 * sentence and its output has to sit inside a `<p>`; a block-level one draws
 * markup of its own — a row of chips — which cannot legally nest there.
 *
 * A block-level function is therefore used as a **paragraph containing nothing
 * else**, which is what lets the whole feature exist without a third block kind.
 * Validation enforces it, and the renderer enforces it again, because a widget
 * with prose wrapped round it has no sensible rendering and silently producing
 * one would be worse than refusing.
 */
enum Level: string
{
    case Inline = 'inline';
    case Block = 'block';
}
