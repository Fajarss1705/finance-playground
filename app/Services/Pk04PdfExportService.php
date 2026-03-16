<?php

namespace App\Services;

use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Pk\PkWorkflow;
use App\Models\Role;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;

class Pk04PdfExportService
{
    /**
     * Generate a PDF file and return the temp file path.
     */
    public function generate(Pk04ProgramTahunan $pk04): string
    {
        $pk04->loadMissing(['kegiatan.anggaran', 'kegiatan.kuisioner', 'pkWorkflow.team']);

        $workflow = $pk04->pkWorkflow;
        $teamName = $workflow?->team?->name ?? 'Unknown';
        $tahun = $this->resolveTahun($workflow);
        $chronology = $this->buildChronology($workflow);

        $bulanLabels = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        $pdf = Pdf::loadView('exports.pk04-pdf', [
            'pk04' => $pk04,
            'workflow' => $workflow,
            'teamName' => $teamName,
            'tahun' => $tahun,
            'bulanLabels' => $bulanLabels,
            'chronology' => $chronology,
        ]);
        $pdf->setPaper('a4', 'landscape');

        $tempPath = tempnam(sys_get_temp_dir(), 'pk04_').'.pdf';
        $pdf->save($tempPath);

        return $tempPath;
    }

    /**
     * Build chronological entries for the PDF history section.
     *
     * @return list<array{at: string, step: string, action: string, action_label: string, user: string, role: string, team: string, notes: ?string}>
     */
    private function buildChronology(?PkWorkflow $workflow): array
    {
        if (! $workflow) {
            return [];
        }

        $history = $workflow->history ?? [];
        $userIds = collect($history)->pluck('by')->filter()->unique()->all();
        $roleIds = collect($history)->pluck('role')->filter()->unique()->all();
        $users = User::whereIn('id', $userIds)->pluck('name', 'id');
        $roles = Role::withTrashed()->whereIn('id', $roleIds)->with('team')->get()->keyBy('id');

        $actionLabels = [
            'created' => 'Dibuat',
            'drafted' => 'Draft Disimpan',
            'submitted' => 'Disubmit',
            'approved' => 'Disetujui',
            'rejected' => 'Ditolak',
            'terminated' => 'Dibatalkan',
            'commented' => 'Komentar',
            'completed' => 'Selesai',
        ];

        $entries = [];

        foreach ($history as $entry) {
            $userId = $entry['by'] ?? null;
            $roleId = $entry['role'] ?? null;
            $role = $roleId ? $roles->get($roleId) : null;

            $entries[] = [
                'at' => isset($entry['at']) ? \Carbon\Carbon::parse($entry['at'])->format('d/m/Y H:i') : '-',
                'step' => $entry['step'] ?? '-',
                'action' => $entry['action'] ?? '-',
                'action_label' => $actionLabels[$entry['action'] ?? ''] ?? ($entry['action'] ?? '-'),
                'user' => $userId ? ($users[$userId] ?? 'Unknown') : 'System',
                'role' => $role?->name ?? '-',
                'team' => $role?->team?->name ?? '-',
                'notes' => $entry['notes'] ?? null,
            ];
        }

        return $entries;
    }

    private function resolveTahun(?PkWorkflow $workflow): int
    {
        $pp01 = $workflow?->ppWorkflow?->latestPp01();

        return (int) ($pp01?->tahun ?? now()->year);
    }
}
