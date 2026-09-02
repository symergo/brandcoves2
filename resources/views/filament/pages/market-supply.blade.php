<x-filament-panels::page>
    @php($rows = $this->rows())
    @php($sources = $this->sources())
    @php($dark = collect($rows)->where('serving', 0))

    {{--
      The alarm first, and only when there is one.
      A market nothing serves has no other symptom in this panel: its pages
      render, its sitemap is submitted, and its search quietly returns nothing.
    --}}
    @if ($dark->isNotEmpty())
        <x-filament::section heading="No source is serving these markets">
            <div class="flex flex-wrap gap-2">
                @foreach ($dark as $row)
                    <x-filament::badge color="danger">
                        {{ $row['market']->label() }} — {{ $row['market']->value }}
                    </x-filament::badge>
                @endforeach
            </div>

            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                Search, gift picks and guides all return nothing here. The row below
                says which sources are close — a feed registered but disabled, or a
                credential that never arrived — and which are not integrated at all.
            </p>
        </x-filament::section>
    @endif

    {{--
      The switches, above the diagnosis they change.
      Deliberately its own grid rather than a control tucked into each status
      cell: the table below says what IS happening, this says what we have
      ASKED for, and a reader has to be able to tell those apart. A source can
      be switched on here and still dark below — no credential, no marketplace,
      backing off after a 429 — and that gap is the most useful thing the two
      grids show together.
    --}}
    <x-filament::section heading="Sources" collapsible>
        <x-slot name="description">
            Off means we stop asking. It does not remove what a source already
            stored &mdash; those offers stay in search until
            <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-800">bc:withdraw-source</code>
            suppresses them, and that command refuses to run while the source is
            still switched on here.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4">Source</th>
                        @foreach (\App\Enums\Market::cases() as $market)
                            <th class="py-2 pr-4 font-mono normal-case">{{ $market->value }}</th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($sources as $source)
                        <tr>
                            <td class="py-2 pr-4">
                                <span class="font-medium">{{ $source->label() }}</span>
                                <span class="ml-1 text-xs text-gray-400 dark:text-gray-500">
                                    {{ $source->isFeed() ? 'ingested' : 'live' }}
                                </span>
                            </td>

                            @foreach (\App\Enums\Market::cases() as $market)
                                @php($on = $this->isEnabled($source, $market))
                                <td class="py-2 pr-4">
                                    <button
                                        type="button"
                                        wire:click="toggle('{{ $source->value }}', '{{ $market->value }}')"
                                        wire:loading.attr="disabled"
                                        @class([
                                            'rounded px-2 py-1 text-xs font-medium transition',
                                            'bg-success-50 text-success-700 hover:bg-success-100 dark:bg-success-400/10 dark:text-success-400' => $on,
                                            'bg-gray-100 text-gray-500 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-400' => ! $on,
                                        ])
                                        title="{{ $on ? 'Switch off' : 'Switch on' }} {{ $source->label() }} for {{ $market->value }}"
                                    >{{ $on ? 'on' : 'off' }}</button>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4 align-bottom">Market</th>

                        @foreach ($sources as $source)
                            <th class="py-2 pr-4 align-bottom">
                                {{ $source->label() }}
                                {{--
                                  Feed and live are not two implementations of one
                                  thing, they are two failure modes. A feed goes
                                  stale and keeps serving; a live source vanishes
                                  from a page mid-session. Which one a column is
                                  changes what a red cell means.
                                --}}
                                <span class="block font-normal normal-case text-gray-400 dark:text-gray-500">
                                    {{ $source->isFeed() ? 'ingested' : 'live' }}
                                </span>
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($rows as $row)
                        <tr class="align-top">
                            <td class="py-3 pr-4">
                                <div class="font-medium">{{ $row['market']->label() }}</div>
                                <div class="font-mono text-xs text-gray-500 dark:text-gray-400">
                                    {{ $row['market']->value }}
                                </div>

                                @unless ($row['published'])
                                    {{--
                                      Stated, because an empty row here is the
                                      expected state for a market that has not
                                      opened rather than a fault to chase.
                                    --}}
                                    <x-filament::badge color="gray" class="mt-1">unpublished</x-filament::badge>
                                @endunless

                                {{--
                                  Groups, not offers. Search, gift picks and guides
                                  all operate on groups, so a big offer count over a
                                  handful of products is a thin market wearing a
                                  large number.
                                --}}
                                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    {{ number_format($row['groups']) }} product(s)
                                    <span class="text-gray-400 dark:text-gray-500">
                                        · {{ number_format($row['offers']) }} stored offer(s)
                                    </span>
                                </div>

                                <a
                                    href="{{ $this->feedsUrl($row['market']) }}"
                                    class="mt-1 inline-block text-xs text-primary-600 hover:underline dark:text-primary-400"
                                >Feeds for this market</a>
                            </td>

                            @foreach ($row['cells'] as $cell)
                                <td class="py-3 pr-4">
                                    <x-filament::badge
                                        :color="match ($cell['status']) {
                                            'ok' => 'success',
                                            'pending' => 'warning',
                                            'failing' => 'danger',
                                            default => 'gray',
                                        }"
                                    >
                                        {{ $cell['headline'] }}
                                    </x-filament::badge>

                                    @if ($cell['earning'])
                                        {{--
                                          Serving and paying nobody. Amber rather
                                          than red: the visitor's experience is
                                          fine, which is precisely why this is
                                          invisible everywhere else.
                                        --}}
                                        <p class="mt-1 text-xs text-warning-600 dark:text-warning-400">
                                            {{ $cell['earning'] }}
                                        </p>
                                    @endif

                                    @foreach ($cell['notes'] as $note)
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $note }}</p>
                                    @endforeach
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="What this page does not prove" collapsible collapsed>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Every cell above is read from the database and from configuration. Nothing
            here calls an upstream, so the page still loads when one is down — but a
            green cell means <em>configured and enabled</em>, not
            <em>the credential is accepted</em>. A revoked key, a spent quota and a
            marketplace the Browse API does not serve all read as green here and as an
            empty result on the site.
        </p>

        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
            To prove one end to end:
            <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-800">php artisan bc:check-bol</code>,
            <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-800">bc:check-ebay --market=be-nl</code>,
            <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-800">bc:check-tradedoubler --market=be-nl --raw</code>.
            Each reports credential lengths, never values.
        </p>
    </x-filament::section>
</x-filament-panels::page>
