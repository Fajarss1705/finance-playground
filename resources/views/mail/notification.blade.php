<x-mail::message>
# {{ $notification->subject }}

{{ $body }}

@if($hasLink)
<x-mail::button :url="$signedUrl">
Lihat Detail
</x-mail::button>

<small>Link ini berlaku hingga {{ $expiresAt }}.</small>
@endif

Salam,<br>
{{ config('app.name') }}
</x-mail::message>
