<x-filament-panels::page>
    @php($plan = $this->plan())
    @php($items = $this->items())
    @php($warning = $this->warning())
    @php($conflicts = $this->conflicts(app(App\Services\Curation\ScheduleConflicts::class)))

    {{--
      What this plan is, and what it will publish.

      "I have four products — is that the page, or will something else appear
      next to them?" is the question the first version of this screen could not
      answer, and it is the one a curator has continuously.
    --}}
    <x-filament::section>
        <div class="flex flex-wrap items-center gap-x-6 gap-y-3 text-sm">
            <span class="font-medium">{{ $plan->market->label() }}</span>

            <span class="text-gray-500 dark:text-gray-400">
                {{ $plan->kind->label() }}@if ($plan->drop_date)
                    — {{ $plan->drop_date->format('D j M Y') }}
                @elseif ($plan->slug)
                    — /{{ $plan->slug }}
                @endif
            </span>

            <x-filament::badge :color="$plan->status === 'approved' ? 'success' : 'warning'">
                {{ $plan->status }}
            </x-filament::badge>

            {{--
              The mode switch, here rather than only on the edit form: "these
              four are the page" is a thought you have with the four in front of
              you, not one you navigate away to record.
            --}}
            <div class="flex items-center gap-1 rounded-lg bg-gray-100 p-1 dark:bg-white/5">
                @foreach (App\Enums\PickMode::cases() as $mode)
                    <button
                        type="button"
                        wire:click="setPickMode('{{ $mode->value }}')"
                        @class([
                            'rounded-md px-2.5 py-1 text-xs font-medium transition',
                            'bg-white shadow-sm dark:bg-gray-700' => $plan->pick_mode === $mode,
                            'text-gray-500 hover:text-gray-700 dark:text-gray-400' => $plan->pick_mode !== $mode,
                        ])
                    >{{ $mode->value }}</button>
                @endforeach
            </div>
        </div>

        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ $this->summary() }}</p>

        @if ($warning)
            {{-- The one thing this screen exists to prevent: finding out at 06:00. --}}
            <p class="mt-3 rounded-md bg-warning-50 px-3 py-2 text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-400">
                {{ $warning }}
            </p>
        @endif
    </x-filament::section>

    {{--
      The brief for the build.

      Above the two panes because it is about the whole article rather than any
      one product, and because it is the thing an editor decides first: what
      this piece is *for* comes before which kettle goes in it.
    --}}
    <x-filament::section
        heading="Instructions for the build"
        description="Direction for whoever writes the article — “keep it short”, “lean on the nostalgia, not the tech”, “do not mention Christmas, it runs in October”. Never shown to a reader."
        collapsible
        :collapsed="blank($this->instructions)"
    >
        <textarea
            rows="3"
            placeholder="How this one should be written. The rules about prices and invented claims still apply."
            class="block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
            wire:model="instructions"
            wire:blur="saveInstructions"
        ></textarea>

        <div class="mt-2 flex items-center gap-3 text-xs">
            @if ($this->instructionsSaved)
                <span class="text-success-600 dark:text-success-400">Saved</span>
            @endif

            @unless ($this->willBeWritten())
                {{--
                  A field quietly doing nothing is worse than no field. Authored
                  prose skips the model entirely, so a brief for it is read by
                  nobody.
                --}}
                <span class="text-warning-600 dark:text-warning-400">
                    This plan carries its own editorial, so nothing will be generated and these
                    instructions will not be read. Clear the editorial to have it written.
                </span>
            @endunless
        </div>
    </x-filament::section>

    {{--
      Two panes from `xl` up: the list being made, and the tool for making it.

      Stacked, curating meant scrolling down to search and back up to see what
      you had, once per product. Nothing about that was broken and all of it was
      tiring.
    --}}
    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_26rem] xl:items-start">

        {{-- ── The shortlist ─────────────────────────────────────────────── --}}
        <x-filament::section
            heading="The shortlist"
            description="In the order the article will follow. The note is the reason the product is here — it goes to the writer and is never shown to a reader."
        >
            @if ($this->undo)
                {{--
                  Undo, not "are you sure". A modal on every removal charges a
                  click for each of the six correct ones to protect the seventh.
                --}}
                <div class="mb-4 flex items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5">
                    <span class="min-w-0 flex-1 truncate">
                        Removed <span class="font-medium">{{ $this->undo['label'] }}</span>
                    </span>
                    <x-filament::button size="xs" wire:click="undoRemove">Undo</x-filament::button>
                    <x-filament::icon-button icon="heroicon-m-x-mark" size="sm" label="Dismiss" wire:click="dismissUndo" />
                </div>
            @endif

            @if ($items === [])
                <div class="py-6 text-center">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Nothing curated yet.
                    </p>
                    <p class="mx-auto mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">
                        Start from the engine's picks and change what does not belong, or
                        search on the right — the search reaches the catalogue and every
                        live merchant, so a product nobody has ingested can still be added.
                    </p>

                    <x-filament::button class="mt-4" wire:click="suggest" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="suggest">Suggest products</span>
                        <span wire:loading wire:target="suggest">Choosing…</span>
                    </x-filament::button>
                </div>
            @else
                <ol class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($items as $index => $item)
                        <li class="flex flex-col gap-3 py-4 sm:flex-row sm:items-start" wire:key="item-{{ $item->id }}">
                            <div class="flex items-center gap-1">
                                <span class="w-5 text-sm font-medium text-gray-400">{{ $index + 1 }}</span>

                                <div class="flex flex-col">
                                    {{--
                                      "Open with this one" is the common edit, and
                                      six presses of an arrow is the interface making
                                      a person do the arithmetic.
                                    --}}
                                    <x-filament::icon-button
                                        icon="heroicon-m-bars-arrow-up"
                                        size="sm"
                                        label="Move to the top"
                                        :disabled="$loop->first"
                                        wire:click="moveToTop({{ $item->id }})"
                                    />
                                    <x-filament::icon-button
                                        icon="heroicon-m-chevron-up"
                                        size="sm"
                                        label="Move up"
                                        :disabled="$loop->first"
                                        wire:click="move({{ $item->id }}, -1)"
                                    />
                                    <x-filament::icon-button
                                        icon="heroicon-m-chevron-down"
                                        size="sm"
                                        label="Move down"
                                        :disabled="$loop->last"
                                        wire:click="move({{ $item->id }}, 1)"
                                    />
                                </div>
                            </div>

                            @if ($item->group?->image_url)
                                <img src="{{ $item->group->image_url }}" alt="" class="h-16 w-16 shrink-0 rounded-md object-contain" />
                            @else
                                <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-md bg-gray-100 text-xs text-gray-400 dark:bg-gray-800">
                                    {{ $item->group === null ? 'live' : 'no image' }}
                                </div>
                            @endif

                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium">
                                    @if ($item->group)
                                        {{-- Openable, so "is this actually any good" is one click. --}}
                                        <a
                                            href="/{{ $plan->market->value }}/p/{{ $item->group->id }}/{{ $item->group->slug }}"
                                            target="_blank"
                                            rel="noopener"
                                            class="hover:underline"
                                        >{{ $item->group->title }}</a>
                                    @else
                                        {{ $item->source?->value }} {{ $item->external_id }}
                                    @endif
                                </p>

                                <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                    @if ($item->group?->brand)
                                        <span>{{ $item->group->brand }}</span>
                                    @endif

                                    @if ($item->group?->min_price)
                                        <span>€{{ number_format($item->group->min_price / 100, 2, ',', '.') }}</span>
                                    @endif

                                    @if (($item->group?->merchant_count ?? 0) > 1)
                                        <span>{{ $item->group->merchant_count }} shops</span>
                                    @endif

                                    @if ($item->group && ! $item->group->in_stock)
                                        {{--
                                          A statement about the page, not about the
                                          row: the Cove filters out-of-stock finds at
                                          render, so this one is chosen and will not
                                          appear.
                                        --}}
                                        <x-filament::badge color="danger" size="xs">out of stock — will not appear</x-filament::badge>
                                    @endif

                                    @if (isset($conflicts[$item->id]))
                                        {{--
                                          The mistake a curator actually makes: not
                                          picking a bad product, picking a good one
                                          twice. Advisory — there are reasons to do it.
                                        --}}
                                        <x-filament::badge color="warning" size="xs">{{ $conflicts[$item->id] }}</x-filament::badge>
                                    @endif

                                    @if ($item->group === null)
                                        <x-filament::badge color="warning" size="xs">
                                            {{ $item->source?->value }} — re-fetched live at render
                                        </x-filament::badge>
                                    @endif
                                </p>

                                <div class="mt-2 space-y-2">
                                    <input
                                        type="text"
                                        placeholder="Verdict — “best for small kitchens”"
                                        class="block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
                                        wire:model="verdicts.{{ $item->id }}"
                                        wire:blur="saveNote({{ $item->id }})"
                                    />

                                    <textarea
                                        rows="2"
                                        placeholder="Why this one is here. The writer is handed this sentence."
                                        class="block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
                                        wire:model="notes.{{ $item->id }}"
                                        wire:blur="saveNote({{ $item->id }})"
                                    ></textarea>

                                    @if ($this->justSaved === $item->id)
                                        {{-- A toast per blur turned writing a note into a stream of notifications. --}}
                                        <p class="text-xs text-success-600 dark:text-success-400">Saved</p>
                                    @endif
                                </div>
                            </div>

                            <x-filament::icon-button
                                icon="heroicon-m-trash"
                                color="danger"
                                label="Remove"
                                wire:click="removeItem({{ $item->id }})"
                            />
                        </li>
                    @endforeach
                </ol>

                @if (count($items) < (int) config('giftcoves.picks.per_day'))
                    <div class="mt-4 border-t border-gray-200 pt-4 dark:border-white/10">
                        <x-filament::button size="sm" color="gray" wire:click="suggest" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="suggest">Fill the rest from the engine</span>
                            <span wire:loading wire:target="suggest">Choosing…</span>
                        </x-filament::button>
                    </div>
                @endif
            @endif
        </x-filament::section>

        {{-- ── The search ────────────────────────────────────────────────── --}}
        <div class="xl:sticky xl:top-6">
            <x-filament::section
                heading="Find products"
                description="The catalogue and every live merchant connector at once. Anything a live source returns that we may store becomes a catalogue product immediately."
            >
                <form wire:submit="runSearch" class="space-y-3">
                    <input
                        type="search"
                        placeholder="Product words — “hondenmand”, “espressomachine”"
                        class="block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
                        wire:model="term"
                    />

                    <div class="flex items-center gap-2">
                        <div class="relative flex-1">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-gray-400">€</span>
                            <input
                                type="text"
                                inputmode="decimal"
                                placeholder="up to"
                                class="block w-full rounded-lg border-gray-300 pl-7 text-sm shadow-sm dark:border-white/10 dark:bg-white/5"
                                wire:model="maxPrice"
                            />
                        </div>

                        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="runSearch">
                            <span wire:loading.remove wire:target="runSearch">Search</span>
                            <span wire:loading wire:target="runSearch">Asking…</span>
                        </x-filament::button>

                        @if ($this->searched)
                            <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" label="Clear" wire:click="clearSearch" />
                        @endif
                    </div>
                </form>

                @if ($this->searched && $this->results === [])
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                        Nothing matched. Product nouns retrieve products; a gift phrase
                        (“cadeau voor hondenliefhebbers”) retrieves nothing, because the
                        catalogue holds product titles rather than gift ideas.
                    </p>
                @endif

                @if ($this->results !== [])
                    <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                        {{ count($this->results) }} result(s)
                    </p>

                    <ul class="mt-2 max-h-[32rem] space-y-2 overflow-y-auto pr-1">
                        @foreach ($this->results as $result)
                            <li class="flex gap-3 rounded-lg border border-gray-200 p-2 dark:border-white/10" wire:key="result-{{ $result['key'] }}">
                                @if ($result['image'])
                                    <img src="{{ $result['image'] }}" alt="" class="h-14 w-14 shrink-0 rounded-md object-contain" />
                                @else
                                    <div class="h-14 w-14 shrink-0 rounded-md bg-gray-100 dark:bg-gray-800"></div>
                                @endif

                                <div class="min-w-0 flex-1">
                                    <p class="line-clamp-2 text-sm font-medium">
                                        @if ($result['url'])
                                            <a href="{{ $result['url'] }}" target="_blank" rel="noopener" class="hover:underline">{{ $result['title'] }}</a>
                                        @else
                                            {{ $result['title'] }}
                                        @endif
                                    </p>

                                    <p class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                        @if ($result['brand'])
                                            <span>{{ $result['brand'] }}</span>
                                        @endif

                                        @if ($result['price'])
                                            <span>€{{ number_format($result['price'] / 100, 2, ',', '.') }}</span>
                                        @endif

                                        @if ($result['merchants'] > 1)
                                            <span>{{ $result['merchants'] }} shops</span>
                                        @endif

                                        {{--
                                          Where it came from. A live source's badge
                                          means the catalogue met this product seconds
                                          ago and has no price history for it, which is
                                          a different thing to curate than one indexed
                                          for months.
                                        --}}
                                        @foreach ($result['sources'] as $source)
                                            <x-filament::badge size="xs" :color="$source === 'awin' ? 'gray' : 'info'">{{ $source }}</x-filament::badge>
                                        @endforeach

                                        @if ($result['live_only'])
                                            <x-filament::badge size="xs" color="warning">not stored</x-filament::badge>
                                        @endif
                                    </p>

                                    <div class="mt-2 flex items-center gap-2">
                                        @if ($result['added'])
                                            <x-filament::badge color="success" size="sm">on the shortlist</x-filament::badge>
                                        @else
                                            <x-filament::button size="xs" wire:click="add('{{ $result['key'] }}')">Add</x-filament::button>
                                        @endif

                                        @if ($result['conflict'])
                                            <span class="text-xs text-warning-600 dark:text-warning-400">{{ $result['conflict'] }}</span>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
