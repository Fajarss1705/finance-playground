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
     * Format Baru (11 segments):
     * XX.XX.XX.XX.XX.XXX.XXX.XXX.XXXX.XX.XX
     * Bidang.SubBidang.Tim.Jenis.Kategori.Program.Kegiatan.Anggaran.Tahun.Bulan.Revisi
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
    ): void {
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
        $revisi = str_pad((string) ($anggaran->revisi_terakhir ?? 0), 2, '0', STR_PAD_LEFT);

        $kodeBaru = implode('.', [
            $bidang, $subBidang, $tim, $jenis, $kategori,
            $program, $kegiatanNomer, $anggaranNomer,
            $tahunStr, $bulan, $revisi,
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

        return [
            'pk01_created_by_user_name' => 'System',
            'pk01_created_by_role_name' => 'System',
            'pk01_created_by_team_name' => null,
            'pk01_created_by_organization_name' => 'System',
            'pk01_created_by_workspace_name' => 'System',
            'pk01_created_at' => now()->toIso8601String(),
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

        // 2. CREATE new pk04_program_tahunan (preserves author + nomer_program)
        $newPk04 = Pk04ProgramTahunan::create([
            'pk_workflow_id' => $pkWorkflow->id,
            'revision' => $newRevision,
            'kode_kategori' => $draftData['kode_kategori'],
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

        // 3. Process existing kegiatan: REASSIGN to new parent + APPLY changes, or DELETE
        $maxNomerKegiatan = (int) $currentPk04->kegiatan->max('nomer_kegiatan');

        foreach ($currentPk04->kegiatan as $kegiatan) {
            if (isset($draftKegiatanById[$kegiatan->id])) {
                $dk = $draftKegiatanById[$kegiatan->id];

                // Reassign to new parent + update kegiatan fields
                $kegiatan->update([
                    'pk04_program_tahunan_id' => $newPk04->id,
                    'nama_kegiatan' => $dk['nama_kegiatan'],
                    'bulan' => (int) $dk['bulan'],
                ]);

                $this->applyAnggaranChanges($kegiatan, $dk, $newRevision);
                $this->applyKuisionerChanges($kegiatan, $dk);
            } else {
                // REMOVED kegiatan — delete row + children (locked items already validated)
                $kegiatan->anggaran()->delete();
                $kegiatan->kuisioner()->delete();
                $kegiatan->delete();
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

        // 5. REGENERATE kode_anggaran for ALL live anggaran (handles bulan/kategori changes)
        $newPk04->load(['kegiatan.anggaran']);
        foreach ($newPk04->kegiatan as $kegiatan) {
            foreach ($kegiatan->anggaran as $anggaran) {
                $this->generateKodeAnggaran($anggaran, $newPk04, $kegiatan, $kodeTeam, $tahun);
            }
        }

        // 6. Generate verification code
        $this->generateVerificationCode($newPk04);

        return $newPk04;
    }

    /**
     * Validate that locked anggaran items in draft_data are unchanged.
     *
     * Also validates no kuisioner removed from kegiatan with locked anggaran.
     *
     * @return list<string> Error messages (empty = valid)
     */
    public function validateLockedItems(Pk04ProgramTahunan $currentPk04, array $draftData): array
    {
        $errors = [];
        $currentPk04->loadMissing(['kegiatan.anggaran', 'kegiatan.kuisioner']);

        // Build flat maps
        $lockedAnggaran = [];
        $kegiatanWithLocked = [];

        foreach ($currentPk04->kegiatan as $kegiatan) {
            foreach ($kegiatan->anggaran as $anggaran) {
                $isLocked = $anggaran->status_pencairan !== null || $anggaran->status_item !== 'active';
                if ($isLocked) {
                    $lockedAnggaran[$anggaran->id] = $anggaran;
                    $kegiatanWithLocked[$kegiatan->id] = true;
                }
            }
        }

        if (empty($lockedAnggaran)) {
            return [];
        }

        // Build draft anggaran index by pk04_anggaran_id
        $draftAnggaranById = [];
        $draftKuisionerIdsByKegiatan = [];

        foreach ($draftData['kegiatan'] ?? [] as $dk) {
            $kId = $dk['pk04_kegiatan_id'] ?? null;

            foreach ($dk['anggaran'] ?? [] as $da) {
                if (! empty($da['pk04_anggaran_id'])) {
                    $draftAnggaranById[(int) $da['pk04_anggaran_id']] = $da;
                }
            }

            if ($kId) {
                $draftKuisionerIdsByKegiatan[(int) $kId] = [];
                foreach ($dk['kuisioner'] ?? [] as $dq) {
                    if (! empty($dq['pk04_kuisioner_id'])) {
                        $draftKuisionerIdsByKegiatan[(int) $kId][] = (int) $dq['pk04_kuisioner_id'];
                    }
                }
            }
        }

        // Check each locked anggaran
        $fieldsToCheck = ['kode_bidang', 'kode_sub_bidang', 'kode_jenis', 'mata_anggaran', 'deskripsi_pk'];

        foreach ($lockedAnggaran as $anggaranId => $anggaran) {
            if (! isset($draftAnggaranById[$anggaranId])) {
                $errors[] = "Item anggaran \"{$anggaran->mata_anggaran}\" yang terkunci tidak dapat dihapus.";

                continue;
            }

            $da = $draftAnggaranById[$anggaranId];
            foreach ($fieldsToCheck as $field) {
                if (($anggaran->$field ?? '') !== ($da[$field] ?? '')) {
                    $errors[] = "Item anggaran \"{$anggaran->mata_anggaran}\" yang terkunci tidak dapat diubah.";

                    continue 2;
                }
            }

            if ((float) $anggaran->nominal_anggaran != (float) ($da['nominal_anggaran'] ?? 0)) {
                $errors[] = "Item anggaran \"{$anggaran->mata_anggaran}\" yang terkunci tidak dapat diubah.";
            }
        }

        // Check kuisioner removal from kegiatan with locked anggaran
        foreach ($kegiatanWithLocked as $kegiatanId => $flag) {
            $kegiatan = $currentPk04->kegiatan->firstWhere('id', $kegiatanId);
            if (! $kegiatan) {
                continue;
            }

            // Kegiatan must be in draft
            if (! isset($draftKuisionerIdsByKegiatan[$kegiatanId])) {
                continue; // Kegiatan removed — already caught by locked anggaran check
            }

            $currentKuisionerIds = $kegiatan->kuisioner->pluck('id')->toArray();
            $draftKuisionerIds = $draftKuisionerIdsByKegiatan[$kegiatanId];
            $removedIds = array_diff($currentKuisionerIds, $draftKuisionerIds);

            foreach ($removedIds as $qId) {
                $q = $kegiatan->kuisioner->firstWhere('id', $qId);
                $errors[] = "Kuisioner \"{$q?->pertanyaan}\" tidak dapat dihapus dari kegiatan yang memiliki anggaran terkunci.";
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

        // 1. Program-level fields
        foreach (['kode_kategori', 'nama_program', 'deskripsi_program', 'tujuan_program'] as $field) {
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

        // 3. Removed kegiatan
        foreach ($currentKegiatanMap as $kId => $kegiatan) {
            if (! isset($draftKegiatanById[$kId])) {
                $changes[] = ['type' => 'kegiatan_removed', 'nama_kegiatan' => $kegiatan->nama_kegiatan];
            }
        }

        // 4. Changes within matched kegiatan
        foreach ($draftKegiatanById as $kId => $dk) {
            $kegiatan = $currentKegiatanMap->get($kId);
            if (! $kegiatan) {
                continue;
            }

            // Kegiatan field changes
            if (($kegiatan->nama_kegiatan ?? '') !== ($dk['nama_kegiatan'] ?? '')) {
                $changes[] = ['type' => 'kegiatan_changed', 'pk04_kegiatan_id' => $kId, 'field' => 'nama_kegiatan', 'old' => $kegiatan->nama_kegiatan, 'new' => $dk['nama_kegiatan'] ?? ''];
            }
            if ((int) $kegiatan->bulan !== (int) ($dk['bulan'] ?? 0)) {
                $changes[] = ['type' => 'kegiatan_changed', 'pk04_kegiatan_id' => $kId, 'field' => 'bulan', 'old' => (int) $kegiatan->bulan, 'new' => (int) ($dk['bulan'] ?? 0)];
            }

            // Anggaran changes
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

            foreach ($currentAnggaranMap as $aId => $anggaran) {
                if (! isset($draftAnggaranById[$aId])) {
                    $changes[] = ['type' => 'anggaran_removed', 'pk04_anggaran_id' => $aId, 'mata_anggaran' => $anggaran->mata_anggaran];
                }
            }

            foreach ($newDraftAnggaran as $da) {
                $changes[] = ['type' => 'anggaran_added', 'mata_anggaran' => $da['mata_anggaran'] ?? ''];
            }

            foreach ($draftAnggaranById as $aId => $da) {
                $anggaran = $currentAnggaranMap->get($aId);
                if (! $anggaran) {
                    continue;
                }

                foreach (['kode_bidang', 'kode_sub_bidang', 'kode_jenis', 'mata_anggaran', 'deskripsi_pk'] as $field) {
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
                    'nominal_realisasi' => $anggaran->nominal_realisasi,
                    'status_realisasi' => $anggaran->status_realisasi,
                    'prbl_workflow_id' => $anggaran->prbl_workflow_id,
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
     * Locked items (pencairan or ditarik_maju) are untouched.
     * Changed items are updated in place with revisi_terakhir set.
     * New items are inserted. Missing items are deleted.
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

        // Update or delete existing anggaran
        foreach ($kegiatan->anggaran as $anggaran) {
            if (isset($draftAnggaranById[$anggaran->id])) {
                $da = $draftAnggaranById[$anggaran->id];
                $isLocked = $anggaran->status_pencairan !== null || $anggaran->status_item !== 'active';

                if (! $isLocked) {
                    $changed = false;
                    $updateData = [];

                    foreach (['kode_bidang', 'kode_sub_bidang', 'kode_jenis', 'mata_anggaran', 'deskripsi_pk'] as $field) {
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
                // Locked: untouched (already reassigned via parent kegiatan)
            } else {
                // Removed — delete (locked items already validated)
                $anggaran->delete();
            }
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
            } else {
                $kuisioner->delete();
            }
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
