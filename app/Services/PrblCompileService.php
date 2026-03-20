<?php

namespace App\Services;

use App\Models\File;
use App\Models\Prbl\Prbl05Bukti;
use App\Models\Prbl\Prbl05FotoKegiatan;
use App\Models\Prbl\Prbl05ItemKegiatan;
use App\Models\Prbl\Prbl05ItemKuisioner;
use App\Models\Prbl\Prbl05ItemRealisasi;
use App\Models\Prbl\Prbl05LaporanBulanan;
use App\Models\Prbl\Prbl05NotaPengeluaran;
use App\Models\Prbl\Prbl05RekeningOrganisasi;
use App\Models\Prbl\PrblWorkflow;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;

class PrblCompileService
{
    /**
     * Compile PRBL05 final snapshot from PRBL01 kegiatan + PRBL03 refund + PP06 rekening.
     *
     * Called synchronously from prbl04Approve inside a DB::transaction.
     * Returns the created Prbl05LaporanBulanan row.
     */
    public function compile(PrblWorkflow $prblWorkflow): Prbl05LaporanBulanan
    {
        $prbl01 = $prblWorkflow->latestPrbl01();
        if (! $prbl01) {
            throw new \RuntimeException('PRBL01 data tidak ditemukan.');
        }

        $prbl01->load([
            'itemKegiatan.fotoKegiatan',
            'itemKegiatan.notaPengeluaran',
            'itemKegiatan.itemKuisioner',
            'itemRealisasi.pk04Anggaran',
        ]);

        $prbl03 = $prblWorkflow->latestPrbl03();
        if (! $prbl03) {
            throw new \RuntimeException('PRBL03 data tidak ditemukan.');
        }

        $prbl03->load('bukti');

        // Step 1 — Resolve author snapshots (5 actors)
        $authorData = $this->resolveAuthorSnapshots($prblWorkflow);

        // Step 2 — Compute totals from PRBL01 realisasi
        $totals = $this->computeTotals($prbl01, $prbl03);

        // Step 3 — Create prbl05_laporan_bulanan
        $prbl05 = Prbl05LaporanBulanan::create([
            'prbl_workflow_id' => $prblWorkflow->id,
            ...$authorData,
            'keterangan' => $prbl03->keterangan,
            ...$totals,
        ]);

        // Step 4 — Create prbl05_item_kegiatan + children
        $kegiatanMap = $this->buildItemKegiatan($prbl05, $prbl01);

        // Step 5 — Copy foto kegiatan
        $this->copyFotoKegiatan($kegiatanMap, $prbl01);

        // Step 6 — Copy nota pengeluaran
        $this->copyNotaPengeluaran($kegiatanMap, $prbl01);

        // Step 7 — Create prbl05_item_kuisioner
        $this->buildItemKuisioner($kegiatanMap, $prbl01);

        // Step 8 — Create prbl05_item_realisasi
        $this->buildItemRealisasi($prbl05, $prbl01);

        // Step 9 — Snapshot rekening organisasi from PP06
        $this->snapshotRekeningOrganisasi($prbl05, $prblWorkflow);

        // Step 10 — Copy bukti from PRBL03
        $this->copyBukti($prbl05, $prbl03);

        // Step 11 — Generate verification code
        $this->generateVerificationCode($prbl05);

        return $prbl05;
    }

