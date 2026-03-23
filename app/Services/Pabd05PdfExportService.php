<?php

namespace App\Services;

use App\Models\File;
use App\Models\Pabd\Pabd05PengajuanBulanan;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Role;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;

class Pabd05PdfExportService
{
    /**
     * Generate a PDF file and return the temp file path.
     */
    public function generate(Pabd05PengajuanBulanan $pabd05): string
    {
        $pabd05->loadMissing([
            'itemAnggaran.pk04Anggaran.pk04Kegiatan.pk04ProgramTahunan.pkWorkflow',
            'buktiTransfer.file',
            'pabdWorkflow.team',
        ]);

        $workflow = $pabd05->pabdWorkflow;
        $teamName = $workflow?->team?->name ?? 'Unknown';
        $bulan = $this->resolveBulanLabel($workflow);
        $tahun = $workflow?->tahun_anggaran ?? now()->year;
        $ppLabel = $this->resolvePpLabel($workflow);
        $chronology = $this->buildChronology($workflow);
        $groupedItems = $this->buildGroupedItems($pabd05);

        $logoBase64 = base64_encode(file_get_contents(public_path('images/app-logo-black.png')));

        $pdf = Pdf::loadView('exports.pabd05-pdf', [
            'pabd05' => $pabd05,
            'workflow' => $workflow,
            'teamName' => $teamName,
            'bulan' => $bulan,
            'tahun' => $tahun,
            'ppLabel' => $ppLabel,
            'chronology' => $chronology,
            'groupedItems' => $groupedItems,
            'logoBase64' => $logoBase64,
            'label' => "PABD-{$bulan} {$tahun}-{$teamName}",
            'judul' => "Pengajuan Anggaran Bulanan {$bulan} {$tahun} — {$teamName}",
            'revision' => 0,
            'dikompilasi' => $pabd05->created_at->format('d F Y, H:i').' WIB',
        ]);
        $pdf->setPaper('a4', 'portrait');

        $tempPath = tempnam(sys_get_temp_dir(), 'pabd05_').'.pdf';
        $pdf->save($tempPath);

        return $tempPath;
    }

    /**
     * Group pabd05_item_anggaran by program -> kegiatan -> anggaran.
     *
     * @return list<array{
     *   program_name: string,
     *   kode_kategori: string,
     *   kegiatan: list<array{
     *     nama_kegiatan: string,
     *     bulan: int|null,
     *     bulan_label: string,
     *     anggaran: list<array{
     *       kode_anggaran_baru: string|null,
     *       mata_anggaran: string|null,
     *       nominal_anggaran: float,
     *       status: string,
     *     }>,
     *   }>,
     * }>
     */
    private function buildGroupedItems(Pabd05PengajuanBulanan $pabd05): array
    {
        $bulanLabels = $this->bulanLabels();
        $grouped = [];

        foreach ($pabd05->itemAnggaran as $item) {
            $pk04Anggaran = $item->pk04Anggaran;
            if (! $pk04Anggaran) {
                continue;
            }

            $kegiatan = $pk04Anggaran->pk04Kegiatan;
            $program = $kegiatan?->pk04ProgramTahunan;

            $programKey = $program?->id ?? 0;
            $kegiatanKey = $kegiatan?->id ?? 0;

            if (! isset($grouped[$programKey])) {
                $grouped[$programKey] = [
                    'program_name' => $program?->nama_program ?? 'Unknown',
                    'kode_kategori' => $program?->kode_kategori ?? '',
                    'kegiatan' => [],
                ];
            }

            if (! isset($grouped[$programKey]['kegiatan'][$kegiatanKey])) {
                $grouped[$programKey]['kegiatan'][$kegiatanKey] = [
                    'nama_kegiatan' => $kegiatan?->nama_kegiatan ?? 'Unknown',
                    'bulan' => $kegiatan?->bulan,
                    'bulan_label' => $bulanLabels[$kegiatan?->bulan ?? 0] ?? '',
                    'anggaran' => [],
                ];
            }

            $grouped[$programKey]['kegiatan'][$kegiatanKey]['anggaran'][] = [
                'kode_anggaran_baru' => $pk04Anggaran->kode_anggaran_baru,
                'mata_anggaran' => $pk04Anggaran->mata_anggaran,
                'nominal_anggaran' => (float) $item->nominal_anggaran,
                'status' => $item->status,
            ];
        }

        // Convert nested associative arrays to indexed arrays
        return collect($grouped)->map(function ($program) {
            $program['kegiatan'] = array_values($program['kegiatan']);

            return $program;
        })->values()->all();
    }

    /**
     * Build chronological entries from workflow history.
     *
     * @return list<array{
     *   is_separator: bool,
     *   at?: string,
     *   step?: string,
     *   action?: string,
     *   action_label?: string,
     *   user?: string,
     *   role?: string,
     *   team?: string,
     *   notes?: string|null,
     *   files?: list<string>,
     * }>
     */
    private function buildChronology(?PabdWorkflow $workflow): array
    {
        if (! $workflow) {
            return [];
        }

        $history = $workflow->history ?? [];

        // Pre-load all users and roles
        $userIds = collect($history)->pluck('by')->filter()->unique()->all();
        $roleIds = collect($history)->pluck('role')->filter()->unique()->all();
        $users = User::whereIn('id', $userIds)->pluck('name', 'id');
        $roles = Role::withTrashed()->whereIn('id', $roleIds)->with('team')->get()->keyBy('id');

        // Resolve file names for attachments
        $allFileIds = collect($history)->pluck('files')->filter()->flatten()
            ->merge(collect($history)->pluck('extra.files')->filter()->flatten())
            ->unique()->all();
        $fileNames = ! empty($allFileIds) ? File::whereIn('id', $allFileIds)->pluck('original_filename', 'id') : collect();

        $actionLabels = [
            'created' => 'Dibuat',
            'drafted' => 'Draft Disimpan',
            'submitted' => 'Disubmit',
            'approved' => 'Disetujui',
            'rejected' => 'Ditolak',
            'completed' => 'Selesai',
            'commented' => 'Komentar',
            'reset' => 'Direset',
            'skipped' => 'Dilewati',
        ];

        $entries = [];

        foreach ($history as $entry) {
            $userId = $entry['by'] ?? null;
            $roleId = $entry['role'] ?? null;
            $role = $roleId ? $roles->get($roleId) : null;
            $step = $entry['step'] ?? '-';
            $action = $entry['action'] ?? '-';

            // Resolve attached file names
            $entryFileNames = [];
            if (! empty($entry['files'])) {
                foreach ($entry['files'] as $fileId) {
                    $entryFileNames[] = $fileNames[$fileId] ?? "file #{$fileId}";
                }
            }
            if (! empty($entry['extra']['files'])) {
                foreach ($entry['extra']['files'] as $fileId) {
                    $entryFileNames[] = $fileNames[$fileId] ?? "file #{$fileId}";
                }
            }

            $entries[] = [
                'is_separator' => false,
                'at' => isset($entry['at']) ? \Carbon\Carbon::parse($entry['at'])->format('d/m/Y H:i') : '-',
                'step' => $step,
                'action' => $action,
                'action_label' => $actionLabels[$action] ?? $action,
                'user' => $userId ? ($users[$userId] ?? 'Unknown') : 'System',
                'role' => $role?->name ?? '-',
                'team' => $role?->team?->name ?? '-',
                'notes' => $entry['notes'] ?? null,
                'files' => $entryFileNames,
            ];
        }

        return $entries;
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
}
