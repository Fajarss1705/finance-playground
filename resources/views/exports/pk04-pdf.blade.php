<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>PK04 Program Tahunan — {{ $teamName }} — Revisi {{ $pk04->revision }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.5; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #ccc; }
        h3 { font-size: 10px; margin: 12px 0 4px; color: #333; }
        .header { text-align: center; margin-bottom: 16px; border-bottom: 2px solid #333; padding-bottom: 10px; }
        .header .org { font-size: 14px; font-weight: bold; }
        .header .subtitle { font-size: 10px; color: #666; margin-top: 2px; }
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
        .chrono-data { margin: 6px 0 0 0; }
        .chrono-data table { font-size: 8px; }
        .chrono-data th { font-size: 7px; padding: 2px 4px; }
        .chrono-data td { font-size: 8px; padding: 2px 4px; }
        .chrono-kode-list { font-size: 8px; color: #444; margin: 2px 0; }
        .chrono-kode-label { font-weight: bold; color: #666; }
        .revision-separator { text-align: center; font-size: 11px; font-weight: bold; color: #333; margin: 20px 0 16px; padding: 6px 0; border-top: 2px solid #666; border-bottom: 2px solid #666; background: #f5f5f5; }
        .revision-note { font-size: 8px; color: #333; font-weight: bold; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="footer">
        Kode Verifikasi: {{ $pk04->verification_code ?? '-' }} &bull; Dikompilasi: {{ $pk04->created_at->format('d/m/Y H:i') }} WIB
    </div>

    {{-- ============================================= --}}
    {{-- PART 1: DATA PROGRAM TAHUNAN                  --}}
    {{-- ============================================= --}}

    <div class="header">
        <div class="org">{{ $pk04->pk01_created_by_organization_name ?? 'Finance Playground' }}</div>
        <h1>Program Tahunan {{ $tahun }}</h1>
        <div class="subtitle">{{ $teamName }} &bull; Revisi {{ $pk04->revision }} &bull; Dikompilasi {{ $pk04->created_at->format('d F Y, H:i') }} WIB</div>
    </div>

    <h2>Informasi Program</h2>
    <table>
        <tr><th style="width:180px">Item</th><th>Nilai</th></tr>
        <tr><td>Tim</td><td>{{ $teamName }}</td></tr>
        <tr><td>Nomer Program</td><td class="mono">{{ $pk04->nomer_program }}</td></tr>
        <tr><td>Kategori</td><td class="mono">{{ $pk04->kode_kategori }}@if(!empty($kodeRefMap['kategori'][$pk04->kode_kategori])) ({{ $kodeRefMap['kategori'][$pk04->kode_kategori] }})@endif</td></tr>
        <tr><td>Nama Program</td><td>{{ $pk04->nama_program }}</td></tr>
        <tr><td>Deskripsi Program</td><td>{{ $pk04->deskripsi_program ?? '-' }}</td></tr>
        <tr><td>Tujuan Program</td><td>{{ $pk04->tujuan_program ?? '-' }}</td></tr>
    </table>
    <div class="author">Disusun oleh: {{ $pk04->pk01_created_by_user_name }} ({{ $pk04->pk01_created_by_role_name ?? '-' }} — {{ $pk04->pk01_created_by_team_name ?? '-' }}) — {{ $pk04->pk01_created_at?->format('d/m/Y') ?? '-' }}</div>

    @php
        $totalAnggaran = 0;
    @endphp

    @foreach($pk04->kegiatan as $kegIdx => $kegiatan)
        <div class="kegiatan-block">
            <div class="kegiatan-title">Kegiatan {{ $kegiatan->nomer_kegiatan }}: {{ $kegiatan->nama_kegiatan }}</div>
            <div class="kegiatan-meta">
                Bulan: {{ $bulanLabels[$kegiatan->bulan] ?? '-' }}
            </div>

            {{-- Anggaran table --}}
            <h3>Anggaran ({{ $kegiatan->anggaran->count() }})</h3>
            @if($kegiatan->anggaran->isEmpty())
                <p style="font-size:8px;color:#999;">Tidak ada anggaran.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th style="width:25px">No</th>
                            <th>Mata Anggaran</th>
                            <th>Deskripsi</th>
                            <th style="width:90px" class="text-right">Nominal (Rp)</th>
                            <th>Bidang</th>
                            <th>Sub Bidang</th>
                            <th>Jenis</th>
                            <th style="width:170px">Kode Anggaran Baru</th>
                            <th style="width:100px">Kode Anggaran Lama</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($kegiatan->anggaran as $angIdx => $anggaran)
                            @php $totalAnggaran += (float) $anggaran->nominal_anggaran; @endphp
                            <tr>
                                <td class="text-center">{{ $angIdx + 1 }}</td>
                                <td>{{ $anggaran->mata_anggaran }}</td>
                                <td>{{ $anggaran->deskripsi_pk ?? '-' }}</td>
                                <td class="text-right mono">{{ number_format($anggaran->nominal_anggaran, 0, ',', '.') }}</td>
                                <td>{{ $anggaran->kode_bidang }}@if(!empty($kodeRefMap['bidang'][$anggaran->kode_bidang]))<br><span style="font-size:7px;color:#666;">{{ $kodeRefMap['bidang'][$anggaran->kode_bidang] }}</span>@endif</td>
                                <td>{{ $anggaran->kode_sub_bidang }}@if(!empty($kodeRefMap['subBidang'][$anggaran->kode_sub_bidang]))<br><span style="font-size:7px;color:#666;">{{ $kodeRefMap['subBidang'][$anggaran->kode_sub_bidang] }}</span>@endif</td>
                                <td>{{ $anggaran->kode_jenis }}@if(!empty($kodeRefMap['jenis'][$anggaran->kode_jenis]))<br><span style="font-size:7px;color:#666;">{{ $kodeRefMap['jenis'][$anggaran->kode_jenis] }}</span>@endif</td>
                                <td class="mono">{{ $anggaran->kode_anggaran_baru ?? '-' }}</td>
                                <td class="mono">{{ $anggaran->kode_anggaran_lama ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td colspan="3">Subtotal Kegiatan</td>
                            <td class="text-right mono">{{ number_format($kegiatan->anggaran->sum('nominal_anggaran'), 0, ',', '.') }}</td>
                            <td colspan="5"></td>
                        </tr>
                    </tfoot>
                </table>
            @endif

            {{-- Kuisioner table --}}
            @if($kegiatan->kuisioner->isNotEmpty())
                <h3>Kuisioner ({{ $kegiatan->kuisioner->count() }})</h3>
                <table>
                    <thead>
                        <tr>
                            <th style="width:60px">Kode</th>
                            <th>Pertanyaan</th>
                            <th style="width:70px">Tipe</th>
                            <th style="width:50px">Satuan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($kegiatan->kuisioner as $k)
                        <tr>
                            <td class="mono">{{ $k->kode_kuisioner ?? '-' }}</td>
                            <td>{{ $k->pertanyaan }}</td>
                            <td>{{ $k->tipe }}</td>
                            <td>{{ $k->satuan ?? '—' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach

    <h2>Ringkasan</h2>
    <table>
        <tr><th style="width:180px">Item</th><th>Nilai</th></tr>
        <tr><td>Total Kegiatan</td><td>{{ $pk04->kegiatan->count() }}</td></tr>
        <tr><td>Total Anggaran</td><td class="mono">Rp {{ number_format($totalAnggaran, 0, ',', '.') }}</td></tr>
        <tr><td>Kode Verifikasi</td><td class="mono">{{ $pk04->verification_code ?? '-' }}</td></tr>
    </table>

    @if($pk04->verification_code)
    <div class="verification">
        <div style="font-size:8px;color:#666;margin-bottom:4px;">KODE VERIFIKASI</div>
        <code>{{ $pk04->verification_code }}</code>
        <div style="font-size:7px;color:#999;margin-top:4px;">Verifikasi keaslian di: {{ url('/verify/' . $pk04->verification_code) }}</div>
    </div>
    @endif

    {{-- ============================================= --}}
    {{-- PART 2: KRONOLOGIS WORKFLOW                   --}}
    {{-- ============================================= --}}

    <div class="chrono-section" style="page-break-before: always;">
        <h2 style="margin-top:0;">Kronologis Workflow</h2>

        @foreach($chronology as $entry)
            @if($entry['is_separator'] ?? false)
                <div class="revision-separator">REVISI {{ $entry['revision'] }}</div>
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
                            ({{ $entry['role'] }}@if($entry['team'] !== '-') — {{ $entry['team'] }}@endif)
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

                {{-- Embedded step data --}}
                @if(!empty($entry['step_data']))
                    <div class="chrono-data">
                        @if($entry['step_data']['type'] === 'pk01')
                            <div class="chrono-kode-list"><span class="chrono-kode-label">Program:</span> {{ $entry['step_data']['nama_program'] }}</div>
                            <div class="chrono-kode-list"><span class="chrono-kode-label">Kategori:</span> {{ $entry['step_data']['kode_kategori'] ?? '-' }}</div>
                            @if($entry['step_data']['deskripsi_program'])
                                <div class="chrono-kode-list"><span class="chrono-kode-label">Deskripsi:</span> {{ $entry['step_data']['deskripsi_program'] }}</div>
                            @endif
                            @if($entry['step_data']['tujuan_program'])
                                <div class="chrono-kode-list"><span class="chrono-kode-label">Tujuan:</span> {{ $entry['step_data']['tujuan_program'] }}</div>
                            @endif
                            <table>
                                <thead>
                                    <tr>
                                        <th>No. Kegiatan</th>
                                        <th>Nama Kegiatan</th>
                                        <th class="text-center">Anggaran</th>
                                        <th class="text-right">Total (Rp)</th>
                                        <th class="text-center">Kuisioner</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($entry['step_data']['kegiatan'] as $keg)
                                    <tr>
                                        <td class="mono">{{ $keg['nomer'] }}</td>
                                        <td>{{ $keg['nama'] }}</td>
                                        <td class="text-center">{{ $keg['anggaran_count'] }} item</td>
                                        <td class="text-right mono">{{ number_format($keg['anggaran_total'], 0, ',', '.') }}</td>
                                        <td class="text-center">{{ $keg['kuisioner_count'] }} item</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="total-row">
                                        <td colspan="2">Total: {{ $entry['step_data']['total_kegiatan'] }} kegiatan, {{ $entry['step_data']['total_kuisioner'] }} kuisioner</td>
                                        <td></td>
                                        <td class="text-right mono">{{ number_format($entry['step_data']['total_anggaran'], 0, ',', '.') }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        @endif
                    </div>
                @endif

                {{-- Revision compiled note --}}
                @if(($entry['revision_marker'] ?? null) !== null)
                    <div class="revision-note">&rarr; PK04 Rev {{ $entry['revision_marker'] }} dikompilasi</div>
                @endif
            </div>
        @endforeach
    </div>
</body>
</html>
