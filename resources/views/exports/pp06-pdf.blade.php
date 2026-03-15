<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>PP06 Periode Tahunan {{ $pp06->tahun }} — Revisi {{ $pp06->revision }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.5; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #ccc; }
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
        .kode-grid { display: table; width: 100%; }
        .kode-col { display: table-cell; width: 50%; vertical-align: top; padding-right: 8px; }
        .kode-col:last-child { padding-right: 0; padding-left: 8px; }
        .kode-title { font-size: 9px; font-weight: bold; color: #666; margin-bottom: 4px; }
        .kode-item { font-size: 9px; margin-bottom: 1px; }
        .kode-item .kode { font-family: 'DejaVu Sans Mono', monospace; color: #666; font-size: 8px; }

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
        .diff-list { font-size: 8px; color: #444; margin: 4px 0 0 8px; }
        .diff-item { margin-bottom: 1px; }
        .revision-note { font-size: 8px; color: #333; font-weight: bold; margin-top: 4px; }
    </style>
</head>
<body>
    @php
        if (!function_exists('pp06AuthorLine')) {
            function pp06AuthorLine($pp06, $prefix) {
                $name = $pp06->{"{$prefix}_created_by_user_name"};
                $role = $pp06->{"{$prefix}_created_by_role_name"};
                $team = $pp06->{"{$prefix}_created_by_team_name"};
                $date = $pp06->{"{$prefix}_created_at"};

                $parts = $name;
                $roleParts = [];
                if ($role) { $roleParts[] = $role; }
                if ($team) { $roleParts[] = $team; }
                if (!empty($roleParts)) { $parts .= ' (' . implode(' — ', $roleParts) . ')'; }
                $parts .= ' — ' . ($date ? $date->format('d/m/Y') : '-');

                return $parts;
            }
        }
    @endphp

    <div class="footer">
        Kode Verifikasi: {{ $pp06->verification_code ?? '-' }} &bull; Dikompilasi: {{ $pp06->created_at->format('d/m/Y H:i') }} WIB
    </div>

    {{-- ============================================= --}}
    {{-- PART 1: DATA TERBARU (latest PP06 snapshot)   --}}
    {{-- ============================================= --}}

    <div class="header">
        <div class="org">{{ $pp06->pp01_created_by_organization_name ?? 'Finance Playground' }}</div>
        <h1>Periode Tahunan {{ $pp06->tahun }}</h1>
        <div class="subtitle">Revisi {{ $pp06->revision }} &bull; Dikompilasi {{ $pp06->created_at->format('d F Y, H:i') }} WIB</div>
    </div>

    <h2>Informasi Periode</h2>
    <table>
        <tr><th style="width:200px">Item</th><th>Nilai</th></tr>
        <tr><td>Tahun Periode</td><td>{{ $pp06->tahun }}</td></tr>
        <tr><td>Tanggal Mulai Pra-Raker</td><td>{{ $pp06->tanggal_mulai_pra_raker?->format('d F Y') ?? '-' }}</td></tr>
        <tr><td>Tanggal Penetapan Program</td><td>{{ $pp06->tanggal_penetapan_program?->format('d F Y') ?? '-' }}</td></tr>
    </table>
    <div class="author">Disusun oleh: {{ pp06AuthorLine($pp06, 'pp01') }}</div>

    <h2>Kode Referensi</h2>
    <div class="kode-grid">
        <div class="kode-col">
            <div class="kode-title">Bidang Pelayanan ({{ $pp06->kodeBidangPelayanan->count() }})</div>
            @foreach($pp06->kodeBidangPelayanan as $item)
                <div class="kode-item"><span class="kode">{{ $item->kode }}</span> — {{ $item->nama }}</div>
            @endforeach
            <div class="kode-title" style="margin-top:8px">Kategori Pelayanan ({{ $pp06->kodeKategoriPelayanan->count() }})</div>
            @foreach($pp06->kodeKategoriPelayanan as $item)
                <div class="kode-item"><span class="kode">{{ $item->kode }}</span> — {{ $item->nama }}</div>
            @endforeach
        </div>
        <div class="kode-col">
            <div class="kode-title">Sub Bidang Pelayanan ({{ $pp06->kodeSubBidangPelayanan->count() }})</div>
            @foreach($pp06->kodeSubBidangPelayanan as $item)
                <div class="kode-item"><span class="kode">{{ $item->kode }}</span> — {{ $item->nama }}</div>
            @endforeach
            <div class="kode-title" style="margin-top:8px">Jenis Program ({{ $pp06->kodeJenisProgram->count() }})</div>
            @foreach($pp06->kodeJenisProgram as $item)
                <div class="kode-item"><span class="kode">{{ $item->kode }}</span> — {{ $item->nama }}</div>
            @endforeach
        </div>
    </div>

    <h2>Pertanyaan Kuisioner</h2>
    <table>
        <thead>
            <tr>
                <th style="width:50px">Kode</th>
                <th>Pertanyaan</th>
                <th style="width:80px">Tipe</th>
                <th style="width:60px">Satuan</th>
            </tr>
        </thead>
        <tbody>
            @foreach($pp06->itemKuisioner as $item)
            <tr>
                <td class="mono">{{ $item->kode }}</td>
                <td>{{ $item->pertanyaan }}</td>
                <td>{{ $item->tipe }}</td>
                <td>{{ $item->satuan ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="author">Disusun oleh: {{ pp06AuthorLine($pp06, 'pp02') }}</div>

    <h2>Plafon Anggaran</h2>
    <table>
        <thead>
            <tr>
                <th style="width:50px">Kode</th>
                <th>Tim</th>
                <th style="width:100px" class="text-right">Plafon (Rp)</th>
                <th style="width:60px">Bank</th>
                <th style="width:80px">Nama Rek.</th>
                <th style="width:80px">No. Rek.</th>
            </tr>
        </thead>
        <tbody>
            @foreach($pp06->itemPlafonAnggaran as $item)
            <tr>
                <td class="mono">{{ $item->kode_team }}</td>
                <td>{{ $item->team?->name ?? '-' }}</td>
                <td class="text-right mono">{{ number_format($item->plafon_anggaran, 0, ',', '.') }}</td>
                <td>{{ $item->nama_bank }}</td>
                <td>{{ $item->nama_rekening }}</td>
                <td class="mono">{{ $item->nomor_rekening }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="2">Total Plafon</td>
                <td class="text-right mono">{{ number_format($totalPlafon, 0, ',', '.') }}</td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>
    <div class="author">Disusun oleh: {{ pp06AuthorLine($pp06, 'pp03') }}</div>

    <h2>Dokumen SOP</h2>
    @if($pp06->itemDokumenSop->isEmpty())
        <p>Tidak ada dokumen dilampirkan.</p>
    @else
        <table>
            <thead>
                <tr><th style="width:30px">No</th><th>Nama File</th></tr>
            </thead>
            <tbody>
                @foreach($pp06->itemDokumenSop as $i => $item)
                <tr>
                    <td class="text-center">{{ $i + 1 }}</td>
                    <td>{{ $item->file?->original_filename ?? 'File tidak ditemukan' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <div class="author">Disusun oleh: {{ pp06AuthorLine($pp06, 'pp04') }}</div>

    <h2>Persetujuan</h2>
    <p>Disetujui oleh: <strong>{{ pp06AuthorLine($pp06, 'pp05') }}</strong></p>

    @if($pp06->verification_code)
    <div class="verification">
        <div style="font-size:8px;color:#666;margin-bottom:4px;">KODE VERIFIKASI</div>
        <code>{{ $pp06->verification_code }}</code>
        <div style="font-size:7px;color:#999;margin-top:4px;">Verifikasi keaslian di: {{ url('/verify/' . $pp06->verification_code) }}</div>
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
                @if($entry['step_data'])
                    <div class="chrono-data">
                        @if($entry['step_data']['type'] === 'pp01')
                            <div class="chrono-kode-list"><span class="chrono-kode-label">Tahun:</span> {{ $entry['step_data']['tahun'] }}</div>
                            <div class="chrono-kode-list"><span class="chrono-kode-label">Tgl Mulai:</span> {{ $entry['step_data']['tanggal_mulai'] ?? '-' }}</div>
                            <div class="chrono-kode-list"><span class="chrono-kode-label">Tgl Penetapan:</span> {{ $entry['step_data']['tanggal_penetapan'] ?? '-' }}</div>
                            @if(!empty($entry['step_data']['bidang']))
                                <div class="chrono-kode-list"><span class="chrono-kode-label">Bidang:</span> {{ implode(', ', $entry['step_data']['bidang']) }}</div>
                            @endif
                            @if(!empty($entry['step_data']['sub_bidang']))
                                <div class="chrono-kode-list"><span class="chrono-kode-label">Sub Bidang:</span> {{ implode(', ', $entry['step_data']['sub_bidang']) }}</div>
                            @endif
                            @if(!empty($entry['step_data']['kategori']))
                                <div class="chrono-kode-list"><span class="chrono-kode-label">Kategori:</span> {{ implode(', ', $entry['step_data']['kategori']) }}</div>
                            @endif
                            @if(!empty($entry['step_data']['jenis']))
                                <div class="chrono-kode-list"><span class="chrono-kode-label">Jenis Program:</span> {{ implode(', ', $entry['step_data']['jenis']) }}</div>
                            @endif

                        @elseif($entry['step_data']['type'] === 'pp02')
                            <table>
                                <thead><tr><th>Kode</th><th>Pertanyaan</th><th>Tipe</th><th>Satuan</th></tr></thead>
                                <tbody>
                                    @foreach($entry['step_data']['items'] as $item)
                                    <tr>
                                        <td class="mono">{{ $item['kode'] }}</td>
                                        <td>{{ $item['pertanyaan'] }}</td>
                                        <td>{{ $item['tipe'] }}</td>
                                        <td>{{ $item['satuan'] ?? '—' }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>

                        @elseif($entry['step_data']['type'] === 'pp03')
                            <table>
                                <thead><tr><th>Kode</th><th>Tim</th><th class="text-right">Plafon (Rp)</th><th>Bank</th><th>Nama Rek.</th><th>No. Rek.</th></tr></thead>
                                <tbody>
                                    @foreach($entry['step_data']['items'] as $item)
                                    <tr>
                                        <td class="mono">{{ $item['kode_team'] }}</td>
                                        <td>{{ $item['team_name'] }}</td>
                                        <td class="text-right mono">{{ number_format($item['plafon'], 0, ',', '.') }}</td>
                                        <td>{{ $item['nama_bank'] }}</td>
                                        <td>{{ $item['nama_rekening'] }}</td>
                                        <td class="mono">{{ $item['nomor_rekening'] }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="total-row">
                                        <td colspan="2">Total</td>
                                        <td class="text-right mono">{{ number_format($entry['step_data']['total'], 0, ',', '.') }}</td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tfoot>
                            </table>

                        @elseif($entry['step_data']['type'] === 'pp04')
                            @if(!empty($entry['step_data']['files']))
                                @foreach($entry['step_data']['files'] as $i => $fname)
                                    <div class="chrono-kode-list">{{ $i + 1 }}. {{ $fname }}</div>
                                @endforeach
                            @else
                                <div class="chrono-kode-list">Tidak ada dokumen dilampirkan.</div>
                            @endif
                        @endif
                    </div>
                @endif

                {{-- Diff for PP07 submit --}}
                @if(!empty($entry['diff']))
                    <div class="diff-list">
                        <div style="font-weight:bold; margin-bottom:2px;">Perubahan dari revisi sebelumnya:</div>
                        @foreach($entry['diff'] as $change)
                            <div class="diff-item">&bull; {{ $change['description'] }}</div>
                        @endforeach
                    </div>
                @endif

                {{-- Revision compiled note --}}
                @if($entry['revision_marker'] !== null)
                    <div class="revision-note">&rarr; PP06 Rev {{ $entry['revision_marker'] }} dikompilasi</div>
                @endif
            </div>
        @endforeach
    </div>
</body>
</html>
