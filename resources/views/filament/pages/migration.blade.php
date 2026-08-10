<x-filament-panels::page>
    @php($build = $this->buildInfo())
    @php($failures = $this->configFailures())

    {{--
      What is running here, first. Every question on this page — should I deploy,
      is it safe to import — starts with "which build is this and did its config
      arrive", so that answer goes above the controls rather than below them.
    --}}
    <x-filament::section heading="This environment">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($build as $label => $value)
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-sm font-medium break-words">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        @php($deploy = $this->lastDeploy())

        @if ($deploy)
            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                Last deploy from this screen:
                {{ \Illuminate\Support\Carbon::parse($deploy['at'])->diffForHumans() }}
                — {{ $deploy['ok'] ? 'accepted' : 'refused' }}.
                Coolify holds the real deployment history.
            </p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Content held here" collapsible collapsed>
        {{--
          The numbers both sides need before moving anything. A production
          catalogue smaller than staging's is exactly why picks get dropped, and
          seeing the two counts side by side explains a drop list before it
          appears.
        --}}
        <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($this->contentCounts() as $label => $count)
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-sm font-medium">{{ number_format($count) }}</p>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Configuration">
        @if ($failures === [])
            <x-filament::badge color="success">
                Every setting required in this environment is present
            </x-filament::badge>
        @else
            <x-filament::badge color="danger">
                Missing and required here: {{ implode(', ', $failures) }}
            </x-filament::badge>
        @endif

        {{--
          Presence and lengths only, never values. This is an authenticated page,
          but a screenshot of it is not — and the question anyone actually has is
          "did it arrive", which a length answers.
        --}}
        <div class="mt-4 space-y-5">
            @foreach ($this->configGroups() as $heading => $rows)
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ $heading }}
                    </p>

                    <div class="mt-2 divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($rows as $row)
                            <div class="flex flex-wrap items-baseline justify-between gap-2 py-1.5">
                                <div class="min-w-0">
                                    <span class="font-mono text-xs">{{ $row['key'] }}</span>
                                    @if ($row['note'])
                                        <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">{{ $row['note'] }}</span>
                                    @endif
                                </div>

                                <x-filament::badge :color="$row['set'] ? 'success' : ($row['required'] ? 'danger' : 'gray')">
                                    {{ $row['set'] ? $row['display'] : ($row['required'] ? 'MISSING' : 'unset') }}
                                </x-filament::badge>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Awin accounts
            </p>

            {{--
              A count, not a flag. The failure this exists to catch was *fewer
              accounts than expected* rather than none: the catalogue still
              built, from one publisher instead of two, and nothing said so.
            --}}
            <div class="mt-2 divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($this->awinAccounts() as $account)
                    <div class="flex flex-wrap items-baseline justify-between gap-2 py-1.5">
                        <div>
                            <span class="text-sm">{{ $account['label'] }}</span>
                            <span class="ml-2 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $account['key'] }}</span>
                        </div>

                        <x-filament::badge :color="$account['visible'] ? 'success' : 'warning'">
                            {{ $account['visible'] ? 'visible' : 'absent — set ' . $account['env'] }}
                        </x-filament::badge>
                    </div>
                @endforeach
            </div>
        </div>
    </x-filament::section>

    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @if ($this->preview !== null)
        <x-filament::section heading="Last check">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="pb-2">Surface</th>
                        <th class="pb-2">Created</th>
                        <th class="pb-2">Updated</th>
                        <th class="pb-2">Dropped</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($this->preview as $surface => $result)
                        <tr>
                            <td class="py-1.5">{{ $surface }}</td>
                            <td class="py-1.5">{{ $result['created'] }}</td>
                            <td class="py-1.5">{{ $result['updated'] }}</td>
                            <td class="py-1.5">{{ count($result['dropped']) ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @php($dropped = collect($this->preview)->flatMap(fn ($r) => $r['dropped'])->all())

            @if ($dropped !== [])
                {{--
                  Named, not counted. "14 dropped" is a number; an identity key
                  is something you can go and look up — and deciding whether a
                  drop matters is the whole reason to check before applying.
                --}}
                <div class="mt-4">
                    <p class="text-sm font-medium">
                        {{ count($dropped) }} reference(s) had no product in this environment
                    </p>
                    <ul class="mt-2 max-h-64 space-y-1 overflow-y-auto text-xs text-gray-600 dark:text-gray-300">
                        @foreach (array_slice($dropped, 0, 200) as $line)
                            <li class="font-mono">{{ $line }}</li>
                        @endforeach
                    </ul>
                    @if (count($dropped) > 200)
                        <p class="mt-2 text-xs text-gray-500">… and {{ count($dropped) - 200 }} more.</p>
                    @endif
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
