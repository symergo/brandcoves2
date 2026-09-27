<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Seo\PageMeta;
use App\Services\Social\InviteAcceptance;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Uitnodiging aannemen", the button in an invitation email (2026-09-27).
 * The rules are in {@see InviteAcceptance}; this only picks the page.
 *
 * ## Opening is not accepting
 *
 * The GET shows a page with one button and consumes nothing: company mail
 * filters open every link in an email, and a GET that signed in would spend
 * the token on the scanner. The POST is the button, a plain form with the
 * session's CSRF token (the invitee's first GET starts a session, so the
 * token is there; unlike the not-wanted links, no mail client POSTs here).
 * Keeping CSRF stops another site from pressing somebody's button for them,
 * which would sign that browser into an account it did not choose.
 *
 * ## Somebody already signed in
 *
 * Shown who they are signed in as and no button: switching accounts under
 * somebody is the one surprise worse than an extra step. They sign out and
 * open the link again. If the signed-in account is the invited address, the
 * invitation already connected them, so they go to My people.
 */
class InviteAcceptController extends Controller
{
    public function show(Request $request, InviteAcceptance $acceptance, string $market, string $token): Response|RedirectResponse
    {
        $invite = $acceptance->acceptable($token);
        $user = $request->user();

        if ($user !== null) {
            $address = $acceptance->addressFor($token);

            if ($address !== null && mb_strtolower((string) $user->email) === $address) {
                return redirect()->to(app(CurrentMarket::class)->url('people'));
            }

            return $this->page($invite?->inviter?->displayName(), signedInAs: (string) $user->email);
        }

        if ($invite === null) {
            return $this->toSignIn($acceptance, $token);
        }

        return $this->page($invite->inviter?->displayName(), signedInAs: null, token: $token);
    }

    public function store(Request $request, InviteAcceptance $acceptance, string $market, string $token): RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->to(app(CurrentMarket::class)->url("invites/accept/{$token}"));
        }

        if ($acceptance->accept($request, $token, $market) === null) {
            return $this->toSignIn($acceptance, $token);
        }

        // My people, where the connection the invitation made is the first
        // thing on the page. A magic link lands on the lists, which for a new
        // account are empty and say nothing about why they came.
        return redirect()->to(app(CurrentMarket::class)->url('people'))
            ->with('success', __('site.invite_accept.welcome'));
    }

    private function page(?string $inviterName, ?string $signedInAs, ?string $token = null): Response
    {
        app(PageMeta::class)->set(
            title: __('site.invite_accept.title_plain'),
            robots: 'noindex, nofollow',
        );

        return Inertia::render('Invites/Accept', [
            'inviterName' => $inviterName,
            'signedInAs' => $signedInAs,
            'acceptUrl' => $token === null ? null : app(CurrentMarket::class)->url("invites/accept/{$token}"),
            // For the plain form: it must work before the page's script loads.
            'csrfToken' => csrf_token(),
        ]);
    }

    /**
     * The sign-in page with the address filled in, and a neutral sentence.
     * The same whether the button was used, expired or meets an existing
     * account, so the page does not describe the account.
     */
    private function toSignIn(InviteAcceptance $acceptance, string $token): RedirectResponse
    {
        $address = $acceptance->addressFor($token);
        $query = $address === null ? '' : '?'.http_build_query(['email' => $address]);

        return redirect()->to(app(CurrentMarket::class)->url('login').$query)
            ->with('status', __('site.invite_accept.sign_in_instead'));
    }
}
