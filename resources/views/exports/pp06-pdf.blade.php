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
        .meta { display: flex; margin-bottom: 8px; }
        .meta-item { margin-right: 24px; }
        .meta-label { color: #666; }
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
    </style>
</head>
<body>
    <div class="footer">
        Kode Verifikasi: {{ $pp06->verification_code ?? '-' }} &bull; Dikompilasi: {{ $pp06->created_at->format('d/m/Y H:i') }} WIB
    </div>

    <div class="header">
        <div class="org">{{ $pp06->pp01_created_by_organization_name ?? 'Finance Playground' }}</div>
        <h1>Periode Tahunan {{ $pp06->tahun }}</h1>
        <div class="subtitle">Revisi {{ $pp06->revision }} &bull; Dikompilasi {{ $pp06->created_at->format('d F Y, H:i') }} WIB</div>
    </div>

    {{-- Informasi Periode --}}
    <h2>Informasi Periode</h2>
    <table>
        <tr>
            <th style="width:200px">Item</th>
            <th>Nilai</th>
        </tr>
        <tr>
            <td>Tahun Periode</td>
            <td>{{ $pp06->tahun }}</td>
        </tr>
        <tr>
            <td>Tanggal Mulai Pra-Raker</td>
            <td>{{ $pp06->tanggal_mulai_pra_raker?->format('d F Y') ?? '-' }}</td>
        </tr>
        <tr>
            <td>Tanggal Penetapan Program</td>
            <td>{{ $pp06->tanggal_penetapan_program?->format('d F Y') ?? '-' }}</td>
        </tr>
    </table>
    <div class="author">Disusun oleh: {{ $pp06->pp01_created_by_user_name }} ({{ $pp06->pp01_created_by_role_name }}) — {{ $pp06->pp01_created_at?->format('d/m/Y') }}</div>

    {{-- Kode Referensi --}}
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

    {{-- Kuisioner --}}
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
    <div class="author">Disusun oleh: {{ $pp06->pp02_created_by_user_name }} ({{ $pp06->pp02_created_by_role_name }}) — {{ $pp06->pp02_created_at?->format('d/m/Y') }}</div>

    {{-- Plafon Anggaran --}}
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
    <div class="author">Disusun oleh: {{ $pp06->pp03_created_by_user_name }} ({{ $pp06->pp03_created_by_role_name }}) — {{ $pp06->pp03_created_at?->format('d/m/Y') }}</div>

    {{-- Dokumen SOP --}}
    <h2>Dokumen SOP</h2>
    @if($pp06->itemDokumenSop->isEmpty())
        <p>Tidak ada dokumen dilampirkan.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width:30px">No</th>
                    <th>Nama File</th>
                </tr>
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
    <div class="author">Disusun oleh: {{ $pp06->pp04_created_by_user_name }} ({{ $pp06->pp04_created_by_role_name }}) — {{ $pp06->pp04_created_at?->format('d/m/Y') }}</div>

    {{-- Persetujuan --}}
    <h2>Persetujuan</h2>
    <p>Disetujui oleh: <strong>{{ $pp06->pp05_created_by_user_name }}</strong> ({{ $pp06->pp05_created_by_role_name }}) — {{ $pp06->pp05_created_at?->format('d F Y') }}</p>

    {{-- Verification Code --}}
    @if($pp06->verification_code)
    <div class="verification">
        <div style="font-size:8px;color:#666;margin-bottom:4px;">KODE VERIFIKASI</div>
        <code>{{ $pp06->verification_code }}</code>
        <div style="font-size:7px;color:#999;margin-top:4px;">Verifikasi keaslian di: {{ url('/verify/' . $pp06->verification_code) }}</div>
    </div>
    @endif
</body>
</html>
