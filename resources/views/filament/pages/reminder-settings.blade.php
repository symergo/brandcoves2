<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">Save</x-filament::button>
        </div>
    </form>

    {{--
      What the current windows mean, spelled out.

      "30, 15, 2" in a text field is a setting; "a reminder 30 days before, then
      15, then 2" is the thing it does. One line, and it removes the arithmetic
      an administrator would otherwise do in their head to check they typed what
      they meant.
    --}}
    <x-filament::section :heading="'The windows'">
        <div class="flex flex-wrap gap-2">
            @forelse ($this->windows() as $days)
                <x-filament::badge>{{ $days }} days before</x-filament::badge>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    None. No occasion reminders are being sent.
                </p>
            @endforelse
        </div>

        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
            Each window fires once per date per year, for three things that carry
            one: a saved birthday, a Secret Friend exchange, and the occasion on
            a list. A reminder that repeats gets muted, and a muted channel is
            silent on the day that matters — so the job dedupes against what it
            has already written rather than trusting the schedule.
        </p>
    </x-filament::section>

    {{--
      Evidence, next to the setting that produced it.

      A settings screen with no numbers on it is one you change and then go
      somewhere else to find out whether it did anything.
    --}}
    <x-filament::section :heading="'Sent in the last week'">
        @php($rows = $this->recent())

        @if (count($rows) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Nothing yet. Either no date falls inside a window, or the
                scheduler has not run since one did — it fires daily at 08:10.
            </p>
        @else
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach ($rows as $row)
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            {{ match ($row['kind']) {
                                'occasion.birthday' => 'Birthdays',
                                'occasion.exchange' => 'Secret Friend',
                                'occasion.list' => 'List occasions',
                                default => $row['kind'],
                            } }}
                        </div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $row['sent'] }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
