<x-filament-panels::page>
    <div class="space-y-6">

        <x-filament::section>
            <x-slot name="heading">The market</x-slot>
            <x-slot name="description">
                One grid at a time. The markets differ in what they stock and in what has been
                planned, so automating them together would be a decision nobody made about four
                of them.
            </x-slot>

            <select
                wire:model.live="market"
                class="block w-full max-w-xs rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
            >
                @foreach ($this->markets() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">What runs on its own</x-slot>
            <x-slot name="description">
                Kinds down, stages across. Everything but <strong>approve</strong> only prepares
                work: a page still needs somebody to approve it before it can publish.
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4 text-left font-medium text-gray-500 dark:text-gray-400">Kind</th>
                            @foreach ($this->stages() as $stage)
                                <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">
                                    {{ $stage }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->kinds() as $kind)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-medium">{{ $kind->label() }}</td>

                                @foreach ($this->stages() as $stage)
                                    <td class="px-3 py-2">
                                        @if (! $this->applies($stage, $kind))
                                            {{--
                                              A disabled cell is the domain saying so, not a missing
                                              feature — so it carries the reason rather than being
                                              blank, which would read as an oversight.
                                            --}}
                                            <span
                                                class="cursor-help text-xs text-gray-400 dark:text-gray-600"
                                                title="{{ $this->whyNot($stage, $kind) }}"
                                            >—</span>
                                        @else
                                            @php($value = $grid[$kind->value][$stage] ?? '0')
                                            @php($on = $stage === 'write' ? $value !== 'off' : $value === '1')

                                            <button
                                                type="button"
                                                wire:click="toggle('{{ $stage }}', '{{ $kind->value }}')"
                                                @class([
                                                    'rounded-md px-2 py-1 text-xs font-medium transition',
                                                    // Approve is the only one that removes a person,
                                                    // so it is the only one coloured as a warning.
                                                    'bg-warning-100 text-warning-800 dark:bg-warning-500/20 dark:text-warning-300'
                                                        => $on && $stage === 'approve',
                                                    'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-300'
                                                        => $on && $stage !== 'approve',
                                                    'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400'
                                                        => ! $on,
                                                ])
                                            >{{ $stage === 'write' ? $value : ($on ? 'on' : 'off') }}</button>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                <strong>write</strong> cycles: <em>off</em>, <em>builder</em> (the model writes it
                here, under the daily AI cap), <em>external</em> (marked for an outside author to
                collect from the writing queue, costing nothing on this server).
            </p>
        </x-filament::section>

        @if ($this->publishing() !== [])
            {{--
              Said where somebody will see it without opening this page.

              A setting that reaches readers and is visible only on the screen
              that sets it is a setting somebody forgets is on.
            --}}
            <x-filament::section>
                <x-slot name="heading">Publishing with nobody reading it</x-slot>

                <ul class="space-y-1 text-sm">
                    @foreach ($this->publishing() as $market => $kinds)
                        <li>
                            <span class="font-medium">{{ $market }}</span>
                            — {{ implode(', ', $kinds) }}
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