    /**
     * Resolve author snapshots from workflow history for 5 actors.
     *
     * @return array<string, mixed>
     */
    private function resolveAuthorSnapshots(PrblWorkflow $prblWorkflow): array
    {
        $history = $prblWorkflow->history ?? [];

        // PRBL01 — latest 'submitted' entry
        $prbl01Entry = $this->findLatestHistoryEntry($history, 'PRBL01', 'submitted');
        $prbl01Author = $this->resolveSnapshotFromEntry($prbl01Entry, 'prbl01');

        // PRBL02A — 'approved' entry
        $prbl02aEntry = $this->findLatestHistoryEntry($history, 'PRBL02A', 'approved');
        $prbl02aAuthor = $this->resolveSnapshotFromEntry($prbl02aEntry, 'prbl02a', 'approved_by');

        // PRBL02B — 'approved' entry
        $prbl02bEntry = $this->findLatestHistoryEntry($history, 'PRBL02B', 'approved');
        $prbl02bAuthor = $this->resolveSnapshotFromEntry($prbl02bEntry, 'prbl02b', 'approved_by');

        // PRBL03 — latest 'submitted' entry
        $prbl03Entry = $this->findLatestHistoryEntry($history, 'PRBL03', 'submitted');
        $prbl03Author = $this->resolveSnapshotFromEntry($prbl03Entry, 'prbl03');

        // PRBL04 — 'approved' entry (the triggering step)
        $prbl04Entry = $this->findLatestHistoryEntry($history, 'PRBL04', 'approved');
        $prbl04Author = $this->resolveSnapshotFromEntry($prbl04Entry, 'prbl04', 'approved_by');

        return [...$prbl01Author, ...$prbl02aAuthor, ...$prbl02bAuthor, ...$prbl03Author, ...$prbl04Author];
    }

    /**
     * Compute totals from PRBL01 realisasi and PRBL03 refund.
     *
     * @return array{total_anggaran_dicairkan: float, total_realisasi: float, total_refund: float, total_item: int}
     */
    private function computeTotals(object $prbl01, object $prbl03): array
    {
        $totalAnggaranDicairkan = 0;
        $totalRealisasi = 0;

        foreach ($prbl01->itemRealisasi as $item) {
            $totalAnggaranDicairkan += (float) ($item->pk04Anggaran?->nominal_anggaran ?? 0);
            $totalRealisasi += (float) ($item->nominal_realisasi ?? 0);
        }

        $totalRefund = (float) ($prbl03->nominal_refund ?? 0);

        return [
            'total_anggaran_dicairkan' => $totalAnggaranDicairkan,
            'total_realisasi' => $totalRealisasi,
            'total_refund' => $totalRefund,
            'total_item' => $prbl01->itemRealisasi->count(),
        ];
    }

    /**
     * Create prbl05_item_kegiatan from prbl01_item_kegiatan.
     *
     * @return array<int, int> Map of prbl01_item_kegiatan_id → prbl05_item_kegiatan_id
     */
    private function buildItemKegiatan(Prbl05LaporanBulanan $prbl05, object $prbl01): array
    {
        $map = [];

        foreach ($prbl01->itemKegiatan as $item) {
            $prbl05Item = Prbl05ItemKegiatan::create([
                'prbl05_laporan_bulanan_id' => $prbl05->id,
                'pk04_kegiatan_id' => $item->pk04_kegiatan_id,
                'masalah' => $item->masalah,
                'langkah_penanganan' => $item->langkah_penanganan,
                'harapan' => $item->harapan,
                'catatan_tim' => $item->catatan_tim,
            ]);

            $map[$item->id] = $prbl05Item->id;
        }

        return $map;
    }

    /**
     * Copy file references from prbl01_foto_kegiatan to prbl05_foto_kegiatan.
     *
     * @param  array<int, int>  $kegiatanMap
     */
    private function copyFotoKegiatan(array $kegiatanMap, object $prbl01): void
    {
        foreach ($prbl01->itemKegiatan as $item) {
            $prbl05KegiatanId = $kegiatanMap[$item->id] ?? null;
            if (! $prbl05KegiatanId) {
                continue;
            }

            foreach ($item->fotoKegiatan as $foto) {
                Prbl05FotoKegiatan::create([
                    'prbl05_item_kegiatan_id' => $prbl05KegiatanId,
                    'file_id' => $foto->file_id,
                ]);
            }
        }
    }

