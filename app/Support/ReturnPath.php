<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where to put somebody back after they sign in, if it is somewhere on this site.
 *
 * This value is chosen by the caller — a page saying "put me back here" — and it
 * ends up in `url.intended`, which is what `redirect()->intended()` hands a
 * freshly signed-in person to. An unchecked value there is an open redirect
 * wearing a login page as a costume: follow a link, sign in for real, and be
 * handed to somebody else's host with the trust of having just authenticated.
 *
 * Extracted from `SaveIntentController` when claiming grew the same need. Two
 * copies of an open-redirect guard is one copy that gets loosened later by
 * somebody who does not know the other exists.
 */
final class ReturnPath
{
    /**
     * A path on this host, or nothing.
     *
     * A leading `//` is rejected because `//evil.example` is a protocol-relative
     * URL and not a path at all, and a backslash because browsers have
     * historically normalised it to a slash.
     */
    public static function safe(?string $path): ?string
    {
        if ($path === null || ! str_starts_with($path, '/')) {
            return null;
        }

        if (str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        return $path;
    }
}
