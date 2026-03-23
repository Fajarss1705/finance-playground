{{-- Shared PDF header: logo + app branding + document info --}}
{{-- Required variables: $label, $judul, $revision, $dikompilasi --}}
<div class="brand-header">
    <img src="data:image/png;base64,{{ $logoBase64 }}" class="brand-logo" alt="Logo">
    <div class="brand-name">{{ config('app.name', 'Finance Playground') }}</div>
    <div class="brand-tagline">Monitoring &amp; Evaluasi</div>
</div>
<table class="doc-info">
    <tr><td class="doc-info-label">Label</td><td class="doc-info-sep">:</td><td>{{ $label }}</td></tr>
    <tr><td class="doc-info-label">Judul Dokumen</td><td class="doc-info-sep">:</td><td>{{ $judul }}</td></tr>
    <tr><td class="doc-info-label">Revisi</td><td class="doc-info-sep">:</td><td><span class="revision-badge">{{ $revision }}</span></td></tr>
    <tr><td class="doc-info-label">Dikompilasi</td><td class="doc-info-sep">:</td><td>{{ $dikompilasi }}</td></tr>
</table>
