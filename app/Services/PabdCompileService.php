<?php

namespace App\Services;

use App\Models\File;
use App\Models\Pabd\Pabd01Data;
use App\Models\Pabd\Pabd01ItemAnggaran;
use App\Models\Pabd\Pabd04ItemBuktiTransfer;
use App\Models\Pabd\Pabd05BuktiTransfer;
use App\Models\Pabd\Pabd05ItemAnggaran;
use App\Models\Pabd\Pabd05PengajuanBulanan;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;

class PabdCompileService
{
    /**
     * Compile PABD05 final snapshot from PABD01 checklist + PABD04 bukti transfer.
     *
     * Called synchronously from pabd04Submit inside a DB::transaction.
     * Returns the created Pabd05PengajuanBulanan row.
     */
    public function compile(PabdWorkflow $pabdWorkflow): Pabd05PengajuanBulanan
    {
        $pabd01 = $pabdWorkflow->latestPabd01();
        if (! $pabd01) {
            throw new \RuntimeException('PABD01 data tidak ditemukan.');
        }

        $pabd01->load('itemAnggaran.pk04Anggaran');

        // Step 1 — Resolve author snapshots (PABD01, PABD03, PABD04)
        $authorData = $this->resolveAuthorSnapshots($pabdWorkflow);

        // Step 2 — Snapshot bank details from PP06
        $bankDetails = $this->snapshotBankDetails($pabdWorkflow);

        // Step 3 — Compute totals from PABD01 checklist
        $dicairkanItems = $pabd01->itemAnggaran->where('dicairkan', true);
        $hangusItems = $pabd01->itemAnggaran->where('dicairkan', false);

        $totalAnggaranDicairkan = $dicairkanItems->sum(fn (Pabd01ItemAnggaran $item) => (float) ($item->pk04Anggaran?->nominal_anggaran ?? 0));

        // Step 3 — Create pabd05_pengajuan_bulanan
        $pabd05 = Pabd05PengajuanBulanan::create([
            'pabd_workflow_id' => $pabdWorkflow->id,
            ...$authorData,
            ...$bankDetails,
            'total_anggaran_dicairkan' => $totalAnggaranDicairkan,
            'total_item_dicairkan' => $dicairkanItems->count(),
            'total_item_hangus' => $hangusItems->count(),
        ]);

        // Step 4 — Create pabd05_item_anggaran
        $this->buildItemAnggaran($pabd05, $pabd01);

        // Step 5 — Copy bukti transfer file references from PABD04
        $this->copyBuktiTransfer($pabd05, $pabdWorkflow);

        // Step 6 — Finalize pk04_anggaran status_pencairan
        $this->updatePk04PencairanStatus($pabd01);

        // Step 7 — Generate verification code
        $this->generateVerificationCode($pabd05);

        return $pabd05;
    }

    /**
     * Resolve author snapshots from workflow history for PABD01, PABD03, PABD04.
     *
     * @return array<string, mixed>
     */
    private function resolveAuthorSnapshots(PabdWorkflow $pabdWorkflow): array
    {
        $history = $pabdWorkflow->history ?? [];

        // PABD01 — latest 'submitted' entry
        $pabd01Entry = $this->findLatestHistoryEntry($history, 'PABD01', 'submitted');
        $pabd01Author = $this->resolveSnapshotFromEntry($pabd01Entry, 'pabd01');

        // PABD03 — 'approved' entry
        $pabd03Entry = $this->findLatestHistoryEntry($history, 'PABD03', 'approved');
        $pabd03Author = $this->resolveSnapshotFromEntry($pabd03Entry, 'pabd03', 'approved_by');

        // PABD04 — latest 'submitted' entry (the triggering step)
        $pabd04Entry = $this->findLatestHistoryEntry($history, 'PABD04', 'submitted');
        $pabd04Author = $this->resolveSnapshotFromEntry($pabd04Entry, 'pabd04');

        return [...$pabd01Author, ...$pabd03Author, ...$pabd04Author];
    }

