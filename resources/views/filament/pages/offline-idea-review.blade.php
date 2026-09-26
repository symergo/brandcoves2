<x-filament-panels::page>
    @php($idea = $this->current())

    <x-filament::section>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <p class="max-w-2xl text-sm text-gray-500 dark:text-gray-400">
                Things at least {{ config('giftcoves.offline_ideas.min_owners') }} different people typed onto their own lists by hand.
                Nothing here is shown to anybody until you approve it. Write the wording as a general idea
                (no names, no dates, no places that point at one person), tag who and what it suits, and approve.
                Reject anything personal or not a gift.
            </p>
            <select wire:model.live="market" class="rounded-md border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                <option value="">All markets</option>
                @foreach ($this->marketOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </x-filament::section>

    @if ($this->last)
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $this->last }}</p>
    @endif

    @if ($idea === null)
        <x-filament::section>
            <p class="text-sm">Nothing waiting{{ $this->market ? ' for this market' : '' }}. New ideas are counted every night at 04:00.</p>
        </x-filament::section>
    @else
        {{-- A approves, R rejects, S skips; ignored while typing in a field. --}}
        <div
            x-data
            x-on:keydown.window="
                if (['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) || $event.metaKey || $event.ctrlKey || $event.altKey) return;
                if ($event.key === 'a') $wire.approve();
                if ($event.key === 'r') $wire.reject();
                if ($event.key === 's') $wire.skip();
            "
            class="space-y-4"
        >
            <x-filament::section>
                <div class="space-y-4 text-sm">
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::badge>{{ $idea->market->value }}</x-filament::badge>
                        @if ($this->editing)
                            <x-filament::badge color="success">approved, editing</x-filament::badge>
                        @else
                            <span class="text-gray-500 dark:text-gray-400">{{ $idea->owners }} people · {{ $this->remaining() }} waiting</span>
                        @endif
                    </div>

                    @if ($idea->sample_title)
                        <p>Most people wrote: <span class="font-medium">"{{ $idea->sample_title }}"</span></p>
                    @endif

                    <label class="block space-y-1">
                        <span class="font-medium">Wording visitors see</span>
                        <input type="text" wire:model="wording" maxlength="120" class="w-full rounded-md border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    </label>

                    <label class="block space-y-1">
                        <span class="font-medium">Rough price</span>
                        <select wire:model="priceBand" class="rounded-md border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                            <option value="">Not set</option>
                            @foreach ($this->priceOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    @foreach ($this->tagOptions() as $group => $options)
                        <fieldset class="space-y-1">
                            <legend class="font-medium">{{ $group }}</legend>
                            <div class="flex flex-wrap gap-x-4 gap-y-1">
                                @foreach ($options as $value => $label)
                                    <label class="inline-flex items-center gap-1">
                                        <input type="checkbox" wire:model="tags" value="{{ $value }}">
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                </div>
            </x-filament::section>

            <div class="flex flex-wrap gap-3">
                <x-filament::button wire:click="approve" color="success" icon="heroicon-o-check">{{ $this->editing ? 'Save' : 'Approve (A)' }}</x-filament::button>
                <x-filament::button wire:click="reject" color="danger" icon="heroicon-o-x-mark">{{ $this->editing ? 'Withdraw' : 'Reject (R)' }}</x-filament::button>
                <x-filament::button wire:click="skip" color="gray">{{ $this->editing ? 'Cancel' : 'Skip (S)' }}</x-filament::button>
            </div>
        </div>
    @endif

    @php($approved = $this->approved())
    @if ($approved->isNotEmpty())
        <x-filament::section heading="Approved">
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-1 pr-4">Market</th>
                        <th class="py-1 pr-4">Wording</th>
                        <th class="py-1 pr-4">Tags</th>
                        <th class="py-1 pr-4">Price</th>
                        <th class="py-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($approved as $row)
                        <tr>
                            <td class="py-1 pr-4">{{ $row->market->value }}</td>
                            <td class="py-1 pr-4">{{ $row->title }}</td>
                            <td class="py-1 pr-4 text-xs">{{ implode(', ', (array) $row->tags) }}</td>
                            <td class="py-1 pr-4">{{ $row->price_band?->label() ?? '-' }}</td>
                            <td class="py-1"><button type="button" wire:click="edit({{ $row->id }})" class="underline">Edit</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
