<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\CurrentMarket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ask others and your people: the three switches, and the email's stop link.
 *
 * - `ask` (the asker): "Send my questions to my people", `ask_people_off_at`;
 * - `receive` (the receiver): "Show me my people's questions",
 *   `people_questions_off_at`;
 * - `email` (the receiver): "Also by email", `people_question_emails_off_at`.
 *
 * All on by default (null is on). The switches sit on the notifications page
 * beside the reminder emails. The stop link from an email turns the email off
 * and nothing else, signed so it works without signing in and cannot turn off
 * anybody else's, exactly as `ReminderEmailController::stop()` does.
 * See docs/features/ask-others.md, "Sent to your people".
 */
class AskPeopleSettingsController extends Controller
{
    private const COLUMNS = [
        'ask' => 'ask_people_off_at',
        'receive' => 'people_questions_off_at',
        'email' => 'people_question_emails_off_at',
    ];

    /** One switch at a time, saved at once, like every switch on this site. */
    public function update(Request $request, CurrentMarket $current): RedirectResponse
    {
        $validated = $request->validate([
            'setting' => ['required', 'string', 'in:'.implode(',', array_keys(self::COLUMNS))],
            'on' => ['required', 'boolean'],
        ]);

        $request->user()->forceFill([
            self::COLUMNS[$validated['setting']] => $validated['on'] ? null : now(),
        ])->save();

        return back();
    }

    /** The signed link from an email. Turning off only; it never turns anything on. */
    public function stopEmails(Request $request, CurrentMarket $current, string $market, string $user): RedirectResponse
    {
        $account = User::query()->find((int) $user);

        if ($account !== null && $account->people_question_emails_off_at === null) {
            $account->forceFill(['people_question_emails_off_at' => now()])->save();
        }

        return redirect()
            ->to($current->url($request->user()?->is($account) ? 'notifications' : ''))
            ->with('status', __('site.ask.people.stopped'));
    }
}
