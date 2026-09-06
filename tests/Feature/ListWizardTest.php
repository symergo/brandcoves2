<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EventType;
use App\Enums\Market;
use App\Models\Friendship;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Social\Friends;
use App\Services\Wishlist\OccasionDate;
use App\Support\DayAndMonth;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The four questions the Gift Cove asks, and the two it should not.
 *
 * The wizard exists to explain a list while making one, so every answer it
 * collects has to arrive on the list. These hold the two places that failed:
 * a friend who quietly stopped being offered, and a date field asking for
 * something the screen already knew.
 */
class ListWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed today, because every assertion here is about which side of
        // it a date falls on.
        $this->travelTo(CarbonImmutable::parse('2026-09-06')->setTime(9, 0));
    }

    private function user(string $email = 'owner@example.test'): User
    {
        return User::create(['email' => $email]);
    }

    #[Test]
    public function a_friend_who_already_has_a_profile_is_still_offered(): void
    {
        /*
         * The bug this is here for: the picker dropped friends who already had
         * a profile with me, on the reasoning that they appeared among my
         * people under their own name. So using a friend once removed them from
         * "from your friends" for good, and somebody whose only friend already
         * had a profile opened a heading with nothing under it.
         */
        $owner = $this->user();
        $friend = $this->user('friend@example.test');
        app(Friends::class)->link($owner, $friend);

        $props = fn () => $this->actingAs($owner)->get('/be-nl/gift-cove')->assertOk()
            ->viewData('page')['props'];

        $before = $props();
        $this->assertSame([$friend->id], array_column($before['friends'], 'id'));
        $this->assertNull($before['friends'][0]['recipientId']);

        // Make a list for them, which is what mints the profile.
        $this->actingAs($owner)->post('/be-nl/lists', [
            'title' => 'For my friend',
            'friend_id' => $friend->id,
        ])->assertRedirect();

        $recipient = Recipient::query()->where('user_id', $friend->id)->firstOrFail();

        $after = $props();

        // Still one entry, still under Friends, and now pointing at the profile
        // they have: picking them again cannot make a second one.
        $this->assertSame([$friend->id], array_column($after['friends'], 'id'));
        $this->assertSame($recipient->id, $after['friends'][0]['recipientId']);

        // And they are not *also* in the plain people list, which would be one
        // person offered twice.
        $this->assertNotContains($recipient->id, array_column($after['recipients'], 'id'));
    }

    #[Test]
    public function a_friends_birthday_travels_into_the_profile_made_for_them(): void
    {
        /*
         * The Friends page holds a birthday for this person, so the list made
         * for them should not arrive blank: the reminders read the profile, and
         * so does the occasion date below.
         */
        $owner = $this->user();
        $friend = $this->user('friend@example.test');
        app(Friends::class)->link($owner, $friend);

        Friendship::query()
            ->where('user_id', $owner->id)
            ->where('friend_id', $friend->id)
            ->update(['friend_birthday_day' => 4, 'friend_birthday_month' => 7]);

        $this->actingAs($owner)->post('/be-nl/lists', [
            'title' => 'For my friend',
            'friend_id' => $friend->id,
            'event_type' => 'birthday',
        ])->assertRedirect();

        $recipient = Recipient::query()->where('user_id', $friend->id)->firstOrFail();
        $this->assertSame(7, $recipient->birthday?->month);
        $this->assertSame(4, $recipient->birthday?->day);

        // 4 July has been and gone this year, so the list is for the next one.
        $list = Wishlist::query()->where('title', 'For my friend')->firstOrFail();
        $this->assertSame('2027-07-04', $list->event_date?->toDateString());
    }

    #[Test]
    public function a_birthday_typed_for_somebody_i_already_have_is_kept(): void
    {
        /*
         * `ListMaker` wrote a birthday only when it minted the person, so
         * choosing an existing one and saying when their birthday is threw the
         * answer away, and the wizard then had nothing to date the list with.
         * Filled in only when blank: this is adding what somebody knows, not
         * correcting what is there.
         */
        $owner = $this->user();
        $person = Recipient::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Ada',
        ]);

        $this->actingAs($owner)->post('/be-nl/lists', [
            'title' => 'Ada',
            'recipient_id' => $person->id,
            'birthday_day' => 12,
            'birthday_month' => 12,
            'event_type' => 'birthday',
        ])->assertRedirect();

        $this->assertSame('12-12', $person->fresh()?->birthday?->format('m-d'));
        $this->assertSame(
            '2026-12-12',
            Wishlist::query()->where('title', 'Ada')->firstOrFail()->event_date?->toDateString(),
        );
    }

    #[Test]
    public function an_occasion_that_knows_its_own_date_brings_it(): void
    {
        $owner = $this->user();

        $this->actingAs($owner)->post('/be-nl/lists', [
            'title' => 'Kerst',
            'event_type' => 'christmas',
        ])->assertRedirect();

        $this->assertSame(
            '2026-12-25',
            Wishlist::query()->where('title', 'Kerst')->firstOrFail()->event_date?->toDateString(),
        );

        // A date sent by hand always wins: the wizard only fills a blank.
        $this->actingAs($owner)->post('/be-nl/lists', [
            'title' => 'Kerst bij ons',
            'event_type' => 'christmas',
            'event_date' => '2026-12-27',
        ])->assertRedirect();

        $this->assertSame(
            '2026-12-27',
            Wishlist::query()->where('title', 'Kerst bij ons')->firstOrFail()->event_date?->toDateString(),
        );
    }

    #[Test]
    public function an_occasion_nobody_can_look_up_is_left_to_its_owner(): void
    {
        $owner = $this->user();

        $this->actingAs($owner)->post('/be-nl/lists', [
            'title' => 'Het huwelijk',
            'new_recipient' => 'Sam',
            'event_type' => 'wedding',
        ])->assertRedirect();

        $list = Wishlist::query()->where('title', 'Het huwelijk')->firstOrFail();

        // The occasion is on the list; the date is a question, not a guess.
        $this->assertSame(EventType::Wedding, $list->event_type);
        $this->assertNull($list->event_date);
    }

    #[Test]
    public function the_dates_an_occasion_can_answer_for_itself(): void
    {
        $dates = app(OccasionDate::class);
        $today = CarbonImmutable::parse('2026-09-06');

        // Fixed, the same everywhere, and always the next one.
        $this->assertSame('2026-12-25', $dates->for(EventType::Christmas, Market::BeNl, from: $today)?->toDateString());
        $this->assertSame('2027-02-14', $dates->for(EventType::Valentines, Market::Es, from: $today)?->toDateString());

        /*
         * Mother's Day and Father's Day are answered by nobody, in any market.
         *
         * They move by region, not only by country: Father's Day is the second
         * Sunday of June in Flanders and the second Sunday of March in
         * Wallonia, and Mother's Day is 15 August in Antwerp. One date per
         * market would be confidently wrong for a chunk of the people reading
         * it, on a day they care about, so the wizard asks instead.
         */
        $this->assertNull($dates->for(EventType::MothersDay, Market::BeNl, from: $today));
        $this->assertNull($dates->for(EventType::MothersDay, Market::Es, from: $today));
        $this->assertNull($dates->for(EventType::FathersDay, Market::NlNl, from: $today));
        $this->assertNull($dates->for(EventType::FathersDay, Market::En, from: $today));

        // A birthday is the person's, and today counts as the next one.
        $this->assertSame(
            '2026-09-06',
            $dates->for(EventType::Birthday, Market::BeNl, new DayAndMonth(6, 9), $today)?->toDateString(),
        );
        $this->assertSame(
            '2027-01-30',
            $dates->for(EventType::Birthday, Market::BeNl, new DayAndMonth(30, 1), $today)?->toDateString(),
        );

        // Somebody born on 29 February is reminded on the 28th in a common
        // year, rather than in March, which is a different month.
        $this->assertSame(
            '2027-02-28',
            $dates->for(EventType::Birthday, Market::BeNl, new DayAndMonth(29, 2), $today)?->toDateString(),
        );

        // And nothing invented for the occasions only their owner knows.
        $this->assertNull($dates->for(EventType::Birthday, Market::BeNl, null, $today));
        $this->assertNull($dates->for(EventType::Wedding, Market::BeNl, null, $today));
        $this->assertNull($dates->for(EventType::Baby, Market::BeNl, null, $today));
    }
}
