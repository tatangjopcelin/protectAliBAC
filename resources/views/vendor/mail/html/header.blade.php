@props(['url'])
@php
    $logoUrl = config('mail.logo_url');
    if ($logoUrl && str_starts_with((string) $logoUrl, '/')) {
        $logoUrl = rtrim((string) config('app.url'), '/') . $logoUrl;
    }
@endphp
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($logoUrl)
<img src="{{ $logoUrl }}" class="logo" alt="{{ config('app.name') }}">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
