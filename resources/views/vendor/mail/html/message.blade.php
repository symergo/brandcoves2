<x-mail::layout>
{{-- Header. The name is the brand, not APP_NAME; see header.blade.php. --}}
<x-slot:header>
<x-mail::header :url="url('/')">
GiftCoves
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} GiftCoves · <a href="{{ url('/') }}">giftcoves.com</a>
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
