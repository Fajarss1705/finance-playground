<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>PABD05 Pengajuan Bulanan — {{ $teamName }} — {{ $bulan }} {{ $tahun }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.5; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #ccc; }
        h3 { font-size: 10px; margin: 12px 0 4px; color: #333; }
        .brand-header { text-align: center; margin-bottom: 10px; }
        .brand-logo { width: 48px; height: 48px; }
        .brand-name { font-size: 14px; font-weight: bold; margin-top: 4px; }
        .brand-tagline { font-size: 10px; color: #666; }
        .doc-info { width: auto; margin: 0 auto 16px; border: none; }
        .doc-info td { border: none; padding: 1px 6px; font-size: 9px; }
        .doc-info-label { font-weight: bold; white-space: nowrap; }
        .doc-info-sep { width: 10px; }
        .revision-badge { background: #333; color: #fff; padding: 1px 8px; font-size: 9px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #f5f5f5; font-weight: bold; font-size: 9px; text-transform: uppercase; }
        td { font-size: 9px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 8px; }
        .total-row { background: #f5f5f5; font-weight: bold; }
        .author { color: #666; font-size: 8px; margin-top: 4px; }
        .verification { text-align: center; margin-top: 20px; padding: 8px; border: 1px dashed #999; }
        .verification code { font-family: 'DejaVu Sans Mono', monospace; font-size: 14px; letter-spacing: 3px; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 7px; color: #999; border-top: 1px solid #eee; padding-top: 4px; }
        .kegiatan-block { margin-bottom: 16px; padding: 6px; border: 1px solid #ddd; background: #fafafa; }
        .kegiatan-title { font-size: 10px; font-weight: bold; margin-bottom: 6px; color: #222; }
        .kegiatan-meta { font-size: 8px; color: #666; margin-bottom: 6px; }

        /* Chronology styles */
        .chrono-section { margin-top: 30px; }
        .chrono-entry { margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px dotted #ddd; }
        .chrono-header { font-size: 9px; font-weight: bold; color: #333; margin-bottom: 2px; }
        .chrono-time { font-family: 'DejaVu Sans Mono', monospace; font-size: 8px; color: #999; }
        .chrono-actor { font-size: 8px; color: #666; }
        .chrono-notes { font-size: 8px; color: #444; font-style: italic; margin: 4px 0; padding-left: 8px; border-left: 2px solid #ddd; }
        .chrono-files { font-size: 8px; color: #555; margin: 2px 0; }

        .status-dicairkan { color: #16a34a; font-weight: bold; }
        .status-hangus { color: #dc2626; font-weight: bold; }
    </style>
</head>
<body>
    <div class="footer">
        Kode Verifikasi: {{ $pabd05->verification_code ?? '-' }} &bull; Dikompilasi: {{ $pabd05->created_at->format('d/m/Y H:i') }} WIB
    </div>

    {{-- ============================================= --}}
    {{-- HEADER                                        --}}
    {{-- ============================================= --}}

    @include('exports._pdf-header')

    {{-- ============================================= --}}
    {{-- SECTION: Informasi Umum                       --}}
    {{-- ============================================= --}}

    <h2>Informasi Umum</h2>
    <table>
        <tr><th style="width:180px">Item</th><th>Nilai</th></tr>
        <tr><td>Tim</td><td>{{ $teamName }}</td></tr>
        <tr><td>Bulan Anggaran</td><td>{{ $bulan }} {{ $tahun }}</td></tr>
        <tr><td>Referensi PP</td><td>{{ $ppLabel ?? '-' }}</td></tr>
        <tr><td>Tanggal Kompilasi</td><td>{{ $pabd05->created_at->format('d/m/Y H:i') }} WIB</td></tr>
        <tr><td>Kode Verifikasi</td><td class="mono">{{ $pabd05->verification_code ?? '-' }}</td></tr>
    </table>
    <div class="author">Checklist oleh: {{ $pabd05->pabd01_created_by_user_name ?? '-' }} ({{ $pabd05->pabd01_created_by_role_name ?? '-' }} &mdash; {{ $pabd05->pabd01_created_by_team_name ?? '-' }}) &mdash; {{ $pabd05->pabd01_created_at?->format('d/m/Y H:i') ?? '-' }}</div>
    <div class="author">Disetujui oleh: {{ $pabd05->pabd03_approved_by_user_name ?? '-' }} ({{ $pabd05->pabd03_approved_by_role_name ?? '-' }} &mdash; {{ $pabd05->pabd03_approved_by_team_name ?? '-' }}) &mdash; {{ $pabd05->pabd03_approved_at?->format('d/m/Y H:i') ?? '-' }}</div>
    <div class="author">Bukti Transfer oleh: {{ $pabd05->pabd04_created_by_user_name ?? '-' }} ({{ $pabd05->pabd04_created_by_role_name ?? '-' }} &mdash; {{ $pabd05->pabd04_created_by_team_name ?? '-' }}) &mdash; {{ $pabd05->pabd04_created_at?->format('d/m/Y H:i') ?? '-' }}</div>

    {{-- ============================================= --}}
    {{-- SECTION: Informasi Rekening                   --}}
    {{-- ============================================= --}}

    <h2>Informasi Rekening</h2>
    <table>
        <tr><th style="width:180px">Item</th><th>Nilai</th></tr>
        <tr><td>Nama Bank</td><td>{{ $pabd05->nama_bank ?? '-' }}</td></tr>
        <tr><td>Nama Rekening</td><td>{{ $pabd05->nama_rekening ?? '-' }}</td></tr>
        <tr><td>Nomor Rekening</td><td class="mono">{{ $pabd05->nomor_rekening ?? '-' }}</td></tr>
    </table>

    {{-- ============================================= --}}
    {{-- SECTION: Daftar Anggaran                      --}}
    {{-- ============================================= --}}

    <h2>Daftar Anggaran</h2>

    @php
        $grandTotal = 0;
        $totalDicairkanCount = 0;
        $totalDicairkanAmount = 0;
        $totalHangusCount = 0;
        $globalNo = 0;
    @endphp

    @foreach($groupedItems as $program)
        <h3>Program: {{ $program['program_name'] }} @if($program['kode_kategori'])<span class="mono">[{{ $program['kode_kategori'] }}]</span>@endif</h3>

        @foreach($program['kegiatan'] as $keg)
            <div class="kegiatan-block">
                <div class="kegiatan-title">{{ $keg['nama_kegiatan'] }}</div>
                <div class="kegiatan-meta">Bulan: {{ $keg['bulan_label'] }}</div>

                @php $subtotal = 0; @endphp

                <table>
                    <thead>
                        <tr>
                            <th style="width:25px">No</th>
                            <th style="width:170px">Kode Anggaran</th>
                            <th>Mata Anggaran</th>
                            <th style="width:100px" class="text-right">Nominal (Rp)</th>
                            <th style="width:70px" class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($keg['anggaran'] as $ang)
                            @php
                                $globalNo++;
                                $subtotal += (float) $ang['nominal_anggaran'];
                                $grandTotal += (float) $ang['nominal_anggaran'];
                                $isDicairkan = $ang['status'] === 'dicairkan';
                                if ($isDicairkan) {
                                    $totalDicairkanCount++;
                                    $totalDicairkanAmount += (float) $ang['nominal_anggaran'];
                                } else {
                                    $totalHangusCount++;
                                }
                            @endphp
                            <tr>
                                <td class="text-center">{{ $globalNo }}</td>
                                <td class="mono">{{ $ang['kode_anggaran_baru'] ?? '-' }}</td>
                                <td>{{ $ang['mata_anggaran'] ?? '-' }}</td>
                                <td class="text-right mono">{{ number_format($ang['nominal_anggaran'], 0, ',', '.') }}</td>
                                <td class="text-center {{ $isDicairkan ? 'status-dicairkan' : 'status-hangus' }}">{{ $isDicairkan ? 'Dicairkan' : 'Hangus' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td colspan="3">Subtotal Kegiatan</td>
                            <td class="text-right mono">{{ number_format($subtotal, 0, ',', '.') }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endforeach
    @endforeach

    {{-- Summary --}}
    <h2>Ringkasan</h2>
    <table>
        <tr><th style="width:180px">Item</th><th>Nilai</th></tr>
        <tr><td>Total Anggaran</td><td class="mono">Rp {{ number_format($grandTotal, 0, ',', '.') }}</td></tr>
        <tr><td>Total Dicairkan</td><td>{{ $totalDicairkanCount }} item &mdash; <span class="mono">Rp {{ number_format($totalDicairkanAmount, 0, ',', '.') }}</span></td></tr>
        <tr><td>Total Hangus</td><td>{{ $totalHangusCount }} item</td></tr>
        <tr><td>Kode Verifikasi</td><td class="mono">{{ $pabd05->verification_code ?? '-' }}</td></tr>
    </table>

    {{-- ============================================= --}}
    {{-- SECTION: Bukti Transfer                       --}}
    {{-- ============================================= --}}

    <h2>Bukti Transfer</h2>
    @if($pabd05->buktiTransfer->isEmpty())
        <p style="font-size:8px;color:#999;">Tidak ada bukti transfer.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width:25px">No</th>
                    <th>Nama File</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pabd05->buktiTransfer as $btIdx => $bt)
                    <tr>
                        <td class="text-center">{{ $btIdx + 1 }}</td>
                        <td>{{ $bt->file?->original_filename ?? 'file tidak ditemukan' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- ============================================= --}}
    {{-- SECTION: Kronologis                           --}}
    {{-- ============================================= --}}

    <div class="chrono-section" style="page-break-before: always;">
        <h2 style="margin-top:0;">Kronologis Workflow</h2>

        @foreach($chronology as $entry)
            @if($entry['is_separator'] ?? false)
                @continue
            @endif

            <div class="chrono-entry">
                <div class="chrono-header">
                    <span class="chrono-time">{{ $entry['at'] }}</span>
                    &nbsp;&mdash;&nbsp;
                    {{ $entry['step'] }} {{ $entry['action_label'] }}
                </div>
                @if($entry['user'] !== 'System')
                    <div class="chrono-actor">
                        Oleh: {{ $entry['user'] }}
                        @if($entry['role'] !== '-')
                            ({{ $entry['role'] }}@if($entry['team'] !== '-') &mdash; {{ $entry['team'] }}@endif)
                        @endif
                    </div>
                @endif

                @if($entry['notes'])
                    <div class="chrono-notes">"{{ $entry['notes'] }}"</div>
                @endif

                @if(!empty($entry['files']))
                    <div class="chrono-files">
                        Lampiran:
                        @foreach($entry['files'] as $fname)
                            {{ $fname }}@if(!$loop->last), @endif
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- ============================================= --}}
    {{-- VERIFICATION BOX                              --}}
    {{-- ============================================= --}}

    @if($pabd05->verification_code)
    <div class="verification">
        <div style="font-size:8px;color:#666;margin-bottom:4px;">KODE VERIFIKASI</div>
        <code>{{ $pabd05->verification_code }}</code>
        <div style="font-size:7px;color:#999;margin-top:4px;">Verifikasi keaslian di: {{ url('/verify/' . $pabd05->verification_code) }}</div>
    </div>
    @endif
</body>
</html>
