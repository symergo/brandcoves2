<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackAnonymousIdentity;
use App\Models\AnonymousIdentity;
use App\Models\User;
use App\Services\Auth\IdentityMerger;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Google sign-in.
 *
 * Offered alongside the magic link rather than instead of it: an email
 * round-trip is slow, and for someone already signed into Google this is one
 * tap. Both paths land on the same account, keyed on the email address.
 */
class GoogleController extends Controller
{
    public function redirect(CurrentMarket $current): RedirectResponse
    {
        abort_unless($this->configured(), 404);

        // The market is carried through the round-trip so the visitor comes
        // back to the catalogue they were browsing rather than the default one.
        session(['auth.market' => $current->value()]);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, IdentityMerger $merger): RedirectResponse
    {
        abort_unless($this->configured(), 404);

        $market = session('auth.market', $request->route('market'));
        $loginPath = "/{$market}/login";

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            // Covers a cancelled consent screen as well as a genuine failure.
            // Neither is worth an error page.
            report($e);

            return redirect($loginPath)->withErrors(['email' => __('site.auth.link_invalid')]);
        }

        $email = mb_strtolower(trim((string) $googleUser->getEmail()));

        if ($email === '') {
            return redirect($loginPath)->withErrors(['email' => __('site.auth.link_invalid')]);
        }

        // Matched on email, case-insensitively, so signing in with Google and
        // then with a magic link lands on one account rather than two with half
        // a gift list each.
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        $user ??= User::create(['email' => $email, 'name' => $googleUser->getName()]);

        $user->forceFill([
            // Google has already verified the address.
            'email_verified_at' => $user->email_verified_at ?? now(),
            'avatar_url' => $googleUser->getAvatar(),
        ])->save();

        // Before login, while the anonymous cookie is still resolvable.
        $anonId = $request->cookie(TrackAnonymousIdentity::COOKIE);
        if (is_string($anonId) && ($anon = AnonymousIdentity::find($anonId)) !== null) {
            $merger->merge($anon, $user);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended("/{$market}/lists");
    }

    /**
     * Hidden entirely when unconfigured.
     *
     * A "Continue with Google" button that leads to a Socialite exception is
     * worse than no button, and staging may legitimately run without OAuth
     * credentials.
     */
    private function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }
}
