<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tell us what is wrong.
 *
 * ## Why the site needs one at all
 *
 * Every quality problem this catalogue has — a wrong price, a dead link, a
 * product filed under the wrong brand, a translation that reads like a machine
 * wrote it — is visible to a visitor long before it is visible to us, and there
 * has been nowhere to say so. Without this the only channel is the imprint
 * address, which almost nobody uses, so the errors simply stayed.
 *
 * ## Why it is a page and not a floating widget
 *
 * A widget is a permanent piece of chrome on every screen, in service of an
 * action almost nobody takes on any given visit. A page in the menu costs
 * nothing when it is not wanted and is findable when it is — and it can be
 * linked to, which a widget cannot.
 *
 * ## No account required
 *
 * The reports worth having come from people annoyed enough to type but not
 * invested enough to register. An address is optional, and the form says what
 * it is for: replying, and nothing else.
 *
 * ## What stops it being a spam inbox
 *
 * A rate limit per visitor, and a honeypot field. Deliberately **not** a
 * captcha: this form is used a handful of times a day at most, and a challenge
 * in front of it costs every honest reporter more than the spam costs us.
 *
 * A rejected submission is answered exactly like an accepted one. Telling a
 * script which of its attempts landed is how it learns to tune itself, and a
 * human who has hit the limit is better served by "thanks" than by an error
 * about a quota they did not know existed.
 */
class FeedbackController extends Controller
{
    /** Enough for a real report and a follow-up; useless as a submission tool. */
    private const PER_HOUR = 5;

    public function show(Request $request, CurrentMarket $current): Response
    {
        app(PageMeta::class)->set(
            title: __('site.feedback.seo_title'),
            description: __('site.feedback.seo_description'),
            canonical: url($current->url('feedback')),
            // Indexable: it is a real page of the site, and "how do I report a
            // wrong price on giftcoves" should find it.
            robots: null,
        );

        return Inertia::render('Feedback', [
            /*
             * Where they came from, prefilled and editable.
             *
             * "The price is wrong" with no page attached is unanswerable, and
             * asking somebody to paste a URL after they have already navigated
             * away is asking them to go back and get it. The referer covers the
             * common path — they were on the page, they clicked Feedback — and
             * is only trusted as far as being shown back to them for correction.
             */
            'path' => $this->refererPath($request),
        ]);
    }

    public function store(Request $request, CurrentMarket $current): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:10', 'max:4000'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:254'],
            'path' => ['nullable', 'string', 'max:2048'],
            /*
             * The honeypot. A field no human sees, so anything in it was typed
             * by something filling every input on the page.
             *
             * Validated as `max:0` rather than checked by hand so that a bot
             * gets a 422 that looks like any other validation failure, and the
             * rule sits with the rest of the shape of this request.
             */
            'website' => ['nullable', 'string', 'max:0'],
        ]);

        $key = 'feedback:'.sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            // Same answer as success — see the class docblock.
            return back()->with('status', __('site.feedback.thanks'));
        }

        RateLimiter::hit($key, 3600);

        Feedback::query()->create([
            'market' => $current->get()->value,
            'message' => $validated['message'],
            'email' => $validated['email'] ?? null,
            'user_id' => $request->user()?->id,
            'path' => $validated['path'] ?? null,
        ]);

        return back()->with('status', __('site.feedback.thanks'));
    }

    /**
     * The path of the page they came from, if it was one of ours.
     *
     * Host-checked, because `Referer` is visitor-controlled: without this, an
     * off-site link could put any string it liked into a field we render back.
     * The query string is dropped — it adds nothing to a bug report and can
     * carry whatever the visitor typed somewhere else on the site.
     */
    private function refererPath(Request $request): ?string
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
