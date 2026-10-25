<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\InviteOutcome;
use RuntimeException;

/**
 * An invitation FriendInvites refused (your own address, today's limit),
 * thrown inside a transaction so whatever was saved with it rolls back. See
 * RecipientController::store(), "Toevoegen en uitnodigen".
 */
final class InviteRefused extends RuntimeException
{
    public function __construct(public readonly InviteOutcome $outcome)
    {
        parent::__construct('Invitation refused: '.$outcome->name);
    }
}
