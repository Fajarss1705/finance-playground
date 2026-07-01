<?php

namespace App\Console\Commands;

use App\Models\Pabd\Pabd01Data;
use App\Models\Pabd\Pabd01ItemAnggaran;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04Kegiatan;
use App\Models\Pk\Pk04ProgramTahunan;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\PpWorkflow;
use App\Models\Prbl\PrblWorkflow;
use App\Models\Workspace;
use App\Services\WorkflowEngine;
use App\Services\WorkflowNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PabdAutoCreate extends Command
{
    protected $signature = 'pabd:auto-create';

    protected $description = 'Auto-create PABD workflows for teams with PK04 kegiatan scheduled in the upcoming month';

    public function __construct(
        public WorkflowEngine $engine,
        private WorkflowNotifier $notifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = now();
        $targetMonth = $now->copy()->addMonth()->month;
        $targetYear = $now->copy()->addMonth()->year;

        $created = 0;
        $skipped = 0;

        $workspaces = Workspace::all();

        foreach ($workspaces as $workspace) {
            // Find all PP workflows in this workspace that have a completed PP06
            $ppWorkflows = PpWorkflow::query()
                ->where('workspace_id', $workspace->id)
                ->get();

            foreach ($ppWorkflows as $ppWorkflow) {
                $pp06 = $ppWorkflow->latestPp06();
                if (! $pp06) {
                    continue;
                }

                // Check tanggal_penetapan_program — must be in the past
                if (! $pp06->tanggal_penetapan_program || $pp06->tanggal_penetapan_program->isFuture()) {
                    continue;
                }

                $tahun = $ppWorkflow->latestPp06()?->tahun;
                if (! $tahun) {
                    continue;
                }

                // Find PK workflows with PK04 finals for this PP, grouped by team
                $pkWorkflows = PkWorkflow::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('pp_workflow_id', $ppWorkflow->id)
                    ->whereNull('deleted_at')
                    ->get();

                // Group by team_id
                $pkByTeam = $pkWorkflows->groupBy('team_id');

                foreach ($pkByTeam as $teamId => $teamPkWorkflows) {
                    try {
                        // Check if PK04 final exists for any PK in this team
                        $pk04Finals = Pk04ProgramTahunan::query()
                            ->whereIn('pk_workflow_id', $teamPkWorkflows->pluck('id'))
                            ->get();

                        if ($pk04Finals->isEmpty()) {
                            continue;
                        }

                        // Check for active anggaran (nominal > 0, status_item = active) in target month
                        $anggaranInMonth = Pk04Anggaran::query()
                            ->whereHas('pk04Kegiatan', function ($q) use ($pk04Finals, $targetMonth) {
                                $q->whereIn('pk04_program_tahunan_id', $pk04Finals->pluck('id'))
                                    ->where('bulan', $targetMonth);
                            })
                            ->where('nominal_anggaran', '>', 0)
                            ->where('status_item', 'active')
                            ->exists();

                        if (! $anggaranInMonth) {
                            $skipped++;

                            continue;
                        }

                        // Check no existing PABD for this team + month + PP
                        $exists = PabdWorkflow::query()
                            ->where('workspace_id', $workspace->id)
                            ->where('team_id', $teamId)
                            ->where('pp_workflow_id', $ppWorkflow->id)
                            ->where('bulan_anggaran', $targetMonth)
                            ->where('tahun_anggaran', $tahun)
                            ->exists();

                        if ($exists) {
                            $skipped++;

                            continue;
                        }

                        // Subsequent PABD check: previous month's PRBL must be complete
                        $previousPabd = PabdWorkflow::query()
                            ->where('workspace_id', $workspace->id)
                            ->where('team_id', $teamId)
                            ->where('pp_workflow_id', $ppWorkflow->id)
                            ->whereNull('deleted_at')
                            ->where(function ($q) use ($targetMonth, $tahun) {
                                $q->where('tahun_anggaran', '<', $tahun)
                                    ->orWhere(function ($q2) use ($targetMonth, $tahun) {
                                        $q2->where('tahun_anggaran', $tahun)
                                            ->where('bulan_anggaran', '<', $targetMonth);
                                    });
                            })
                            ->orderByDesc('tahun_anggaran')
                            ->orderByDesc('bulan_anggaran')
                            ->first();

                        if ($previousPabd) {
                            // Previous PABD exists — check its PRBL is complete
                            $previousPrbl = PrblWorkflow::query()
                                ->where('workspace_id', $workspace->id)
                                ->where('team_id', $teamId)
                                ->where('pp_workflow_id', $ppWorkflow->id)
                                ->where('bulan_laporan', $previousPabd->bulan_anggaran)
                                ->where('tahun_laporan', $previousPabd->tahun_anggaran)
                                ->first();

                            if (! $previousPrbl) {
                                $skipped++;

                                continue;
                            }

                            $prblStatus = $this->engine->getWorkflowStatus($previousPrbl->history ?? []);
                            if ($prblStatus !== 'completed') {
                                $skipped++;

                                continue;
                            }
                        }

                        // Create PABD workflow + PABD01 data + items
                        $pabdWorkflow = $this->createPabdWorkflow(
                            $workspace,
                            $teamId,
                            $ppWorkflow,
                            $pk04Finals,
                            $targetMonth,
                            $tahun,
                        );

                        // Notify team that PABD has been auto-created
                        $this->notifier->notify($pabdWorkflow, 'pabd.auto_created', [
                            'actor_name' => 'Sistem',
                        ]);

                        $created++;
                    } catch (\Throwable $e) {
                        $this->components->error("PABD auto-create failed for team {$teamId} (PP {$ppWorkflow->id}, month {$targetMonth}/{$tahun}): {$e->getMessage()}");
                        $skipped++;
                    }
                }
            }
        }

        $this->components->info("PABD auto-create: {$created} created, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /**
     * Create a PABD workflow with PABD01 data and item anggaran rows.
     *
     * @param  \Illuminate\Support\Collection<int, Pk04ProgramTahunan>  $pk04Finals
     */
    private function createPabdWorkflow(
        Workspace $workspace,
        int $teamId,
        PpWorkflow $ppWorkflow,
        $pk04Finals,
        int $targetMonth,
        int $tahun,
    ): PabdWorkflow {
        return DB::transaction(function () use ($workspace, $teamId, $ppWorkflow, $pk04Finals, $targetMonth, $tahun) {
            $pabdWorkflow = PabdWorkflow::create([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'team_id' => $teamId,
                'pp_workflow_id' => $ppWorkflow->id,
                'bulan_anggaran' => $targetMonth,
                'tahun_anggaran' => $tahun,
                'created_by_user_id' => null,
                'created_by_role_id' => null,
                'created_by_team_id' => null,
                'created_by_org_id' => null,
                'history' => [],
            ]);

            // Collect all pk04_anggaran IDs for this month from all PK04 finals
            $anggaranIds = Pk04Anggaran::query()
                ->whereHas('pk04Kegiatan', function ($q) use ($pk04Finals, $targetMonth) {
                    $q->whereIn('pk04_program_tahunan_id', $pk04Finals->pluck('id'))
                        ->where('bulan', $targetMonth);
                })
                ->pluck('id');

            // Build pk04_revisions_snapshot
            $revisionsSnapshot = [];
            foreach ($pk04Finals as $pk04) {
                $hasKegiatanInMonth = Pk04Kegiatan::query()
                    ->where('pk04_program_tahunan_id', $pk04->id)
                    ->where('bulan', $targetMonth)
                    ->exists();

                if ($hasKegiatanInMonth) {
                    $revisionsSnapshot[$pk04->id] = $pk04->revision;
                }
            }

            $pabd01 = Pabd01Data::create([
                'pabd_workflow_id' => $pabdWorkflow->id,
                'ada_perubahan' => false,
                'pk04_revisions_snapshot' => $revisionsSnapshot,
            ]);

            foreach ($anggaranIds as $anggaranId) {
                Pabd01ItemAnggaran::create([
                    'pabd01_data_id' => $pabd01->id,
                    'pk04_anggaran_id' => $anggaranId,
                    'dicairkan' => false,
                ]);
            }

            // Record creation in history
            $this->engine->recordAction(
                workflow: $pabdWorkflow,
                step: 'PABD01',
                action: 'created',
                userId: null,
                sessionContext: [],
                table: 'pabd01_data',
                dataId: $pabd01->id,
            );

            return $pabdWorkflow;
        });
    }
}
