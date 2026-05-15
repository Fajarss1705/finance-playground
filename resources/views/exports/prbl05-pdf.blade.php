<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>PRBL05 Laporan Bulanan — {{ $teamName }} — {{ $bulanLabel }} {{ $tahun }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.5; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #ccc; }
        h3 { font-size: 10px; margin: 12px 0 4px; color: #333; }
        .brand-header { text-align: center; margin-bottom: 10px; }
        .brand-logo { width: 160px; height: auto; }
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
        .chrono-data { margin: 6px 0 0 0; }
        .chrono-data table { font-size: 8px; }
        .chrono-data th { font-size: 7px; padding: 2px 4px; }
        .chrono-data td { font-size: 8px; padding: 2px 4px; }
        .chrono-kode-list { font-size: 8px; color: #444; margin: 2px 0; }
        .chrono-kode-label { font-weight: bold; color: #666; }
        .revision-separator { text-align: center; font-size: 11px; font-weight: bold; color: #333; margin: 20px 0 16px; padding: 6px 0; border-top: 2px solid #666; border-bottom: 2px solid #666; background: #f5f5f5; }
        .revision-note { font-size: 8px; color: #333; font-weight: bold; margin-top: 4px; }

        /* Narasi styles */
        .narasi-label { font-weight: bold; font-size: 9px; color: #333; }
        .narasi-text { font-size: 9px; color: #444; padding-left: 8px; border-left: 2px solid #ddd; margin: 2px 0 6px; white-space: pre-line; }

        /* Image embed styles */
        .photo-grid { margin-top: 6px; }
        .photo-item { display: inline-block; vertical-align: top; margin: 4px 4px 4px 0; text-align: center; }
        .photo-item img { max-width: 200px; max-height: 160px; border: 1px solid #ccc; display: block; }
        .photo-item .photo-caption { font-size: 7px; color: #888; margin-top: 2px; max-width: 200px; word-break: break-all; }
        .photo-filename { font-size: 8px; color: #555; }
    </style>
</head>
<body>
    <div class="footer">
        Kode Verifikasi: {{ $prbl05->verification_code ?? '-' }} &bull; Dikompilasi: {{ $prbl05->created_at->format('d/m/Y H:i') }} WIB
    </div>

    {{-- ============================================= --}}
    {{-- HEADER                                        --}}
    {{-- ============================================= --}}

    @include('exports._pdf-header')

    {{-- ============================================= --}}
    {{-- SECTION 1: Informasi Umum                     --}}
    {{-- ============================================= --}}

    <h2>1. Informasi Umum</h2>
    <table>
        <tr><th style="width:180px">Item</th><th>Nilai</th></tr>
        <tr><td>Tim</td><td>{{ $teamName }}</td></tr>
        <tr><td>Bulan Laporan</td><td>{{ $bulanLabel }} {{ $tahun }}</td></tr>
        <tr><td>Referensi PP</td><td>{{ $ppLabel }}</td></tr>
        <tr><td>Tanggal Kompilasi</td><td>{{ $prbl05->created_at->format('d/m/Y H:i') }} WIB</td></tr>
        <tr><td>Kode Verifikasi</td><td class="mono">{{ $prbl05->verification_code ?? '-' }}</td></tr>
    </table>

    <div class="author">Laporan oleh: {{ $prbl05->prbl01_created_by_user_name ?? '-' }} ({{ $prbl05->prbl01_created_by_role_name ?? '-' }} &mdash; {{ $prbl05->prbl01_created_by_team_name ?? '-' }}) &mdash; {{ $prbl05->prbl01_created_at?->format('d/m/Y') ?? '-' }}</div>
    <div class="author">Review Narasi oleh: {{ $prbl05->prbl02a_approved_by_user_name ?? '-' }} ({{ $prbl05->prbl02a_approved_by_role_name ?? '-' }} &mdash; {{ $prbl05->prbl02a_approved_by_team_name ?? '-' }}) &mdash; {{ $prbl05->prbl02a_approved_at?->format('d/m/Y') ?? '-' }}</div>
    <div class="author">Review Anggaran oleh: {{ $prbl05->prbl02b_approved_by_user_name ?? '-' }} ({{ $prbl05->prbl02b_approved_by_role_name ?? '-' }} &mdash; {{ $prbl05->prbl02b_approved_by_team_name ?? '-' }}) &mdash; {{ $prbl05->prbl02b_approved_at?->format('d/m/Y') ?? '-' }}</div>
    <div class="author">Refund oleh: {{ $prbl05->prbl03_created_by_user_name ?? '-' }} ({{ $prbl05->prbl03_created_by_role_name ?? '-' }} &mdash; {{ $prbl05->prbl03_created_by_team_name ?? '-' }}) &mdash; {{ $prbl05->prbl03_created_at?->format('d/m/Y') ?? '-' }}</div>
    <div class="author">Disetujui oleh: {{ $prbl05->prbl04_approved_by_user_name ?? '-' }} ({{ $prbl05->prbl04_approved_by_role_name ?? '-' }} &mdash; {{ $prbl05->prbl04_approved_by_team_name ?? '-' }}) &mdash; {{ $prbl05->prbl04_approved_at?->format('d/m/Y') ?? '-' }}</div>

    {{-- ============================================= --}}
    {{-- SECTION 2: Laporan Kegiatan                   --}}
    {{-- ============================================= --}}

    <h2>2. Laporan Kegiatan</h2>

    @forelse($groupedKegiatan as $program)
        <h3>Program: {{ $program['nomer_program'] ? $program['nomer_program'] . ' — ' : '' }}{{ $program['nama_program'] }}</h3>

        @foreach($program['kegiatan'] as $kegiatan)
            <div class="kegiatan-block">
                <div class="kegiatan-title">Kegiatan {{ $kegiatan['nomer_kegiatan'] ?? '-' }}: {{ $kegiatan['nama_kegiatan'] }}</div>
                <div class="kegiatan-meta">
                    Bulan: {{ $bulanLabels[$kegiatan['bulan']] ?? '-' }}
                </div>

                {{-- Narasi --}}
                <div class="narasi-label">Masalah:</div>
                <div class="narasi-text">{{ $kegiatan['masalah'] ?? '-' }}</div>

                <div class="narasi-label">Langkah Penanganan:</div>
                <div class="narasi-text">{{ $kegiatan['langkah_penanganan'] ?? '-' }}</div>

                <div class="narasi-label">Harapan:</div>
                <div class="narasi-text">{{ $kegiatan['harapan'] ?? '-' }}</div>

                <div class="narasi-label">Catatan Tim:</div>
                <div class="narasi-text">{{ $kegiatan['catatan_tim'] ?? '-' }}</div>

                {{-- Kuisioner --}}
                @if(!empty($kegiatan['kuisioner']))
                    <h3>Kuisioner ({{ count($kegiatan['kuisioner']) }})</h3>
                    <table>
                        <thead>
                            <tr>
                                <th style="width:25px">#</th>
                                <th>Pertanyaan</th>
                                <th style="width:60px">Tipe</th>
                                <th style="width:50px">Satuan</th>
                                <th style="width:80px">Jawaban</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($kegiatan['kuisioner'] as $kIdx => $k)
                                <tr>
                                    <td class="text-center">{{ $kIdx + 1 }}</td>
                                    <td>{{ $k['pertanyaan'] }}</td>
                                    <td>{{ $k['tipe'] }}</td>
                                    <td>{{ $k['satuan'] ?? '—' }}</td>
                                    <td>{{ $k['jawaban'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                {{-- Foto Kegiatan --}}
                @if(!empty($kegiatan['foto_kegiatan']))
                    <div style="margin-top:6px;">
                        <strong style="font-size:9px;">Foto Kegiatan:</strong>
                        <div class="photo-grid">
                            @foreach($kegiatan['foto_kegiatan'] as $foto)
                                @if($foto['data_uri'])
                                    <div class="photo-item">
                                        <img src="{{ $foto['data_uri'] }}" alt="{{ $foto['filename'] }}">
                                        <div class="photo-caption">{{ $foto['filename'] }}</div>
                                    </div>
                                @else
                                    <div style="margin:2px 0;" class="photo-filename">&#128206; {{ $foto['filename'] }}</div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Nota Pengeluaran --}}
                @if(!empty($kegiatan['nota_pengeluaran']))
                    <div style="margin-top:6px;">
                        <strong style="font-size:9px;">Nota Pengeluaran:</strong>
                        <div class="photo-grid">
                            @foreach($kegiatan['nota_pengeluaran'] as $nota)
                                @if($nota['data_uri'])
                                    <div class="photo-item">
                                        <img src="{{ $nota['data_uri'] }}" alt="{{ $nota['filename'] }}">
                                        <div class="photo-caption">{{ $nota['filename'] }}</div>
                                    </div>
                                @else
                                    <div style="margin:2px 0;" class="photo-filename">&#128206; {{ $nota['filename'] }}</div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    @empty
        <p style="font-size:9px;color:#999;">Tidak ada data kegiatan.</p>
    @endforelse

    {{-- ============================================= --}}
    {{-- SECTION 3: Realisasi Anggaran                 --}}
    {{-- ============================================= --}}

    <h2 style="page-break-before: always;">3. Realisasi Anggaran</h2>

    @php $rowNum = 0; @endphp

    @forelse($groupedRealisasi['programs'] as $program)
        <h3>Program: {{ $program['nomer_program'] ? $program['nomer_program'] . ' — ' : '' }}{{ $program['nama_program'] }}</h3>

        @foreach($program['kegiatan'] as $kegiatan)
            <div style="font-size:9px;font-weight:bold;color:#333;margin:6px 0 2px;">
                Kegiatan {{ $kegiatan['nomer_kegiatan'] ?? '-' }}: {{ $kegiatan['nama_kegiatan'] }}
            </div>
            <table>
                <thead>
                    <tr>
                        <th style="width:25px">No</th>
                        <th style="width:100px">Kode Anggaran</th>
                        <th>Mata Anggaran</th>
                        <th style="width:85px" class="text-right">Dicairkan (Rp)</th>
                        <th style="width:85px" class="text-right">Realisasi (Rp)</th>
                        <th style="width:80px" class="text-right">Selisih (Rp)</th>
                        <th>Komentar</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($kegiatan['items'] as $item)
                        @php $rowNum++; @endphp
                        <tr>
                            <td class="text-center">{{ $rowNum }}</td>
                            <td class="mono">{{ $item['kode_anggaran'] ?? '-' }}</td>
                            <td>{{ $item['mata_anggaran'] }}</td>
                            <td class="text-right mono">{{ number_format($item['nominal_anggaran'], 0, ',', '.') }}</td>
                            <td class="text-right mono">{{ number_format($item['nominal_realisasi'], 0, ',', '.') }}</td>
                            <td class="text-right mono">{{ number_format($item['selisih'], 0, ',', '.') }}</td>
                            <td>{{ $item['komentar'] ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="3">Subtotal Kegiatan</td>
                        <td class="text-right mono">{{ number_format($kegiatan['subtotal_anggaran'], 0, ',', '.') }}</td>
                        <td class="text-right mono">{{ number_format($kegiatan['subtotal_realisasi'], 0, ',', '.') }}</td>
                        <td class="text-right mono">{{ number_format($kegiatan['subtotal_selisih'], 0, ',', '.') }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        @endforeach
    @empty
        <p style="font-size:9px;color:#999;">Tidak ada data realisasi.</p>
    @endforelse

    @if(!empty($groupedRealisasi['programs']))
        <table>
            <tfoot>
                <tr class="total-row" style="font-size:10px;">
                    <td colspan="3"><strong>GRAND TOTAL</strong></td>
                    <td class="text-right mono"><strong>{{ number_format($groupedRealisasi['total_anggaran'], 0, ',', '.') }}</strong></td>
                    <td class="text-right mono"><strong>{{ number_format($groupedRealisasi['total_realisasi'], 0, ',', '.') }}</strong></td>
                    <td class="text-right mono"><strong>{{ number_format($groupedRealisasi['total_refund'], 0, ',', '.') }}</strong></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        <div style="font-size:9px;margin-top:4px;">
            <strong>Total Dicairkan:</strong> Rp {{ number_format($groupedRealisasi['total_anggaran'], 0, ',', '.') }} &bull;
            <strong>Total Realisasi:</strong> Rp {{ number_format($groupedRealisasi['total_realisasi'], 0, ',', '.') }} &bull;
            <strong>Total Refund:</strong> Rp {{ number_format($groupedRealisasi['total_refund'], 0, ',', '.') }}
        </div>
    @endif

    {{-- ============================================= --}}
    {{-- SECTION 4: Refund & Bukti Transfer            --}}
    {{-- ============================================= --}}

    <h2>4. Refund &amp; Bukti Transfer</h2>

    <table>
        <tr><th style="width:180px">Item</th><th>Nilai</th></tr>
        <tr><td>Total Refund</td><td class="mono">Rp {{ number_format((float) ($prbl05->total_refund ?? 0), 0, ',', '.') }}</td></tr>
    </table>

    {{-- Rekening Organisasi --}}
    @if($prbl05->rekeningOrganisasi->isNotEmpty())
        <h3>Rekening Organisasi</h3>
        <table>
            <thead>
                <tr>
                    <th>Nama Bank</th>
                    <th>Nama Rekening</th>
                    <th>Nomor Rekening</th>
                </tr>
            </thead>
            <tbody>
                @foreach($prbl05->rekeningOrganisasi as $rek)
                    <tr>
                        <td>{{ $rek->nama_bank }}</td>
                        <td>{{ $rek->nama_rekening }}</td>
                        <td class="mono">{{ $rek->nomor_rekening }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Bukti Transfer --}}
    @php
        $buktiTransfer = $prbl05->bukti->where('tipe', 'bukti_transfer');
    @endphp

    @if($buktiTransfer->isNotEmpty())
        <h3>Bukti Transfer</h3>
        <div class="photo-grid">
            @foreach($buktiTransfer as $bt)
                @php $imgData = $buktiImages[$bt->id] ?? null; @endphp
                @if($imgData && $imgData['data_uri'])
                    <div class="photo-item">
                        <img src="{{ $imgData['data_uri'] }}" alt="{{ $imgData['filename'] }}">
                        <div class="photo-caption">{{ $imgData['filename'] }}</div>
                    </div>
                @else
                    <div style="margin:2px 0;" class="photo-filename">&#128206; {{ $imgData['filename'] ?? $bt->file?->original_filename ?? 'file' }}</div>
                @endif
            @endforeach
        </div>
    @endif

    @if($prbl05->keterangan)
        <h3>Keterangan</h3>
        <div class="narasi-text">{{ $prbl05->keterangan }}</div>
    @endif

    {{-- ============================================= --}}
    {{-- SECTION 5: Kronologis                         --}}
    {{-- ============================================= --}}

    <div class="chrono-section" style="page-break-before: always;">
        <h2 style="margin-top:0;">5. Kronologis Workflow</h2>

        @foreach($chronology as $entry)
            @if($entry['is_separator'] ?? false)
                <div class="revision-separator">REVISI {{ $entry['revision'] ?? '-' }}</div>
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
            </div>
        @endforeach
    </div>

    {{-- ============================================= --}}
    {{-- VERIFICATION BOX                              --}}
    {{-- ============================================= --}}

    @if($prbl05->verification_code)
    <div class="verification">
        <div style="font-size:8px;color:#666;margin-bottom:4px;">KODE VERIFIKASI</div>
        <code>{{ $prbl05->verification_code }}</code>
        <div style="font-size:7px;color:#999;margin-top:4px;">Verifikasi keaslian di: {{ url('/verify/' . $prbl05->verification_code) }}</div>
    </div>
    @endif
</body>
</html>
