<x-filament-panels::page>
    @php($summary = $this->summary())
    @php($planner = \App\Filament\Resources\CovePlans\CovePlanResource::getUrl('index'))

    <x-filament::section>
        {{--
          Two switchers, because market and year are independent — the same
          reason the planner carries two tab strips. A single control that meant
          "be-nl 2028" would need twenty entries and could not answer either
          question on its own.
        --}}
        <div class="flex flex-wrap items-center gap-2">
            @foreach (\App\Enums\Market::cases() as $market)
                <button
                    type="button"
                    wire:click="$set('market', '{{ $market->value }}')"
                    @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium transition',
                        'bg-primary-600 text-white' => $this->market === $market->value,
                        'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300' => $this->market !== $market->value,
                    ])
                >{{ $market->value }}</button>
            @endforeach

            <span class="mx-2 h-5 w-px bg-gray-200 dark:bg-gray-700"></span>

            @foreach ($this->years() as $year)
                <button
                    type="button"
                    wire:click="$set('year', {{ $year }})"
                    @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium transition',
                        'bg-primary-600 text-white' => $this->year === $year,
                        'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300' => $this->year !== $year,
                    ])
                >{{ $year }}</button>
            @endforeach
        </div>

        {{--
          The state of the year in one line, before twelve months of detail.
          It is the difference between "the calendar is in hand" and "nobody has
          looked at the autumn".
        --}}
        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
            {{ $summary['seasonsPlanned'] }} of {{ $summary['seasons'] }} season(s) planned,
            across {{ $summary['parts'] }} dated part(s).
            {{ $summary['daysPlanned'] }} of {{ $summary['days'] }} named day(s) have a Daily Cove drafted.
            The calendar is the same every year — <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-800">bc:plan-coves</code>
            fills it in weekly, 120 days ahead.
        </p>
    </x-filament::section>

    @foreach ($this->months() as $month)
        @continue($month['seasons'] === [] && $month['days'] === [])

        <x-filament::section :heading="$month['label'].' '.$this->year" collapsible>
            @if ($month['seasons'] !== [])
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Seasons running
                </h3>

                <ul class="mt-2 space-y-3">
                    @foreach ($month['seasons'] as $season)
                        @php($opens = $season['from']->month === $month['month'] && $season['from']->year === $this->year)

                        <li class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{ $season['topic'] }}</span>

                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $season['window'] }}</span>

                                {{--
                                  Marked on the month it opens. Listing a season
                                  in every month it runs through is what shows
                                  that half of August is three overlapping
                                  windows; without saying which month is the
                                  start, every month would read as a deadline.
                                --}}
                                @if ($opens)
                                    <x-filament::badge color="warning">opens</x-filament::badge>
                                @endif

                                @if ($season['rejected'])
                                    <x-filament::badge color="danger">rejected</x-filament::badge>
                                @elseif ($season['parts'] === [])
                                    <x-filament::badge color="gray">nothing planned</x-filament::badge>
                                @endif

                                {{--
                                  Null is "nobody has counted", which is not the
                                  same claim as zero: on an environment where the
                                  nightly pass has never run, every season would
                                  otherwise report no products.
                                --}}
                                @if ($season['availableProducts'] !== null)
                                    <span class="text-xs text-gray-400">
                                        {{ $season['availableProducts'] }} product(s) available
                                    </span>
                                @endif
                            </div>

                            @if ($season['parts'] !== [])
                                <ul class="mt-2 space-y-1 text-sm">
                                    @foreach ($season['parts'] as $part)
                                        <li class="flex flex-wrap items-center gap-2">
                                            <a href="{{ $planner }}" class="text-primary-600 hover:underline dark:text-primary-400">
                                                {{ $part['title'] }}
                                            </a>

                                            @if ($part['dueLabel'])
                                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                                    due {{ $part['dueLabel'] }}
                                                </span>
                                            @endif

                                            <x-filament::badge :color="match ($part['status']) {
                                                'approved' => 'success',
                                                'rejected' => 'danger',
                                                'used' => 'gray',
                                                default => 'warning',
                                            }">{{ $part['status'] }}</x-filament::badge>

                                            {{--
                                              The recurrence, made visible. A
                                              published part whose date has moved
                                              past the date it was last built for
                                              will be rebuilt at the same URL on
                                              the day — and nothing about a title
                                              and a date says so.
                                            --}}
                                            @if ($part['refreshDue'])
                                                <x-filament::badge color="info">refresh due</x-filament::badge>
                                            @elseif ($part['published'])
                                                <x-filament::badge color="gray">live</x-filament::badge>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @if ($opens && ! $season['rejected'])
                                <div class="mt-3">
                                    <x-filament::button
                                        size="xs"
                                        color="gray"
                                        wire:click="planSeason('{{ $season['topic'] }}')"
                                        wire:loading.attr="disabled"
                                    >
                                        {{ $season['parts'] === [] ? 'Lay this season out' : 'Bring it round for this window' }}
                                    </x-filament::button>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($month['days'] !== [])
                <h3 @class([
                    'text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400',
                    'mt-5' => $month['seasons'] !== [],
                ])>
                    Named days
                </h3>

                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($month['days'] as $day)
                        <li class="flex flex-wrap items-center gap-2">
                            <span class="w-14 shrink-0 tabular-nums text-gray-500 dark:text-gray-400">
                                {{ $day['label'] }}
                            </span>

                            <span>{{ $day['title'] }}</span>

                            @if ($day['plan'] === null)
                                <x-filament::button
                                    size="xs"
                                    color="gray"
                                    wire:click="planDay('{{ $day['date'] }}')"
                                    wire:loading.attr="disabled"
                                >
                                    Draft it
                                </x-filament::button>
                            @else
                                <x-filament::badge :color="match ($day['plan']['status']) {
                                    'approved' => 'success',
                                    'rejected' => 'danger',
                                    'used' => 'gray',
                                    default => 'warning',
                                }">{{ $day['plan']['status'] }}</x-filament::badge>

                                @if ($day['plan']['published'])
                                    <x-filament::badge color="gray">live</x-filament::badge>
                                @endif
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    @endforeach

    {{--
      The two-thirds of the year that is not on this page, said out loud.

      Every date without a named day still gets an edition, themed from the
      evergreen rotation. Listing three hundred of those would bury the ninety
      that are actually occasions — but leaving them unmentioned would read as
      "nothing happens in the gaps", which is the opposite of true.
    --}}
    <x-filament::section heading="The rest of the year">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Every other date gets a Daily Cove too, themed from the evergreen rotation in
            <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-800">config/cove_themes.php</code>
            — sixty-four situations rather than occasions, so a Cove never opens with "today's picks"
            and a shrug. They are not listed here because they claim nothing about their date and
            there is nothing to plan around; they are drafted with everything else by
            <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-800">bc:plan-coves</code>
            and appear in the <a href="{{ $planner }}" class="text-primary-600 hover:underline dark:text-primary-400">Cove planner</a>.
        </p>
    </x-filament::section>
</x-filament-panels::page>
