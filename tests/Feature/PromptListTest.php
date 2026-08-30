<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\PromptTemplates\Pages\ListPromptTemplates;
use App\Models\PromptTemplate;
use App\Models\User;
use App\Services\Ai\PromptBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The prompts list shows every prompt, not only the overridden ones.
 *
 * `prompt_templates` holds overrides and is deliberately not seeded — a stale
 * prompt produces plausible output, which is worse than an obviously missing
 * one, so a slot with no row uses what the site shipped with. Right storage,
 * bad list: reading the table straight off the model meant the normal state of
 * the screen was empty, and the only way to learn which prompts exist was to
 * read `PromptBank::slots()` in the source.
 *
 * So the registry is the data source and an override is an attribute of a row.
 */
class PromptListTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function slots($component): array
    {
        return array_values(array_map(
            fn ($record) => $record['slot'],
            $component->instance()->getTableRecords()->all(),
        ));
    }

    #[Test]
    public function every_declared_prompt_is_listed_even_with_an_empty_table(): void
    {
        $this->assertSame(0, PromptTemplate::query()->count());

        $component = Livewire::actingAs($this->admin())->test(ListPromptTemplates::class);

        $component->assertOk();

        $listed = $this->slots($component);

        $this->assertNotEmpty(PromptBank::slots());

        foreach (array_keys(PromptBank::slots()) as $slot) {
            $this->assertContains($slot, $listed, "{$slot} is declared but not listed");
        }
    }

    #[Test]
    public function a_slot_nobody_has_touched_reads_as_shipped(): void
    {
        $component = Livewire::actingAs($this->admin())->test(ListPromptTemplates::class);

        $row = collect($component->instance()->getTableRecords()->all())->firstWhere('slot', 'cove.theme');

        $this->assertSame('shipped', $row['system']);
        $this->assertSame('shipped', $row['user_template']);
        $this->assertSame('shipped', $row['state']);
        $this->assertNull($row['row']);
    }

    #[Test]
    public function an_override_shows_which_half_was_changed(): void
    {
        PromptTemplate::create([
            'slot' => 'cove.theme',
            'user_template' => 'Write about {finds} in {language}.',
            'enabled' => true,
        ]);

        $row = collect(
            Livewire::actingAs($this->admin())->test(ListPromptTemplates::class)->instance()->getTableRecords()->all()
        )->firstWhere('slot', 'cove.theme');

        // Both halves are independently overridable, so the list has to say
        // which one somebody actually changed.
        $this->assertSame('shipped', $row['system']);
        $this->assertSame('overridden', $row['user_template']);
        $this->assertSame('overridden', $row['state']);
    }

    #[Test]
    public function a_switched_off_override_is_not_the_same_as_shipped(): void
    {
        PromptTemplate::create([
            'slot' => 'cove.theme',
            'user_template' => 'Write about {finds} in {language}.',
            'enabled' => false,
        ]);

        $row = collect(
            Livewire::actingAs($this->admin())->test(ListPromptTemplates::class)->instance()->getTableRecords()->all()
        )->firstWhere('slot', 'cove.theme');

        // The words are still there; they are just not being used. A list that
        // said "shipped" would make somebody rewrite what they already wrote.
        $this->assertSame('off', $row['state']);
    }

    /**
     * A rename's casualty stays reachable.
     *
     * The row is inert — `PromptBank::override()` checks the slot against the
     * allowlist before reading it — but somebody wrote it, and hiding it would
     * leave the work unreachable with nothing to say it exists.
     */
    #[Test]
    public function a_slot_the_code_forgot_is_listed_and_marked(): void
    {
        PromptTemplate::create([
            'slot' => 'cove.something_renamed',
            'user_template' => 'Write about {finds} in {language}.',
            'enabled' => true,
        ]);

        $rows = collect(
            Livewire::actingAs($this->admin())->test(ListPromptTemplates::class)->instance()->getTableRecords()->all()
        );

        $orphan = $rows->firstWhere('slot', 'cove.something_renamed');

        $this->assertNotNull($orphan, 'an override for a retired slot vanished from the screen');
        $this->assertTrue($orphan['orphaned']);
    }

    #[Test]
    public function searching_narrows_the_list(): void
    {
        $component = Livewire::actingAs($this->admin())
            ->test(ListPromptTemplates::class)
            ->set('tableSearch', 'theme');

        $this->assertSame(['cove.theme'], $this->slots($component));
    }

    #[Test]
    public function it_writes_an_override_from_the_row(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ListPromptTemplates::class)
            ->callTableAction('edit', 'cove.theme', [
                'system' => 'Write like a person.',
                'user_template' => PromptBank::shipped('cove.theme')['user_template'],
                'notes' => 'Trying a warmer voice.',
                'enabled' => true,
            ]);

        $row = PromptTemplate::query()->where('slot', 'cove.theme')->firstOrFail();

        $this->assertSame('Write like a person.', $row->system);
        // The brief came back exactly as shipped, so it is not an override —
        // storing a copy would silently pin the slot to today's wording while
        // the shipped one is improved in code.
        $this->assertNull($row->user_template);
    }

    #[Test]
    public function it_refuses_a_brief_that_lost_its_required_block(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ListPromptTemplates::class)
            ->callTableAction('edit', 'cove.theme', [
                'system' => 'Write like a person.',
                // {finds} gone: the model would be asked to write about nothing,
                // and a model asked to write about nothing writes a plausible
                // article about products that are not on the page.
                'user_template' => 'Write something in {language}.',
                'enabled' => true,
            ])
            ->assertHasErrors();

        $this->assertSame(0, PromptTemplate::query()->count());
    }

    #[Test]
    public function resetting_deletes_the_override(): void
    {
        PromptTemplate::create([
            'slot' => 'cove.theme',
            'system' => 'Mine.',
            'enabled' => true,
        ]);

        Livewire::actingAs($this->admin())
            ->test(ListPromptTemplates::class)
            ->callTableAction('reset', 'cove.theme');

        // Deleting *is* the undo. There is no third state between "the shipped
        // prompt" and "mine".
        $this->assertSame(0, PromptTemplate::query()->count());
    }

    #[Test]
    public function editing_everything_back_to_shipped_removes_the_row(): void
    {
        PromptTemplate::create([
            'slot' => 'cove.theme',
            'system' => 'Mine.',
            'enabled' => true,
        ]);

        $shipped = PromptBank::shipped('cove.theme');

        Livewire::actingAs($this->admin())
            ->test(ListPromptTemplates::class)
            ->callTableAction('edit', 'cove.theme', [
                'system' => $shipped['system'],
                'user_template' => $shipped['user_template'],
                'notes' => null,
                'enabled' => true,
            ]);

        // An all-null row would read as "overridden" on the list while changing
        // nothing anywhere.
        $this->assertSame(0, PromptTemplate::query()->count());
    }
}
