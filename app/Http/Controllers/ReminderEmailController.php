<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Reminder emails on or off, per person.
 *
 * Two ways to the same switch (`users.reminder_emails_off_at`):
 *
 * - **the link in every reminder email**, signed, so it works without being
 *   signed in and without anybody being able to turn somebody else's off. A
 *   GET as well as a POST: a mail client cannot POST from a footer link, and
 *   the POST is RFC 8058 one-click for clients that offer an unsubscribe
 *   button. The same reasoning as the Cove digest's unsubscribe;
 * - **the switch on the notifications page**, for turning them back on.
 *
 * Off stops the emails only. The reminder is still written to the inbox, so
 * nothing is lost, and the switch is one click to undo.
 * See docs/features/occasion-reminders.md.
 */
class ReminderEmailController extends Controller
{
    /** The signed link from an email. Turning off only; it never turns anything on. */
    public function stop(Request $request, CurrentMarket $current, string $market, string $user): RedirectResponse
    {
        $account = User::query()->find((int) $user);

        if ($account !== null && $account->reminder_emails_off_at === null) {
            $account->forceFill(['reminder_emails_off_at' => now()])->save();
        }

        return redirect()
            ->to($current->url($request->user()?->is($account) ? 'notifications' : ''))
            ->with('status', __('site.reminders.stopped'));
    }

    /** The switch on the notifications page. */
    public function update(Request $request, CurrentMarket $current): RedirectResponse
    {
        $validated = $request->validate(['on' => ['required', 'boolean']]);

        $request->user()->forceFill([
            'reminder_emails_off_at' => $validated['on'] ? null : now(),
        ])->save();

        return back();
    }
}
