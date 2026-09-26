<x-filament-panels::page>
    @php($candidate = $this->current())
    @php($precision = $this->precision())

    {{--
      Each rule's record. Decided = merged + not the same; precision = merged
      out of decided. This is the number that would let a rule merge without a
      person one day, so it sits above the queue where it cannot be missed.
    --}}
    <x-filament::section>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <table class="text-left text-sm">
                <thead class="text-xs text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-1 pr-6">Rule</th>
                        <th class="py-1 pr-6">Decided</th>
                        <th class="py-1 pr-6">The same</th>
                        <th class="py-1 pr-6">Precision</th>
                        <th class="py-1 pr-6">Waiting</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($precision as $rule => $row)
                        <tr>
                            <td class="py-1 pr-6">{{ App\Enums\MatchRule::from($rule)->label() }}</td>
                            <td class="py-1 pr-6">{{ $row['decided'] }}</td>
                            <td class="py-1 pr-6">{{ $row['merged'] }}</td>
                            <td class="py-1 pr-6">{{ $row['precision'] === null ? '-' : number_format($row['precision'] * 100, 1).' %' }}</td>
                            <td class="py-1 pr-6">{{ $row['pending'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="flex gap-3 text-sm">
                <select wire:model.live="market" class="rounded-md border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <option value="">All markets</option>
                    @foreach ($this->marketOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="rule" class="rounded-md border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <option value="">All rules</option>
                    @foreach ($this->ruleOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </x-filament::section>

    @if ($this->last)
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $this->last }}</p>
    @endif

    @if ($candidate === null)
        <x-filament::section>
            <p class="text-sm">Nothing waiting{{ $this->market || $this->rule ? ' for this filter' : '' }}. New pairs are proposed after each grouping run, twice a day.</p>
        </x-filament::section>
    @else
        @php($keeper = $this->keeper($candidate))

        {{--
          Shortcuts, ignored while typing in a field. M merges, N keeps them
          apart, S skips, K swaps which one is kept.
        --}}
        <div
            x-data
            x-on:keydown.window="
                if (['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) || $event.metaKey || $event.ctrlKey || $event.altKey) return;
                if ($event.key === 'm') $wire.merge();
                if ($event.key === 'n') $wire.reject();
                if ($event.key === 's') $wire.skip();
                if ($event.key === 'k') $wire.swap();
            "
            class="space-y-4"
        >
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <x-filament::badge>{{ $candidate->rule->label() }}</x-filament::badge>
                @if ($candidate->evidence)
                    <code class="text-xs">{{ $candidate->evidence }}</code>
                @endif
                <span class="text-gray-500 dark:text-gray-400">titles {{ number_format($candidate->score * 100) }} % alike · {{ $candidate->market->value }} · {{ $this->remaining() }} waiting</span>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                @foreach ([$candidate->groupA, $candidate->groupB] as $side)
                    <x-filament::section>
                        <div class="space-y-3 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <a class="font-medium underline" target="_blank" href="{{ App\Filament\Resources\ProductGroups\ProductGroupResource::getUrl('manage', ['record' => $side]) }}">#{{ $side->id }}</a>
                                @if ($keeper->id === $side->id)
                                    <x-filament::badge color="success">kept on merge</x-filament::badge>
                                @endif
                            </div>

                            @if ($side->image_url)
                                <img src="{{ $side->image_url }}" alt="" class="h-40 w-full rounded-lg bg-white object-contain">
                            @endif

                            <p class="text-base font-medium">{{ $side->title }}</p>
                            <p class="text-gray-500 dark:text-gray-400">
                                {{ $side->brand ?? 'no brand' }} · {{ $side->identity_kind->value === 'ean' ? 'barcode '.$side->identity_key : 'grouped by title' }}
                            </p>
                            <p>
                                {{ $side->offer_count }} offers, {{ $side->merchant_count }} shops
                                @if ($side->min_price !== null)
                                    · {{ number_format($side->min_price / 100, 2) }}@if ($side->max_price && $side->max_price !== $side->min_price) to {{ number_format($side->max_price / 100, 2) }}@endif EUR
                                @endif
                            </p>

                            <ul class="space-y-1 border-t border-gray-200 pt-2 text-xs dark:border-white/10">
                                @foreach ($this->offersOf($side) as $offer)
                                    <li>
                                        <span class="font-medium">{{ $offer->merchant?->name ?? $offer->source->value }}</span>
                                        {{ $offer->price === null ? '-' : number_format($offer->price / 100, 2) }}
                                        <span class="text-gray-500 dark:text-gray-400">{{ $offer->title }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </x-filament::section>
                @endforeach
            </div>

            <div class="flex flex-wrap gap-3">
                <x-filament::button wire:click="merge" color="success" icon="heroicon-o-check">The same (M)</x-filament::button>
                <x-filament::button wire:click="reject" color="danger" icon="heroicon-o-x-mark">Not the same (N)</x-filament::button>
                <x-filament::button wire:click="skip" color="gray">Skip (S)</x-filament::button>
                <x-filament::button wire:click="swap" color="gray" icon="heroicon-o-arrows-right-left">Keep the other one (K)</x-filament::button>
            </div>
        </div>
    @endif
</x-filament-panels::page>
