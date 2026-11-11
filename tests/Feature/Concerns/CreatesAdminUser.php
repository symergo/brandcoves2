<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Models\User;

/**
 * An admin who signs in with a password, the way /admin is reached.
 * `is_admin` is set with forceFill because it is not mass assignable.
 */
trait CreatesAdminUser
{
    protected function admin(string $email = 'admin@example.test'): User
    {
        $user = User::create(['name' => 'Admin', 'email' => $email, 'password' => 'password-for-testing']);
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }
}
