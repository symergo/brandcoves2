<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The accounts screen in the admin panel.
 *
 * Granting panel access is the one thing this screen can do that nothing else
 * in the app may, so most of this file is about the guards around that: the
 * flag actually lands (it is guarded against mass assignment, and a save that
 * silently drops it would look like success), a promoted admin gets the
 * password they need to sign in, and nobody can lock themselves out.
 */
class UserAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::create(['name' => 'Root', 'email' => 'root@example.test', 'password' => 'password-for-testing']);
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    /** A shopper: signed in by magic link, so no name and no password. */
    private function shopper(string $email = 'ann@example.test'): User
    {
        return User::create(['email' => $email]);
    }

    #[Test]
    public function the_accounts_page_renders_with_both_kinds_of_row(): void
    {
        $admin = $this->admin();
        $this->shopper();

        // Seeded with an admin and a nameless shopper, so every column closure
        // runs against a row of each shape — an empty table renders nothing.
        $this->actingAs($admin)->get('/admin/users')->assertOk();

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->assertCanSeeTableRecords(User::all())
            ->assertSee('ann@example.test');
    }

    #[Test]
    public function a_non_admin_cannot_reach_it(): void
    {
        $this->actingAs($this->shopper())->get('/admin/users')->assertForbidden();
    }

    #[Test]
    public function accounts_are_not_created_by_hand(): void
    {
        // An account is a proven address. There is no create page.
        $this->assertFalse(UserResource::canCreate());
    }

    #[Test]
    public function promoting_a_shopper_grants_the_flag_and_sets_a_panel_password(): void
    {
        $shopper = $this->shopper();

        Livewire::actingAs($this->admin())
            ->test(EditUser::class, ['record' => $shopper->getRouteKey()])
            ->fillForm(['is_admin' => true, 'password' => 'correct-horse-battery'])
            ->call('save')
            ->assertHasNoFormErrors();

        $shopper->refresh();

        // The whole point of handleRecordUpdate: is_admin is guarded, and the
        // default save would have dropped it without a word.
        $this->assertTrue($shopper->is_admin);
        $this->assertTrue(Hash::check('correct-horse-battery', (string) $shopper->password));
    }

    #[Test]
    public function promoting_a_shopper_without_a_password_is_refused(): void
    {
        $shopper = $this->shopper();

        // The site signs people in without a password and the panel does
        // not. An admin flag with no password is a login that can never
        // succeed, which reads as a broken panel rather than a missing field.
        Livewire::actingAs($this->admin())
            ->test(EditUser::class, ['record' => $shopper->getRouteKey()])
            ->fillForm(['is_admin' => true, 'password' => ''])
            ->call('save')
            ->assertHasFormErrors(['password']);

        $this->assertFalse($shopper->refresh()->is_admin);
    }

    #[Test]
    public function an_empty_password_field_keeps_the_existing_one(): void
    {
        $other = $this->admin();
        $other->forceFill(['email' => 'other@example.test'])->save();
        $before = $other->password;

        $me = User::create(['name' => 'Me', 'email' => 'me@example.test', 'password' => 'password-for-testing']);
        $me->forceFill(['is_admin' => true])->save();

        Livewire::actingAs($me)
            ->test(EditUser::class, ['record' => $other->getRouteKey()])
            ->fillForm(['name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $other->refresh();
        $this->assertSame('Renamed', $other->name);
        $this->assertSame($before, $other->password, 'saving the form without typing a password must not blank the hash');
        $this->assertTrue($other->is_admin);
    }

    #[Test]
    public function an_admin_cannot_demote_themselves(): void
    {
        $me = $this->admin();

        // The toggle is disabled for your own row, and a disabled field is not
        // dehydrated — so even a crafted request leaves the flag alone.
        Livewire::actingAs($me)
            ->test(EditUser::class, ['record' => $me->getRouteKey()])
            ->assertFormFieldDisabled('is_admin')
            ->fillForm(['is_admin' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($me->refresh()->is_admin);
    }

    #[Test]
    public function an_admin_cannot_delete_themselves(): void
    {
        $me = $this->admin();

        Livewire::actingAs($me)
            ->test(EditUser::class, ['record' => $me->getRouteKey()])
            ->assertActionHidden(DeleteAction::class);

        Livewire::actingAs($me)
            ->test(ListUsers::class)
            ->assertActionHidden(TestAction::make(DeleteAction::class)->table($me));
    }

    #[Test]
    public function the_email_is_stored_lowercased_and_must_be_unique_regardless_of_case(): void
    {
        $this->shopper('taken@example.test');
        $target = $this->shopper('ann@example.test');
        $admin = $this->admin();

        // The unique index is on lower(email). A rule that compared exactly
        // would pass here and the save would 500 on the index instead.
        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['email' => 'Taken@Example.test'])
            ->call('save')
            ->assertHasFormErrors(['email']);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['email' => 'Ann.New@Example.test'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('ann.new@example.test', $target->refresh()->email);
    }

    #[Test]
    public function deleting_an_account_takes_its_lists_and_recipients_with_it(): void
    {
        $shopper = $this->shopper();
        $list = Wishlist::factory()->create(['owner_user_id' => $shopper->id]);
        $recipient = Recipient::factory()->create(['owner_user_id' => $shopper->id]);

        Livewire::actingAs($this->admin())
            ->test(ListUsers::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($shopper));

        $this->assertDatabaseMissing('users', ['id' => $shopper->id]);
        $this->assertDatabaseMissing('wishlists', ['id' => $list->id]);
        $this->assertDatabaseMissing('recipients', ['id' => $recipient->id]);
    }
}
