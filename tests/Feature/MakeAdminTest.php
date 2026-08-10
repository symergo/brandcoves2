<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The command that mints administrators.
 *
 * It had no test at all, and a missing `use Illuminate\Support\Str` sat in it
 * until someone tried to create an admin who did not already exist. Promoting
 * an existing user short-circuits the `??` and never evaluates `Str`, so every
 * path anyone had exercised worked — the one that runs on a fresh environment
 * fatally errored.
 *
 * That is the shape worth remembering: this is the first command run against a
 * new deployment, on a database with no users in it, by someone who has just
 * finished a cutover and has no other way into the panel.
 */
class MakeAdminTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_an_admin_who_does_not_exist_yet(): void
    {
        // The regression. A brand new admin has no name, so the fallback runs.
        $this->artisan('bc:make-admin', ['email' => 'new@example.test', '--password' => 'longenough'])
            ->assertSuccessful();

        $user = User::query()->where('email', 'new@example.test')->firstOrFail();

        $this->assertTrue($user->is_admin);
        $this->assertNotNull($user->email_verified_at, 'an admin created by hand has proven nothing by email, but must not be locked out by verification');
        $this->assertSame('New', $user->name, 'the name falls back to the headline of the email local part');
        $this->assertTrue(Hash::check('longenough', (string) $user->password));
    }

    #[Test]
    public function it_promotes_an_existing_user_without_renaming_them(): void
    {
        $user = User::factory()->create(['email' => 'known@example.test', 'name' => 'Margot Messaoudi']);

        $this->artisan('bc:make-admin', ['email' => 'known@example.test', '--password' => 'longenough'])
            ->assertSuccessful();

        $user->refresh();

        $this->assertTrue($user->is_admin);
        // A name already set is theirs, not the command's.
        $this->assertSame('Margot Messaoudi', $user->name);
    }

    #[Test]
    public function it_matches_an_existing_address_case_insensitively(): void
    {
        // users has a unique index on lower(email); matching any other way
        // would build a duplicate the database then rejects.
        User::factory()->create(['email' => 'mixed@example.test', 'name' => 'Vic Evrard']);

        $this->artisan('bc:make-admin', ['email' => 'MIXED@example.test', '--password' => 'longenough'])
            ->assertSuccessful();

        $this->assertSame(1, User::query()->whereRaw('lower(email) = ?', ['mixed@example.test'])->count());
    }

    #[Test]
    public function it_demotes(): void
    {
        $user = User::factory()->create(['email' => 'demote@example.test']);
        $user->forceFill(['is_admin' => true])->save();

        $this->artisan('bc:make-admin', ['email' => 'demote@example.test', '--demote' => true])
            ->assertSuccessful();

        $this->assertFalse($user->refresh()->is_admin);
    }

    #[Test]
    public function it_refuses_a_short_password_and_an_invalid_address(): void
    {
        $this->artisan('bc:make-admin', ['email' => 'short@example.test', '--password' => 'nope'])
            ->assertFailed();

        $this->artisan('bc:make-admin', ['email' => 'not-an-email', '--password' => 'longenough'])
            ->assertFailed();

        $this->assertSame(0, User::query()->where('is_admin', true)->count());
    }

    #[Test]
    public function it_will_not_invent_a_password(): void
    {
        /*
         * Non-interactive and no BC_ADMIN_PASSWORD: the command must fail
         * rather than generate or default one. An admin account that exists
         * with a password nobody chose is worse than no admin account, because
         * nothing tells you it is there.
         */
        $this->artisan('bc:make-admin', ['email' => 'nopass@example.test', '--no-interaction' => true])
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'nopass@example.test']);
    }
}