    /**
     * Copy file references from prbl01_nota_pengeluaran to prbl05_nota_pengeluaran.
     *
     * @param  array<int, int>  $kegiatanMap
     */
    private function copyNotaPengeluaran(array $kegiatanMap, object $prbl01): void
    {
        foreach ($prbl01->itemKegiatan as $item) {
            $prbl05KegiatanId = $kegiatanMap[$item->id] ?? null;
            if (! $prbl05KegiatanId) {
                continue;
            }

            foreach ($item->notaPengeluaran as $nota) {
                Prbl05NotaPengeluaran::create([
                    'prbl05_item_kegiatan_id' => $prbl05KegiatanId,
                    'file_id' => $nota->file_id,
                ]);
            }
        }
    }

    /**
     * Create prbl05_item_kuisioner from prbl01_item_kuisioner.
     *
     * @param  array<int, int>  $kegiatanMap
     */
    private function buildItemKuisioner(array $kegiatanMap, object $prbl01): void
    {
        foreach ($prbl01->itemKegiatan as $item) {
            $prbl05KegiatanId = $kegiatanMap[$item->id] ?? null;
            if (! $prbl05KegiatanId) {
                continue;
            }

            foreach ($item->itemKuisioner as $kuisioner) {
                Prbl05ItemKuisioner::create([
                    'prbl05_item_kegiatan_id' => $prbl05KegiatanId,
                    'pk04_kuisioner_id' => $kuisioner->pk04_kuisioner_id,
                    'jawaban' => $kuisioner->jawaban,
                ]);
            }
        }
    }

    /**
     * Create prbl05_item_realisasi from prbl01_item_realisasi + pk04_anggaran.
     */
    private function buildItemRealisasi(Prbl05LaporanBulanan $prbl05, object $prbl01): void
    {
        foreach ($prbl01->itemRealisasi as $item) {
            $nominalAnggaran = (float) ($item->pk04Anggaran?->nominal_anggaran ?? 0);
            $nominalRealisasi = (float) ($item->nominal_realisasi ?? 0);

            Prbl05ItemRealisasi::create([
                'prbl05_laporan_bulanan_id' => $prbl05->id,
                'pk04_anggaran_id' => $item->pk04_anggaran_id,
                'nominal_anggaran' => $nominalAnggaran,
                'nominal_realisasi' => $nominalRealisasi,
                'selisih' => $nominalAnggaran - $nominalRealisasi,
                'komentar_realisasi' => $item->komentar_realisasi,
            ]);
        }
    }

    /**
     * Snapshot rekening organisasi from PP06 to prbl05_rekening_organisasi.
     */
    private function snapshotRekeningOrganisasi(Prbl05LaporanBulanan $prbl05, PrblWorkflow $prblWorkflow): void
    {
        $pp06 = $prblWorkflow->ppWorkflow?->latestPp06();
        if (! $pp06) {
            return;
        }

        $rekeningRows = $pp06->rekeningOrganisasi;
        foreach ($rekeningRows as $rek) {
            Prbl05RekeningOrganisasi::create([
                'prbl05_laporan_bulanan_id' => $prbl05->id,
                'nama_bank' => $rek->nama_bank,
                'nama_rekening' => $rek->nama_rekening,
                'nomor_rekening' => $rek->nomor_rekening,
            ]);
        }
    }

    /**
     * Copy file references from prbl03_bukti to prbl05_bukti.
     */
    private function copyBukti(Prbl05LaporanBulanan $prbl05, object $prbl03): void
    {
        foreach ($prbl03->bukti as $bukti) {
            Prbl05Bukti::create([
                'prbl05_laporan_bulanan_id' => $prbl05->id,
                'tipe' => $bukti->tipe,
                'file_id' => $bukti->file_id,
            ]);
        }
    }

