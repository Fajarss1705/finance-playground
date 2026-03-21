<?php

namespace App\Services;

use App\Models\File;
use App\Models\Pk\Pk01Data;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04Kegiatan;
use App\Models\Pk\Pk04Kuisioner;
use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Prbl\Prbl05ItemRealisasi;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PkCompileService
{
    /**
     * Compile PK01 data into PK04 final snapshot (revision 0).
     *
     * Called synchronously from pk03Approve inside a DB::transaction.
     * Returns the created Pk04ProgramTahunan row.
     *
     * @throws \RuntimeException if budget exceeds plafon
     */
    public function compile(PkWorkflow $pkWorkflow): Pk04ProgramTahunan
    {
        $pk01 = $pkWorkflow->latestPk01();
        if (! $pk01) {
            throw new \RuntimeException('PK01 data tidak ditemukan.');
        }

        $pk01->load(['kegiatan.anggaran', 'kegiatan.kuisioner']);

        $pp06 = $this->getLatestPp06($pkWorkflow);
        if (! $pp06) {
            throw new \RuntimeException('PP06 tidak ditemukan.');
        }

        // Budget hard block (raker only)
        if ($pkWorkflow->tipe === 'raker') {
            $this->checkBudgetHardBlock($pkWorkflow, $pk01, $pp06);
        }

        // Resolve author snapshot from PK01 submitter
        $authorData = $this->resolveAuthor($pkWorkflow);

        // Assign nomer_program (next sequential per team per PP period)
        $nomerProgram = $this->getNextNomerProgram($pkWorkflow);

        // Create pk04_program_tahunan
        $pk04 = Pk04ProgramTahunan::create([
            'pk_workflow_id' => $pkWorkflow->id,
            'revision' => 0,
            'kode_kategori' => $pk01->kode_kategori,
            'nama_program' => $pk01->nama_program,
            'deskripsi_program' => $pk01->deskripsi_program,
            'tujuan_program' => $pk01->tujuan_program,
            'nomer_program' => $nomerProgram,
            ...$authorData,
        ]);

        // Resolve kode_team for kode anggaran
        $kodeTeam = $this->resolveKodeTeam($pkWorkflow, $pp06);
        $tahun = $this->resolveTahun($pkWorkflow);

        // Copy kegiatan → pk04_kegiatan
        $nomerKegiatan = 0;
        foreach ($pk01->kegiatan as $kegiatan) {
            $nomerKegiatan++;

            $pk04Kegiatan = Pk04Kegiatan::create([
                'pk04_program_tahunan_id' => $pk04->id,
                'nama_kegiatan' => $kegiatan->nama_kegiatan,
                'bulan' => $kegiatan->bulan,
                'nomer_kegiatan' => $nomerKegiatan,
                'source' => 'pk',
            ]);

            // Copy anggaran → pk04_anggaran
            $nomerAnggaran = 0;
            foreach ($kegiatan->anggaran as $anggaran) {
                $nomerAnggaran++;

                $pk04Anggaran = Pk04Anggaran::create([
                    'pk04_kegiatan_id' => $pk04Kegiatan->id,
                    'kode_bidang' => $anggaran->kode_bidang,
                    'kode_sub_bidang' => $anggaran->kode_sub_bidang,
                    'kode_jenis' => $anggaran->kode_jenis,
                    'mata_anggaran' => $anggaran->mata_anggaran,
                    'deskripsi_pk' => $anggaran->deskripsi_pk,
                    'nominal_anggaran' => $anggaran->nominal_anggaran,
                    'nomer_anggaran' => $nomerAnggaran,
                    'revisi_terakhir' => 0,
                    'status_item' => 'active',
                    'source' => 'pk',
                ]);

                // Generate kode anggaran
                $this->generateKodeAnggaran(
                    $pk04Anggaran,
                    $pk04,
                    $pk04Kegiatan,
                    $kodeTeam,
                    $tahun,
                    $pkWorkflow->tipe,
                );
            }

            // Copy kuisioner → pk04_kuisioner
            foreach ($kegiatan->kuisioner as $kuisioner) {
                Pk04Kuisioner::create([
                    'pk04_kegiatan_id' => $pk04Kegiatan->id,
                    'kode_kuisioner' => $kuisioner->kode_kuisioner,
                    'pertanyaan' => $kuisioner->pertanyaan,
                    'tipe' => $kuisioner->tipe,
                    'satuan' => $kuisioner->satuan,
                ]);
            }
        }

        // Generate verification code
        $this->generateVerificationCode($pk04);

        return $pk04;
    }

    /**
     * Check if adding this PK's anggaran would exceed the team's plafon.
     *
     * @throws \RuntimeException on budget violation
     */
    private function checkBudgetHardBlock(PkWorkflow $pkWorkflow, Pk01Data $pk01, Pp06PeriodeTahunan $pp06): void
    {
        $plafon = (float) ($pp06->itemPlafonAnggaran()
            ->where('team_id', $pkWorkflow->team_id)
            ->value('plafon_anggaran') ?? 0);

        // Sum of active anggaran from other completed raker PKs (scoped to latest revision)
        $accepted = $this->sumAcceptedAnggaran($pkWorkflow, $pkWorkflow->id);

        // This PK's total from PK01
        $thisPkTotal = (float) $pk01->kegiatan()
            ->with('anggaran')
            ->get()
            ->flatMap(fn ($k) => $k->anggaran)
            ->sum('nominal_anggaran');

        if (($accepted + $thisPkTotal) > $plafon) {
            $sisa = $plafon - $accepted;
            $formattedSisa = 'Rp '.number_format($sisa, 0, ',', '.');
            $formattedTotal = 'Rp '.number_format($thisPkTotal, 0, ',', '.');

            throw new \RuntimeException(
                "Total anggaran {$formattedTotal} melebihi sisa plafon tim. Sisa plafon: {$formattedSisa}."
            );
        }
    }

    /**
     * Get the next sequential nomer_program for this team within the PP period.
     */
    private function getNextNomerProgram(PkWorkflow $pkWorkflow): int
    {
        $maxNomer = Pk04ProgramTahunan::query()
            ->whereHas('pkWorkflow', fn ($q) => $q
                ->where('team_id', $pkWorkflow->team_id)
                ->where('pp_workflow_id', $pkWorkflow->pp_workflow_id)
                ->where('workspace_id', $pkWorkflow->workspace_id)
                ->whereNull('deleted_at')
            )
            ->max('nomer_program');

        return ($maxNomer ?? 0) + 1;
    }

    /**
     * Resolve kode_team from PP06 plafon for this team.
     */
    private function resolveKodeTeam(PkWorkflow $pkWorkflow, Pp06PeriodeTahunan $pp06): string
    {
        $kodeTeam = $pp06->itemPlafonAnggaran()
            ->where('team_id', $pkWorkflow->team_id)
            ->value('kode_team');

        if (! $kodeTeam) {
            throw new \RuntimeException('Tim tidak memiliki kode_team dalam plafon PP06.');
        }

        return $kodeTeam;
    }

    /**
     * Resolve tahun from PP period.
     */
    private function resolveTahun(PkWorkflow $pkWorkflow): int
    {
        $pp01 = $pkWorkflow->ppWorkflow?->latestPp01();

        return (int) ($pp01?->tahun ?? now()->year);
    }

    /**
     * Generate kode anggaran (baru + lama formats) for a pk04_anggaran row.
     *
     * Format Baru (13 segments):
     * T.XX.XX.XX.XX.XX.XXX.XXX.XXX.XXXX.XX.revN.MN
     * Tipe.Bidang.SubBidang.Tim.Jenis.Kategori.Program.Kegiatan.Anggaran.Tahun.Bulan.Revisi.Tarik
     *
     * Format Lama (6 segments):
     * XX.XX.XX.XX.XX.XXX
     * Bidang.SubBidang.Tim.Jenis.Kategori.Program
     */
    private function generateKodeAnggaran(
        Pk04Anggaran $anggaran,
        Pk04ProgramTahunan $pk04,
        Pk04Kegiatan $kegiatan,
        string $kodeTeam,
        int $tahun,
        string $tipe,
    ): void {
        $tipeCode = $tipe === 'raker' ? 'R' : 'P';
        $bidang = str_pad($anggaran->kode_bidang ?? '00', 2, '0', STR_PAD_LEFT);
        $subBidang = str_pad($anggaran->kode_sub_bidang ?? '00', 2, '0', STR_PAD_LEFT);
        $tim = str_pad($kodeTeam, 2, '0', STR_PAD_LEFT);
        $jenis = str_pad($anggaran->kode_jenis ?? '00', 2, '0', STR_PAD_LEFT);
        $kategori = str_pad($pk04->kode_kategori ?? '00', 2, '0', STR_PAD_LEFT);
        $program = str_pad((string) $pk04->nomer_program, 3, '0', STR_PAD_LEFT);
        $kegiatanNomer = str_pad((string) $kegiatan->nomer_kegiatan, 3, '0', STR_PAD_LEFT);
        $anggaranNomer = str_pad((string) $anggaran->nomer_anggaran, 3, '0', STR_PAD_LEFT);
        $tahunStr = str_pad((string) $tahun, 4, '0', STR_PAD_LEFT);
        $bulan = str_pad((string) ($kegiatan->bulan ?? 0), 2, '0', STR_PAD_LEFT);
        $revisi = 'rev'.($anggaran->revisi_terakhir ?? 0);
        $tarik = 'M'.$this->resolveTarikDepth($anggaran);

        $kodeBaru = implode('.', [
            $tipeCode, $bidang, $subBidang, $tim, $jenis, $kategori,
            $program, $kegiatanNomer, $anggaranNomer,
            $tahunStr, $bulan, $revisi, $tarik,
        ]);

        $kodeLama = implode('.', [
            $bidang, $subBidang, $tim, $jenis, $kategori, $program,
        ]);

        $anggaran->update([
            'kode_anggaran_baru' => $kodeBaru,
            'kode_anggaran_lama' => $kodeLama,
        ]);
    }

    /**
     * Resolve tarik maju chain depth from previous_anggaran_id.
     *
     * M0 = original PK item, M1 = first tarik maju, M2 = chained, etc.
     */
    private function resolveTarikDepth(Pk04Anggaran $anggaran): int
    {
        if ($anggaran->source !== 'tarik_maju' || ! $anggaran->previous_anggaran_id) {
            return 0;
        }

        $depth = 0;
        $currentId = $anggaran->previous_anggaran_id;

        while ($currentId) {
            $depth++;
            $prev = Pk04Anggaran::where('id', $currentId)
                ->where('source', 'tarik_maju')
                ->value('previous_anggaran_id');
            $currentId = $prev;
        }

        return $depth;
    }

    /**
     * Build canonical data array for verification code generation.
     *
     * Includes program info, kegiatan, anggaran, kuisioner — excludes
     * author snapshots, timestamps, and PABD/PRBL side-effect columns.
     *
     * @return array<string, mixed>
     */
    public function buildCanonicalData(Pk04ProgramTahunan $pk04): array
    {
        $pk04->loadMissing(['kegiatan.anggaran', 'kegiatan.kuisioner']);

        return [
            'kode_kategori' => $pk04->kode_kategori,
            'nama_program' => $pk04->nama_program,
            'deskripsi_program' => $pk04->deskripsi_program,
            'tujuan_program' => $pk04->tujuan_program,
            'nomer_program' => (int) $pk04->nomer_program,
            'kegiatan' => $pk04->kegiatan
                ->sortBy('nomer_kegiatan')
                ->map(fn (Pk04Kegiatan $k) => [
                    'nama_kegiatan' => $k->nama_kegiatan,
                    'bulan' => (int) $k->bulan,
                    'nomer_kegiatan' => (int) $k->nomer_kegiatan,
                    'anggaran' => $k->anggaran
                        ->sortBy('nomer_anggaran')
                        ->map(fn (Pk04Anggaran $a) => [
                            'kode_bidang' => $a->kode_bidang,
                            'kode_sub_bidang' => $a->kode_sub_bidang,
                            'kode_jenis' => $a->kode_jenis,
                            'mata_anggaran' => $a->mata_anggaran,
                            'deskripsi_pk' => $a->deskripsi_pk ?? '',
                            'nominal_anggaran' => (int) $a->nominal_anggaran,
                            'nomer_anggaran' => (int) $a->nomer_anggaran,
                        ])->values()->all(),
                    'kuisioner' => $k->kuisioner
                        ->sortBy('id')
                        ->map(fn (Pk04Kuisioner $q) => [
                            'kode_kuisioner' => $q->kode_kuisioner ?? '',
                            'pertanyaan' => $q->pertanyaan,
                            'tipe' => $q->tipe,
                            'satuan' => $q->satuan ?? '',
                        ])->values()->all(),
                ])->values()->all(),
        ];
    }

    /**
     * Generate and store a deterministic verification code for a PK04 row.
     *
     * SHA-256 of canonical JSON → base36 truncate to 8 chars.
     */
    public function generateVerificationCode(Pk04ProgramTahunan $pk04): string
    {
        $canonical = $this->buildCanonicalData($pk04);
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $json);
        $code = strtoupper(substr(base_convert(substr($hash, 0, 16), 16, 36), 0, 8));

        $pk04->update(['verification_code' => $code]);

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
    public function generateExportFiles(Pk04ProgramTahunan $pk04, int $userId, int $workspaceId): array
    {
        $result = ['pdf_file_id' => null, 'excel_file_id' => null];
        $pkWorkflow = $pk04->pkWorkflow;
        $teamName = $pkWorkflow?->team?->name ?? 'Unknown';
        $tahun = $this->resolveTahun($pkWorkflow);

        // PDF
        try {
            $pdfService = app(Pk04PdfExportService::class);
            $tempPath = $pdfService->generate($pk04);
            $filename = "PK04-{$teamName}-{$tahun}-Rev{$pk04->revision}.pdf";
            $storagePath = "exports/pk/{$pkWorkflow->id}/{$filename}";

            Storage::disk('local')->put($storagePath, file_get_contents($tempPath));
            @unlink($tempPath);

            $file = File::create([
                'original_filename' => $filename,
                'filename' => $filename,
                'mime_type' => 'application/pdf',
                'size' => Storage::disk('local')->size($storagePath),
                'disk' => 'local',
                'path' => $storagePath,
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
                'attachable_type' => Pk04ProgramTahunan::class,
                'attachable_id' => $pk04->id,
                'source_route' => 'pk04.export.pdf',
            ]);
            $result['pdf_file_id'] = $file->id;
        } catch (\Throwable $e) {
            Log::warning("PK04 PDF export failed for PK04#{$pk04->id}: {$e->getMessage()}");
        }

        // Excel
        try {
            $excelService = app(Pk04ExcelExportService::class);
            $tempPath = $excelService->generate($pk04);
            $filename = "PK04-{$teamName}-{$tahun}-Rev{$pk04->revision}.xlsx";
            $storagePath = "exports/pk/{$pkWorkflow->id}/{$filename}";

            Storage::disk('local')->put($storagePath, file_get_contents($tempPath));
            @unlink($tempPath);

            $file = File::create([
                'original_filename' => $filename,
                'filename' => $filename,
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'size' => Storage::disk('local')->size($storagePath),
                'disk' => 'local',
                'path' => $storagePath,
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
                'attachable_type' => Pk04ProgramTahunan::class,
                'attachable_id' => $pk04->id,
                'source_route' => 'pk04.export.excel',
            ]);
            $result['excel_file_id'] = $file->id;
        } catch (\Throwable $e) {
            Log::warning("PK04 Excel export failed for PK04#{$pk04->id}: {$e->getMessage()}");
        }

        return $result;
    }

    /**
     * Append export file IDs to the matching PK04 'completed' history entry.
     */
    public function appendExportFilesToHistory(PkWorkflow $pkWorkflow, array $exportResult, int $revision): void
    {
        $newFileIds = array_values(array_filter([
            $exportResult['pdf_file_id'],
            $exportResult['excel_file_id'],
        ]));

        if (empty($newFileIds)) {
            return;
        }

        $history = $pkWorkflow->history ?? [];

        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (
                ($history[$i]['step'] ?? '') === 'PK04'
                && ($history[$i]['action'] ?? '') === 'completed'
                && ($history[$i]['revision'] ?? null) === $revision
            ) {
                $existing = $history[$i]['files'] ?? [];
                $history[$i]['files'] = array_values(array_unique(array_merge($existing, $newFileIds)));

                break;
            }
        }

        $pkWorkflow->update(['history' => $history]);
    }

    /**
     * Resolve PK01 author snapshot from history.
     *
     * @return array<string, mixed>
     */
    private function resolveAuthor(PkWorkflow $pkWorkflow): array
    {
        $history = $pkWorkflow->history ?? [];

        // Find latest PK01 submitted entry
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['step'] ?? '') === 'PK01' && ($history[$i]['action'] ?? '') === 'submitted') {
                $entry = $history[$i];
                $userId = $entry['by'] ?? null;
                $user = $userId ? User::find($userId) : null;

                return [
                    'pk01_created_by_user_name' => $user?->name ?? 'System',
                    'pk01_created_by_role_name' => $this->resolveRoleName($entry['role'] ?? null),
                    'pk01_created_by_team_name' => $this->resolveTeamName($entry['team'] ?? null),
                    'pk01_created_by_organization_name' => $this->resolveOrgName($entry['org'] ?? null),
                    'pk01_created_by_workspace_name' => $this->resolveWorkspaceName($entry['workspace'] ?? null),
                    'pk01_created_at' => $entry['at'] ?? now()->toIso8601String(),
                ];
            }
        }

        // Fallback: resolve from workflow creator metadata
        $creatorUser = $pkWorkflow->created_by_user_id ? User::find($pkWorkflow->created_by_user_id) : null;

        return [
            'pk01_created_by_user_name' => $creatorUser?->name ?? 'System',
            'pk01_created_by_role_name' => $this->resolveRoleName($pkWorkflow->created_by_role_id),
            'pk01_created_by_team_name' => $this->resolveTeamName($pkWorkflow->created_by_team_id),
            'pk01_created_by_organization_name' => $this->resolveOrgName($pkWorkflow->created_by_org_id),
            'pk01_created_by_workspace_name' => $this->resolveWorkspaceName($pkWorkflow->workspace_id),
            'pk01_created_at' => $pkWorkflow->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }

    private function getLatestPp06(PkWorkflow $pkWorkflow): ?Pp06PeriodeTahunan
    {
        return $pkWorkflow->ppWorkflow?->latestPp06();
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

        return \App\Models\Workspace::find($workspaceId)?->name ?? 'Unknown';
    }

    // ──────────────────────────────────────────────────────────
    //  PK05 Revision — C + Carbon Copy Recompile
    // ──────────────────────────────────────────────────────────

    /**
     * Recompile PK04 from PK05 revision using C + Carbon Copy strategy.
     *
     * Snapshots current live rows as frozen copies on the OLD pk04_program_tahunan,
     * then updates live rows in place. Downstream FKs (PABD/PRBL → pk04_anggaran)
     * always point to live rows → never break.
     *
     * Called inside DB::transaction from pk05Submit.
     * Assumes validateLockedItems() has already passed.
     *
     * @throws \RuntimeException if PP06 or PK04 not found
     */
    public function recompileFromRevision(PkWorkflow $pkWorkflow, array $draftData, int $newRevision): Pk04ProgramTahunan
    {
        $currentPk04 = $pkWorkflow->latestPk04();
        if (! $currentPk04) {
            throw new \RuntimeException('PK04 tidak ditemukan.');
        }
        $currentPk04->load(['kegiatan.anggaran', 'kegiatan.kuisioner']);

        $pp06 = $this->getLatestPp06($pkWorkflow);
        if (! $pp06) {
            throw new \RuntimeException('PP06 tidak ditemukan.');
        }
        $kodeTeam = $this->resolveKodeTeam($pkWorkflow, $pp06);
        $tahun = $this->resolveTahun($pkWorkflow);

        // 1. SNAPSHOT: copy current live rows as frozen copies on OLD pk04
        $this->snapshotCurrentState($currentPk04);

        // 2. CREATE new pk04_program_tahunan (preserves author + nomer_program + locked kode_kategori)
        $newPk04 = Pk04ProgramTahunan::create([
            'pk_workflow_id' => $pkWorkflow->id,
            'revision' => $newRevision,
            'kode_kategori' => $currentPk04->kode_kategori,
            'nama_program' => $draftData['nama_program'],
            'deskripsi_program' => $draftData['deskripsi_program'],
            'tujuan_program' => $draftData['tujuan_program'],
            'nomer_program' => $currentPk04->nomer_program,
            'pk01_created_by_user_name' => $currentPk04->pk01_created_by_user_name,
            'pk01_created_by_role_name' => $currentPk04->pk01_created_by_role_name,
            'pk01_created_by_team_name' => $currentPk04->pk01_created_by_team_name,
            'pk01_created_by_organization_name' => $currentPk04->pk01_created_by_organization_name,
            'pk01_created_by_workspace_name' => $currentPk04->pk01_created_by_workspace_name,
            'pk01_created_at' => $currentPk04->pk01_created_at,
        ]);

        // Build index of draft kegiatan by pk04_kegiatan_id
        $draftKegiatanById = [];
        $newDraftKegiatan = [];
        foreach ($draftData['kegiatan'] ?? [] as $dk) {
            if (! empty($dk['pk04_kegiatan_id'])) {
                $draftKegiatanById[(int) $dk['pk04_kegiatan_id']] = $dk;
            } else {
                $newDraftKegiatan[] = $dk;
            }
        }

        // 3. Process existing kegiatan: REASSIGN to new parent + APPLY editable changes (no deletion, bulan locked)
        $maxNomerKegiatan = (int) $currentPk04->kegiatan->max('nomer_kegiatan');

        foreach ($currentPk04->kegiatan as $kegiatan) {
            $dk = $draftKegiatanById[$kegiatan->id] ?? [];

            // Reassign to new parent + update only nama_kegiatan (bulan is locked)
            $kegiatan->update([
                'pk04_program_tahunan_id' => $newPk04->id,
                'nama_kegiatan' => $dk['nama_kegiatan'] ?? $kegiatan->nama_kegiatan,
            ]);

            if (! empty($dk)) {
                $this->applyAnggaranChanges($kegiatan, $dk, $newRevision);
                $this->applyKuisionerChanges($kegiatan, $dk);
            }
        }

        // 4. INSERT new kegiatan
        foreach ($newDraftKegiatan as $dk) {
            $maxNomerKegiatan++;

            $newKegiatan = Pk04Kegiatan::create([
                'pk04_program_tahunan_id' => $newPk04->id,
                'nama_kegiatan' => $dk['nama_kegiatan'],
                'bulan' => (int) $dk['bulan'],
                'nomer_kegiatan' => $maxNomerKegiatan,
                'source' => 'pk',
            ]);

            $nomerAnggaran = 0;
            foreach ($dk['anggaran'] ?? [] as $da) {
                $nomerAnggaran++;
                Pk04Anggaran::create([
                    'pk04_kegiatan_id' => $newKegiatan->id,
                    'kode_bidang' => $da['kode_bidang'],
                    'kode_sub_bidang' => $da['kode_sub_bidang'],
                    'kode_jenis' => $da['kode_jenis'],
                    'mata_anggaran' => $da['mata_anggaran'],
                    'deskripsi_pk' => $da['deskripsi_pk'] ?? null,
                    'nominal_anggaran' => $da['nominal_anggaran'] ?? 0,
                    'nomer_anggaran' => $nomerAnggaran,
                    'revisi_terakhir' => $newRevision,
                    'status_item' => 'active',
                    'source' => 'pk',
                ]);
            }

            foreach ($dk['kuisioner'] ?? [] as $dq) {
                Pk04Kuisioner::create([
                    'pk04_kegiatan_id' => $newKegiatan->id,
                    'kode_kuisioner' => $dq['kode_kuisioner'] ?? null,
                    'pertanyaan' => $dq['pertanyaan'],
                    'tipe' => $dq['tipe'],
                    'satuan' => $dq['satuan'] ?? null,
                ]);
            }
        }

        // 5. REGENERATE kode_anggaran for ALL live anggaran
        $tipe = $pkWorkflow->tipe;
        $newPk04->load(['kegiatan.anggaran']);
        foreach ($newPk04->kegiatan as $kegiatan) {
            foreach ($kegiatan->anggaran as $anggaran) {
                $this->generateKodeAnggaran($anggaran, $newPk04, $kegiatan, $kodeTeam, $tahun, $tipe);
            }
        }

        // 6. Generate verification code
        $this->generateVerificationCode($newPk04);

        return $newPk04;
    }

    /**
     * Validate PK05 revision draft against locking rules.
     *
     * Locked fields (cannot change): kode_kategori, bulan, kode_bidang, kode_sub_bidang, kode_jenis.
     * Editable fields: mata_anggaran, deskripsi_pk, nominal_anggaran, nama_kegiatan.
     * No existing kegiatan or anggaran can be deleted. New ones can be added.
     * Kuisioner can be added, edited, or deleted freely.
     *
     * @return list<string> Error messages (empty = valid)
     */
    public function validateLockedItems(Pk04ProgramTahunan $currentPk04, array $draftData): array
    {
        $errors = [];
        $currentPk04->loadMissing(['kegiatan.anggaran', 'kegiatan.kuisioner']);

        // 1. Program-level: kode_kategori locked
        if (($currentPk04->kode_kategori ?? '') !== ($draftData['kode_kategori'] ?? '')) {
            $errors[] = 'Kategori pelayanan tidak dapat diubah setelah kompilasi.';
        }

        // Build draft kegiatan index
        $draftKegiatanById = [];
        foreach ($draftData['kegiatan'] ?? [] as $dk) {
            if (! empty($dk['pk04_kegiatan_id'])) {
                $draftKegiatanById[(int) $dk['pk04_kegiatan_id']] = $dk;
            }
        }

        // 2. Check each existing kegiatan
        foreach ($currentPk04->kegiatan as $kegiatan) {
            // Kegiatan cannot be deleted
            if (! isset($draftKegiatanById[$kegiatan->id])) {
                $errors[] = "Kegiatan \"{$kegiatan->nama_kegiatan}\" tidak dapat dihapus setelah kompilasi.";

                continue;
            }

            $dk = $draftKegiatanById[$kegiatan->id];

            // Bulan locked
            if ((int) $kegiatan->bulan !== (int) ($dk['bulan'] ?? 0)) {
                $errors[] = "Bulan kegiatan \"{$kegiatan->nama_kegiatan}\" tidak dapat diubah setelah kompilasi.";
            }

            // Build draft anggaran index for this kegiatan
            $draftAnggaranById = [];
            foreach ($dk['anggaran'] ?? [] as $da) {
                if (! empty($da['pk04_anggaran_id'])) {
                    $draftAnggaranById[(int) $da['pk04_anggaran_id']] = $da;
                }
            }

            // 3. Check each existing anggaran
            $kegiatanHasRealisasiLock = false;
            foreach ($kegiatan->anggaran as $anggaran) {
                // Anggaran cannot be deleted
                if (! isset($draftAnggaranById[$anggaran->id])) {
                    $errors[] = "Item anggaran \"{$anggaran->mata_anggaran}\" tidak dapat dihapus setelah kompilasi.";

                    continue;
                }

                $da = $draftAnggaranById[$anggaran->id];

                // Kode fields locked
                foreach (['kode_bidang', 'kode_sub_bidang', 'kode_jenis'] as $field) {
                    if (($anggaran->$field ?? '') !== ($da[$field] ?? '')) {
                        $label = match ($field) {
                            'kode_bidang' => 'Bidang',
                            'kode_sub_bidang' => 'Sub Bidang',
                            'kode_jenis' => 'Jenis',
                        };
                        $errors[] = "{$label} pada \"{$anggaran->mata_anggaran}\" tidak dapat diubah setelah kompilasi.";
                    }
                }

                // Tier 3: realisasi lock — track for kuisioner deletion check
                if ($anggaran->hasRealisasiLock()) {
                    $kegiatanHasRealisasiLock = true;
                }
            }

            // 4. Tier 3: kuisioner deletion blocked when kegiatan has realisasi lock
            if ($kegiatanHasRealisasiLock) {
                $draftKuisionerIds = collect($dk['kuisioner'] ?? [])
                    ->pluck('pk04_kuisioner_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->all();

                foreach ($kegiatan->kuisioner as $kuisioner) {
                    if (! in_array($kuisioner->id, $draftKuisionerIds, true)) {
                        $errors[] = "Kuisioner \"{$kuisioner->pertanyaan}\" pada kegiatan \"{$kegiatan->nama_kegiatan}\" tidak dapat dihapus karena sudah dilaporkan di PRBL.";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Compute changelog diff between current PK04 revision and PK05 draft_data.
     *
     * Uses pk04_*_id for matching (not name-based like PK01 diff).
     *
     * @return list<array<string, mixed>>
     */
    public function computePk05Diff(Pk04ProgramTahunan $currentPk04, array $draftData): array
    {
        $changes = [];
        $currentPk04->loadMissing(['kegiatan.anggaran', 'kegiatan.kuisioner']);

        // 1. Program-level editable fields (kode_kategori is locked)
        foreach (['nama_program', 'deskripsi_program', 'tujuan_program'] as $field) {
            if (($currentPk04->$field ?? '') !== ($draftData[$field] ?? '')) {
                $changes[] = [
                    'type' => 'program_changed',
                    'field' => $field,
                    'old' => $currentPk04->$field,
                    'new' => $draftData[$field] ?? null,
                ];
            }
        }

        // Build index maps
        $currentKegiatanMap = $currentPk04->kegiatan->keyBy('id');
        $draftKegiatanById = [];
        $newDraftKegiatan = [];
        foreach ($draftData['kegiatan'] ?? [] as $dk) {
            if (! empty($dk['pk04_kegiatan_id'])) {
                $draftKegiatanById[(int) $dk['pk04_kegiatan_id']] = $dk;
            } else {
                $newDraftKegiatan[] = $dk;
            }
        }

        // 2. Added kegiatan
        foreach ($newDraftKegiatan as $dk) {
            $changes[] = ['type' => 'kegiatan_added', 'nama_kegiatan' => $dk['nama_kegiatan'] ?? '', 'bulan' => $dk['bulan'] ?? null];
        }

        // 3. Existing kegiatan cannot be removed (locked), skip removed diff

        // 4. Changes within matched kegiatan (only editable fields)
        foreach ($draftKegiatanById as $kId => $dk) {
            $kegiatan = $currentKegiatanMap->get($kId);
            if (! $kegiatan) {
                continue;
            }

            // Kegiatan: only nama_kegiatan is editable (bulan is locked)
            if (($kegiatan->nama_kegiatan ?? '') !== ($dk['nama_kegiatan'] ?? '')) {
                $changes[] = ['type' => 'kegiatan_changed', 'pk04_kegiatan_id' => $kId, 'field' => 'nama_kegiatan', 'old' => $kegiatan->nama_kegiatan, 'new' => $dk['nama_kegiatan'] ?? ''];
            }

            // Anggaran changes (only editable fields: mata_anggaran, deskripsi_pk, nominal)
            $currentAnggaranMap = $kegiatan->anggaran->keyBy('id');
            $draftAnggaranById = [];
            $newDraftAnggaran = [];
            foreach ($dk['anggaran'] ?? [] as $da) {
                if (! empty($da['pk04_anggaran_id'])) {
                    $draftAnggaranById[(int) $da['pk04_anggaran_id']] = $da;
                } else {
                    $newDraftAnggaran[] = $da;
                }
            }

            // Existing anggaran cannot be removed (locked), skip removed diff

            foreach ($newDraftAnggaran as $da) {
                $changes[] = ['type' => 'anggaran_added', 'mata_anggaran' => $da['mata_anggaran'] ?? ''];
            }

            foreach ($draftAnggaranById as $aId => $da) {
                $anggaran = $currentAnggaranMap->get($aId);
                if (! $anggaran) {
                    continue;
                }

                foreach (['mata_anggaran', 'deskripsi_pk'] as $field) {
                    if (($anggaran->$field ?? '') !== ($da[$field] ?? '')) {
                        $changes[] = ['type' => 'anggaran_changed', 'pk04_anggaran_id' => $aId, 'mata_anggaran' => $da['mata_anggaran'] ?? $anggaran->mata_anggaran, 'field' => $field, 'old' => $anggaran->$field, 'new' => $da[$field] ?? null];
                    }
                }

                if ((float) $anggaran->nominal_anggaran != (float) ($da['nominal_anggaran'] ?? 0)) {
                    $changes[] = ['type' => 'anggaran_changed', 'pk04_anggaran_id' => $aId, 'mata_anggaran' => $da['mata_anggaran'] ?? $anggaran->mata_anggaran, 'field' => 'nominal_anggaran', 'old' => (float) $anggaran->nominal_anggaran, 'new' => (float) ($da['nominal_anggaran'] ?? 0)];
                }
            }

            // Kuisioner changes
            $currentKuisionerMap = $kegiatan->kuisioner->keyBy('id');
            $draftKuisionerById = [];
            $newDraftKuisioner = [];
            foreach ($dk['kuisioner'] ?? [] as $dq) {
                if (! empty($dq['pk04_kuisioner_id'])) {
                    $draftKuisionerById[(int) $dq['pk04_kuisioner_id']] = $dq;
                } else {
                    $newDraftKuisioner[] = $dq;
                }
            }

            foreach ($currentKuisionerMap as $qId => $q) {
                if (! isset($draftKuisionerById[$qId])) {
                    $changes[] = ['type' => 'kuisioner_removed', 'pertanyaan' => $q->pertanyaan];
                }
            }

            foreach ($newDraftKuisioner as $dq) {
                $changes[] = ['type' => 'kuisioner_added', 'pertanyaan' => $dq['pertanyaan'] ?? ''];
            }
        }

        return $changes;
    }

    /**
     * Check if the PK05 revision's new total would exceed the team's plafon.
     *
     * Called BEFORE recompile (fail fast). Only for raker PKs.
     *
     * @throws \RuntimeException on budget violation
     */
    public function checkBudgetHardBlockForRevision(PkWorkflow $pkWorkflow, array $draftData): void
    {
        $pp06 = $this->getLatestPp06($pkWorkflow);
        if (! $pp06) {
            throw new \RuntimeException('PP06 tidak ditemukan.');
        }

        $plafon = (float) ($pp06->itemPlafonAnggaran()
            ->where('team_id', $pkWorkflow->team_id)
            ->value('plafon_anggaran') ?? 0);

        // Sum from OTHER completed raker PKs (excluding this one)
        $accepted = $this->sumAcceptedAnggaran($pkWorkflow, $pkWorkflow->id);

        // This PK's new total from draft_data
        $thisPkTotal = 0.0;
        foreach ($draftData['kegiatan'] ?? [] as $dk) {
            foreach ($dk['anggaran'] ?? [] as $da) {
                $thisPkTotal += (float) ($da['nominal_anggaran'] ?? 0);
            }
        }

        if (($accepted + $thisPkTotal) > $plafon) {
            $sisa = $plafon - $accepted;
            $formattedSisa = 'Rp '.number_format($sisa, 0, ',', '.');
            $formattedTotal = 'Rp '.number_format($thisPkTotal, 0, ',', '.');
            $formattedAccepted = 'Rp '.number_format($accepted, 0, ',', '.');

            throw new \RuntimeException(
                "Total anggaran setelah revisi ({$formattedTotal}) melebihi sisa plafon ({$formattedSisa}). Sudah ditetapkan PK lain: {$formattedAccepted}."
            );
        }
    }

    // ──────────────────────────────────────────────────────────
    //  PABD02B — Tarik Maju Recompile
    // ──────────────────────────────────────────────────────────

    /**
     * Recompile PK04 after tarik maju approval (PABD02B approve).
     *
     * Snapshot-then-move pattern (same as PK05 recompile):
     * 1. Snapshot current state on OLD pk04
     * 2. Create NEW pk04 with incremented revision
     * 3. Reassign live kegiatan to new parent
     * 4. Apply tarik maju changes (zero old, create new in destination month)
     * 5. Regenerate all kode_anggaran
     *
     * Called inside DB::transaction from pabd02bApprove.
     *
     * @param  list<array{pk04_anggaran_id: int, bulan_tujuan: int}>  $tarikMajuItems
     *
     * @throws \RuntimeException if PK04 or PP06 not found
     */
    public function recompileFromTarikMaju(PkWorkflow $pkWorkflow, array $tarikMajuItems): Pk04ProgramTahunan
    {
        $currentPk04 = $pkWorkflow->latestPk04();
        if (! $currentPk04) {
            throw new \RuntimeException('PK04 tidak ditemukan.');
        }
        $currentPk04->load(['kegiatan.anggaran', 'kegiatan.kuisioner']);

        $pp06 = $this->getLatestPp06($pkWorkflow);
        if (! $pp06) {
            throw new \RuntimeException('PP06 tidak ditemukan.');
        }
        $kodeTeam = $this->resolveKodeTeam($pkWorkflow, $pp06);
        $tahun = $this->resolveTahun($pkWorkflow);
        $newRevision = $currentPk04->revision + 1;

        // 1. Snapshot current state on OLD pk04
        $this->snapshotCurrentState($currentPk04);

        // 2. Create new pk04_program_tahunan (carry forward all fields)
        $newPk04 = Pk04ProgramTahunan::create([
            'pk_workflow_id' => $pkWorkflow->id,
            'revision' => $newRevision,
            'kode_kategori' => $currentPk04->kode_kategori,
            'nama_program' => $currentPk04->nama_program,
            'deskripsi_program' => $currentPk04->deskripsi_program,
            'tujuan_program' => $currentPk04->tujuan_program,
            'nomer_program' => $currentPk04->nomer_program,
            'pk01_created_by_user_name' => $currentPk04->pk01_created_by_user_name,
            'pk01_created_by_role_name' => $currentPk04->pk01_created_by_role_name,
            'pk01_created_by_team_name' => $currentPk04->pk01_created_by_team_name,
            'pk01_created_by_organization_name' => $currentPk04->pk01_created_by_organization_name,
            'pk01_created_by_workspace_name' => $currentPk04->pk01_created_by_workspace_name,
            'pk01_created_at' => $currentPk04->pk01_created_at,
        ]);

        // 3. Reassign all live kegiatan to new parent
        foreach ($currentPk04->kegiatan as $kegiatan) {
            $kegiatan->update(['pk04_program_tahunan_id' => $newPk04->id]);
        }

        // 4. Apply tarik maju changes
        // Index items by pk04_anggaran_id for fast lookup
        $tarikMap = collect($tarikMajuItems)->keyBy('pk04_anggaran_id');
        $maxNomerKegiatan = (int) $newPk04->kegiatan()->max('nomer_kegiatan');

        foreach ($tarikMap as $anggaranId => $item) {
            $oldAnggaran = Pk04Anggaran::find($anggaranId);
            if (! $oldAnggaran || $oldAnggaran->status_item !== 'active') {
                continue;
            }

            $bulanTujuan = (int) $item['bulan_tujuan'];
            $oldKegiatan = $oldAnggaran->pk04Kegiatan;

            // Capture original nominal before zeroing
            $originalNominal = $oldAnggaran->nominal_anggaran;

            // Zero old anggaran
            $oldAnggaran->update([
                'nominal_anggaran' => 0,
                'status_item' => 'ditarik_maju',
                'revisi_terakhir' => $newRevision,
            ]);

            // Create new kegiatan in destination month (copy from old)
            $maxNomerKegiatan++;
            $newKegiatan = Pk04Kegiatan::create([
                'pk04_program_tahunan_id' => $newPk04->id,
                'nama_kegiatan' => $oldKegiatan->nama_kegiatan,
                'bulan' => $bulanTujuan,
                'nomer_kegiatan' => $maxNomerKegiatan,
                'source' => 'tarik_maju',
                'source_pabd_workflow_id' => $item['pabd_workflow_id'] ?? null,
                'previous_kegiatan_id' => $oldKegiatan->id,
            ]);

            // Create new anggaran with original nominal
            $newAnggaran = Pk04Anggaran::create([
                'pk04_kegiatan_id' => $newKegiatan->id,
                'kode_bidang' => $oldAnggaran->kode_bidang,
                'kode_sub_bidang' => $oldAnggaran->kode_sub_bidang,
                'kode_jenis' => $oldAnggaran->kode_jenis,
                'mata_anggaran' => $oldAnggaran->mata_anggaran,
                'deskripsi_pk' => $oldAnggaran->deskripsi_pk,
                'nominal_anggaran' => $originalNominal,
                'nomer_anggaran' => 1,
                'revisi_terakhir' => $newRevision,
                'status_item' => 'active',
                'previous_anggaran_id' => $oldAnggaran->id,
                'source' => 'tarik_maju',
                'source_pabd_workflow_id' => $item['pabd_workflow_id'] ?? null,
            ]);

            // Copy kuisioner from old kegiatan
            foreach ($oldKegiatan->kuisioner ?? [] as $q) {
                Pk04Kuisioner::create([
                    'pk04_kegiatan_id' => $newKegiatan->id,
                    'kode_kuisioner' => $q->kode_kuisioner,
                    'pertanyaan' => $q->pertanyaan,
                    'tipe' => $q->tipe,
                    'satuan' => $q->satuan,
                ]);
            }
        }

        // 5. Regenerate kode_anggaran for ALL live anggaran
        $tipe = $pkWorkflow->tipe;
        $newPk04->load(['kegiatan.anggaran']);
        foreach ($newPk04->kegiatan as $kegiatan) {
            foreach ($kegiatan->anggaran as $anggaran) {
                $this->generateKodeAnggaran($anggaran, $newPk04, $kegiatan, $kodeTeam, $tahun, $tipe);
            }
        }

        // 6. Generate verification code
        $this->generateVerificationCode($newPk04);

        return $newPk04;
    }

    /**
     * Compile a PK Proposal (tipe='proposal') straight to PK04, skipping PK02A/02B/PK03.
     *
     * Creates a new pk04_program_tahunan with tipe='proposal' (excluded from plafon).
     * Used by PABD02B approve for proposal_baru items.
     *
     * Called inside DB::transaction from pabd02bApprove.
     *
     * @throws \RuntimeException if PK01 data or PP06 not found
     */
    public function compileProposalToPk04(PkWorkflow $proposalWorkflow, ?int $pabdWorkflowId = null): Pk04ProgramTahunan
    {
        $pk01 = $proposalWorkflow->pk01Data?->sortByDesc('id')->first();
        if (! $pk01) {
            throw new \RuntimeException('PK01 data untuk proposal tidak ditemukan.');
        }
        $pk01->load(['kegiatan.anggaran', 'kegiatan.kuisioner']);

        $pp06 = $this->getLatestPp06($proposalWorkflow);
        if (! $pp06) {
            throw new \RuntimeException('PP06 tidak ditemukan.');
        }

        $authorData = $this->resolveAuthor($proposalWorkflow);
        $nomerProgram = $this->getNextNomerProgram($proposalWorkflow);
        $kodeTeam = $this->resolveKodeTeam($proposalWorkflow, $pp06);
        $tahun = $this->resolveTahun($proposalWorkflow);

        // Create pk04_program_tahunan
        $pk04 = Pk04ProgramTahunan::create([
            'pk_workflow_id' => $proposalWorkflow->id,
            'revision' => 0,
            'kode_kategori' => $pk01->kode_kategori,
            'nama_program' => $pk01->nama_program,
            'deskripsi_program' => $pk01->deskripsi_program,
            'tujuan_program' => $pk01->tujuan_program,
            'nomer_program' => $nomerProgram,
            ...$authorData,
        ]);

        // Copy kegiatan → pk04_kegiatan
        $nomerKegiatan = 0;
        foreach ($pk01->kegiatan as $kegiatan) {
            $nomerKegiatan++;

            $pk04Kegiatan = Pk04Kegiatan::create([
                'pk04_program_tahunan_id' => $pk04->id,
                'nama_kegiatan' => $kegiatan->nama_kegiatan,
                'bulan' => $kegiatan->bulan,
                'nomer_kegiatan' => $nomerKegiatan,
                'source' => 'proposal',
                'source_pabd_workflow_id' => $pabdWorkflowId,
            ]);

            // Copy anggaran → pk04_anggaran
            $nomerAnggaran = 0;
            foreach ($kegiatan->anggaran as $anggaran) {
                $nomerAnggaran++;

                $pk04Anggaran = Pk04Anggaran::create([
                    'pk04_kegiatan_id' => $pk04Kegiatan->id,
                    'kode_bidang' => $anggaran->kode_bidang,
                    'kode_sub_bidang' => $anggaran->kode_sub_bidang,
                    'kode_jenis' => $anggaran->kode_jenis,
                    'mata_anggaran' => $anggaran->mata_anggaran,
                    'deskripsi_pk' => $anggaran->deskripsi_pk,
                    'nominal_anggaran' => $anggaran->nominal_anggaran,
                    'nomer_anggaran' => $nomerAnggaran,
                    'revisi_terakhir' => 0,
                    'status_item' => 'active',
                    'source' => 'proposal',
                    'source_pabd_workflow_id' => $pabdWorkflowId,
                ]);

                $this->generateKodeAnggaran(
                    $pk04Anggaran,
                    $pk04,
                    $pk04Kegiatan,
                    $kodeTeam,
                    $tahun,
                    'proposal',
                );
            }

            // Copy kuisioner → pk04_kuisioner
            foreach ($kegiatan->kuisioner as $kuisioner) {
                Pk04Kuisioner::create([
                    'pk04_kegiatan_id' => $pk04Kegiatan->id,
                    'kode_kuisioner' => $kuisioner->kode_kuisioner,
                    'pertanyaan' => $kuisioner->pertanyaan,
                    'tipe' => $kuisioner->tipe,
                    'satuan' => $kuisioner->satuan,
                ]);
            }
        }

        // Generate verification code
        $this->generateVerificationCode($pk04);

        // Record PK04 completion in proposal workflow history
        $proposalWorkflow->update([
            'history' => array_merge($proposalWorkflow->history ?? [], [
                [
                    'step' => 'PK04',
                    'action' => 'completed',
                    'by' => null,
                    'role' => null,
                    'team' => null,
                    'org' => null,
                    'workspace' => $proposalWorkflow->workspace_id,
                    'at' => now()->toIso8601String(),
                    'triggered_by' => ['step' => 'PABD02B', 'action' => 'approved'],
                    'revision' => 0,
                ],
            ]),
        ]);

        return $pk04;
    }

    // ──────────────────────────────────────────────────────────
    //  PK05 Private Helpers
    // ──────────────────────────────────────────────────────────

    /**
     * Snapshot current live kegiatan/anggaran/kuisioner as frozen copies
     * that stay attached to the OLD pk04_program_tahunan.
     */
    private function snapshotCurrentState(Pk04ProgramTahunan $currentPk04): void
    {
        foreach ($currentPk04->kegiatan as $kegiatan) {
            $snapshotKegiatan = Pk04Kegiatan::create([
                'pk04_program_tahunan_id' => $currentPk04->id,
                'nama_kegiatan' => $kegiatan->nama_kegiatan,
                'bulan' => $kegiatan->bulan,
                'nomer_kegiatan' => $kegiatan->nomer_kegiatan,
                'source' => $kegiatan->source,
                'source_pabd_workflow_id' => $kegiatan->source_pabd_workflow_id,
                'previous_kegiatan_id' => $kegiatan->previous_kegiatan_id,
            ]);

            foreach ($kegiatan->anggaran as $anggaran) {
                Pk04Anggaran::create([
                    'pk04_kegiatan_id' => $snapshotKegiatan->id,
                    'kode_bidang' => $anggaran->kode_bidang,
                    'kode_sub_bidang' => $anggaran->kode_sub_bidang,
                    'kode_jenis' => $anggaran->kode_jenis,
                    'mata_anggaran' => $anggaran->mata_anggaran,
                    'deskripsi_pk' => $anggaran->deskripsi_pk,
                    'nominal_anggaran' => $anggaran->nominal_anggaran,
                    'nomer_anggaran' => $anggaran->nomer_anggaran,
                    'revisi_terakhir' => $anggaran->revisi_terakhir,
                    'kode_anggaran_baru' => $anggaran->kode_anggaran_baru,
                    'kode_anggaran_lama' => $anggaran->kode_anggaran_lama,
                    'status_item' => $anggaran->status_item,
                    'previous_anggaran_id' => $anggaran->previous_anggaran_id,
                    'source' => $anggaran->source,
                    'source_pabd_workflow_id' => $anggaran->source_pabd_workflow_id,
                    'status_pencairan' => $anggaran->status_pencairan,
                    'tanggal_pencairan' => $anggaran->tanggal_pencairan,
                    'pencairan_pabd_workflow_id' => $anggaran->pencairan_pabd_workflow_id,
                ]);
            }

            foreach ($kegiatan->kuisioner as $kuisioner) {
                Pk04Kuisioner::create([
                    'pk04_kegiatan_id' => $snapshotKegiatan->id,
                    'kode_kuisioner' => $kuisioner->kode_kuisioner,
                    'pertanyaan' => $kuisioner->pertanyaan,
                    'tipe' => $kuisioner->tipe,
                    'satuan' => $kuisioner->satuan,
                ]);
            }
        }
    }

    /**
     * Apply anggaran changes from draft to existing kegiatan.
     *
     * Only editable fields (mata_anggaran, deskripsi_pk, nominal_anggaran) are updated.
     * Kode fields (bidang, sub_bidang, jenis) are locked after compile.
     * Existing anggaran cannot be deleted. New items can be inserted.
     */
    private function applyAnggaranChanges(Pk04Kegiatan $kegiatan, array $draftKegiatan, int $newRevision): void
    {
        $draftAnggaranById = [];
        $newDraftAnggaran = [];
        foreach ($draftKegiatan['anggaran'] ?? [] as $da) {
            if (! empty($da['pk04_anggaran_id'])) {
                $draftAnggaranById[(int) $da['pk04_anggaran_id']] = $da;
            } else {
                $newDraftAnggaran[] = $da;
            }
        }

        $maxNomerAnggaran = (int) $kegiatan->anggaran->max('nomer_anggaran');

        // Update existing anggaran (editable fields only, no deletion)
        foreach ($kegiatan->anggaran as $anggaran) {
            if (isset($draftAnggaranById[$anggaran->id])) {
                $da = $draftAnggaranById[$anggaran->id];
                $isLocked = $anggaran->status_pencairan !== null || $anggaran->status_item !== 'active' || $anggaran->hasRealisasiLock();

                if (! $isLocked) {
                    $changed = false;
                    $updateData = [];

                    foreach (['mata_anggaran', 'deskripsi_pk'] as $field) {
                        if (($anggaran->$field ?? '') !== ($da[$field] ?? '')) {
                            $changed = true;
                            $updateData[$field] = $da[$field] ?? null;
                        }
                    }

                    if ((float) $anggaran->nominal_anggaran != (float) ($da['nominal_anggaran'] ?? 0)) {
                        $changed = true;
                        $updateData['nominal_anggaran'] = $da['nominal_anggaran'] ?? 0;
                    }

                    if ($changed) {
                        $updateData['revisi_terakhir'] = $newRevision;
                        $anggaran->update($updateData);
                    }
                }
            }
            // Existing anggaran never deleted — validated by validateLockedItems
        }

        // Insert new anggaran
        foreach ($newDraftAnggaran as $da) {
            $maxNomerAnggaran++;
            Pk04Anggaran::create([
                'pk04_kegiatan_id' => $kegiatan->id,
                'kode_bidang' => $da['kode_bidang'],
                'kode_sub_bidang' => $da['kode_sub_bidang'],
                'kode_jenis' => $da['kode_jenis'],
                'mata_anggaran' => $da['mata_anggaran'],
                'deskripsi_pk' => $da['deskripsi_pk'] ?? null,
                'nominal_anggaran' => $da['nominal_anggaran'] ?? 0,
                'nomer_anggaran' => $maxNomerAnggaran,
                'revisi_terakhir' => $newRevision,
                'status_item' => 'active',
                'source' => 'pk',
            ]);
        }
    }

    /**
     * Apply kuisioner changes from draft to existing kegiatan.
     *
     * Existing kuisioner fields can be updated. New kuisioner are inserted.
     * Missing kuisioner are deleted (lock constraint validated beforehand).
     */
    private function applyKuisionerChanges(Pk04Kegiatan $kegiatan, array $draftKegiatan): void
    {
        $draftKuisionerById = [];
        $newDraftKuisioner = [];
        foreach ($draftKegiatan['kuisioner'] ?? [] as $dq) {
            if (! empty($dq['pk04_kuisioner_id'])) {
                $draftKuisionerById[(int) $dq['pk04_kuisioner_id']] = $dq;
            } else {
                $newDraftKuisioner[] = $dq;
            }
        }

        // Tier 3: block kuisioner deletion when kegiatan has realisasi-locked anggaran
        $kegiatanHasRealisasiLock = Prbl05ItemRealisasi::whereIn(
            'pk04_anggaran_id',
            $kegiatan->anggaran->pluck('id')
        )->exists();

        // Update or delete existing kuisioner
        foreach ($kegiatan->kuisioner as $kuisioner) {
            if (isset($draftKuisionerById[$kuisioner->id])) {
                $dq = $draftKuisionerById[$kuisioner->id];
                $kuisioner->update([
                    'kode_kuisioner' => $dq['kode_kuisioner'] ?? $kuisioner->kode_kuisioner,
                    'pertanyaan' => $dq['pertanyaan'],
                    'tipe' => $dq['tipe'],
                    'satuan' => $dq['satuan'] ?? null,
                ]);
            } elseif (! $kegiatanHasRealisasiLock) {
                $kuisioner->delete();
            }
            // If realisasi-locked, silently skip deletion (validated upstream)
        }

        // Insert new kuisioner
        foreach ($newDraftKuisioner as $dq) {
            Pk04Kuisioner::create([
                'pk04_kegiatan_id' => $kegiatan->id,
                'kode_kuisioner' => $dq['kode_kuisioner'] ?? null,
                'pertanyaan' => $dq['pertanyaan'],
                'tipe' => $dq['tipe'],
                'satuan' => $dq['satuan'] ?? null,
            ]);
        }
    }

    /**
     * Sum active anggaran from latest PK04 revision of completed raker PKs.
     *
     * Correctly scopes to latest revision per PK to avoid double-counting
     * snapshot rows created by the C + Carbon Copy strategy.
     */
    private function sumAcceptedAnggaran(PkWorkflow $referenceWorkflow, ?int $excludeWorkflowId = null): float
    {
        $query = PkWorkflow::query()
            ->where('team_id', $referenceWorkflow->team_id)
            ->where('workspace_id', $referenceWorkflow->workspace_id)
            ->where('pp_workflow_id', $referenceWorkflow->pp_workflow_id)
            ->where('tipe', 'raker')
            ->whereNull('deleted_at');

        if ($excludeWorkflowId) {
            $query->where('id', '!=', $excludeWorkflowId);
        }

        $pkWorkflowIds = $query->pluck('id');

        if ($pkWorkflowIds->isEmpty()) {
            return 0.0;
        }

        // Get latest pk04_program_tahunan per workflow (highest revision)
        $latestPk04Ids = [];
        foreach ($pkWorkflowIds as $pkId) {
            $latestId = Pk04ProgramTahunan::where('pk_workflow_id', $pkId)
                ->orderByDesc('revision')
                ->value('id');
            if ($latestId) {
                $latestPk04Ids[] = $latestId;
            }
        }

        if (empty($latestPk04Ids)) {
            return 0.0;
        }

        return (float) Pk04Anggaran::query()
            ->whereHas('pk04Kegiatan', fn ($q) => $q->whereIn('pk04_program_tahunan_id', $latestPk04Ids))
            ->where('status_item', 'active')
            ->sum('nominal_anggaran');
    }
}
