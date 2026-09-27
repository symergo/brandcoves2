<x-filament-panels::page>
    @php($counts = $this->counts())
    @php($prompt = $this->prompt())

    <x-filament::section>
        <div class="flex flex-wrap items-end gap-4">
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium">Queue</span>
                <select wire:model.live="source" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900">
                    <option value="lists">Saved to a wish list</option>
                    <option value="new">New in the catalogue</option>
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium">Market</span>
                <select wire:model.live="market" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900">
                    <option value="all">All markets</option>
                    @foreach (array_keys($counts) as $value)
                        <option value="{{ $value }}">{{ $value }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="font-medium">Added in the last</span>
                <select wire:model.live="days" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900">
                    @foreach (\App\Filament\Pages\ProductTagging::DAY_OPTIONS as $d)
                        <option value="{{ $d }}">{{ $d }} {{ $d === 1 ? 'day' : 'days' }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </x-filament::section>

    <x-filament::section heading="Waiting for tags">
        {{--
          Both queues side by side whatever is selected, so it is plain which
          one has work before choosing.
        --}}
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 dark:text-gray-400">
                    <th class="py-1 pr-4 font-medium">Market</th>
                    <th class="py-1 pr-4 font-medium">Saved to a wish list</th>
                    <th class="py-1 font-medium">New in the catalogue</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($counts as $value => $row)
                    <tr class="border-t border-gray-200 dark:border-gray-800">
                        <td class="py-1 pr-4 font-medium">{{ $value }}</td>
                        <td class="py-1 pr-4">{{ number_format($row['lists']) }}</td>
                        <td class="py-1">{{ number_format($row['new']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Prompt for Claude">
        @if ($prompt === null)
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing waiting in this queue. Choose another queue, market or period.</p>
        @else
            <div x-data="{ copied: false }" class="flex flex-col gap-2">
                <textarea x-ref="prompt" readonly rows="10" class="w-full rounded-md border border-gray-300 p-2 font-mono text-xs dark:border-gray-700 dark:bg-gray-900">{{ $prompt }}</textarea>
                <div class="flex items-center gap-3">
                    <x-filament::button
                        size="sm"
                        icon="heroicon-o-clipboard"
                        x-on:click="navigator.clipboard.writeText($refs.prompt.value); copied = true; setTimeout(() => copied = false, 2000)"
                    >Copy prompt</x-filament::button>
                    <span x-show="copied" x-cloak class="text-sm text-success-600">Copied</span>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Paste it into Claude Code, opened in the GiftCoves repository. It needs the publish key in
                    <code>.claude/giftcoves_api.api</code>. Nothing runs on the server: the tags arrive over the editorial API.
                </p>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
