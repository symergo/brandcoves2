<x-filament-panels::page>
    {{--
      One warning line, and only when there is something to warn about.

      A revoked key on one account is a configuration problem, not a reason to
      hide the other accounts' feeds — so discovery carries on and says which
      account it could not reach. Without this the list is simply shorter than it
      should be, and nothing on screen explains why.
    --}}
    @if ($this->warnings !== [])
        <x-filament::section icon="heroicon-o-exclamation-triangle" heading="Some accounts did not answer">
            <ul class="list-disc space-y-1 ps-5 text-sm">
                @foreach ($this->warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
