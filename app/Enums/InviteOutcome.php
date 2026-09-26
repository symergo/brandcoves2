<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What inviting an address tells the member who did it.
 *
 * Every case is a fact about the member's own actions, never about the
 * address: whether it has an account, whether it asked for no invitations,
 * whether anybody complained. Those three must look like `Sent`, or the form
 * becomes a way to test addresses. See App\Services\Social\FriendInvites.
 */
enum InviteOutcome: string
{
    /** Recorded, and (as far as the member can tell) emailed. */
    case Sent = 'sent';

    /** This member invited this address within the repeat window already. */
    case AlreadyInvited = 'already_invited';

    /** This member reached the daily limit. Nothing was recorded. */
    case DailyLimit = 'daily_limit';

    /** Their own address. Nothing was recorded. */
    case OwnAddress = 'own_address';
}
