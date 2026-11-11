<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Mail\FriendInviteMail;
use Illuminate\Support\Facades\Mail;

/**
 * A link as it went out in the queued friend invitation to one address, read
 * the way its reader meets it: signed out. Needs `Mail::fake()`.
 */
trait ReadsQueuedFriendInvite
{
    /** @param 'url'|'notWantedUrl' $property  the mail's button or its "not wanted" link */
    protected function queuedInviteLink(string $email, string $property): string
    {
        $url = null;

        Mail::assertQueued(FriendInviteMail::class, function (FriendInviteMail $mail) use ($email, $property, &$url) {
            if ($mail->hasTo($email)) {
                $url = $mail->{$property};

                return true;
            }

            return false;
        });

        // Signed out from here on: the reader of an email has no session here.
        $this->app['auth']->forgetGuards();

        return (string) $url;
    }
}
