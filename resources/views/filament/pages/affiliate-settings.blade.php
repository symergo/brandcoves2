<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">Save</x-filament::button>
        </div>
    </form>

    {{--
      What each value does, in one place.

      The fields say what they are; this says what happens when one is wrong,
      which is the part worth knowing before typing. A wrong affiliate id is the
      quietest failure on the site: every link still works for the visitor, the
      sale still happens, and the commission goes to nobody.
    --}}
    <x-filament::section :heading="'What each value does'">
        <ul class="list-disc space-y-2 pl-5 text-sm text-gray-600 dark:text-gray-300">
            <li>
                <strong>bol partner site ids</strong> go into every bol link a visitor clicks,
                one per country. A wrong id still sends the visitor to bol and earns nothing,
                which is why the ids are shown in full here rather than hidden.
            </li>
            <li>
                <strong>bol client id and secret</strong> let the site ask bol for products and
                prices. A change takes effect at once: saving clears bol's cached login and tells
                the queue workers to restart with the new values.
            </li>
            <li>
                <strong>Amazon Associates tags</strong> go into the "search on Amazon" link, one
                per storefront. The Belgian tag serves both Belgian markets, because Amazon runs
                them on one storefront.
            </li>
            <li>
                A field left empty uses the value set in Coolify. That is also the way back if a
                value typed here turns out to be wrong.
            </li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
