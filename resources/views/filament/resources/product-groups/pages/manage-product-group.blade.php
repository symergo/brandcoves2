<x-filament-panels::page>
    @php($group = $this->group())
    @php($offers = $this->offers())
    @php($overrides = $this->overrides())
    @php($candidates = $this->candidates())

    <x-filament::section>
        <div class="flex flex-wrap items-start gap-6">
            @if ($group->image_url)
                <img src="{{ $group->image_url }}" alt="" class="h-28 w-28 rounded-lg object-contain bg-white">
            @endif

            <div class="flex-1 space-y-2 text-sm">
                <p class="text-base font-medium">{{ $group->heading() }}</p>
                <p class="text-gray-500 dark:text-gray-400">
                    {{ $group->market->value }} · {{ $group->brand ?? 'no brand' }} · {{ $group->category ?? 'no category' }}
                </p>
                <p>
                    <x-filament::badge :color="$group->identity_kind->value === 'ean' ? 'success' : 'warning'" class="inline-flex">
                        {{ $group->identity_kind->value === 'ean' ? 'Barcode' : 'Title' }}
                    </x-filament::badge>
                    <code class="ml-2 text-xs text-gray-500 dark:text-gray-400">{{ $group->identity_key }}</code>
                </p>
                <p>
                    {{ $group->offer_count }} offers from {{ $group->merchant_count }} shops
                    @if ($group->min_price !== null)
                        · from {{ number_format($group->min_price / 100, 2) }} EUR
                    @endif
                </p>

                @if ($group->merged_into_id)
                    <p class="rounded-md bg-warning-50 px-3 py-2 text-warning-800 dark:bg-warning-500/10 dark:text-warning-400">
                        Merged into
                        <a class="underline" href="{{ App\Filament\Resources\ProductGroups\ProductGroupResource::getUrl('manage', ['record' => $group->merged_into_id]) }}">#{{ $group->merged_into_id }}</a>.
                        Its page redirects there.
                    </p>
                @endif
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Offers">
        @if ($offers->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No offers. A product whose offers all left the feeds keeps its page, out of stock.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-2 pr-4">Shop</th>
                            <th class="py-2 pr-4">Title as the shop sends it</th>
                            <th class="py-2 pr-4">Price</th>
                            <th class="py-2 pr-4">Identity</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($offers as $offer)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td class="py-2 pr-4">
                                    {{ $offer->merchant?->name ?? '-' }}
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $offer->source->value }} · {{ $offer->status->value }}</span>
                                </td>
                                <td class="py-2 pr-4">{{ $offer->title }}</td>
                                <td class="py-2 pr-4 whitespace-nowrap">
                                    {{ $offer->price === null ? '-' : number_format($offer->price / 100, 2).' '.$offer->currency }}
                                </td>
                                <td class="py-2 pr-4 text-xs">
                                    <code>{{ $offer->identity_key ?? 'none' }}</code>
                                    @if ($offer->mpn)
                                        <span class="block text-gray-500 dark:text-gray-400">part number {{ $offer->mpn }}</span>
                                    @endif
                                    @isset($overrides[$offer->id])
                                        <span class="block text-warning-600">split by hand: {{ $overrides[$offer->id] }}</span>
                                    @endisset
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    @if ($candidates->isNotEmpty())
        <x-filament::section heading="Proposed matches">
            <ul class="space-y-2 text-sm">
                @foreach ($candidates as $candidate)
                    @php($other = $candidate->group_a === $group->id ? $candidate->groupB : $candidate->groupA)
                    <li class="flex flex-wrap items-center gap-2">
                        <x-filament::badge :color="$this->statusColor($candidate->status)">{{ $candidate->status->value }}</x-filament::badge>
                        <span class="text-gray-500 dark:text-gray-400">{{ $candidate->rule->label() }}{{ $candidate->evidence ? ' '.$candidate->evidence : '' }}</span>
                        @if ($other)
                            <a class="underline" href="{{ App\Filament\Resources\ProductGroups\ProductGroupResource::getUrl('manage', ['record' => $other]) }}">#{{ $other->id }} {{ $other->title }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
