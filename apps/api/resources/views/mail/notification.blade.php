<x-mail::message>
{{ $body }}

@if ($url)
<x-mail::button :url="$url">
Lihat detail
</x-mail::button>
@endif
</x-mail::message>
