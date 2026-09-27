<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use App\Services\Gift\GiftFeedback;
use App\Services\Social\Friends;
use App\Support\Owner;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'preferred_market', 'email_opt_in', 'avatar_url', 'birthday', 'friends_see_birthday'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    protected static function booted(): void
    {
        /*
         * Their thumbs on Find a gift's ideas go with the account. The thumbs
         * for their saved people go by cascade with the people; the crowd
         * votes hold only a one-way code, which the database cannot join to
         * the account, so they are found by computing that code here.
         */
        static::deleting(function (self $user): void {
            GiftFeedback::forgetVoter(new Owner($user, null));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferred_market' => Market::class,
            'is_admin' => 'boolean',
            'email_opt_in' => 'boolean',
            // A date, never a datetime: a birthday has no time of day, and
            // casting it as one makes it move across timezones.
            'birthday' => 'date',
            'friends_see_birthday' => 'boolean',
            'reminder_emails_off_at' => 'datetime',
            // Ask others and your people; null is on. See QuestionToPeople.
            'ask_people_off_at' => 'datetime',
            'people_questions_off_at' => 'datetime',
            'people_question_emails_off_at' => 'datetime',
        ];
    }

    /** @return HasMany<Wishlist, $this> */
    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class, 'owner_user_id');
    }

    /**
     * The Coves this person saved into My Coves: bookmarks, not copies.
     *
     * @return HasMany<SavedCove, $this>
     */
    public function savedCoves(): HasMany
    {
        return $this->hasMany(SavedCove::class);
    }

    /** @return HasMany<Recipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class, 'owner_user_id');
    }

    /** @return HasMany<Notification, $this> */
    public function inbox(): HasMany
    {
        return $this->hasMany(Notification::class)->latest();
    }

    /**
     * The people this person shares lists with.
     *
     * One direction of a symmetric pair; {@see Friends}
     * is the only thing that writes both. Not a permission — see the migration.
     *
     * @return HasMany<Friendship, $this>
     */
    public function friendships(): HasMany
    {
        return $this->hasMany(Friendship::class);
    }

    /**
     * "This is spam" presses under this member's invitation emails. Read by
     * the admin screen of the same name; see App\Services\Social\InviteMailer.
     *
     * @return HasMany<InviteComplaint, $this>
     */
    public function inviteComplaints(): HasMany
    {
        return $this->hasMany(InviteComplaint::class, 'inviter_id');
    }

    /**
     * The ideas on the contribute page this person voted for. Deleted with
     * the account (cascade); see docs/features/contribute.md.
     *
     * @return HasMany<FeatureVote, $this>
     */
    public function featureVotes(): HasMany
    {
        return $this->hasMany(FeatureVote::class);
    }

    /**
     * Filament admin access.
     *
     * Deliberately a database flag with no self-service path: the admin panel
     * exposes connector credentials, AI spend and the whole catalogue, so
     * granting access is a manual act.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin === true;
    }

    /**
     * The name Filament shows in the user menu and the default avatar.
     *
     * `users.name` is nullable — this site signs people in by magic link, and a
     * shopper never gives a name. Filament, however, declares
     * `getUserName(): string` and blew up with a TypeError on every panel page
     * for an admin who had none. A 500 immediately after a successful login,
     * which reads as "login is broken" rather than "this row lacks a name".
     *
     * The email's local part is a better fallback than a placeholder: it is
     * recognisably *them*, and the alternative is every nameless admin
     * appearing as the same word.
     */
    public function getFilamentName(): string
    {
        if (filled($this->name)) {
            return (string) $this->name;
        }

        return Str::of((string) $this->email)->before('@')->whenEmpty(fn () => Str::of('Admin'))->toString();
    }

    /**
     * Identity used for wishlist claims — hashed, never stored in the clear.
     * Keyed on the immutable id rather than the email, which can change.
     */
    /**
     * Something to call this person on screen.
     *
     * `name` is nullable: the magic-link flow only ever needed an address, so
     * most accounts have none — and every surface that showed a name fell back
     * to whatever was nearest, which is how a shared wishlist ended up telling
     * visitors it belonged to "Saved items".
     *
     * The fallback is the local part of the address, **not** the address. A
     * share link travels through group chats and lands with strangers; printing
     * somebody's full email on it hands their address to everyone who ever sees
     * the page, which is a leak they never agreed to and cannot undo. "ann" is
     * a name; "ann@gmail.com" is a target for spam.
     */
    public function displayName(): string
    {
        if (filled($this->name)) {
            return $this->name;
        }

        return (string) str($this->email)->before('@');
    }

    public function claimIdentity(): string
    {
        return 'user:'.$this->getKey();
    }
}
