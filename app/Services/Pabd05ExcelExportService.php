<?php

namespace App\Services;

use App\Models\File;
use App\Models\Pabd\Pabd05PengajuanBulanan;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Role;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Pabd05ExcelExportService
{
    /**
     * Generate an Excel file and return the temp file path.
     */
    public function generate(Pabd05PengajuanBulanan $pabd05): string
    {
        $pabd05->loadMissing([
            'itemAnggaran.pk04Anggaran.pk04Kegiatan.pk04ProgramTahunan',
            'buktiTransfer.file',
            'pabdWorkflow.team',
        ]);

        $workflow = $pabd05->pabdWorkflow;
        $teamName = $workflow?->team?->name ?? 'Unknown';
        $bulan = $this->resolveBulanLabel($workflow);
        $tahun = $workflow?->tahun_anggaran ?? now()->year;
        $ppLabel = $this->resolvePpLabel($workflow);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle("Pengajuan Anggaran Bulanan {$bulan} {$tahun} — {$teamName}")
            ->setCreator($pabd05->pabd01_created_by_organization_name ?? 'Finance Playground');

        $this->sheetInformasi($spreadsheet, $pabd05, $workflow, $teamName, $bulan, $tahun, $ppLabel);
        $this->sheetDaftarAnggaran($spreadsheet, $pabd05);
        $this->sheetBuktiTransfer($spreadsheet, $pabd05);
        $this->sheetRiwayat($spreadsheet, $workflow);

        $spreadsheet->setActiveSheetIndex(0);

        $tempPath = tempnam(sys_get_temp_dir(), 'pabd05_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return $tempPath;
    }

    private function sheetInformasi(
        Spreadsheet $spreadsheet,
        Pabd05PengajuanBulanan $pabd05,
        ?PabdWorkflow $workflow,
        string $teamName,
        string $bulan,
        int $tahun,
        ?string $ppLabel,
    ): void {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Informasi');

        $sheet->setCellValue('A1', "Pengajuan Anggaran Bulanan {$bulan} {$tahun} — {$teamName}");
        $sheet->mergeCells('A1:B1');
        $this->styleHeader($sheet, 'A1:B1');

        $rows = [
            ['Tim', $teamName],
            ['Bulan Anggaran', $bulan],
            ['Tahun', $tahun],
            ['Referensi PP', $ppLabel ?? '-'],
            ['Kode Verifikasi', $pabd05->verification_code ?? '-'],
            ['Dikompilasi', $pabd05->created_at->format('d/m/Y H:i').' WIB'],
        ];

        $row = 3;
        foreach ($rows as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $value);
            $row++;
        }

        // Author section
        $row += 1;
        $sheet->setCellValue("A{$row}", 'Disusun oleh');
        $this->styleSubHeader($sheet, "A{$row}:B{$row}");
        $row++;

        $authors = [
            ['PABD01 — Checklist', 'pabd01_created_by', 'pabd01_created_at'],
            ['PABD03 — Persetujuan', 'pabd03_approved_by', 'pabd03_approved_at'],
            ['PABD04 — Bukti Transfer', 'pabd04_created_by', 'pabd04_created_at'],
        ];

        foreach ($authors as [$stepLabel, $prefix, $dateField]) {
            $name = $pabd05->{"{$prefix}_user_name"} ?? '-';
            $roleParts = [];
            if ($pabd05->{"{$prefix}_role_name"}) {
                $roleParts[] = $pabd05->{"{$prefix}_role_name"};
            }
            if ($pabd05->{"{$prefix}_team_name"}) {
                $roleParts[] = $pabd05->{"{$prefix}_team_name"};
            }
            $authorDisplay = $name;
            if (! empty($roleParts)) {
                $authorDisplay .= ' ('.implode(' — ', $roleParts).')';
            }
            $authorDisplay .= ' — '.($pabd05->{$dateField}?->format('d/m/Y') ?? '-');

            $sheet->setCellValue("A{$row}", $stepLabel);
            $sheet->setCellValue("B{$row}", $authorDisplay);
            $row++;
        }

        // Bank info section
        $row += 1;
        $sheet->setCellValue("A{$row}", 'Informasi Rekening');
        $this->styleSubHeader($sheet, "A{$row}:B{$row}");
        $row++;

        $bankRows = [
            ['Nama Bank', $pabd05->nama_bank ?? '-'],
            ['Nama Rekening', $pabd05->nama_rekening ?? '-'],
            ['Nomor Rekening', $pabd05->nomor_rekening ?? '-'],
        ];

        foreach ($bankRows as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $value);
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(60);
    }

    private function sheetDaftarAnggaran(Spreadsheet $spreadsheet, Pabd05PengajuanBulanan $pabd05): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Daftar Anggaran');

        $bulanLabels = $this->bulanLabels();

        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        $headers = ['Kode Anggaran Baru', 'Kode Anggaran Lama', 'Program', 'Kegiatan', 'Bulan', 'Mata Anggaran', 'Nominal (Rp)', 'Status'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue("{$columns[$i]}1", $header);
        }
        $this->styleTableHeader($sheet, 'A1:H1');

        $row = 2;
        $totalDicairkan = 0;
        $totalHangus = 0;

        foreach ($pabd05->itemAnggaran as $item) {
            $pk04Anggaran = $item->pk04Anggaran;
            $kegiatan = $pk04Anggaran?->pk04Kegiatan;
            $program = $kegiatan?->pk04ProgramTahunan;

            $sheet->setCellValue("A{$row}", $pk04Anggaran?->kode_anggaran_baru ?? '-');
            $sheet->setCellValue("B{$row}", $pk04Anggaran?->kode_anggaran_lama ?? '-');
            $sheet->setCellValue("C{$row}", $program?->nama_program ?? '-');
            $sheet->setCellValue("D{$row}", $kegiatan?->nama_kegiatan ?? '-');
            $sheet->setCellValue("E{$row}", $bulanLabels[$kegiatan?->bulan ?? 0] ?? '-');
            $sheet->setCellValue("F{$row}", $pk04Anggaran?->mata_anggaran ?? '-');
            $sheet->setCellValue("G{$row}", (float) $item->nominal_anggaran);
            $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode('#,##0');

            $statusLabel = $item->status === 'dicairkan' ? 'Dicairkan' : 'Hangus';
            $sheet->setCellValue("H{$row}", $statusLabel);

            if ($item->status === 'dicairkan') {
                $totalDicairkan += (float) $item->nominal_anggaran;
            } else {
                $totalHangus += (float) $item->nominal_anggaran;
            }

            $this->styleBorderedRow($sheet, "A{$row}:H{$row}");
            $row++;
        }

        // Summary rows
        $sheet->setCellValue("A{$row}", 'Total Dicairkan');
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("G{$row}", (float) $totalDicairkan);
        $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
        $this->styleBorderedRow($sheet, "A{$row}:H{$row}");
        $row++;

        $sheet->setCellValue("A{$row}", 'Total Hangus');
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValue("G{$row}", (float) $totalHangus);
        $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
        $this->styleBorderedRow($sheet, "A{$row}:H{$row}");

        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(25);
        $sheet->getColumnDimension('D')->setWidth(25);
        $sheet->getColumnDimension('E')->setWidth(14);
        $sheet->getColumnDimension('F')->setWidth(22);
        $sheet->getColumnDimension('G')->setWidth(18);
        $sheet->getColumnDimension('H')->setWidth(14);
    }

    private function sheetBuktiTransfer(Spreadsheet $spreadsheet, Pabd05PengajuanBulanan $pabd05): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Bukti Transfer');

        $columns = ['A', 'B', 'C'];
        $headers = ['No', 'Nama File', 'Diunggah'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue("{$columns[$i]}1", $header);
        }
        $this->styleTableHeader($sheet, 'A1:C1');

        $row = 2;
        $number = 1;
        foreach ($pabd05->buktiTransfer as $bukti) {
            $file = $bukti->file;

            $sheet->setCellValue("A{$row}", $number);
            $sheet->setCellValue("B{$row}", $file?->original_filename ?? '-');
            $sheet->setCellValue("C{$row}", $file?->created_at?->format('d/m/Y H:i') ?? '-');

            $this->styleBorderedRow($sheet, "A{$row}:C{$row}");
            $row++;
            $number++;
        }

        if ($pabd05->buktiTransfer->isEmpty()) {
            $sheet->setCellValue('A2', 'Tidak ada bukti transfer.');
            $sheet->mergeCells('A2:C2');
        }

        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(20);
    }

    private function sheetRiwayat(Spreadsheet $spreadsheet, ?PabdWorkflow $workflow): void
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

            $this->styleBorderedRow($sheet, "A{$row}:G{$row}");
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
            'reset' => 'Direset',
            'skipped' => 'Dilewati',
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

    /**
     * Resolve PP reference label from workflow.
     */
    private function resolvePpLabel(?PabdWorkflow $workflow): ?string
    {
        $pp06 = $workflow?->ppWorkflow?->latestPp06();
        if (! $pp06) {
            return null;
        }

        $tahun = $workflow?->ppWorkflow?->latestPp01()?->tahun;

        return $tahun ? "PP-{$tahun} Revisi {$pp06->revision}" : null;
    }

    /**
     * Resolve bulan label from workflow.
     */
    private function resolveBulanLabel(?PabdWorkflow $workflow): string
    {
        $bulan = $workflow?->bulan_anggaran;

        return $this->bulanLabels()[$bulan ?? 0] ?? (string) ($bulan ?? '-');
    }

    /** @return array<int, string> */
    private function bulanLabels(): array
    {
        return [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
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

    private function styleBorderedRow(mixed $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
    }
}
