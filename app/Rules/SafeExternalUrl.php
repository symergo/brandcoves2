<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\WishlistItem;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A link a person typed, which we are going to put in an `href`.
 *
 * Laravel's `url` rule is not this rule. It accepts anything with a plausible
 * scheme — `javascript:`, `data:`, `ftp:` — because validating a URL and
 * deciding a URL is safe to click are different questions, and only the second
 * one matters when the value ends up in an anchor on a page other people open.
 *
 * The answer lives on the model (`WishlistItem::isSafeExternalUrl()`), which is
 * also what the write path re-checks. This class exists to attach a sentence a
 * human can act on — "it has to start with https://" — to the same test, rather
 * than letting the saver silently drop the link and leave somebody wondering
 * where it went.
 */
class SafeExternalUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // An absent link is fine — this rule only judges one that is present.
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (! is_string($value) || ! WishlistItem::isSafeExternalUrl($value)) {
            $fail(__('site.lists.manual_url_invalid'));
        }
    }
}
