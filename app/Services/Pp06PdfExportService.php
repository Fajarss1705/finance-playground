<?php

namespace App\Services;

use App\Models\File;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\PpWorkflow;
use App\Models\Role;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;

class Pp06PdfExportService
{
    public function __construct(
        private PpCompileService $compileService,
    ) {}

    /**
     * Generate a PDF file and return the temp file path.
     */
    public function generate(Pp06PeriodeTahunan $pp06): string
    {
        $pp06->loadMissing([
            'kodeBidangPelayanan', 'kodeSubBidangPelayanan',
            'kodeKategoriPelayanan', 'kodeJenisProgram',
            'itemKuisioner', 'itemPlafonAnggaran.team', 'rekeningOrganisasi', 'itemDokumenSop.file',
            'ppWorkflow',
        ]);

        $workflow = $pp06->ppWorkflow;
        $totalPlafon = $pp06->itemPlafonAnggaran->sum('plafon_anggaran');
        $chronology = $this->buildChronology($workflow, $pp06);
        $logoBase64 = base64_encode(file_get_contents(public_path('images/app-logo-black.png')));

        $pdf = Pdf::loadView('exports.pp06-pdf', [
            'pp06' => $pp06,
            'workflow' => $workflow,
            'totalPlafon' => $totalPlafon,
            'chronology' => $chronology,
            'logoBase64' => $logoBase64,
            'label' => "PP-{$pp06->tahun}",
            'judul' => "Periode Tahunan {$pp06->tahun}",
            'revision' => $pp06->revision,
            'dikompilasi' => $pp06->created_at->format('d F Y, H:i').' WIB',
        ]);
        $pdf->setPaper('a4', 'portrait');

        $tempPath = tempnam(sys_get_temp_dir(), 'pp06_').'.pdf';
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
     *   diff?: list<array{type: string, description: string}>|null,
     *   revision_marker?: int|null,
     *   revision?: int,
     * }>
     */
    private function buildChronology(PpWorkflow $workflow, Pp06PeriodeTahunan $currentPp06): array
    {
        $history = $workflow->history ?? [];

        // Pre-load all users and roles
        $userIds = collect($history)->pluck('by')->filter()->unique()->all();
        $roleIds = collect($history)->pluck('role')->filter()->unique()->all();
        $users = User::whereIn('id', $userIds)->pluck('name', 'id');
        $roles = Role::withTrashed()->whereIn('id', $roleIds)->with('team')->get()->keyBy('id');

        // Pre-load step data for submitted entries
        $pp01Data = $workflow->pp01Data()->with(['kodeBidangPelayanan', 'kodeSubBidangPelayanan', 'kodeKategoriPelayanan', 'kodeJenisProgram'])->get()->keyBy('id');
        $pp02Data = $workflow->pp02Data()->with('itemKuisioner')->get()->keyBy('id');
        $pp03Data = $workflow->pp03Data()->with(['itemPlafonAnggaran.team', 'rekeningOrganisasi'])->get()->keyBy('id');
        $pp04Data = $workflow->pp04Data()->with('itemDokumen.file')->get()->keyBy('id');

        // Pre-load PP06 revisions for diff
        $pp06Revisions = $workflow->pp06PeriodeTahunan()
            ->with(['kodeBidangPelayanan', 'kodeSubBidangPelayanan', 'kodeKategoriPelayanan', 'kodeJenisProgram', 'itemKuisioner', 'itemPlafonAnggaran', 'rekeningOrganisasi', 'itemDokumenSop'])
            ->orderBy('revision')
            ->get()
            ->keyBy('revision');

        // Resolve file names for comment attachments
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

            // Resolve attached file names for comments
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

            // Build step data for submitted entries
            $stepData = null;
            if ($action === 'submitted' && $dataId) {
                $stepData = $this->resolveStepData($step, $dataId, $pp01Data, $pp02Data, $pp03Data, $pp04Data);
            }

            // Compute diff for PP07 submit (revision marker)
            $diff = null;
            $revisionMarker = null;

            if ($step === 'PP05' && $action === 'approved') {
                $revisionMarker = 0;
            }

            if ($step === 'PP07' && $action === 'submitted') {
                $revision = $entry['revision'] ?? null;
                if ($revision !== null && $revision > 0) {
                    $revisionMarker = $revision;
                    $prevRevision = $pp06Revisions->get($revision - 1);
                    $currentRevPp06 = $pp06Revisions->get($revision);

                    if ($prevRevision && $currentRevPp06) {
                        $diff = $this->compileService->computeDiff($prevRevision, $this->compileService->buildCanonicalData($currentRevPp06));
                    }
                }
            }

            // Add revision separator before PP07 entries
            if ($step === 'PP07' && $action === 'created') {
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
                'diff' => $diff,
                'revision_marker' => $revisionMarker,
            ];

            if ($revisionMarker !== null) {
                $lastRevisionMarker = $revisionMarker;
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveStepData(string $step, int $dataId, mixed $pp01Data, mixed $pp02Data, mixed $pp03Data, mixed $pp04Data): ?array
    {
        return match ($step) {
            'PP01' => $this->resolvePp01Data($pp01Data->get($dataId)),
            'PP02' => $this->resolvePp02Data($pp02Data->get($dataId)),
            'PP03' => $this->resolvePp03Data($pp03Data->get($dataId)),
            'PP04' => $this->resolvePp04Data($pp04Data->get($dataId)),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePp01Data(mixed $data): ?array
    {
        if (! $data) {
            return null;
        }

        return [
            'type' => 'pp01',
            'tahun' => $data->tahun,
            'tanggal_mulai' => $data->tanggal_mulai_pra_raker?->format('d/m/Y'),
            'tanggal_penetapan' => $data->tanggal_penetapan_program?->format('d/m/Y'),
            'bidang' => $data->kodeBidangPelayanan->map(fn ($i) => "{$i->kode} — {$i->nama}")->all(),
            'sub_bidang' => $data->kodeSubBidangPelayanan->map(fn ($i) => "{$i->kode} — {$i->nama}")->all(),
            'kategori' => $data->kodeKategoriPelayanan->map(fn ($i) => "{$i->kode} — {$i->nama}")->all(),
            'jenis' => $data->kodeJenisProgram->map(fn ($i) => "{$i->kode} — {$i->nama}")->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePp02Data(mixed $data): ?array
    {
        if (! $data) {
            return null;
        }

        return [
            'type' => 'pp02',
            'items' => $data->itemKuisioner->map(fn ($i) => [
                'kode' => $i->kode,
                'pertanyaan' => $i->pertanyaan,
                'tipe' => $i->tipe,
                'satuan' => $i->satuan,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePp03Data(mixed $data): ?array
    {
        if (! $data) {
            return null;
        }

        return [
            'type' => 'pp03',
            'items' => $data->itemPlafonAnggaran->map(fn ($i) => [
                'kode_team' => $i->kode_team,
                'team_name' => $i->team?->name ?? '-',
                'plafon' => $i->plafon_anggaran,
                'nama_bank' => $i->nama_bank,
                'nama_rekening' => $i->nama_rekening,
                'nomor_rekening' => $i->nomor_rekening,
            ])->all(),
            'total' => $data->itemPlafonAnggaran->sum('plafon_anggaran'),
            'rekening_organisasi' => $data->rekeningOrganisasi->map(fn ($r) => [
                'nama_bank' => $r->nama_bank,
                'nama_rekening' => $r->nama_rekening,
                'nomor_rekening' => $r->nomor_rekening,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePp04Data(mixed $data): ?array
    {
        if (! $data) {
            return null;
        }

        return [
            'type' => 'pp04',
            'files' => $data->itemDokumen->map(fn ($i) => $i->file?->original_filename ?? 'File tidak ditemukan')->all(),
        ];
    }
}
