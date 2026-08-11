{{--
  One list of chart moves. Shared by climbers, arrivals and fallers, because the
  three differ only in which moves they hold — a copy per section is three places
  to forget that an ungrouped entry has no title to show.
--}}
@if ($moves === [])
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $empty }}</p>
@else
    <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-800">
        @foreach ($moves as $move)
            <li class="flex items-baseline gap-3 py-2">
                <span class="w-12 shrink-0 text-right tabular-nums text-gray-500 dark:text-gray-400">
                    #{{ $move->rank }}
                </span>

                <span class="min-w-0 flex-1">
                    {{--
                      An entry we could not group has no title: it charted, and
                      either its product was not stored or it has not been
                      grouped yet. Shown with its external id rather than hidden,
                      because a category full of these is a linking problem
                      somebody needs to see.
                    --}}
                    <span class="block truncate font-medium">
                        {{ $move->title ?? $move->externalId }}
                    </span>
                    @if ($move->categoryName)
                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                            {{ $move->categoryName }}
                        </span>
                    @endif
                </span>

                <span class="shrink-0 text-xs font-medium tabular-nums">
                    @if ($move->isNewEntry())
                        <span class="text-primary-600 dark:text-primary-400">new</span>
                    @elseif ($move->delta() > 0)
                        <span class="text-success-600 dark:text-success-400">▲ {{ $move->delta() }}</span>
                    @else
                        <span class="text-danger-600 dark:text-danger-400">▼ {{ abs($move->delta()) }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>
@endif
