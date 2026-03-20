<?php

namespace App\Console\Commands;

use App\Models\Pabd\PabdWorkflow;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04Kuisioner;
use App\Models\Prbl\Prbl01Data;
use App\Models\Prbl\Prbl01ItemKegiatan;
use App\Models\Prbl\Prbl01ItemKuisioner;
use App\Models\Prbl\Prbl01ItemRealisasi;
use App\Models\Prbl\PrblWorkflow;
use App\Models\Workspace;
use App\Services\WorkflowEngine;
use App\Services\WorkflowNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrblAutoCreate extends Command
{
    protected $signature = 'prbl:auto-create';

    protected $description = 'Auto-create PRBL workflows for teams with completed PABD05, on or after day 15 of kegiatan month';

    public function __construct(
        public WorkflowEngine $engine,
        private WorkflowNotifier $notifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = now();
        $created = 0;
        $skipped = 0;

        $workspaces = Workspace::all();

        foreach ($workspaces as $workspace) {
            // Find completed PABD workflows in this workspace
            $pabdWorkflows = PabdWorkflow::query()
                ->where('workspace_id', $workspace->id)
                ->whereNull('deleted_at')
                ->get();

            foreach ($pabdWorkflows as $pabdWorkflow) {
                // Check PABD05 is complete (workflow status = 'completed')
                $status = $this->engine->getWorkflowStatus($pabdWorkflow->history ?? []);
                if ($status !== 'completed') {
                    continue;
                }

                $bulanLaporan = $pabdWorkflow->bulan_anggaran;
                $tahunLaporan = $pabdWorkflow->tahun_anggaran;
                $teamId = $pabdWorkflow->team_id;
                $ppWorkflowId = $pabdWorkflow->pp_workflow_id;

                // Check current date >= day 15 of kegiatan month
                $day15 = $now->copy()->setDate($tahunLaporan, $bulanLaporan, 15)->startOfDay();
                if ($now->lt($day15)) {
                    $skipped++;

                    continue;
                }

                // Check no existing PRBL for team + month + PP
                $exists = PrblWorkflow::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('team_id', $teamId)
                    ->where('pp_workflow_id', $ppWorkflowId)
                    ->where('bulan_laporan', $bulanLaporan)
                    ->where('tahun_laporan', $tahunLaporan)
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                // Check previous month's PRBL is complete (for months after the first)
                if (! $this->isPreviousMonthPrblComplete($workspace, $teamId, $ppWorkflowId, $bulanLaporan, $tahunLaporan)) {
                    $skipped++;

                    continue;
                }

                // Create PRBL workflow + PRBL01 data + items
                $prblWorkflow = $this->createPrblWorkflow(
                    $workspace,
                    $pabdWorkflow,
                );

                // Notify team that PRBL has been auto-created
                $this->notifier->notify($prblWorkflow, 'prbl.auto_created', [
                    'actor_name' => 'Sistem',
                ]);

                $created++;
            }
        }

        $this->components->info("PRBL auto-create: {$created} created, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /**
     * Check if the previous month's PRBL is complete.
     *
     * Returns true if this is the first PABD month for this team+PP (no previous PABD exists),
     * or if the previous month's PRBL has status 'completed'.
     */
    private function isPreviousMonthPrblComplete(
        Workspace $workspace,
        int $teamId,
        int $ppWorkflowId,
        int $bulanLaporan,
        int $tahunLaporan,
    ): bool {
        // Find the previous PABD for this team+PP (the one with the closest earlier month)
        $previousPabd = PabdWorkflow::query()
            ->where('workspace_id', $workspace->id)
            ->where('team_id', $teamId)
            ->where('pp_workflow_id', $ppWorkflowId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($bulanLaporan, $tahunLaporan) {
                $q->where('tahun_anggaran', '<', $tahunLaporan)
                    ->orWhere(function ($q2) use ($bulanLaporan, $tahunLaporan) {
                        $q2->where('tahun_anggaran', $tahunLaporan)
                            ->where('bulan_anggaran', '<', $bulanLaporan);
                    });
            })
            ->orderByDesc('tahun_anggaran')
            ->orderByDesc('bulan_anggaran')
            ->first();

        if (! $previousPabd) {
            // First PABD month for this team+PP — no previous PRBL required
            return true;
        }

        // Find the PRBL for the previous month
        $previousPrbl = PrblWorkflow::query()
            ->where('workspace_id', $workspace->id)
            ->where('team_id', $teamId)
            ->where('pp_workflow_id', $ppWorkflowId)
            ->where('bulan_laporan', $previousPabd->bulan_anggaran)
            ->where('tahun_laporan', $previousPabd->tahun_anggaran)
            ->first();

        if (! $previousPrbl) {
            // Previous PRBL not yet created
            return false;
        }

        $status = $this->engine->getWorkflowStatus($previousPrbl->history ?? []);

        return $status === 'completed';
    }

    /**
     * Create a PRBL workflow with PRBL01 data and child rows.
     */
    private function createPrblWorkflow(
        Workspace $workspace,
        PabdWorkflow $pabdWorkflow,
    ): PrblWorkflow {
        return DB::transaction(function () use ($workspace, $pabdWorkflow) {
            $prblWorkflow = PrblWorkflow::create([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'team_id' => $pabdWorkflow->team_id,
                'pabd_workflow_id' => $pabdWorkflow->id,
                'pp_workflow_id' => $pabdWorkflow->pp_workflow_id,
                'bulan_laporan' => $pabdWorkflow->bulan_anggaran,
                'tahun_laporan' => $pabdWorkflow->tahun_anggaran,
                'created_by_user_id' => null,
                'created_by_role_id' => null,
                'created_by_team_id' => null,
                'created_by_org_id' => null,
                'history' => [],
            ]);

            $prbl01 = Prbl01Data::create([
                'prbl_workflow_id' => $prblWorkflow->id,
            ]);

            // Find all pk04_anggaran dicairkan via this PABD
            $dicairkanAnggaran = Pk04Anggaran::query()
                ->where('pencairan_pabd_workflow_id', $pabdWorkflow->id)
                ->where('status_pencairan', 'dicairkan')
                ->get();

            // Group anggaran by kegiatan to create item_kegiatan rows
            $anggaranByKegiatan = $dicairkanAnggaran->groupBy(function ($anggaran) {
                return $anggaran->pk04_kegiatan_id;
            });

            foreach ($anggaranByKegiatan as $kegiatanId => $anggaranItems) {
                // Create item_kegiatan row
                $itemKegiatan = Prbl01ItemKegiatan::create([
                    'prbl01_data_id' => $prbl01->id,
                    'pk04_kegiatan_id' => $kegiatanId,
                ]);

                // Create item_kuisioner rows for this kegiatan
                $kuisioners = Pk04Kuisioner::query()
                    ->where('pk04_kegiatan_id', $kegiatanId)
                    ->get();

                foreach ($kuisioners as $kuisioner) {
                    Prbl01ItemKuisioner::create([
                        'prbl01_item_kegiatan_id' => $itemKegiatan->id,
                        'pk04_kuisioner_id' => $kuisioner->id,
                    ]);
                }
            }

            // Create item_realisasi rows for all dicairkan anggaran
            foreach ($dicairkanAnggaran as $anggaran) {
                Prbl01ItemRealisasi::create([
                    'prbl01_data_id' => $prbl01->id,
                    'pk04_anggaran_id' => $anggaran->id,
                ]);
            }

            // Record creation in history
            $this->engine->recordAction(
                workflow: $prblWorkflow,
                step: 'PRBL01',
                action: 'created',
                userId: null,
                sessionContext: [],
                table: 'prbl01_data',
                dataId: $prbl01->id,
            );

            return $prblWorkflow;
        });
    }
}
