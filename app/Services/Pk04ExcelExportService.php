<?php

namespace App\Services;

use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Pk\PkWorkflow;
use App\Models\Role;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Pk04ExcelExportService
{
    /** @var array{bidang: array<string, string>, subBidang: array<string, string>, jenis: array<string, string>, kategori: array<string, string>} */
    private array $kodeRefMap = ['bidang' => [], 'subBidang' => [], 'jenis' => [], 'kategori' => []];

    /**
     * Generate an Excel file and return the temp file path.
     */
    public function generate(Pk04ProgramTahunan $pk04): string
    {
        $pk04->loadMissing(['kegiatan.anggaran', 'kegiatan.kuisioner', 'pkWorkflow.team']);

        $workflow = $pk04->pkWorkflow;
        $teamName = $workflow?->team?->name ?? 'Unknown';
        $tahun = $this->resolveTahun($workflow);
        $this->kodeRefMap = $this->loadKodeRefMap($workflow);

        $bulanLabels = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle("PK04 Program Tahunan {$tahun} — {$teamName} — Rev {$pk04->revision}")
            ->setCreator($pk04->pk01_created_by_organization_name ?? 'Finance Playground');

        $this->sheetInformasi($spreadsheet, $pk04, $teamName, $tahun);
        $this->sheetKegiatan($spreadsheet, $pk04, $bulanLabels);
        $this->sheetKuisioner($spreadsheet, $pk04);
        $this->sheetRingkasan($spreadsheet, $pk04, $teamName, $tahun);
        $this->sheetRiwayat($spreadsheet, $workflow);

        $spreadsheet->setActiveSheetIndex(0);

        $tempPath = tempnam(sys_get_temp_dir(), 'pk04_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return $tempPath;
    }

    private function sheetInformasi(Spreadsheet $spreadsheet, Pk04ProgramTahunan $pk04, string $teamName, int $tahun): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Informasi');

        $sheet->setCellValue('A1', "Program Tahunan {$tahun} — {$teamName} — Revisi {$pk04->revision}");
        $sheet->mergeCells('A1:B1');
        $this->styleHeader($sheet, 'A1:B1');

        $rows = [
            ['Tim', $teamName],
            ['Nomer Program', $pk04->nomer_program],
            ['Kategori', $pk04->kode_kategori.($this->kodeRefMap['kategori'][$pk04->kode_kategori] ?? '' ? ' ('.$this->kodeRefMap['kategori'][$pk04->kode_kategori].')' : '')],
            ['Nama Program', $pk04->nama_program],
            ['Deskripsi Program', $pk04->deskripsi_program ?? '-'],
            ['Tujuan Program', $pk04->tujuan_program ?? '-'],
            ['Kode Verifikasi', $pk04->verification_code ?? '-'],
            ['Dikompilasi', $pk04->created_at->format('d/m/Y H:i').' WIB'],
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

        $authorParts = $pk04->pk01_created_by_user_name;
        $roleParts = [];
        if ($pk04->pk01_created_by_role_name) {
            $roleParts[] = $pk04->pk01_created_by_role_name;
        }
        if ($pk04->pk01_created_by_team_name) {
            $roleParts[] = $pk04->pk01_created_by_team_name;
        }
        if (! empty($roleParts)) {
            $authorParts .= ' ('.implode(' — ', $roleParts).')';
        }
        $authorParts .= ' — '.($pk04->pk01_created_at?->format('d/m/Y') ?? '-');

        $sheet->setCellValue("A{$row}", 'PK01 — Program Kegiatan');
        $sheet->setCellValue("B{$row}", $authorParts);

        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(60);
    }

    private function sheetKegiatan(Spreadsheet $spreadsheet, Pk04ProgramTahunan $pk04, array $bulanLabels): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Kegiatan & Anggaran');

        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'];
        $headers = ['No. Kegiatan', 'Nama Kegiatan', 'Bulan', 'Mata Anggaran', 'Deskripsi', 'Nominal (Rp)', 'Bidang', 'Sub Bidang', 'Jenis', 'Kode Anggaran Baru', 'Kode Anggaran Lama'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue("{$columns[$i]}1", $header);
        }
        $this->styleTableHeader($sheet, 'A1:K1');

        $row = 2;
        foreach ($pk04->kegiatan as $kegiatan) {
            $bulanDisplay = $bulanLabels[$kegiatan->bulan] ?? '-';

            if ($kegiatan->anggaran->isEmpty()) {
                $sheet->setCellValue("A{$row}", $kegiatan->nomer_kegiatan);
                $sheet->setCellValue("B{$row}", $kegiatan->nama_kegiatan);
                $sheet->setCellValue("C{$row}", $bulanDisplay);
                $sheet->setCellValue("D{$row}", '-');
                $row++;
            } else {
                $firstRow = $row;
                foreach ($kegiatan->anggaran as $anggaran) {
                    $sheet->setCellValue("A{$row}", $kegiatan->nomer_kegiatan);
                    $sheet->setCellValue("B{$row}", $kegiatan->nama_kegiatan);
                    $sheet->setCellValue("C{$row}", $bulanDisplay);
                    $sheet->setCellValue("D{$row}", $anggaran->mata_anggaran);
                    $sheet->setCellValue("E{$row}", $anggaran->deskripsi_pk ?? '-');
                    $sheet->setCellValue("F{$row}", (float) $anggaran->nominal_anggaran);
                    $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode('#,##0');

                    $bidangNama = $this->kodeRefMap['bidang'][$anggaran->kode_bidang] ?? '';
                    $subBidangNama = $this->kodeRefMap['subBidang'][$anggaran->kode_sub_bidang] ?? '';
                    $jenisNama = $this->kodeRefMap['jenis'][$anggaran->kode_jenis] ?? '';
                    $sheet->setCellValue("G{$row}", $anggaran->kode_bidang.($bidangNama ? " ({$bidangNama})" : ''));
                    $sheet->setCellValue("H{$row}", $anggaran->kode_sub_bidang.($subBidangNama ? " ({$subBidangNama})" : ''));
                    $sheet->setCellValue("I{$row}", $anggaran->kode_jenis.($jenisNama ? " ({$jenisNama})" : ''));

                    $sheet->setCellValue("J{$row}", $anggaran->kode_anggaran_baru ?? '-');
                    $sheet->setCellValue("K{$row}", $anggaran->kode_anggaran_lama ?? '-');
                    $row++;
                }

                // Merge kegiatan cells if multiple anggaran
                if ($row - $firstRow > 1) {
                    foreach (['A', 'B', 'C'] as $col) {
                        $sheet->mergeCells("{$col}{$firstRow}:{$col}".($row - 1));
                        $sheet->getStyle("{$col}{$firstRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
                    }
                }
            }
        }

        // Total row
        $totalAnggaran = $pk04->kegiatan->flatMap->anggaran->sum('nominal_anggaran');
        $sheet->setCellValue("A{$row}", 'Total');
        $sheet->mergeCells("A{$row}:E{$row}");
        $sheet->setCellValue("F{$row}", (float) $totalAnggaran);
        $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("A{$row}:K{$row}")->getFont()->setBold(true);

        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(22);
        $sheet->getColumnDimension('E')->setWidth(25);
        $sheet->getColumnDimension('F')->setWidth(18);
        $sheet->getColumnDimension('G')->setWidth(22);
        $sheet->getColumnDimension('H')->setWidth(22);
        $sheet->getColumnDimension('I')->setWidth(18);
        $sheet->getColumnDimension('J')->setWidth(38);
        $sheet->getColumnDimension('K')->setWidth(22);
    }

    private function sheetKuisioner(Spreadsheet $spreadsheet, Pk04ProgramTahunan $pk04): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Kuisioner');

        $headers = ['No. Kegiatan', 'Nama Kegiatan', 'Kode', 'Pertanyaan', 'Tipe', 'Satuan'];
        $columns = ['A', 'B', 'C', 'D', 'E', 'F'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue("{$columns[$i]}1", $header);
        }
        $this->styleTableHeader($sheet, 'A1:F1');

        $row = 2;
        foreach ($pk04->kegiatan as $kegiatan) {
            if ($kegiatan->kuisioner->isEmpty()) {
                continue;
            }

            $firstRow = $row;
            foreach ($kegiatan->kuisioner as $k) {
                $sheet->setCellValue("A{$row}", $kegiatan->nomer_kegiatan);
                $sheet->setCellValue("B{$row}", $kegiatan->nama_kegiatan);
                $sheet->setCellValue("C{$row}", $k->kode_kuisioner ?? '-');
                $sheet->setCellValue("D{$row}", $k->pertanyaan);
                $sheet->setCellValue("E{$row}", $k->tipe);
                $sheet->setCellValue("F{$row}", $k->satuan ?? '');
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

        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(50);
        $sheet->getColumnDimension('E')->setWidth(12);
        $sheet->getColumnDimension('F')->setWidth(12);
    }

    private function sheetRingkasan(Spreadsheet $spreadsheet, Pk04ProgramTahunan $pk04, string $teamName, int $tahun): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Ringkasan');

        $sheet->setCellValue('A1', "Ringkasan — {$teamName}");
        $sheet->mergeCells('A1:B1');
        $this->styleHeader($sheet, 'A1:B1');

        $totalAnggaran = $pk04->kegiatan->flatMap->anggaran->sum('nominal_anggaran');
        $totalKuisioner = $pk04->kegiatan->flatMap->kuisioner->count();

        $rows = [
            ['Tahun', $tahun],
            ['Tim', $teamName],
            ['Revisi', $pk04->revision],
            ['Nama Program', $pk04->nama_program],
            ['Total Kegiatan', $pk04->kegiatan->count()],
            ['Total Anggaran', 'Rp '.number_format($totalAnggaran, 0, ',', '.')],
            ['Total Kuisioner', $totalKuisioner],
            ['Kode Verifikasi', $pk04->verification_code ?? '-'],
        ];

        $row = 3;
        foreach ($rows as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $value);
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(50);
    }

    private function sheetRiwayat(Spreadsheet $spreadsheet, ?PkWorkflow $workflow): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Riwayat');

        $headers = ['Waktu', 'Step', 'Aksi', 'Oleh', 'Role', 'Tim', 'Catatan'];
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

    /**
     * @return array{bidang: array<string, string>, subBidang: array<string, string>, jenis: array<string, string>, kategori: array<string, string>}
     */
    private function loadKodeRefMap(?PkWorkflow $workflow): array
    {
        $empty = ['bidang' => [], 'subBidang' => [], 'jenis' => [], 'kategori' => []];
        $pp06 = $workflow?->ppWorkflow?->latestPp06();
        if (! $pp06) {
            return $empty;
        }

        return [
            'bidang' => $pp06->kodeBidangPelayanan()->pluck('nama', 'kode')->toArray(),
            'subBidang' => $pp06->kodeSubBidangPelayanan()->pluck('nama', 'kode')->toArray(),
            'jenis' => $pp06->kodeJenisProgram()->pluck('nama', 'kode')->toArray(),
            'kategori' => $pp06->kodeKategoriPelayanan()->pluck('nama', 'kode')->toArray(),
        ];
    }

    private function resolveTahun(?PkWorkflow $workflow): int
    {
        $pp01 = $workflow?->ppWorkflow?->latestPp01();

        return (int) ($pp01?->tahun ?? now()->year);
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
