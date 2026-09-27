<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Seo\PageMeta;
use App\Services\Social\InviteMailer;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Wil je geen uitnodigingen meer ontvangen?", from the link in every
 * invitation email, and the "Meld als spam" button on the page it opens.
 *
 * Two different answers since 2026-09-27 (owner's decision): the first only
 * stops the emails; only the spam button counts a complaint against the
 * member who sent the invitation.
 *
 * Works without an account: the reader may never have been here. The signed
 * URL is the credential. It names the member who sent the invitation and a
 * hash of the address it went to, and the signature is what stops anybody
 * silencing another address or complaining against another member.
 *
 * ## Two steps on the link, one in the header
 *
 * The link in the email opens a page; its buttons do it. Unlike the
 * reminder emails' stop link, which acts on the GET: the spam button counts
 * a complaint against a person, and company mail filters
 * open every link in an email to scan it. A GET that complained would let a
 * virus scanner stop a member's invitations. The mail client's own
 * unsubscribe button (RFC 8058) POSTs, which no scanner does, so that path is
 * one step.
 *
 * The page reads the current state rather than a flash, so opening the link
 * again later still shows the truth and the undo.
 */
class InviteNotWantedController extends Controller
{
    public function show(Request $request, InviteMailer $mailer, string $market, string $inviter, string $hash): Response
    {
        app(PageMeta::class)->set(
            title: __('site.invite_mail.page_title'),
            robots: 'noindex, nofollow',
        );

        $current = app(CurrentMarket::class)->get();

        return Inertia::render('Invites/NotWanted', [
            'stopped' => $mailer->isSuppressed($hash),
            'reported' => $mailer->isReported((int) $inviter, $hash),
            'stopUrl' => $mailer->notWantedUrl((int) $inviter, $hash, $current),
            'spamUrl' => $mailer->spamUrl((int) $inviter, $hash, $current),
            'undoUrl' => $mailer->undoUrl((int) $inviter, $hash, $current),
        ]);
    }

    /**
     * "Stuur me geen uitnodigingen meer", and the mail client's one-click
     * unsubscribe POST. Stops the emails; counts nothing against anybody.
     */
    public function store(InviteMailer $mailer, string $market, string $inviter, string $hash): RedirectResponse
    {
        $mailer->stop($hash);

        return redirect()->to($mailer->notWantedUrl((int) $inviter, $hash, app(CurrentMarket::class)->get()));
    }

    /** "Meld als spam": stops the emails and counts one complaint. */
    public function report(InviteMailer $mailer, string $market, string $inviter, string $hash): RedirectResponse
    {
        $mailer->report((int) $inviter, $hash);

        return redirect()->to($mailer->notWantedUrl((int) $inviter, $hash, app(CurrentMarket::class)->get()));
    }

    public function undo(InviteMailer $mailer, string $market, string $inviter, string $hash): RedirectResponse
    {
        $mailer->undo((int) $inviter, $hash);

        return redirect()
            ->to($mailer->notWantedUrl((int) $inviter, $hash, app(CurrentMarket::class)->get()))
            ->with('success', __('site.invite_mail.undone'));
    }
}