    /**
     * Build canonical data array for verification code generation.
     *
     * @return array<string, mixed>
     */
    public function buildCanonicalData(Prbl05LaporanBulanan $prbl05): array
    {
        $prbl05->loadMissing([
            'itemKegiatan.fotoKegiatan',
            'itemKegiatan.notaPengeluaran',
            'itemKegiatan.itemKuisioner',
            'itemRealisasi',
            'rekeningOrganisasi',
            'bukti',
        ]);

        $main = $prbl05->only([
            'prbl_workflow_id',
            'prbl01_created_by_user_name',
            'prbl01_created_by_role_name',
            'prbl01_created_by_team_name',
            'prbl01_created_by_organization_name',
            'prbl01_created_by_workspace_name',
            'prbl02a_approved_by_user_name',
            'prbl02a_approved_by_role_name',
            'prbl02a_approved_by_team_name',
            'prbl02a_approved_by_organization_name',
            'prbl02a_approved_by_workspace_name',
            'prbl02b_approved_by_user_name',
            'prbl02b_approved_by_role_name',
            'prbl02b_approved_by_team_name',
            'prbl02b_approved_by_organization_name',
            'prbl02b_approved_by_workspace_name',
            'prbl03_created_by_user_name',
            'prbl03_created_by_role_name',
            'prbl03_created_by_team_name',
            'prbl03_created_by_organization_name',
            'prbl03_created_by_workspace_name',
            'prbl04_approved_by_user_name',
            'prbl04_approved_by_role_name',
            'prbl04_approved_by_team_name',
            'prbl04_approved_by_organization_name',
            'prbl04_approved_by_workspace_name',
            'keterangan',
            'total_anggaran_dicairkan',
            'total_realisasi',
            'total_refund',
            'total_item',
        ]);

        return [
            'prbl05' => $main,
            'kegiatan' => $prbl05->itemKegiatan->sortBy('id')->map(fn ($item) => [
                'pk04_kegiatan_id' => $item->pk04_kegiatan_id,
                'masalah' => $item->masalah,
                'langkah_penanganan' => $item->langkah_penanganan,
                'harapan' => $item->harapan,
                'catatan_tim' => $item->catatan_tim,
            ])->values()->all(),
            'foto' => $prbl05->itemKegiatan->sortBy('id')->flatMap(fn ($k) => $k->fotoKegiatan->sortBy('id')->map(fn ($f) => [
                'file_id' => $f->file_id,
            ]))->values()->all(),
            'nota' => $prbl05->itemKegiatan->sortBy('id')->flatMap(fn ($k) => $k->notaPengeluaran->sortBy('id')->map(fn ($n) => [
                'file_id' => $n->file_id,
            ]))->values()->all(),
            'kuisioner' => $prbl05->itemKegiatan->sortBy('id')->flatMap(fn ($k) => $k->itemKuisioner->sortBy('id')->map(fn ($q) => [
                'pk04_kuisioner_id' => $q->pk04_kuisioner_id,
                'jawaban' => $q->jawaban,
            ]))->values()->all(),
            'realisasi' => $prbl05->itemRealisasi->sortBy('id')->map(fn ($item) => [
                'pk04_anggaran_id' => $item->pk04_anggaran_id,
                'nominal_anggaran' => $item->nominal_anggaran,
                'nominal_realisasi' => $item->nominal_realisasi,
                'selisih' => $item->selisih,
                'komentar_realisasi' => $item->komentar_realisasi,
            ])->values()->all(),
            'rekening' => $prbl05->rekeningOrganisasi->sortBy('id')->map(fn ($r) => [
                'nama_bank' => $r->nama_bank,
                'nama_rekening' => $r->nama_rekening,
                'nomor_rekening' => $r->nomor_rekening,
            ])->values()->all(),
            'bukti' => $prbl05->bukti->sortBy('id')->map(fn ($b) => [
                'tipe' => $b->tipe,
                'file_id' => $b->file_id,
            ])->values()->all(),
        ];
    }

