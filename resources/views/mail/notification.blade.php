<x-mail::message>
# {{ $notification->subject }}

{{ $body }}

@if($hasLink)
<x-mail::button :url="$signedUrl">
Lihat Detail
</x-mail::button>
@endif

Salam,<br>
{{ config('app.name') }}
</x-mail::message>
