@props(['url'])
@php
    // Logo intégré en base64 (s'affiche bien sur téléphone ; peut être vide sur Gmail web/desktop si logo lourd)
    $logoPath = public_path('images/logo.png');
    $logoSrc = null;
    if (file_exists($logoPath)) {
        $logoData = base64_encode(file_get_contents($logoPath));
        $logoSrc = 'data:image/png;base64,' . $logoData;
    }
    if (!$logoSrc) {
        $logoUrl = config('mail.logo_url');
        if ($logoUrl && str_starts_with((string) $logoUrl, '/')) {
            $logoUrl = rtrim((string) config('app.url'), '/') . $logoUrl;
        }
        if ($logoUrl) {
            $logoSrc = $logoUrl;
        }
    }
    $headerText = config('mail.header_text', 'Brole');
@endphp
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($logoSrc)
<img src="{{ $logoSrc }}" class="logo" alt="{{ $headerText }}">
@else
{{ $headerText }}
@endif
</a>
</td>
</tr>
