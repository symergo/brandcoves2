<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The page a visitor came from, if it was one of ours.
 *
 * ## Why this is host-checked, and why that is the whole point
 *
 * `Referer` is a header the visitor's browser sends and anything can set. The
 * report form renders this value back into an editable field, so without the
 * host check an off-site link could put any string it liked in front of the
 * person filling it in.
 *
 * The query string is dropped as well: it adds nothing to a bug report and can
 * carry whatever that visitor typed somewhere else on the site.
 *
 * ## Why it is shared
 *
 * The rule was `FeedbackController`'s alone until the form moved onto `/help`
 * on 2026-09-06. A second copy on the new page was written without the host
 * check - the test caught it - which is exactly how one of two copies of a
 * security rule ends up being the one that is wrong.
 */
final class RefererPath
{
    public static function of(Request $request): ?string
    {
        $referer = (string) $request->headers->get('referer');

        if ($referer === '') {
            return null;
        }

        $parts = parse_url($referer);

        if (($parts['host'] ?? null) !== $request->getHost()) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');

        return $path === '' ? null : $path;
    }
}
