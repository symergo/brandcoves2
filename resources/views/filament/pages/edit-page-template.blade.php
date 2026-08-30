<x-filament-panels::page>
    {{--
      A form, not a table.

      The screen is arranged the way the page reads — a region, then its blocks
      in the order they appear — so the wrapper stays out of the way: the form,
      the palette, and one Save.
    --}}
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <x-filament::button type="submit">
                Save
            </x-filament::button>

            {{-- Rotation is a setting rather than a per-block control, and an
                 editor adding a third phrasing will reasonably wonder when it
                 starts appearing. Saying so here is cheaper than a support
                 question. --}}
            <span class="text-sm text-gray-500 dark:text-gray-400">
                Live on the next page load. Each page keeps the same wording for
                @php($rotation = config('giftcoves.copy.rotation', 'weekly'))
                {{ $rotation === 'static' ? 'good' : 'a ' . str_replace(['weekly', 'daily', 'monthly'], ['week', 'day', 'month'], $rotation) }},
                then draws again — so two pages rarely read the same.
            </span>
        </div>
    </form>

    {{--
      The placeholder palette.

      Rendered from the registry rather than written out here, so a placeholder
      added in code next year appears in this list on its own. That is the point
      of the registry: without this panel, "you can add placeholder functions
      later" means "and then tell the editors by email".
    --}}
    @php($palette = $this->palette())

    @if ($palette !== [])
        <x-filament::section
            collapsible
            collapsed
            heading="What you can put in a block"
            description="Type the token into a sentence. Anything naming a value this page cannot supply simply does not appear — so a sentence about discounts stays off a page with none."
        >
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-4 font-medium">Token</th>
                            <th class="py-2 pr-4 font-medium">What it is</th>
                            <th class="py-2 pr-4 font-medium">Looks like</th>
                            <th class="py-2 font-medium">Where</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($palette as $item)
                            <tr class="border-t border-gray-200 align-top dark:border-white/10">
                                <td class="whitespace-nowrap py-2 pr-4 font-mono text-primary-600 dark:text-primary-400">
                                    {{ $item['token'] }}
                                </td>
                                <td class="py-2 pr-4">
                                    <span class="font-medium">{{ $item['label'] }}</span>
                                    <span class="block text-gray-500 dark:text-gray-400">{{ $item['help'] }}</span>
                                </td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $item['sample'] }}</td>
                                <td class="whitespace-nowrap py-2 text-gray-500 dark:text-gray-400">{{ $item['level'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
