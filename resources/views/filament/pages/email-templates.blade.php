<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap gap-3">
            <x-filament::button type="submit">Save</x-filament::button>

            {{--
              Not a delete. It puts the shipped wording back in the fields so it
              can be read, compared and saved over — the override row and its
              history stay where they are until somebody deliberately saves.
            --}}
            <x-filament::button type="button" color="gray" wire:click="useShipped">
                Load the shipped wording
            </x-filament::button>
        </div>
    </form>

    <x-filament::section :heading="'Placeholders'">
        @php($placeholders = $this->placeholders())

        @if (count($placeholders) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                This email has none — it says the same thing to everybody.
            </p>
        @else
            <div class="flex flex-wrap gap-2">
                @foreach ($placeholders as $name)
                    <x-filament::badge>:{{ $name }}</x-filament::badge>
                @endforeach
            </div>
        @endif

        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
            Written the way the language files write them, because an editor who
            has seen one has seen both. A name that is not on this list is left
            on the page exactly as typed — a visible <code>:whatever</code> is a
            bug somebody reports, where a silent gap is one nobody notices.
        </p>
    </x-filament::section>

    <x-filament::section :heading="'What this screen does not control'">
        <ul class="list-disc space-y-1 pl-5 text-sm text-gray-500 dark:text-gray-400">
            <li>
                The button, where it points, and the fallback link underneath it.
                Those come from the code that sends the email — a URL typed into
                a body is wrong the moment the market changes, and an email whose
                button went missing is one nobody can act on.
            </li>
            <li>
                Two emails are not offered here at all. The <strong>Cove
                digest</strong> is a list of products rather than a paragraph, and
                the <strong>Secret Friend assignment</strong> exists to reveal one
                name — a rewrite that failed to is a broken draw, not a wording
                choice.
            </li>
            <li>
                Which language somebody receives. That follows the market their
                list, group or subscription belongs to.
            </li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
