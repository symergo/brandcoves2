<x-filament-panels::page>
    @php($snapshot = $this->snapshot())
    @php($markets = $this->marketsWithData())

    @if ($markets === [])
        {{--
          The empty state is the useful one on a fresh environment: nothing here
          is broken, the puller has simply not run yet, and saying so is faster
          than making someone go and find out.
        --}}
        <x-filament::section heading="No chart data yet">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Bestseller charts are pulled nightly at 03:40. To pull one now:
                <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-800">php artisan bc:pull-charts --market=be-nl</code>,
                or check the endpoint first with
                <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-800">--discover --dry-run</code>.
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($markets as $value)
                    <button
                        type="button"
                        wire:click="$set('market', '{{ $value }}')"
                        @class([
                            'rounded-md px-3 py-1.5 text-sm font-medium transition',
                            'bg-primary-600 text-white' => $this->market === $value,
                            'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300' => $this->market !== $value,
                        ])
                    >{{ $value }}</button>
                @endforeach
            </div>

            {{--
              Every figure below is a difference between two snapshots. Stating
              which two is not decoration: "up 14 places" is a different claim
              since yesterday than since a gap in the data.
            --}}
            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                @if ($snapshot['latest'])
                    Latest snapshot {{ $snapshot['latest'] }} —
                    {{ $snapshot['entries'] }} chart position(s) across
                    {{ $snapshot['categories'] }} known categor(y|ies).
                    Movement is measured against the most recent snapshot at least
                    {{ $snapshot['window_days'] }} days older.
                @else
                    No snapshot for this market yet.
                @endif
            </p>
        </x-filament::section>

        {{--
          Categories before products. "Kettles are moving" is the finding worth
          acting on — commission a guide, chase an advertiser — and the
          individual climbers are the evidence for it, which is the order a
          person reads them in.
        --}}
        <x-filament::section heading="Categories with the most movement">
            @php($categories = $this->activeCategories())

            @if ($categories === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Nothing to compare yet — this needs two snapshots at least
                    {{ $snapshot['window_days'] }} days apart.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="py-2 pr-4">Category</th>
                                <th class="py-2 pr-4 text-right">Charting</th>
                                <th class="py-2 pr-4 text-right">Moved</th>
                                <th class="py-2 pr-4 text-right">New</th>
                                <th class="py-2 text-right">Churn</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($categories as $row)
                                <tr>
                                    <td class="py-2 pr-4 font-medium">
                                        {{ $row['name'] ?? ($row['category_external_id'] === '*' ? 'Market-wide' : $row['category_external_id']) }}
                                    </td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $row['entries'] }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $row['moved'] }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $row['new'] }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ number_format($row['churn'] * 100, 0) }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-filament::section heading="Climbing">
                @include('filament.pages.partials.trend-list', [
                    'moves' => $this->risers(),
                    'empty' => 'Nothing is climbing in this window.',
                ])
            </x-filament::section>

            <x-filament::section heading="New entries">
                @include('filament.pages.partials.trend-list', [
                    'moves' => $this->newEntries(),
                    'empty' => 'No arrivals in this window.',
                ])
            </x-filament::section>
        </div>

        {{--
          Fallers are as informative as risers and much easier to leave out. A
          category whose whole top ten is sliding is a reason NOT to commission
          the guide, and that decision is only visible here.
        --}}
        <x-filament::section heading="Falling" collapsible collapsed>
            @include('filament.pages.partials.trend-list', [
                'moves' => $this->fallers(),
                'empty' => 'Nothing is falling in this window.',
            ])
        </x-filament::section>
    @endif
</x-filament-panels::page>
