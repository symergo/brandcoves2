<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">Save</x-filament::button>
        </div>
    </form>

    {{--
      Today's spend, next to the caps that bound it.

      A number you can change and a number you cannot see are a bad pair: without
      this an administrator sets a cap of 60 with no idea whether yesterday used
      six or fifty-nine.
    --}}
    <x-filament::section :heading="'Used today'">
        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ($this->usageToday() as $row)
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ str_replace('_', ' ', $row['feature']) }}
                    </div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ $row['used'] }}<span class="text-base font-normal text-gray-400"> / {{ $row['cap'] }}</span>
                    </div>

                    @if ($row['cap'] > 0)
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                            <div
                                @class([
                                    'h-full rounded-full',
                                    'bg-primary-500' => $row['used'] < $row['cap'],
                                    'bg-danger-500' => $row['used'] >= $row['cap'],
                                ])
                                style="width: {{ min(100, (int) round($row['used'] / max(1, $row['cap']) * 100)) }}%"
                            ></div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
            A feature that reaches its cap stops calling and logs it. Nothing
            breaks — themes fall back to the curated rotation and guides to
            template copy, exactly as when generation is off entirely.
        </p>
    </x-filament::section>
</x-filament-panels::page>
