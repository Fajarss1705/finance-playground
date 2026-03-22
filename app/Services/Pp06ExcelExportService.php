<?php

namespace App\Services;

use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Role;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Pp06ExcelExportService
{
    /**
     * Generate an Excel file and return the temp file path.
     */
    public function generate(Pp06PeriodeTahunan $pp06): string
    {
        $pp06->loadMissing([
            'kodeBidangPelayanan', 'kodeSubBidangPelayanan',
            'kodeKategoriPelayanan', 'kodeJenisProgram',
            'itemKuisioner', 'itemPlafonAnggaran.team', 'rekeningOrganisasi', 'itemDokumenSop.file',
            'ppWorkflow',
        ]);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle("PP06 Periode Tahunan {$pp06->tahun} — Rev {$pp06->revision}")
            ->setCreator($pp06->pp01_created_by_organization_name ?? 'Finance Playground');

        $this->sheetInformasi($spreadsheet, $pp06);
        $this->sheetKodeReferensi($spreadsheet, $pp06);
        $this->sheetKuisioner($spreadsheet, $pp06);
        $this->sheetPlafon($spreadsheet, $pp06);
        $this->sheetDokumen($spreadsheet, $pp06);
        $this->sheetRiwayat($spreadsheet, $pp06);

        $spreadsheet->setActiveSheetIndex(0);

        $tempPath = tempnam(sys_get_temp_dir(), 'pp06_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return $tempPath;
    }

    private function authorLine(Pp06PeriodeTahunan $pp06, string $prefix): string
    {
        $name = $pp06->{"{$prefix}_created_by_user_name"};
        $role = $pp06->{"{$prefix}_created_by_role_name"};
        $team = $pp06->{"{$prefix}_created_by_team_name"};
        $date = $pp06->{"{$prefix}_created_at"};

        $parts = $name;
        $roleParts = [];
        if ($role) {
            $roleParts[] = $role;
        }
        if ($team) {
            $roleParts[] = $team;
        }
        if (! empty($roleParts)) {
            $parts .= ' ('.implode(' — ', $roleParts).')';
        }
        $parts .= ' — '.($date?->format('d/m/Y') ?? '-');

        return $parts;
    }

    private function sheetInformasi(Spreadsheet $spreadsheet, Pp06PeriodeTahunan $pp06): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Informasi');

        $sheet->setCellValue('A1', 'Periode Tahunan '.$pp06->tahun.' — Revisi '.$pp06->revision);
        $sheet->mergeCells('A1:B1');
        $this->styleHeader($sheet, 'A1:B1');

        $rows = [
            ['Tahun Periode', $pp06->tahun],
            ['Tanggal Mulai Pra-Raker', $pp06->tanggal_mulai_pra_raker?->format('d/m/Y') ?? '-'],
            ['Tanggal Penetapan Program', $pp06->tanggal_penetapan_program?->format('d/m/Y') ?? '-'],
            ['Kode Verifikasi', $pp06->verification_code ?? '-'],
            ['Dikompilasi', $pp06->created_at->format('d/m/Y H:i').' WIB'],
        ];

        $row = 3;
        foreach ($rows as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $value);
            $row++;
        }

        $row += 1;
        $sheet->setCellValue("A{$row}", 'Persetujuan');
        $this->styleSubHeader($sheet, "A{$row}:B{$row}");
        $row++;

        $authors = [
            ['PP01 — Periode', 'pp01'],
            ['PP02 — Kuisioner', 'pp02'],
            ['PP03 — Plafon', 'pp03'],
            ['PP04 — Dokumen', 'pp04'],
            ['PP05 — Persetujuan', 'pp05'],
        ];

        foreach ($authors as [$step, $prefix]) {
            $sheet->setCellValue("A{$row}", $step);
            $sheet->setCellValue("B{$row}", $this->authorLine($pp06, $prefix));
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(60);
    }

    private function sheetKodeReferensi(Spreadsheet $spreadsheet, Pp06PeriodeTahunan $pp06): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Kode Referensi');

        $row = 1;
        $tables = [
            ['Bidang Pelayanan', $pp06->kodeBidangPelayanan],
            ['Sub Bidang Pelayanan', $pp06->kodeSubBidangPelayanan],
            ['Kategori Pelayanan', $pp06->kodeKategoriPelayanan],
            ['Jenis Program', $pp06->kodeJenisProgram],
        ];

        foreach ($tables as [$title, $items]) {
            $sheet->setCellValue("A{$row}", $title);
            $sheet->mergeCells("A{$row}:C{$row}");
            $this->styleSubHeader($sheet, "A{$row}:C{$row}");
            $row++;

            $sheet->setCellValue("A{$row}", 'Kode');
            $sheet->setCellValue("B{$row}", 'Nama');
            $sheet->setCellValue("C{$row}", 'Catatan');
            $this->styleTableHeader($sheet, "A{$row}:C{$row}");
            $row++;

            foreach ($items as $item) {
                $sheet->setCellValue("A{$row}", $item->kode);
                $sheet->setCellValue("B{$row}", $item->nama);
                $sheet->setCellValue("C{$row}", $item->catatan ?? '');
                $row++;
            }

            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(30);
    }

    private function sheetKuisioner(Spreadsheet $spreadsheet, Pp06PeriodeTahunan $pp06): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Kuisioner');

        $sheet->setCellValue('A1', 'Kode');
        $sheet->setCellValue('B1', 'Pertanyaan');
        $sheet->setCellValue('C1', 'Tipe');
        $sheet->setCellValue('D1', 'Satuan');
        $this->styleTableHeader($sheet, 'A1:D1');

        $row = 2;
        foreach ($pp06->itemKuisioner as $item) {
            $sheet->setCellValue("A{$row}", $item->kode);
            $sheet->setCellValue("B{$row}", $item->pertanyaan);
            $sheet->setCellValue("C{$row}", $item->tipe);
            $sheet->setCellValue("D{$row}", $item->satuan ?? '');
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(50);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(12);
    }

    private function sheetPlafon(Spreadsheet $spreadsheet, Pp06PeriodeTahunan $pp06): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Plafon');

        $columns = ['A', 'B', 'C', 'D', 'E', 'F'];
        $headers = ['Kode', 'Tim', 'Plafon (Rp)', 'Bank', 'Nama Rekening', 'No. Rekening'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue("{$columns[$i]}1", $header);
        }
        $this->styleTableHeader($sheet, 'A1:F1');

        $row = 2;
        foreach ($pp06->itemPlafonAnggaran as $item) {
            $sheet->setCellValue("A{$row}", $item->kode_team);
            $sheet->setCellValue("B{$row}", $item->team?->name ?? '-');
            $sheet->setCellValue("C{$row}", (int) $item->plafon_anggaran);
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('#,##0');
            $sheet->setCellValue("D{$row}", $item->nama_bank);
            $sheet->setCellValue("E{$row}", $item->nama_rekening);
            $sheet->setCellValue("F{$row}", $item->nomor_rekening);
            $row++;
        }

        $totalPlafon = $pp06->itemPlafonAnggaran->sum('plafon_anggaran');
        $sheet->setCellValue("A{$row}", 'Total');
        $sheet->mergeCells("A{$row}:B{$row}");
        $sheet->setCellValue("C{$row}", (int) $totalPlafon);
        $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true);

        if ($pp06->rekeningOrganisasi->isNotEmpty()) {
            $row += 2;
            $sheet->setCellValue("A{$row}", 'Rekening Organisasi untuk Refund');
            $sheet->mergeCells("A{$row}:C{$row}");
            $this->styleSubHeader($sheet, "A{$row}:C{$row}");
            $row++;

            $sheet->setCellValue("A{$row}", 'Bank');
            $sheet->setCellValue("B{$row}", 'Nama Rekening');
            $sheet->setCellValue("C{$row}", 'No. Rekening');
            $this->styleTableHeader($sheet, "A{$row}:C{$row}");
            $row++;

            foreach ($pp06->rekeningOrganisasi as $rek) {
                $sheet->setCellValue("A{$row}", $rek->nama_bank);
                $sheet->setCellValue("B{$row}", $rek->nama_rekening);
                $sheet->setCellValue("C{$row}", $rek->nomor_rekening);
                $row++;
            }
        }

        $sheet->getColumnDimension('A')->setWidth(10);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(12);
        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->getColumnDimension('F')->setWidth(18);
    }

    private function sheetDokumen(Spreadsheet $spreadsheet, Pp06PeriodeTahunan $pp06): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Dokumen');

        $sheet->setCellValue('A1', 'No');
        $sheet->setCellValue('B1', 'Nama File');
        $this->styleTableHeader($sheet, 'A1:B1');

        $row = 2;
        foreach ($pp06->itemDokumenSop as $i => $item) {
            $sheet->setCellValue("A{$row}", $i + 1);
            $sheet->setCellValue("B{$row}", $item->file?->original_filename ?? 'File tidak ditemukan');
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(50);
    }

    private function sheetRiwayat(Spreadsheet $spreadsheet, Pp06PeriodeTahunan $pp06): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Riwayat');

        $headers = ['Waktu', 'Step', 'Aksi', 'Oleh', 'Role', 'Tim', 'Catatan'];
        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue("{$columns[$i]}1", $header);
        }
        $this->styleTableHeader($sheet, 'A1:G1');

        $history = $pp06->ppWorkflow->history ?? [];
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
