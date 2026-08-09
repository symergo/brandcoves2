<x-filament-panels::page>
    {{--
      A form, not a table.

      The whole point of this screen is that the copy is arranged the way the
      page reads, so the wrapper stays out of the way: sections, then one Save.
    --}}
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex items-center gap-3">
            <x-filament::button type="submit">
                Save
            </x-filament::button>

            {{-- Rotation is a setting rather than a per-line control, and an
                 editor adding a fourth variant will reasonably wonder when it
                 starts appearing. Saying so here is cheaper than a support
                 question. --}}
            <span class="text-sm text-gray-500 dark:text-gray-400">
                Live on the next page load. Each page keeps the same wording for
                {{ config('brandcoves.copy.rotation', 'weekly') === 'static' ? 'good' : 'a ' . str_replace(['weekly', 'daily', 'monthly'], ['week', 'day', 'month'], config('brandcoves.copy.rotation', 'weekly')) }},
                then draws again — so two pages rarely read the same.
            </span>
        </div>
    </form>
</x-filament-panels::page>
