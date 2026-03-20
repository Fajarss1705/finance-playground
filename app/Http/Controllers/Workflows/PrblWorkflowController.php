<?php

namespace App\Http\Controllers\Workflows;

use App\Enums\WorkflowType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflows\Prbl01DraftRequest;
use App\Http\Requests\Workflows\Prbl01SubmitRequest;
use App\Http\Requests\Workflows\PrblCommentRequest;
use App\Models\File;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Prbl\Prbl01Data;
use App\Models\Prbl\Prbl01FotoKegiatan;
use App\Models\Prbl\Prbl01ItemKegiatan;
use App\Models\Prbl\Prbl01ItemKuisioner;
use App\Models\Prbl\Prbl01ItemRealisasi;
use App\Models\Prbl\Prbl01NotaPengeluaran;
use App\Models\Prbl\PrblWorkflow;
use App\Models\Role;
use App\Services\ActiveSessionService;
use App\Services\CommentService;
use App\Services\HistoryFormatter;
use App\Services\WorkflowEngine;
use App\Services\WorkflowNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PrblWorkflowController extends Controller
{
    public function __construct(
        private WorkflowEngine $engine,
        private ActiveSessionService $session,
        private HistoryFormatter $historyFormatter,
        private CommentService $commentService,
        private WorkflowNotifier $notifier,
    ) {}

    // ──────────────────────────────────────
    // PRBL01 — Laporan Kegiatan & Realisasi
    // ──────────────────────────────────────

    public function prbl01Show(PrblWorkflow $prblWorkflow, Prbl01Data $prbl01Data): Response
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($prblWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($prblWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.prbl.prbl01.show");

        $definition = $this->engine->resolveDefinition(WorkflowType::PRBL);
        $history = $prblWorkflow->history ?? [];
        $statuses = $this->engine->getStepStatuses($definition, $history);

        // Resolve mode
        $mode = $this->resolveMode($statuses, 'PRBL01');
        if (($statuses['PRBL01']['dataId'] ?? null) !== null && $statuses['PRBL01']['dataId'] !== $prbl01Data->id) {
            $mode = 'readonly';
        }
        if ($scope === 'admin') {
            $mode = 'readonly';
        }

        $permissions = $this->session->getActivePermissions();
        $permPrefix = "{$scope}.workflows.prbl";
        $isEditable = in_array($mode, ['edit', 'create']) && $scope === 'team';

        // Labels
        $teamName = $prblWorkflow->team?->name ?? 'Unknown';
        $bulanNames = $this->bulanNames();
        $bulanLabel = $bulanNames[$prblWorkflow->bulan_laporan] ?? (string) $prblWorkflow->bulan_laporan;
        $label = "PRBL-{$teamName}-{$bulanLabel}/{$prblWorkflow->tahun_laporan}";

        // PP reference
        $ppWorkflow = $prblWorkflow->ppWorkflow;
        $pp06 = $ppWorkflow?->latestPp06();
        $ppLabel = $pp06 ? "PP-{$ppWorkflow->latestPp01()?->tahun} Revisi {$pp06->revision}" : null;

        // PABD reference
        $pabdWorkflow = $prblWorkflow->pabdWorkflow;
        $pabdLabel = $pabdWorkflow
            ? "PABD-{$teamName}-{$bulanLabel}/{$prblWorkflow->tahun_laporan}"
            : null;

        $basePath = $scope === 'team'
            ? "/team/workflows/prbl/{$prblWorkflow->id}"
            : "/admin/workflows/prbl/{$prblWorkflow->id}";

        // Load kegiatan items grouped by program
        $kegiatanItems = $this->resolveKegiatanItems($prbl01Data, $prblWorkflow);

        // Totals
        $totalDicairkan = $this->calculateTotalDicairkan($prblWorkflow);
        $totalRealisasi = (float) $prbl01Data->itemRealisasi()->sum('nominal_realisasi');

        // Cycle-back detection
        $cycle = $statuses['PRBL01']['cycle'] ?? 1;
        $isReentry = $cycle > 1 && ($statuses['PRBL01']['status'] ?? '') === 'active';
        $cycleBackNotes = $isReentry ? $this->resolveCycleBackNotes($history) : null;

        // Stepper
        $stepperCycles = $this->engine->getStepperData($definition, $history, function (string $code, ?int $dataId) use ($prblWorkflow, $scope): ?string {
            $base = "/{$scope}/workflows/prbl/{$prblWorkflow->id}";
            if ($code === 'PRBL01' && $dataId) {
                return "{$base}/prbl01/{$dataId}";
            }

            return null;
        });

        return Inertia::render('workflows/prbl/prbl01', [
            'workflow' => [
                'id' => $prblWorkflow->id,
                'label' => $label,
                'status' => $this->engine->getWorkflowStatus($history),
                'history' => $this->historyFormatter->format($history),
                'stepper_cycles' => $stepperCycles,
            ],
            'prbl01' => [
                'id' => $prbl01Data->id,
                'updated_at' => $prbl01Data->updated_at->toIso8601String(),
            ],
            'kegiatanItems' => $kegiatanItems,
            'totalDicairkan' => $totalDicairkan,
            'totalRealisasi' => $totalRealisasi,
            'ppLabel' => $ppLabel,
            'pabdLabel' => $pabdLabel,
            'workflowMeta' => [
                'bulan_laporan' => $prblWorkflow->bulan_laporan,
                'bulan_label' => $bulanLabel,
                'tahun_laporan' => $prblWorkflow->tahun_laporan,
                'team_name' => $teamName,
                'auto_created_at' => $prblWorkflow->created_at->format('d/m/Y'),
            ],
            'mode' => $mode,
            'canDraft' => $isEditable && in_array("{$permPrefix}.prbl01.draft", $permissions),
            'canSubmit' => $isEditable && in_array("{$permPrefix}.prbl01.submit", $permissions),
            'canComment' => in_array("{$permPrefix}.comment", $permissions),
            'isReentry' => $isReentry,
            'cycleBackNotes' => $cycleBackNotes,
            'actionRoles' => $this->resolveActionRoles([
                'team.workflows.prbl.prbl01.draft' => ['Simpan Draft', false],
                'team.workflows.prbl.prbl01.submit' => ['Submit', true],
                "{$permPrefix}.comment" => ['Komentar', false],
            ]),
            'activeRoleName' => $this->getActiveRoleName(),
            'scope' => $scope,
            'basePath' => $basePath,
        ]);
    }

    public function prbl01Draft(Prbl01DraftRequest $request, PrblWorkflow $prblWorkflow, Prbl01Data $prbl01Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->ensureTeamOwnership($prblWorkflow);
        $this->checkPermission('team.workflows.prbl.prbl01.draft');
        $this->ensureStepActive($prblWorkflow, 'PRBL01');
        $this->ensureCurrentRecord($prblWorkflow, 'PRBL01', $prbl01Data->id);

        $validated = $request->validated();
        $this->checkOptimisticLock($prbl01Data, $validated['expected_updated_at']);

        // Sync narrative fields
        $this->syncNarrativeFields($prbl01Data, $validated['items'] ?? []);

        // Sync kuisioner answers
        $this->syncKuisionerAnswers($validated['items'] ?? []);

        // Sync realisasi amounts
        $this->syncRealisasiAmounts($prbl01Data, $validated['realisasi'] ?? []);

        $prbl01Data->touch();

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $prbl01Data,
            'prbl.prbl01.draft',
            $request->user()->id,
            $sessionContext,
        );

        $this->engine->recordAction(
            workflow: $prblWorkflow,
            step: 'PRBL01',
            action: 'drafted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'prbl01_data',
            dataId: $prbl01Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
            extra: ['pp06_revision' => $this->getLatestPp06Revision($prblWorkflow)],
        );

        return to_route("{$this->getScope()}.workflows.prbl.prbl01.show", [$prblWorkflow, $prbl01Data])
            ->with('success', 'Draft PRBL01 berhasil disimpan.');
    }

    public function prbl01Submit(Prbl01SubmitRequest $request, PrblWorkflow $prblWorkflow, Prbl01Data $prbl01Data): RedirectResponse
    {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->ensureTeamOwnership($prblWorkflow);
        $this->checkPermission('team.workflows.prbl.prbl01.submit');
        $this->ensureStepActive($prblWorkflow, 'PRBL01');
        $this->ensureCurrentRecord($prblWorkflow, 'PRBL01', $prbl01Data->id);

        $validated = $request->validated();
        $this->checkOptimisticLock($prbl01Data, $validated['expected_updated_at']);

        // Sync narrative fields
        $this->syncNarrativeFields($prbl01Data, $validated['items']);

        // Sync kuisioner answers
        $this->syncKuisionerAnswers($validated['items']);

        // Sync realisasi amounts
        $this->syncRealisasiAmounts($prbl01Data, $validated['realisasi']);

        // Validate realisasi constraint: sum(realisasi) <= sum(dicairkan)
        $this->validateRealisasiConstraint($prbl01Data, $prblWorkflow);

        $prbl01Data->touch();

        $sessionContext = $this->getSessionContext();
        $fileIds = $this->commentService->storeFiles(
            $request->file('files', []),
            $prbl01Data,
            'prbl.prbl01.submit',
            $request->user()->id,
            $sessionContext,
        );

        // Record PRBL01 submitted
        $this->engine->recordAction(
            workflow: $prblWorkflow,
            step: 'PRBL01',
            action: 'submitted',
            userId: $request->user()->id,
            sessionContext: $sessionContext,
            table: 'prbl01_data',
            dataId: $prbl01Data->id,
            notes: $validated['notes'] ?? null,
            files: ! empty($fileIds) ? $fileIds : null,
            extra: ['pp06_revision' => $this->getLatestPp06Revision($prblWorkflow)],
        );

        // Fork: activate PRBL02A + PRBL02B (parallel)
        $this->engine->recordAction(
            workflow: $prblWorkflow,
            step: 'PRBL02A',
            action: 'created',
            userId: null,
            sessionContext: [],
        );

        $this->engine->recordAction(
            workflow: $prblWorkflow,
            step: 'PRBL02B',
            action: 'created',
            userId: null,
            sessionContext: [],
        );

        // Notify PRBL02A + PRBL02B approvers
        $this->notifier->notify($prblWorkflow, 'prbl01.submitted', [
            'actor_name' => $request->user()->name,
            'actor_role' => $this->resolveSessionRoleName(),
        ], $request->user()->id);

        return to_route('team.workflows.prbl.prbl01.show', [$prblWorkflow, $prbl01Data])
            ->with('success', 'Laporan kegiatan berhasil disubmit. Menunggu review narasi (PRBL02A) dan anggaran (PRBL02B).');
    }

    // ──────────────────────────────────────
    // PRBL01 — Foto Kegiatan Upload/Delete
    // ──────────────────────────────────────

    public function prbl01FotoUpload(Request $request, PrblWorkflow $prblWorkflow, Prbl01Data $prbl01Data): \Illuminate\Http\JsonResponse
    {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->ensureTeamOwnership($prblWorkflow);
        $this->checkPermission('team.workflows.prbl.prbl01.draft');
        $this->ensureStepActive($prblWorkflow, 'PRBL01');
        $this->ensureCurrentRecord($prblWorkflow, 'PRBL01', $prbl01Data->id);

        $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:51200'],
            'prbl01_item_kegiatan_id' => ['required', 'integer', 'exists:prbl01_item_kegiatan,id'],
        ]);

        $itemKegiatan = Prbl01ItemKegiatan::where('id', $request->input('prbl01_item_kegiatan_id'))
            ->where('prbl01_data_id', $prbl01Data->id)
            ->firstOrFail();

        $uploadedFile = $request->file('file');
        $sessionContext = $this->getSessionContext();

        // Upload and store file
        $file = $this->storeUploadedFile($uploadedFile, $prblWorkflow, $request->user()->id, $sessionContext, 'prbl.prbl01.foto_upload');

        // Auto-resize if > 10MB
        if ($file->size > 10 * 1024 * 1024) {
            $this->autoResizeImage($file);
        }

        // Create join row
        $fotoRow = Prbl01FotoKegiatan::create([
            'prbl01_item_kegiatan_id' => $itemKegiatan->id,
            'file_id' => $file->id,
        ]);

        return response()->json([
            'id' => $fotoRow->id,
            'file_id' => $file->id,
            'original_filename' => $file->original_filename,
            'thumbnail_url' => route('files.download', $file),
        ]);
    }

    public function prbl01FotoDelete(Request $request, PrblWorkflow $prblWorkflow, Prbl01Data $prbl01Data): \Illuminate\Http\JsonResponse
    {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->ensureTeamOwnership($prblWorkflow);
        $this->checkPermission('team.workflows.prbl.prbl01.draft');
        $this->ensureStepActive($prblWorkflow, 'PRBL01');
        $this->ensureCurrentRecord($prblWorkflow, 'PRBL01', $prbl01Data->id);

        $request->validate([
            'prbl01_foto_kegiatan_id' => ['required', 'integer', 'exists:prbl01_foto_kegiatan,id'],
        ]);

        $foto = Prbl01FotoKegiatan::where('id', $request->input('prbl01_foto_kegiatan_id'))
            ->whereHas('prbl01ItemKegiatan', fn ($q) => $q->where('prbl01_data_id', $prbl01Data->id))
            ->firstOrFail();

        $foto->delete();

        return response()->json(['success' => true]);
    }

    // ──────────────────────────────────────
    // PRBL01 — Nota Pengeluaran Upload/Delete
    // ──────────────────────────────────────

    /** Blocked file extensions for nota upload. */
    private const BLOCKED_EXTENSIONS = ['exe', 'bat', 'sh', 'cmd', 'ps1', 'com', 'scr', 'msi', 'vbs', 'js', 'wsh', 'wsf'];

    public function prbl01NotaUpload(Request $request, PrblWorkflow $prblWorkflow, Prbl01Data $prbl01Data): \Illuminate\Http\JsonResponse
    {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->ensureTeamOwnership($prblWorkflow);
        $this->checkPermission('team.workflows.prbl.prbl01.draft');
        $this->ensureStepActive($prblWorkflow, 'PRBL01');
        $this->ensureCurrentRecord($prblWorkflow, 'PRBL01', $prbl01Data->id);

        $request->validate([
            'file' => ['required', 'file', 'max:25600'],
            'prbl01_item_kegiatan_id' => ['required', 'integer', 'exists:prbl01_item_kegiatan,id'],
        ]);

        $uploadedFile = $request->file('file');
        $ext = strtolower($uploadedFile->getClientOriginalExtension());

        if (in_array($ext, self::BLOCKED_EXTENSIONS)) {
            abort(422, 'Tipe file ini tidak diperbolehkan.');
        }

        $itemKegiatan = Prbl01ItemKegiatan::where('id', $request->input('prbl01_item_kegiatan_id'))
            ->where('prbl01_data_id', $prbl01Data->id)
            ->firstOrFail();

        $sessionContext = $this->getSessionContext();
        $file = $this->storeUploadedFile($uploadedFile, $prblWorkflow, $request->user()->id, $sessionContext, 'prbl.prbl01.nota_upload');

        $notaRow = Prbl01NotaPengeluaran::create([
            'prbl01_item_kegiatan_id' => $itemKegiatan->id,
            'file_id' => $file->id,
        ]);

        return response()->json([
            'id' => $notaRow->id,
            'file_id' => $file->id,
            'original_filename' => $file->original_filename,
            'mime_type' => $file->mime_type,
            'download_url' => route('files.download', $file),
        ]);
    }

    public function prbl01NotaDelete(Request $request, PrblWorkflow $prblWorkflow, Prbl01Data $prbl01Data): \Illuminate\Http\JsonResponse
    {
        $this->ensureWorkspaceOwnership($prblWorkflow);
        $this->ensureTeamOwnership($prblWorkflow);
        $this->checkPermission('team.workflows.prbl.prbl01.draft');
        $this->ensureStepActive($prblWorkflow, 'PRBL01');
        $this->ensureCurrentRecord($prblWorkflow, 'PRBL01', $prbl01Data->id);

        $request->validate([
            'prbl01_nota_pengeluaran_id' => ['required', 'integer', 'exists:prbl01_nota_pengeluaran,id'],
        ]);

        $nota = Prbl01NotaPengeluaran::where('id', $request->input('prbl01_nota_pengeluaran_id'))
            ->whereHas('prbl01ItemKegiatan', fn ($q) => $q->where('prbl01_data_id', $prbl01Data->id))
            ->firstOrFail();

        $nota->delete();

        return response()->json(['success' => true]);
    }

    // ──────────────────────────────────────
    // Comment
    // ──────────────────────────────────────

    public function comment(PrblCommentRequest $request, PrblWorkflow $prblWorkflow): RedirectResponse
    {
        $scope = $this->getScope();
        $this->ensureWorkspaceOwnership($prblWorkflow);
        if ($scope === 'team') {
            $this->ensureTeamOwnership($prblWorkflow);
        }
        $this->checkPermission("{$scope}.workflows.prbl.comment");

        $validated = $request->validated();
        $sessionContext = $this->getSessionContext();

        $this->commentService->store(
            $prblWorkflow,
            $validated['source'],
            $validated['notes'],
            $request->file('files', []),
            $request->user()->id,
            $sessionContext,
            'prbl',
        );

        return back()->with('success', 'Komentar berhasil ditambahkan.');
    }

    // ──────────────────────────────────────
    // PRBL01 Data Helpers
    // ──────────────────────────────────────

    /**
     * Resolve kegiatan items grouped by program for the PRBL01 page.
     *
     * @return list<array<string, mixed>>
     */
    private function resolveKegiatanItems(Prbl01Data $prbl01Data, PrblWorkflow $prblWorkflow): array
    {
        $prbl01Data->load([
            'itemKegiatan.pk04Kegiatan.pk04ProgramTahunan',
            'itemKegiatan.fotoKegiatan.file',
            'itemKegiatan.notaPengeluaran.file',
            'itemKegiatan.itemKuisioner.pk04Kuisioner',
            'itemRealisasi.pk04Anggaran',
        ]);

        $bulanNames = $this->bulanNames();

        // Index realisasi by pk04_anggaran_id for nesting under kegiatan
        $realisasiByAnggaran = [];
        foreach ($prbl01Data->itemRealisasi as $realisasi) {
            $realisasiByAnggaran[$realisasi->pk04_anggaran_id] = $realisasi;
        }

        // Group kegiatan by program
        $programMap = [];

        foreach ($prbl01Data->itemKegiatan as $itemKegiatan) {
            $pk04Kegiatan = $itemKegiatan->pk04Kegiatan;
            if (! $pk04Kegiatan) {
                continue;
            }

            $program = $pk04Kegiatan->pk04ProgramTahunan;
            if (! $program) {
                continue;
            }

            $programId = $program->id;

            if (! isset($programMap[$programId])) {
                $programMap[$programId] = [
                    'program_id' => $program->id,
                    'program_name' => $program->nama_program,
                    'kode_kategori' => $program->kode_kategori,
                    'kegiatan' => [],
                ];
            }

            // Resolve dicairkan anggaran for this kegiatan
            $dicairkanAnggaran = Pk04Anggaran::where('pk04_kegiatan_id', $pk04Kegiatan->id)
                ->where('pencairan_pabd_workflow_id', $prblWorkflow->pabd_workflow_id)
                ->where('status_pencairan', 'dicairkan')
                ->get();

            $realisasiItems = [];
            foreach ($dicairkanAnggaran as $anggaran) {
                $realisasi = $realisasiByAnggaran[$anggaran->id] ?? null;
                $realisasiItems[] = [
                    'prbl01_item_realisasi_id' => $realisasi?->id,
                    'pk04_anggaran_id' => $anggaran->id,
                    'kode_anggaran_baru' => $anggaran->kode_anggaran_baru,
                    'mata_anggaran' => $anggaran->mata_anggaran,
                    'nominal_anggaran' => (float) $anggaran->nominal_anggaran,
                    'nominal_realisasi' => $realisasi ? (float) $realisasi->nominal_realisasi : 0,
                    'komentar_realisasi' => $realisasi?->komentar_realisasi,
                ];
            }

            // Fotos
            $fotos = $itemKegiatan->fotoKegiatan->map(fn ($foto) => [
                'id' => $foto->id,
                'file_id' => $foto->file_id,
                'original_filename' => $foto->file?->original_filename ?? '',
                'thumbnail_url' => $foto->file ? route('files.download', $foto->file) : null,
            ])->values()->all();

            // Nota
            $nota = $itemKegiatan->notaPengeluaran->map(fn ($n) => [
                'id' => $n->id,
                'file_id' => $n->file_id,
                'original_filename' => $n->file?->original_filename ?? '',
                'mime_type' => $n->file?->mime_type ?? '',
                'download_url' => $n->file ? route('files.serve', $n->file->uuid) : null,
            ])->values()->all();

            // Kuisioner
            $kuisioner = $itemKegiatan->itemKuisioner->map(fn ($k) => [
                'prbl01_item_kuisioner_id' => $k->id,
                'pk04_kuisioner_id' => $k->pk04_kuisioner_id,
                'pertanyaan' => $k->pk04Kuisioner?->pertanyaan ?? '',
                'tipe' => $k->pk04Kuisioner?->tipe ?? '',
                'satuan' => $k->pk04Kuisioner?->satuan,
                'jawaban' => $k->jawaban,
            ])->values()->all();

            $programMap[$programId]['kegiatan'][] = [
                'prbl01_item_kegiatan_id' => $itemKegiatan->id,
                'pk04_kegiatan_id' => $pk04Kegiatan->id,
                'nama_kegiatan' => $pk04Kegiatan->nama_kegiatan,
                'bulan' => $pk04Kegiatan->bulan,
                'bulan_label' => $bulanNames[$pk04Kegiatan->bulan] ?? (string) $pk04Kegiatan->bulan,
                'masalah' => $itemKegiatan->masalah,
                'langkah_penanganan' => $itemKegiatan->langkah_penanganan,
                'harapan' => $itemKegiatan->harapan,
                'catatan_tim' => $itemKegiatan->catatan_tim,
                'fotos' => $fotos,
                'nota' => $nota,
                'kuisioner' => $kuisioner,
                'realisasi' => $realisasiItems,
            ];
        }

        return array_values($programMap);
    }

    /**
     * Calculate total dicairkan amount for this PRBL workflow.
     */
    private function calculateTotalDicairkan(PrblWorkflow $prblWorkflow): float
    {
        return (float) Pk04Anggaran::where('pencairan_pabd_workflow_id', $prblWorkflow->pabd_workflow_id)
            ->where('status_pencairan', 'dicairkan')
            ->whereHas('pk04Kegiatan', fn ($q) => $q->where('bulan', $prblWorkflow->bulan_laporan))
            ->sum('nominal_anggaran');
    }

    /**
     * Sync narrative fields on prbl01_item_kegiatan rows.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function syncNarrativeFields(Prbl01Data $prbl01Data, array $items): void
    {
        foreach ($items as $item) {
            $id = $item['prbl01_item_kegiatan_id'] ?? null;
            if (! $id) {
                continue;
            }

            Prbl01ItemKegiatan::where('id', $id)
                ->where('prbl01_data_id', $prbl01Data->id)
                ->update([
                    'masalah' => $item['masalah'] ?? null,
                    'langkah_penanganan' => $item['langkah_penanganan'] ?? null,
                    'harapan' => $item['harapan'] ?? null,
                    'catatan_tim' => $item['catatan_tim'] ?? null,
                ]);
        }
    }

    /**
     * Sync kuisioner answers on prbl01_item_kuisioner rows.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function syncKuisionerAnswers(array $items): void
    {
        foreach ($items as $item) {
            foreach ($item['kuisioner'] ?? [] as $k) {
                $id = $k['prbl01_item_kuisioner_id'] ?? null;
                if (! $id) {
                    continue;
                }

                Prbl01ItemKuisioner::where('id', $id)
                    ->update(['jawaban' => $k['jawaban'] ?? null]);
            }
        }
    }

    /**
     * Sync realisasi amounts on prbl01_item_realisasi rows.
     *
     * @param  list<array<string, mixed>>  $realisasi
     */
    private function syncRealisasiAmounts(Prbl01Data $prbl01Data, array $realisasi): void
    {
        foreach ($realisasi as $r) {
            $id = $r['prbl01_item_realisasi_id'] ?? null;
            if (! $id) {
                continue;
            }

            Prbl01ItemRealisasi::where('id', $id)
                ->where('prbl01_data_id', $prbl01Data->id)
                ->update([
                    'nominal_realisasi' => $r['nominal_realisasi'] ?? 0,
                    'komentar_realisasi' => $r['komentar_realisasi'] ?? null,
                ]);
        }
    }

    /**
     * Validate that sum(realisasi) <= sum(dicairkan) for this month.
     */
    private function validateRealisasiConstraint(Prbl01Data $prbl01Data, PrblWorkflow $prblWorkflow): void
    {
        $totalRealisasi = (float) $prbl01Data->itemRealisasi()->sum('nominal_realisasi');
        $totalDicairkan = $this->calculateTotalDicairkan($prblWorkflow);

        if ($totalRealisasi > $totalDicairkan) {
            $selisih = $totalRealisasi - $totalDicairkan;
            abort(422, 'Total realisasi (Rp '.number_format($totalRealisasi, 0, ',', '.').') melebihi total anggaran dicairkan (Rp '.number_format($totalDicairkan, 0, ',', '.').'). Selisih: Rp '.number_format($selisih, 0, ',', '.').'.');
        }
    }

    /**
     * Resolve cycle-back rejection notes from history.
     *
     * Handles two sources:
     * 1. PRBL02A+02B compiled rejection (wait-for-both)
     * 2. PRBL04 reject_to_prbl01 (major rejection)
     *
     * @return array<string, mixed>|null
     */
    private function resolveCycleBackNotes(array $history): ?array
    {
        $formattedHistory = $this->historyFormatter->format($history);

        // Check from most recent backwards
        for ($i = count($formattedHistory) - 1; $i >= 0; $i--) {
            $entry = $formattedHistory[$i];
            $step = $entry['step'] ?? '';
            $action = $entry['action'] ?? '';

            // PRBL04 reject_to_prbl01
            if ($step === 'PRBL04' && $action === 'rejected') {
                return [
                    'source' => 'prbl04',
                    'prbl04' => [
                        'step' => 'PRBL04',
                        'step_label' => 'Review Final',
                        'by_name' => $entry['by_name'] ?? null,
                        'role_name' => $entry['role_name'] ?? null,
                        'team_name' => $entry['team_name'] ?? null,
                        'at' => $entry['at'] ?? null,
                        'notes' => $entry['notes'] ?? null,
                        'files' => $entry['files'] ?? null,
                    ],
                ];
            }

            // PRBL02A+02B compiled rejection
            if (in_array($step, ['PRBL02A', 'PRBL02B']) && in_array($action, ['approved', 'rejected'])) {
                return $this->compilePrbl02Feedback($formattedHistory, $i);
            }
        }

        return null;
    }

    /**
     * Compile PRBL02A+02B feedback from both tracks.
     *
     * @return array<string, mixed>
     */
    private function compilePrbl02Feedback(array $formattedHistory, int $startIndex): array
    {
        $prbl02a = null;
        $prbl02b = null;

        // Scan backwards from startIndex to find the latest PRBL02A and PRBL02B entries
        for ($i = $startIndex; $i >= 0; $i--) {
            $entry = $formattedHistory[$i];
            $step = $entry['step'] ?? '';
            $action = $entry['action'] ?? '';

            if ($step === 'PRBL02A' && in_array($action, ['approved', 'rejected']) && ! $prbl02a) {
                $prbl02a = [
                    'status' => $action === 'approved' ? 'Disetujui' : 'Ditolak',
                    'by_name' => $entry['by_name'] ?? null,
                    'role_name' => $entry['role_name'] ?? null,
                    'team_name' => $entry['team_name'] ?? null,
                    'at' => $entry['at'] ?? null,
                    'notes' => $entry['notes'] ?? null,
                    'files' => $entry['files'] ?? null,
                ];
            }

            if ($step === 'PRBL02B' && in_array($action, ['approved', 'rejected']) && ! $prbl02b) {
                $prbl02b = [
                    'status' => $action === 'approved' ? 'Disetujui' : 'Ditolak',
                    'by_name' => $entry['by_name'] ?? null,
                    'role_name' => $entry['role_name'] ?? null,
                    'team_name' => $entry['team_name'] ?? null,
                    'at' => $entry['at'] ?? null,
                    'notes' => $entry['notes'] ?? null,
                    'files' => $entry['files'] ?? null,
                ];
            }

            if ($prbl02a && $prbl02b) {
                break;
            }

            // Stop scanning if we hit PRBL01 submitted (previous cycle boundary)
            if ($step === 'PRBL01' && $action === 'submitted') {
                break;
            }
        }

        return [
            'source' => 'prbl02',
            'prbl02a' => $prbl02a,
            'prbl02b' => $prbl02b,
        ];
    }

    // ──────────────────────────────────────
    // File Upload Helpers
    // ──────────────────────────────────────

    /**
     * Store an uploaded file to disk and create a File model.
     */
    private function storeUploadedFile(
        \Illuminate\Http\UploadedFile $uploadedFile,
        PrblWorkflow $prblWorkflow,
        int $userId,
        array $sessionContext,
        string $sourceRoute,
    ): File {
        $uuid = (string) Str::uuid();
        $ext = $uploadedFile->getClientOriginalExtension();
        $filename = "{$uuid}.{$ext}";
        $workspaceId = $sessionContext['workspace'] ?? $prblWorkflow->workspace_id;
        $path = "files/{$workspaceId}/".now()->format('Y/m')."/{$filename}";

        Storage::disk('local')->putFileAs(
            dirname($path),
            $uploadedFile,
            basename($path),
        );

        $roleId = $sessionContext['role'] ?? null;
        $role = $roleId ? Role::with('team')->find($roleId) : null;

        return File::create([
            'uuid' => $uuid,
            'original_filename' => $uploadedFile->getClientOriginalName(),
            'filename' => $filename,
            'mime_type' => $uploadedFile->getMimeType(),
            'size' => $uploadedFile->getSize(),
            'disk' => 'local',
            'path' => $path,
            'user_id' => $userId,
            'role_id' => $roleId,
            'team_id' => $role?->team_id,
            'organization_id' => $role?->team?->organization_id,
            'workspace_id' => $workspaceId,
            'source_route' => $sourceRoute,
            'attachable_type' => Prbl01Data::class,
            'attachable_id' => 0, // Not polymorphic — tracked via join tables
        ]);
    }

    /**
     * Auto-resize an image file if it exceeds 10MB.
     */
    private function autoResizeImage(File $file): void
    {
        $fullPath = Storage::disk('local')->path($file->path);

        if (! file_exists($fullPath)) {
            return;
        }

        $maxBytes = 10 * 1024 * 1024;
        $currentSize = filesize($fullPath);

        if ($currentSize <= $maxBytes) {
            return;
        }

        $imageInfo = @getimagesize($fullPath);
        if (! $imageInfo) {
            return;
        }

        $mime = $imageInfo['mime'];
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($fullPath),
            'image/png' => @imagecreatefrompng($fullPath),
            'image/webp' => @imagecreatefromwebp($fullPath),
            default => null,
        };

        if (! $image) {
            return;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Reduce dimensions iteratively until under 10MB
        for ($scale = 0.8; $scale >= 0.2; $scale -= 0.1) {
            $newWidth = (int) ($width * $scale);
            $newHeight = (int) ($height * $scale);

            $resized = imagecreatetruecolor($newWidth, $newHeight);

            if ($mime === 'image/png' || $mime === 'image/webp') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
            }

            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            match ($mime) {
                'image/jpeg' => imagejpeg($resized, $fullPath, 85),
                'image/png' => imagepng($resized, $fullPath, 6),
                'image/webp' => imagewebp($resized, $fullPath, 85),
                default => null,
            };

            imagedestroy($resized);

            $newSize = filesize($fullPath);
            if ($newSize <= $maxBytes) {
                $file->update(['size' => $newSize]);

                break;
            }
        }

        imagedestroy($image);
    }

    // ──────────────────────────────────────
    // Shared Helpers (same pattern as PABD)
    // ──────────────────────────────────────

    private function getLatestPp06Revision(PrblWorkflow $prblWorkflow): int
    {
        return $prblWorkflow->ppWorkflow?->latestPp06()?->revision ?? 0;
    }

    private function getScope(): string
    {
        $routeName = request()->route()?->getName() ?? '';

        return str_starts_with($routeName, 'team.') ? 'team' : 'admin';
    }

    private function ensureWorkspaceOwnership(PrblWorkflow $prblWorkflow): void
    {
        if ($prblWorkflow->workspace_id !== $this->session->getActiveWorkspaceId()) {
            abort(403, 'Workflow bukan milik workspace aktif.');
        }
    }

    private function ensureTeamOwnership(PrblWorkflow $prblWorkflow): void
    {
        $roleId = $this->session->getActiveRoleId();
        $userTeamId = $roleId ? Role::find($roleId)?->team_id : null;

        if ($userTeamId === null || $prblWorkflow->team_id !== $userTeamId) {
            abort(403, 'Workflow bukan milik tim Anda.');
        }
    }

    private function checkPermission(string $permission): void
    {
        if (! in_array($permission, $this->session->getActivePermissions())) {
            abort(403);
        }
    }

    private function ensureStepActive(PrblWorkflow $prblWorkflow, string $step): void
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PRBL);

        if (! in_array($step, $this->engine->getCurrentSteps($definition, $prblWorkflow->history ?? []))) {
            abort(409, 'Step ini sudah tidak aktif.');
        }
    }

    private function ensureCurrentRecord(PrblWorkflow $prblWorkflow, string $step, int $recordId): void
    {
        $definition = $this->engine->resolveDefinition(WorkflowType::PRBL);
        $statuses = $this->engine->getStepStatuses($definition, $prblWorkflow->history ?? []);
        $expectedId = $statuses[$step]['dataId'] ?? null;

        if ($expectedId !== null && $expectedId !== $recordId) {
            abort(409, 'Data ini bukan versi terkini untuk step ini.');
        }
    }

    private function checkOptimisticLock($model, string $expectedUpdatedAt): void
    {
        if ($model->updated_at->toIso8601String() !== $expectedUpdatedAt) {
            abort(409, 'Data telah diubah oleh pengguna lain. Refresh halaman.');
        }
    }

    private function getSessionContext(): array
    {
        $roleId = $this->session->getActiveRoleId();
        $teamId = $roleId ? Role::find($roleId)?->team_id : null;

        return [
            'role' => $roleId,
            'team' => $teamId,
            'org' => null,
            'workspace' => $this->session->getActiveWorkspaceId(),
        ];
    }

    private function resolveMode(array $statuses, string $step): string
    {
        $status = $statuses[$step]['status'] ?? 'pending';

        if ($status === 'active' && ($statuses[$step]['cycle'] ?? 1) > 1) {
            return 'edit';
        }

        if ($status === 'active' && ($statuses[$step]['dataId'] ?? null) !== null) {
            return 'edit';
        }

        if ($status === 'active') {
            return 'create';
        }

        return 'readonly';
    }

    /** @return list<array{action: string, label: string, roles: list<array{name: string, users: list<string>}>, highlight: bool}> */
    private function resolveActionRoles(array $permissionLabels): array
    {
        $permissions = \App\Models\Permission::whereIn('name', array_keys($permissionLabels))
            ->with(['roles.team', 'roles.users'])
            ->get()
            ->keyBy('name');

        $result = [];

        foreach ($permissionLabels as $permName => [$label, $highlight]) {
            $perm = $permissions->get($permName);
            $roles = [];

            if ($perm) {
                foreach ($perm->roles->sortBy(fn (Role $r) => $r->team ? "{$r->name} ({$r->team->name})" : $r->name) as $role) {
                    $roles[] = [
                        'name' => $role->team ? "{$role->name} ({$role->team->name})" : $role->name,
                        'users' => $role->users->pluck('name')->sort()->values()->all(),
                    ];
                }
            }

            $result[] = [
                'action' => $permName,
                'label' => $label,
                'roles' => $roles,
                'highlight' => $highlight,
            ];
        }

        return $result;
    }

    private function getActiveRoleName(): ?string
    {
        $roleId = $this->session->getActiveRoleId();
        if (! $roleId) {
            return null;
        }

        $role = Role::with('team')->find($roleId);

        return $role
            ? ($role->team ? "{$role->name} ({$role->team->name})" : $role->name)
            : null;
    }

    private function resolveSessionRoleName(): string
    {
        $roleId = $this->session->getActiveRoleId();
        if (! $roleId) {
            return 'System';
        }

        return Role::find($roleId)?->name ?? 'Unknown';
    }

    /** @return array<int, string> */
    private function bulanNames(): array
    {
        return [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
    }
}
