<?php

namespace App\Services;

use App\Models\Prbl\Prbl05LaporanBulanan;
use App\Models\Prbl\PrblWorkflow;
use App\Models\Role;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Prbl05ExcelExportService
{
    /**
     * Generate an Excel file and return the temp file path.
     */
    public function generate(Prbl05LaporanBulanan $prbl05): string
    {
        $prbl05->loadMissing([
            'itemKegiatan.fotoKegiatan.file',
            'itemKegiatan.notaPengeluaran.file',
            'itemKegiatan.itemKuisioner.pk04Kuisioner',
            'itemKegiatan.pk04Kegiatan.pk04ProgramTahunan',
            'itemRealisasi.pk04Anggaran.pk04Kegiatan.pk04ProgramTahunan',
            'rekeningOrganisasi',
            'bukti.file',
            'prblWorkflow.team',
        ]);

        $workflow = $prbl05->prblWorkflow;
        $teamName = $workflow?->team?->name ?? 'Unknown';
        $tahun = $workflow->tahun_laporan ?? now()->year;
        $bulan = $workflow->bulan_laporan ?? 1;

        $bulanLabels = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        $bulanLabel = $bulanLabels[$bulan] ?? (string) $bulan;
        $ppReference = $this->resolvePpReference($workflow);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle("Laporan Bulanan {$bulanLabel} {$tahun} — {$teamName}")
            ->setCreator($prbl05->prbl01_created_by_organization_name ?? 'Finance Playground');

        $this->sheetInformasi($spreadsheet, $prbl05, $teamName, $tahun, $bulanLabel, $ppReference);
        $this->sheetLaporanKegiatan($spreadsheet, $prbl05, $bulanLabels);
        $this->sheetKuisioner($spreadsheet, $prbl05);
        $this->sheetRealisasi($spreadsheet, $prbl05);
        $this->sheetRefund($spreadsheet, $prbl05);
        $this->sheetRiwayat($spreadsheet, $workflow);

        $spreadsheet->setActiveSheetIndex(0);

        $tempPath = tempnam(sys_get_temp_dir(), 'prbl05_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return $tempPath;
    }

    private function sheetInformasi(Spreadsheet $spreadsheet, Prbl05LaporanBulanan $prbl05, string $teamName, int $tahun, string $bulanLabel, string $ppReference): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Informasi');

        $sheet->setCellValue('A1', "Laporan Bulanan {$bulanLabel} {$tahun} — {$teamName}");
        $sheet->mergeCells('A1:B1');
        $this->styleHeader($sheet, 'A1:B1');

        $rows = [
            ['Tim', $teamName],
            ['Bulan Laporan', $bulanLabel],
            ['Tahun', $tahun],
            ['Referensi PP', $ppReference],
            ['Kode Verifikasi', $prbl05->verification_code ?? '-'],
            ['Dikompilasi', $prbl05->created_at->format('d/m/Y H:i').' WIB'],
        ];

        $row = 3;
        foreach ($rows as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $value);
            $row++;
        }

        $row += 1;
        $sheet->setCellValue("A{$row}", 'Disusun oleh');
        $this->styleSubHeader($sheet, "A{$row}:B{$row}");
        $row++;

        $authorSteps = [
            ['PRBL01 — Laporan Kegiatan', 'prbl01_created_by', 'prbl01_created_at'],
            ['PRBL02A — Review Narasi', 'prbl02a_approved_by', 'prbl02a_approved_at'],
            ['PRBL02B — Review Anggaran', 'prbl02b_approved_by', 'prbl02b_approved_at'],
            ['PRBL03 — Refund', 'prbl03_created_by', 'prbl03_created_at'],
            ['PRBL04 — Persetujuan Final', 'prbl04_approved_by', 'prbl04_approved_at'],
        ];

        foreach ($authorSteps as [$stepLabel, $prefix, $dateField]) {
            $authorParts = $prbl05->{$prefix.'_user_name'} ?? '-';
            $roleParts = [];
            if ($prbl05->{$prefix.'_role_name'}) {
                $roleParts[] = $prbl05->{$prefix.'_role_name'};
            }
            if ($prbl05->{$prefix.'_team_name'}) {
                $roleParts[] = $prbl05->{$prefix.'_team_name'};
            }
            if (! empty($roleParts)) {
                $authorParts .= ' ('.implode(' — ', $roleParts).')';
            }
            $authorParts .= ' — '.($prbl05->{$dateField}?->format('d/m/Y') ?? '-');

            $sheet->setCellValue("A{$row}", $stepLabel);
            $sheet->setCellValue("B{$row}", $authorParts);
            $row++;
        }

        $row += 1;
        $sheet->setCellValue("A{$row}", 'Rekening Organisasi');
        $this->styleSubHeader($sheet, "A{$row}:B{$row}");
        $row++;

        foreach ($prbl05->rekeningOrganisasi as $rekening) {
            $sheet->setCellValue("A{$row}", 'Nama Bank');
            $sheet->setCellValue("B{$row}", $rekening->nama_bank ?? '-');
            $row++;
            $sheet->setCellValue("A{$row}", 'Nama Rekening');
            $sheet->setCellValue("B{$row}", $rekening->nama_rekening ?? '-');
            $row++;
            $sheet->setCellValue("A{$row}", 'Nomor Rekening');
            $sheet->setCellValue("B{$row}", $rekening->nomor_rekening ?? '-');
            $row++;
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(60);
    }

    private function sheetLaporanKegiatan(Spreadsheet $spreadsheet, Prbl05LaporanBulanan $prbl05, array $bulanLabels): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Laporan Kegiatan');

        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        $headers = ['Program', 'Kegiatan', 'Bulan', 'Masalah', 'Langkah Penanganan', 'Harapan', 'Catatan Tim'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue("{$columns[$i]}1", $header);
        }
        $this->styleTableHeader($sheet, 'A1:G1');

        $row = 2;
        foreach ($prbl05->itemKegiatan as $item) {
            $kegiatan = $item->pk04Kegiatan;
            $programName = $kegiatan?->pk04ProgramTahunan?->nama_program ?? '-';
            $kegiatanName = $kegiatan?->nama_kegiatan ?? '-';
            $bulanDisplay = $bulanLabels[$kegiatan?->bulan ?? 0] ?? '-';

            $sheet->setCellValue("A{$row}", $programName);
            $sheet->setCellValue("B{$row}", $kegiatanName);
            $sheet->setCellValue("C{$row}", $bulanDisplay);
            $sheet->setCellValue("D{$row}", $item->masalah ?? '-');
            $sheet->setCellValue("E{$row}", $item->langkah_penanganan ?? '-');
            $sheet->setCellValue("F{$row}", $item->harapan ?? '-');
            $sheet->setCellValue("G{$row}", $item->catatan_tim ?? '-');
            $row++;
        }

        if ($row === 2) {
            $sheet->setCellValue('A2', 'Tidak ada laporan kegiatan.');
            $sheet->mergeCells('A2:G2');
        }

        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(25);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(40);
        $sheet->getColumnDimension('E')->setWidth(40);
        $sheet->getColumnDimension('F')->setWidth(40);
        $sheet->getColumnDimension('G')->setWidth(40);
    }

    private function sheetKuisioner(Spreadsheet $spreadsheet, Prbl05LaporanBulanan $prbl05): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Kuisioner');

        $columns = ['A', 'B', 'C', 'D', 'E', 'F'];
        $headers = ['Program', 'Kegiatan', 'Pertanyaan', 'Tipe', 'Satuan', 'Jawaban'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue("{$columns[$i]}1", $header);
        }
        $this->styleTableHeader($sheet, 'A1:F1');

        $row = 2;
        foreach ($prbl05->itemKegiatan as $item) {
            if ($item->itemKuisioner->isEmpty()) {
                continue;
            }

            $kegiatan = $item->pk04Kegiatan;
            $programName = $kegiatan?->pk04ProgramTahunan?->nama_program ?? '-';
            $kegiatanName = $kegiatan?->nama_kegiatan ?? '-';

            $firstRow = $row;
            foreach ($item->itemKuisioner as $kuisioner) {
                $pk04Kuisioner = $kuisioner->pk04Kuisioner;

                $sheet->setCellValue("A{$row}", $programName);
                $sheet->setCellValue("B{$row}", $kegiatanName);
                $sheet->setCellValue("C{$row}", $pk04Kuisioner?->pertanyaan ?? '-');
                $sheet->setCellValue("D{$row}", $pk04Kuisioner?->tipe ?? '-');
                $sheet->setCellValue("E{$row}", $pk04Kuisioner?->satuan ?? '');
                $sheet->setCellValue("F{$row}", $kuisioner->jawaban ?? '-');
                $row++;
            }

            if ($row - $firstRow > 1) {
                foreach (['A', 'B'] as $col) {
                    $sheet->mergeCells("{$col}{$firstRow}:{$col}".($row - 1));
                    $sheet->getStyle("{$col}{$firstRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
                }
            }
        }

        if ($row === 2) {
            $sheet->setCellValue('A2', 'Tidak ada kuisioner.');
            $sheet->mergeCells('A2:F2');
        }

        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(25);
        $sheet->getColumnDimension('C')->setWidth(50);
        $sheet->getColumnDimension('D')->setWidth(12);
        $sheet->getColumnDimension('E')->setWidth(12);
        $sheet->getColumnDimension('F')->setWidth(30);
    }

    private function sheetRealisasi(Spreadsheet $spreadsheet, Prbl05LaporanBulanan $prbl05): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Realisasi');

        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];
        $headers = ['Kode Anggaran Baru', 'Kode Anggaran Lama', 'Program', 'Kegiatan', 'Mata Anggaran', 'Dicairkan (Rp)', 'Realisasi (Rp)', 'Selisih (Rp)', 'Komentar'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue("{$columns[$i]}1", $header);
        }
        $this->styleTableHeader($sheet, 'A1:I1');

        $row = 2;
        foreach ($prbl05->itemRealisasi as $item) {
            $anggaran = $item->pk04Anggaran;
            $kegiatan = $anggaran?->pk04Kegiatan;
            $program = $kegiatan?->pk04ProgramTahunan;

            $sheet->setCellValue("A{$row}", $anggaran?->kode_anggaran_baru ?? '-');
            $sheet->setCellValue("B{$row}", $anggaran?->kode_anggaran_lama ?? '-');
            $sheet->setCellValue("C{$row}", $program?->nama_program ?? '-');
            $sheet->setCellValue("D{$row}", $kegiatan?->nama_kegiatan ?? '-');
            $sheet->setCellValue("E{$row}", $anggaran?->mata_anggaran ?? '-');
            $sheet->setCellValue("F{$row}", (float) $item->nominal_anggaran);
            $sheet->setCellValue("G{$row}", (float) $item->nominal_realisasi);
            $sheet->setCellValue("H{$row}", (float) $item->selisih);
            $sheet->setCellValue("I{$row}", $item->komentar_realisasi ?? '-');

            $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("H{$row}")->getNumberFormat()->setFormatCode('#,##0');
            $row++;
        }

        if ($row === 2) {
            $sheet->setCellValue('A2', 'Tidak ada data realisasi.');
            $sheet->mergeCells('A2:I2');
        } else {
            // Summary row
            $sheet->setCellValue("A{$row}", 'Total');
            $sheet->mergeCells("A{$row}:E{$row}");
            $sheet->setCellValue("F{$row}", (float) $prbl05->total_anggaran_dicairkan);
            $sheet->setCellValue("G{$row}", (float) $prbl05->total_realisasi);
            $sheet->setCellValue("H{$row}", (float) $prbl05->total_refund);
            $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("H{$row}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle("A{$row}:I{$row}")->getFont()->setBold(true);
        }

        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(25);
        $sheet->getColumnDimension('D')->setWidth(25);
        $sheet->getColumnDimension('E')->setWidth(22);
        $sheet->getColumnDimension('F')->setWidth(18);
        $sheet->getColumnDimension('G')->setWidth(18);
        $sheet->getColumnDimension('H')->setWidth(18);
        $sheet->getColumnDimension('I')->setWidth(30);
    }

    private function sheetRefund(Spreadsheet $spreadsheet, Prbl05LaporanBulanan $prbl05): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Refund');

        $sheet->setCellValue('A1', 'Refund');
        $sheet->mergeCells('A1:B1');
        $this->styleHeader($sheet, 'A1:B1');

        $sheet->setCellValue('A3', 'Total Refund');
        $sheet->setCellValue('B3', (float) $prbl05->total_refund);
        $sheet->getStyle('B3')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('A3:B3')->getFont()->setBold(true);

        // Rekening Organisasi
        $row = 5;
        $sheet->setCellValue("A{$row}", 'Rekening Organisasi');
        $this->styleSubHeader($sheet, "A{$row}:C{$row}");
        $row++;

        $rekeningHeaders = ['Nama Bank', 'Nama Rekening', 'Nomor Rekening'];
        foreach ($rekeningHeaders as $i => $header) {
            $col = chr(65 + $i);
            $sheet->setCellValue("{$col}{$row}", $header);
        }
        $this->styleTableHeader($sheet, "A{$row}:C{$row}");
        $row++;

        foreach ($prbl05->rekeningOrganisasi as $rekening) {
            $sheet->setCellValue("A{$row}", $rekening->nama_bank ?? '-');
            $sheet->setCellValue("B{$row}", $rekening->nama_rekening ?? '-');
            $sheet->setCellValue("C{$row}", $rekening->nomor_rekening ?? '-');
            $row++;
        }

        if ($prbl05->rekeningOrganisasi->isEmpty()) {
            $sheet->setCellValue("A{$row}", 'Tidak ada rekening organisasi.');
            $sheet->mergeCells("A{$row}:C{$row}");
            $row++;
        }

        // Bukti Transfer
        $row += 1;
        $sheet->setCellValue("A{$row}", 'Bukti Transfer');
        $this->styleSubHeader($sheet, "A{$row}:C{$row}");
        $row++;

        $buktiTransfer = $prbl05->bukti->where('tipe', 'bukti_transfer');

        $buktiHeaders = ['No', 'Nama File', 'Tipe'];
        foreach ($buktiHeaders as $i => $header) {
            $col = chr(65 + $i);
            $sheet->setCellValue("{$col}{$row}", $header);
        }
        $this->styleTableHeader($sheet, "A{$row}:C{$row}");
        $row++;

        $no = 1;
        foreach ($buktiTransfer as $bukti) {
            $sheet->setCellValue("A{$row}", $no);
            $sheet->setCellValue("B{$row}", $bukti->file?->original_filename ?? '-');
            $sheet->setCellValue("C{$row}", $bukti->file?->mime_type ?? '-');
            $row++;
            $no++;
        }

        if ($buktiTransfer->isEmpty()) {
            $sheet->setCellValue("A{$row}", 'Tidak ada bukti transfer.');
            $sheet->mergeCells("A{$row}:C{$row}");
            $row++;
        }

        // Keterangan
        $row += 1;
        $sheet->setCellValue("A{$row}", 'Keterangan');
        $this->styleSubHeader($sheet, "A{$row}:B{$row}");
        $row++;
        $sheet->setCellValue("A{$row}", $prbl05->keterangan ?? '-');
        $sheet->mergeCells("A{$row}:C{$row}");

        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(25);
    }

    private function sheetRiwayat(Spreadsheet $spreadsheet, ?PrblWorkflow $workflow): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Riwayat');

        $headers = ['Waktu', 'Step', 'Aksi', 'Pengguna', 'Peran', 'Tim', 'Catatan'];
        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue("{$columns[$i]}1", $header);
        }
        $this->styleTableHeader($sheet, 'A1:G1');

        $history = $workflow?->history ?? [];
        $entries = $this->resolveHistory($history);

        $row = 2;
        foreach ($entries as $entry) {
            $sheet->setCellValue("A{$row}", $entry['at']);
            $sheet->setCellValue("B{$row}", $entry['step']);
            $sheet->setCellValue("C{$row}", $entry['action']);
            $sheet->setCellValue("D{$row}", $entry['user']);
            $sheet->setCellValue("E{$row}", $entry['role']);
            $sheet->setCellValue("F{$row}", $entry['team']);
            $sheet->setCellValue("G{$row}", $entry['notes'] ?? '');
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(10);
        $sheet->getColumnDimension('C')->setWidth(16);
        $sheet->getColumnDimension('D')->setWidth(22);
        $sheet->getColumnDimension('E')->setWidth(22);
        $sheet->getColumnDimension('F')->setWidth(22);
        $sheet->getColumnDimension('G')->setWidth(40);
    }

    /**
     * Resolve history entries into display-ready data.
     *
     * @param  list<array<string, mixed>>  $history
     * @return list<array{step: string, action: string, user: string, role: string, team: string, at: string, notes: string|null}>
     */
    private function resolveHistory(array $history): array
    {
        $userIds = collect($history)->pluck('by')->filter()->unique()->all();
        $roleIds = collect($history)->pluck('role')->filter()->unique()->all();

        $users = User::whereIn('id', $userIds)->pluck('name', 'id');
        $roles = Role::withTrashed()->whereIn('id', $roleIds)->with('team')->get()->keyBy('id');

        $actionLabels = [
            'created' => 'Dibuat',
            'drafted' => 'Draft disimpan',
            'submitted' => 'Disubmit',
            'approved' => 'Disetujui',
            'rejected' => 'Ditolak',
            'terminated' => 'Dibatalkan',
            'commented' => 'Komentar',
            'completed' => 'Selesai',
        ];

        return collect($history)->map(function ($entry) use ($users, $roles, $actionLabels) {
            $userId = $entry['by'] ?? null;
            $roleId = $entry['role'] ?? null;
            $role = $roleId ? $roles->get($roleId) : null;

            return [
                'step' => $entry['step'] ?? '-',
                'action' => $actionLabels[$entry['action'] ?? ''] ?? ($entry['action'] ?? '-'),
                'user' => $userId ? ($users[$userId] ?? 'Unknown') : 'System',
                'role' => $role?->name ?? '-',
                'team' => $role?->team?->name ?? '-',
                'at' => isset($entry['at']) ? \Carbon\Carbon::parse($entry['at'])->format('d/m/Y H:i') : '-',
                'notes' => $entry['notes'] ?? null,
            ];
        })->all();
    }

    private function resolvePpReference(?PrblWorkflow $workflow): string
    {
        $pp06 = $workflow?->ppWorkflow?->latestPp06();
        if (! $pp06) {
            return '-';
        }

        $pp01 = $workflow?->ppWorkflow?->latestPp01();
        $tahun = $pp01?->tahun ?? now()->year;

        return "PP-{$tahun} Revisi {$pp06->revision}";
    }

    private function styleHeader(mixed $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 14],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
    }

    private function styleSubHeader(mixed $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8E8E8']],
        ]);
    }

    private function styleTableHeader(mixed $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 9],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F0F0']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }
}