    /**
     * Generate and store a deterministic verification code for PRBL05.
     *
     * SHA-256 of canonical JSON → base36 truncated to 8 chars uppercase.
     */
    public function generateVerificationCode(Prbl05LaporanBulanan $prbl05): string
    {
        $canonical = $this->buildCanonicalData($prbl05);
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $json);
        $code = strtoupper(substr(base_convert(substr($hash, 0, 16), 16, 36), 0, 8));

        $prbl05->update(['verification_code' => $code]);

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
    public function generateExportFiles(Prbl05LaporanBulanan $prbl05, int $userId, int $workspaceId): array
    {
        $result = ['pdf_file_id' => null, 'excel_file_id' => null];
        $prblWorkflow = $prbl05->prblWorkflow;
        $teamName = $prblWorkflow?->team?->name ?? 'Unknown';
        $bulanNames = $this->bulanNames();
        $bulan = $bulanNames[$prblWorkflow?->bulan_laporan ?? 0] ?? 'Unknown';
        $tahun = $prblWorkflow?->tahun_laporan ?? now()->year;

        // PDF placeholder
        try {
            $filename = "PRBL-{$teamName}-{$bulan}-{$tahun}.pdf";
            $storagePath = "exports/prbl/{$prblWorkflow->id}/{$filename}";

            $file = File::create([
                'original_filename' => $filename,
                'filename' => $filename,
                'mime_type' => 'application/pdf',
                'size' => 0,
                'disk' => 'local',
                'path' => $storagePath,
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
                'attachable_type' => Prbl05LaporanBulanan::class,
                'attachable_id' => $prbl05->id,
                'source_route' => 'prbl05.export.pdf',
            ]);
            $result['pdf_file_id'] = $file->id;
        } catch (\Throwable $e) {
            Log::warning("PRBL05 PDF export placeholder failed for PRBL05#{$prbl05->id}: {$e->getMessage()}");
        }

        // Excel placeholder
        try {
            $filename = "PRBL-{$teamName}-{$bulan}-{$tahun}.xlsx";
            $storagePath = "exports/prbl/{$prblWorkflow->id}/{$filename}";

            $file = File::create([
                'original_filename' => $filename,
                'filename' => $filename,
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'size' => 0,
                'disk' => 'local',
                'path' => $storagePath,
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
                'attachable_type' => Prbl05LaporanBulanan::class,
                'attachable_id' => $prbl05->id,
                'source_route' => 'prbl05.export.excel',
            ]);
            $result['excel_file_id'] = $file->id;
        } catch (\Throwable $e) {
            Log::warning("PRBL05 Excel export placeholder failed for PRBL05#{$prbl05->id}: {$e->getMessage()}");
        }

        return $result;
    }

    /**
     * Append export file IDs to the matching PRBL05 'completed' history entry.
     */
    public function appendExportFilesToHistory(PrblWorkflow $prblWorkflow, array $exportResult): void
    {
        $newFileIds = array_values(array_filter([
            $exportResult['pdf_file_id'],
            $exportResult['excel_file_id'],
        ]));

        if (empty($newFileIds)) {
            return;
        }

        $history = $prblWorkflow->history ?? [];

        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (
                ($history[$i]['step'] ?? '') === 'PRBL05'
                && ($history[$i]['action'] ?? '') === 'completed'
            ) {
                $existing = $history[$i]['files'] ?? [];
                $history[$i]['files'] = array_values(array_unique(array_merge($existing, $newFileIds)));

                break;
            }
        }

        $prblWorkflow->update(['history' => $history]);
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
                "{$prefix}_{$suffix}_team_name" => 'System',
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

    private function resolveRoleName(?int $roleId): string
    {
        if (! $roleId) {
            return 'System';
        }

        return Role::find($roleId)?->name ?? 'Unknown';
    }

    private function resolveTeamName(?int $teamId): string
    {
        if (! $teamId) {
            return 'Unknown';
        }

        return Team::find($teamId)?->name ?? 'Unknown';
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