    /**
     * Find the latest history entry matching step + action.
     *
     * @return array<string, mixed>|null
     */
    private function findLatestHistoryEntry(array $history, string $step, string $action): ?array
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['step'] ?? '') === $step && ($history[$i]['action'] ?? '') === $action) {
                return $history[$i];
            }
        }

        return null;
    }

    /**
     * Resolve snapshot columns from a history entry.
     *
     * @return array<string, mixed>
     */
    private function resolveSnapshotFromEntry(?array $entry, string $prefix, string $suffix = 'created_by'): array
    {
        $atKey = $suffix === 'created_by' ? 'created_at' : 'approved_at';

        if (! $entry) {
            return [
                "{$prefix}_{$suffix}_user_name" => 'System',
                "{$prefix}_{$suffix}_role_name" => 'System',
                "{$prefix}_{$suffix}_team_name" => null,
                "{$prefix}_{$suffix}_organization_name" => 'System',
                "{$prefix}_{$suffix}_workspace_name" => 'System',
                "{$prefix}_{$atKey}" => now()->toIso8601String(),
            ];
        }

        $userId = $entry['by'] ?? null;
        $user = $userId ? User::find($userId) : null;

        return [
            "{$prefix}_{$suffix}_user_name" => $user?->name ?? 'System',
            "{$prefix}_{$suffix}_role_name" => $this->resolveRoleName($entry['role'] ?? null),
            "{$prefix}_{$suffix}_team_name" => $this->resolveTeamName($entry['team'] ?? null),
            "{$prefix}_{$suffix}_organization_name" => $this->resolveOrgName($entry['org'] ?? null),
            "{$prefix}_{$suffix}_workspace_name" => $this->resolveWorkspaceName($entry['workspace'] ?? null),
            "{$prefix}_{$atKey}" => $entry['at'] ?? now()->toIso8601String(),
        ];
    }

    /**
     * Snapshot bank details from latest PP06.
     *
     * @return array{nama_bank: string, nama_rekening: string, nomor_rekening: string}
     */
    private function snapshotBankDetails(PabdWorkflow $pabdWorkflow): array
    {
        $pp06 = $pabdWorkflow->ppWorkflow?->latestPp06();

        if (! $pp06) {
            return [
                'nama_bank' => '',
                'nama_rekening' => '',
                'nomor_rekening' => '',
            ];
        }

        $plafonItem = $pp06->itemPlafonAnggaran()
            ->where('team_id', $pabdWorkflow->team_id)
            ->first();

        return [
            'nama_bank' => $plafonItem?->nama_bank ?? '',
            'nama_rekening' => $plafonItem?->nama_rekening ?? '',
            'nomor_rekening' => $plafonItem?->nomor_rekening ?? '',
        ];
    }

    /**
     * Create pabd05_item_anggaran rows from PABD01 checklist.
     */
    private function buildItemAnggaran(Pabd05PengajuanBulanan $pabd05, Pabd01Data $pabd01): void
    {
        foreach ($pabd01->itemAnggaran as $item) {
            $pk04Anggaran = $item->pk04Anggaran;

            Pabd05ItemAnggaran::create([
                'pabd05_pengajuan_bulanan_id' => $pabd05->id,
                'pk04_anggaran_id' => $item->pk04_anggaran_id,
                'status' => $item->dicairkan ? 'dicairkan' : 'hangus',
                'nominal_anggaran' => $pk04Anggaran?->nominal_anggaran ?? 0,
            ]);
        }
    }

    /**
     * Copy bukti transfer file references from PABD04 to PABD05.
     */
    private function copyBuktiTransfer(Pabd05PengajuanBulanan $pabd05, PabdWorkflow $pabdWorkflow): void
    {
        $latestPabd04 = $pabdWorkflow->pabd04Data()->latest('id')->first();
        if (! $latestPabd04) {
            return;
        }

        $buktiItems = Pabd04ItemBuktiTransfer::where('pabd04_data_id', $latestPabd04->id)->get();

        foreach ($buktiItems as $item) {
            Pabd05BuktiTransfer::create([
                'pabd05_pengajuan_bulanan_id' => $pabd05->id,
                'file_id' => $item->file_id,
            ]);
        }
    }

    /**
     * Finalize pk04_anggaran status_pencairan based on PABD01 checklist.
     *
     * Items already have status_pencairan = 'menunggu_pencairan' from PABD03 approve.
     * PABD05 compile transitions to final status: dicairkan or hangus.
     */
    private function updatePk04PencairanStatus(Pabd01Data $pabd01): void
    {
        foreach ($pabd01->itemAnggaran as $item) {
            $pk04Anggaran = Pk04Anggaran::find($item->pk04_anggaran_id);
            if (! $pk04Anggaran) {
                continue;
            }

            if ($item->dicairkan) {
                $pk04Anggaran->update([
                    'status_pencairan' => 'dicairkan',
                    'tanggal_pencairan' => now(),
                ]);
            } else {
                $pk04Anggaran->update([
                    'status_pencairan' => 'hangus',
                ]);
            }
        }
    }

    /**
     * Build canonical data array for verification code generation.
     *
     * @return array<string, mixed>
     */
    public function buildCanonicalData(Pabd05PengajuanBulanan $pabd05): array
    {
        $pabd05->loadMissing(['itemAnggaran', 'buktiTransfer']);

        $main = $pabd05->only([
            'pabd_workflow_id',
            'verification_code',
            'pabd01_created_by_user_name',
            'pabd01_created_by_role_name',
            'pabd01_created_by_team_name',
            'pabd01_created_by_organization_name',
            'pabd01_created_by_workspace_name',
            'pabd03_approved_by_user_name',
            'pabd03_approved_by_role_name',
            'pabd03_approved_by_team_name',
            'pabd03_approved_by_organization_name',
            'pabd03_approved_by_workspace_name',
            'pabd04_created_by_user_name',
            'pabd04_created_by_role_name',
            'pabd04_created_by_team_name',
            'pabd04_created_by_organization_name',
            'pabd04_created_by_workspace_name',
            'nama_bank',
            'nama_rekening',
            'nomor_rekening',
            'total_anggaran_dicairkan',
            'total_item_dicairkan',
            'total_item_hangus',
        ]);

        return [
            'pabd05' => $main,
            'items' => $pabd05->itemAnggaran->sortBy('id')->map(fn ($item) => [
                'pk04_anggaran_id' => $item->pk04_anggaran_id,
                'status' => $item->status,
                'nominal_anggaran' => $item->nominal_anggaran,
            ])->values()->all(),
            'bukti' => $pabd05->buktiTransfer->sortBy('id')->map(fn ($b) => [
                'file_id' => $b->file_id,
            ])->values()->all(),
        ];
    }

    /**
     * Generate and store a deterministic verification code for PABD05.
     *
     * SHA-256 of canonical JSON → base36 truncated to 8 chars uppercase.
     */
    public function generateVerificationCode(Pabd05PengajuanBulanan $pabd05): string
    {
        $canonical = $this->buildCanonicalData($pabd05);
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $json);
        $code = strtoupper(substr(base_convert(substr($hash, 0, 16), 16, 36), 0, 8));

        $pabd05->update(['verification_code' => $code]);

        return $code;
    }

    /**
     * Generate PDF and Excel export files and store them as File records.
     *
     * Called after compile (outside the transaction). Failures are logged
     * but do not abort the compile — exports can be regenerated on demand.
     *
     * @return array{pdf_file_id: int|null, excel_file_id: int|null}
     */
    public function generateExportFiles(Pabd05PengajuanBulanan $pabd05, int $userId, int $workspaceId): array
    {
        $result = ['pdf_file_id' => null, 'excel_file_id' => null];
        $pabdWorkflow = $pabd05->pabdWorkflow;
        $teamName = $pabdWorkflow?->team?->name ?? 'Unknown';
        $bulanNames = $this->bulanNames();
        $bulan = $bulanNames[$pabdWorkflow?->bulan_anggaran ?? 0] ?? 'Unknown';
        $tahun = $pabdWorkflow?->tahun_anggaran ?? now()->year;

        // PDF placeholder — actual PabdPdfExportService to be created in export session
        try {
            $filename = "PABD-{$teamName}-{$bulan}-{$tahun}.pdf";
            $storagePath = "exports/pabd/{$pabdWorkflow->id}/{$filename}";

            // Create placeholder file record (actual PDF generation deferred)
            $file = File::create([
                'original_filename' => $filename,
                'filename' => $filename,
                'mime_type' => 'application/pdf',
                'size' => 0,
                'disk' => 'local',
                'path' => $storagePath,
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
                'attachable_type' => Pabd05PengajuanBulanan::class,
                'attachable_id' => $pabd05->id,
                'source_route' => 'pabd05.export.pdf',
            ]);
            $result['pdf_file_id'] = $file->id;
        } catch (\Throwable $e) {
            Log::warning("PABD05 PDF export placeholder failed for PABD05#{$pabd05->id}: {$e->getMessage()}");
        }

        // Excel placeholder
        try {
            $filename = "PABD-{$teamName}-{$bulan}-{$tahun}.xlsx";
            $storagePath = "exports/pabd/{$pabdWorkflow->id}/{$filename}";

            $file = File::create([
                'original_filename' => $filename,
                'filename' => $filename,
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'size' => 0,
                'disk' => 'local',
                'path' => $storagePath,
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
                'attachable_type' => Pabd05PengajuanBulanan::class,
                'attachable_id' => $pabd05->id,
                'source_route' => 'pabd05.export.excel',
            ]);
            $result['excel_file_id'] = $file->id;
        } catch (\Throwable $e) {
            Log::warning("PABD05 Excel export placeholder failed for PABD05#{$pabd05->id}: {$e->getMessage()}");
        }

        return $result;
    }

    /**
     * Append export file IDs to the matching PABD05 'completed' history entry.
     */
    public function appendExportFilesToHistory(PabdWorkflow $pabdWorkflow, array $exportResult): void
    {
        $newFileIds = array_values(array_filter([
            $exportResult['pdf_file_id'],
            $exportResult['excel_file_id'],
        ]));

        if (empty($newFileIds)) {
            return;
        }

        $history = $pabdWorkflow->history ?? [];

        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (
                ($history[$i]['step'] ?? '') === 'PABD05'
                && ($history[$i]['action'] ?? '') === 'completed'
            ) {
                $existing = $history[$i]['files'] ?? [];
                $history[$i]['files'] = array_values(array_unique(array_merge($existing, $newFileIds)));

                break;
            }
        }

        $pabdWorkflow->update(['history' => $history]);
    }

    private function resolveRoleName(?int $roleId): string
    {
        if (! $roleId) {
            return 'System';
        }

        return Role::find($roleId)?->name ?? 'Unknown';
    }

    private function resolveTeamName(?int $teamId): ?string
    {
        if (! $teamId) {
            return null;
        }

        return Team::find($teamId)?->name;
    }

    private function resolveOrgName(?int $orgId): string
    {
        if (! $orgId) {
            return 'System';
        }

        return \App\Models\Organization::find($orgId)?->name ?? 'Unknown';
    }

    private function resolveWorkspaceName(?int $workspaceId): string
    {
        if (! $workspaceId) {
            return 'System';
        }

        return Workspace::find($workspaceId)?->name ?? 'Unknown';
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
