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
        $teamId = $pkWorkflow->team_id;

        $plafon = (float) ($pp06->itemPlafonAnggaran()
            ->where('team_id', $teamId)
            ->value('plafon_anggaran') ?? 0);

        // Sum of all active pk04_anggaran from completed raker PKs for this team
        // (excluding the current PK, which hasn't compiled yet)
        $accepted = (float) Pk04Anggaran::query()
            ->whereHas('pk04Kegiatan.pk04ProgramTahunan.pkWorkflow', fn ($q) => $q
                ->where('team_id', $teamId)
                ->where('workspace_id', $pkWorkflow->workspace_id)
                ->where('pp_workflow_id', $pkWorkflow->pp_workflow_id)
                ->where('tipe', 'raker')
                ->where('id', '!=', $pkWorkflow->id)
                ->whereNull('deleted_at')
            )
            ->where('status_item', 'active')
            ->sum('nominal_anggaran');

        // This PK's total
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
}
