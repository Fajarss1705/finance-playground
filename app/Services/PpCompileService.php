<?php

namespace App\Services;

use App\Models\Pp\Pp06ItemDokumenSop;
use App\Models\Pp\Pp06ItemKuisioner;
use App\Models\Pp\Pp06ItemPlafonAnggaran;
use App\Models\Pp\Pp06KodeBidangPelayanan;
use App\Models\Pp\Pp06KodeJenisProgram;
use App\Models\Pp\Pp06KodeKategoriPelayanan;
use App\Models\Pp\Pp06KodeSubBidangPelayanan;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\PpWorkflow;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PpCompileService
{
    /**
     * Compile PP01-PP04 data into PP06 final snapshot.
     * Called on PP05 approve (revision 0) or PP07 submit (revision+1).
     */
    public function compile(PpWorkflow $workflow, int $revision = 0, ?array $authorOverrides = null): Pp06PeriodeTahunan
    {
        return DB::transaction(function () use ($workflow, $revision, $authorOverrides) {
            $pp01 = $workflow->pp01Data()->latest('id')->firstOrFail();
            $pp02 = $workflow->pp02Data()->latest('id')->firstOrFail();
            $pp03 = $workflow->pp03Data()->latest('id')->firstOrFail();
            $pp04 = $workflow->pp04Data()->latest('id')->firstOrFail();

            $authorData = $authorOverrides ?? $this->resolveAuthors($workflow);

            $pp06 = Pp06PeriodeTahunan::create(array_merge([
                'pp_workflow_id' => $workflow->id,
                'revision' => $revision,
                'tahun' => $pp01->tahun,
                'tanggal_mulai_pra_raker' => $pp01->tanggal_mulai_pra_raker,
                'tanggal_penetapan_program' => $pp01->tanggal_penetapan_program,
            ], $authorData));

            // Copy kode tables from PP01
            $this->copyKodeTables($pp01, $pp06);

            // Copy kuisioner from PP02
            foreach ($pp02->itemKuisioner as $item) {
                Pp06ItemKuisioner::create([
                    'pp06_periode_tahunan_id' => $pp06->id,
                    'kode' => $item->kode,
                    'pertanyaan' => $item->pertanyaan,
                    'tipe' => $item->tipe,
                    'satuan' => $item->satuan,
                ]);
            }

            // Copy plafon from PP03
            foreach ($pp03->itemPlafonAnggaran as $item) {
                Pp06ItemPlafonAnggaran::create([
                    'pp06_periode_tahunan_id' => $pp06->id,
                    'team_id' => $item->team_id,
                    'kode_team' => $item->kode_team,
                    'plafon_anggaran' => $item->plafon_anggaran,
                    'nama_bank' => $item->nama_bank,
                    'nama_rekening' => $item->nama_rekening,
                    'nomor_rekening' => $item->nomor_rekening,
                    'catatan' => $item->catatan,
                ]);
            }

            // Copy dokumen from PP04
            foreach ($pp04->itemDokumen as $item) {
                Pp06ItemDokumenSop::create([
                    'pp06_periode_tahunan_id' => $pp06->id,
                    'file_id' => $item->file_id,
                ]);
            }

            return $pp06;
        });
    }

    /**
     * Resolve author snapshots from history entries.
     *
     * @return array<string, mixed>
     */
    private function resolveAuthors(PpWorkflow $workflow): array
    {
        $history = $workflow->history ?? [];
        $authors = [];

        $stepMap = [
            'PP01' => 'pp01',
            'PP02' => 'pp02',
            'PP03' => 'pp03',
            'PP04' => 'pp04',
            'PP05' => 'pp05',
        ];

        foreach ($history as $entry) {
            $step = $entry['step'] ?? '';
            $action = $entry['action'] ?? '';

            // Use the submitter/approver as the author for that step
            if (isset($stepMap[$step]) && in_array($action, ['submitted', 'approved'], true)) {
                $prefix = $stepMap[$step];
                $userId = $entry['by'] ?? null;
                $user = $userId ? User::find($userId) : null;

                $authors["{$prefix}_created_by_user_name"] = $user?->name ?? 'System';
                $authors["{$prefix}_created_by_role_name"] = $this->resolveRoleName($entry['role'] ?? null);
                $authors["{$prefix}_created_by_team_name"] = $this->resolveTeamName($entry['team'] ?? null);
                $authors["{$prefix}_created_by_organization_name"] = $this->resolveOrgName($entry['org'] ?? null);
                $authors["{$prefix}_created_by_workspace_name"] = $this->resolveWorkspaceName($entry['workspace'] ?? null);
                $authors["{$prefix}_created_at"] = $entry['at'] ?? now()->toIso8601String();
            }
        }

        return $authors;
    }

    private function resolveRoleName(?int $roleId): string
    {
        if (! $roleId) {
            return 'System';
        }

        return \App\Models\Role::find($roleId)?->name ?? 'Unknown';
    }

    private function resolveTeamName(?int $teamId): ?string
    {
        if (! $teamId) {
            return null;
        }

        return \App\Models\Team::find($teamId)?->name;
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

    private function copyKodeTables(\App\Models\Pp\Pp01Data $pp01, Pp06PeriodeTahunan $pp06): void
    {
        foreach ($pp01->kodeBidangPelayanan as $item) {
            Pp06KodeBidangPelayanan::create([
                'pp06_periode_tahunan_id' => $pp06->id,
                'kode' => $item->kode,
                'nama' => $item->nama,
                'catatan' => $item->catatan,
            ]);
        }

        foreach ($pp01->kodeSubBidangPelayanan as $item) {
            Pp06KodeSubBidangPelayanan::create([
                'pp06_periode_tahunan_id' => $pp06->id,
                'kode' => $item->kode,
                'nama' => $item->nama,
                'catatan' => $item->catatan,
            ]);
        }

        foreach ($pp01->kodeKategoriPelayanan as $item) {
            Pp06KodeKategoriPelayanan::create([
                'pp06_periode_tahunan_id' => $pp06->id,
                'kode' => $item->kode,
                'nama' => $item->nama,
                'catatan' => $item->catatan,
            ]);
        }

        foreach ($pp01->kodeJenisProgram as $item) {
            Pp06KodeJenisProgram::create([
                'pp06_periode_tahunan_id' => $pp06->id,
                'kode' => $item->kode,
                'nama' => $item->nama,
                'catatan' => $item->catatan,
            ]);
        }
    }
}
