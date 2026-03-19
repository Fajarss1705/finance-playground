<?php

namespace App\Services;

use App\Models\File;
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
        $kodeRefMap = $this->loadKodeRefMap($workflow);

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
            'kodeRefMap' => $kodeRefMap,
        ]);
        $pdf->setPaper('a4', 'landscape');

        $tempPath = tempnam(sys_get_temp_dir(), 'pk04_').'.pdf';
        $pdf->save($tempPath);

        return $tempPath;
    }

    /**
     * Build chronological entries with embedded step data.
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
     *   step_data?: array|null,
     *   revision_marker?: int|null,
     * }>
     */
    private function buildChronology(?PkWorkflow $workflow): array
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

        // Pre-load PK01 step data for submitted entries
        $pk01Data = $workflow->pk01Data()
            ->with(['kegiatan.anggaran', 'kegiatan.kuisioner'])
            ->get()
            ->keyBy('id');

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
            'terminated' => 'Dibatalkan',
            'commented' => 'Komentar',
            'completed' => 'Selesai',
        ];

        $entries = [];
        $lastRevisionMarker = -1;

        foreach ($history as $entry) {
            $userId = $entry['by'] ?? null;
            $roleId = $entry['role'] ?? null;
            $role = $roleId ? $roles->get($roleId) : null;
            $step = $entry['step'] ?? '-';
            $action = $entry['action'] ?? '-';
            $dataId = $entry['id'] ?? null;

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

            // Build step data for PK01 submitted entries
            $stepData = null;
            if ($action === 'submitted' && $dataId && $step === 'PK01') {
                $stepData = $this->resolvePk01Data($pk01Data->get($dataId));
            }

            // Revision marker for PK04 compiled
            $revisionMarker = null;
            if ($step === 'PK04' && $action === 'completed') {
                $revisionMarker = $entry['revision'] ?? 0;
            }

            // Add revision separator before PK05 entries
            if ($step === 'PK05' && $action === 'created') {
                $nextRevision = ($lastRevisionMarker >= 0) ? $lastRevisionMarker + 1 : 1;
                $entries[] = [
                    'is_separator' => true,
                    'revision' => $nextRevision,
                ];
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
                'step_data' => $stepData,
                'revision_marker' => $revisionMarker,
            ];

            if ($revisionMarker !== null) {
                $lastRevisionMarker = $revisionMarker;
            }
        }

        return $entries;
    }

    /**
     * Resolve PK01 submitted data into a summary for PDF kronologis.
     *
     * @return array<string, mixed>|null
     */
    private function resolvePk01Data(mixed $data): ?array
    {
        if (! $data) {
            return null;
        }

        $kegiatan = $data->kegiatan ?? collect();
        $totalAnggaran = $kegiatan->sum(fn ($k) => $k->anggaran->sum('nominal_anggaran'));
        $totalKuisioner = $kegiatan->sum(fn ($k) => $k->kuisioner->count());

        return [
            'type' => 'pk01',
            'nama_program' => $data->nama_program,
            'kode_kategori' => $data->kode_kategori,
            'deskripsi_program' => $data->deskripsi_program,
            'tujuan_program' => $data->tujuan_program,
            'kegiatan' => $kegiatan->map(fn ($k) => [
                'nama' => $k->nama_kegiatan,
                'nomer' => $k->nomer_kegiatan,
                'anggaran_count' => $k->anggaran->count(),
                'anggaran_total' => $k->anggaran->sum('nominal_anggaran'),
                'kuisioner_count' => $k->kuisioner->count(),
            ])->all(),
            'total_anggaran' => $totalAnggaran,
            'total_kegiatan' => $kegiatan->count(),
            'total_kuisioner' => $totalKuisioner,
        ];
    }

    /**
     * Load kode reference name maps from PP06.
     *
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
}
