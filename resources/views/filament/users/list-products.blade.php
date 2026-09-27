{{-- The products on one list. See WishlistsRelationManager for what is left out, and why. --}}
@if ($items === [])
    <p class="text-sm text-gray-500 dark:text-gray-400">No products on this list.</p>
@else
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 dark:text-gray-400">
                <th class="py-1 pr-4 font-medium">Product</th>
                <th class="py-1 pr-4 font-medium">Price</th>
                <th class="py-1 font-medium">Added</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr class="border-t border-gray-200 dark:border-gray-800">
                    <td class="py-1 pr-4">
                        @if ($item['url'])
                            <a href="{{ $item['url'] }}" target="_blank" rel="noopener" class="text-primary-600 hover:underline">{{ $item['title'] }}</a>
                        @else
                            {{ $item['title'] }} <span class="text-xs text-gray-500">(added by hand)</span>
                        @endif
                    </td>
                    <td class="py-1 pr-4 whitespace-nowrap">{{ $item['priceCents'] === null ? '—' : '€ '.number_format($item['priceCents'] / 100, 2, ',', '.') }}</td>
                    <td class="py-1 whitespace-nowrap">{{ $item['added'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
