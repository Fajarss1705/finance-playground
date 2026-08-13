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

        // Allow PABD creation up to 30 days before the 1st of the target month.
        // On e.g. July 2, we can create August PABD. On July 30, still August.
        // On August 1, target becomes September.
        $startOfTarget = $now->copy()->startOfMonth()->addMonthNoOverflow();
        $daysUntil = $now->diffInDays($startOfTarget, false);
        if ($daysUntil > 30) {
            // Too early to create PABD for this month
            $this->components->info("PABD auto-create: too early — {$daysUntil} days until month start.");

            return self::SUCCESS;
        }
        $targetMonth = $startOfTarget->month;
        $targetYear = $startOfTarget->year;

        // One-month grace: the month immediately before the target is exempt from the
        // "prior PRBL must be complete" gate. Teams are never finished reporting month
        // N-1 by the time month N+1 opens, so requiring it deadlocks the whole cycle
        // and every PABD ends up created by hand. Everything older than the grace month
        // still has to be closed, so a backlog cannot grow past one month.
        $startOfGrace = $startOfTarget->copy()->subMonthNoOverflow();
        $graceMonth = $startOfGrace->month;
        $graceYear = $startOfGrace->year;

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

                // A programme only generates workflows for its own year.
                //
                // Without this the target month comes from the calendar while the year
                // comes from PP06, and the two drift apart in two ways. A stale programme
                // (one was left in production from April 2026 testing, tahun 2025) keeps
                // matching calendar months and stamps them with its own year, manufacturing
                // workflows for months that never existed. And every December, the January
                // target gets stamped with the *current* year, so the duplicate guard below
                // matches January of the year just ending and skips every team — the cycle
                // would stop dead and the skip counter would not say why.
                //
                // Refusing is the correct answer, not a fallback: January 2027 budgets come
                // from the 2027 programme, and if that does not exist yet there is nothing
                // to base a PABD on.
                if ((int) $tahun !== $targetYear) {
                    $skipped++;

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

                        // All prior PABD months must have completed PRBL, except the
                        // immediately preceding month — see the grace note in handle().
                        $allPreviousPabd = PabdWorkflow::query()
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
                            ->whereNot(function ($q) use ($graceMonth, $graceYear) {
                                $q->where('tahun_anggaran', $graceYear)
                                    ->where('bulan_anggaran', $graceMonth);
                            })
                            ->get();

                        $allPriorPrblComplete = true;
                        foreach ($allPreviousPabd as $prevPabd) {
                            $prevPrbl = PrblWorkflow::query()
                                ->where('workspace_id', $workspace->id)
                                ->where('team_id', $teamId)
                                ->where('pp_workflow_id', $ppWorkflow->id)
                                ->where('bulan_laporan', $prevPabd->bulan_anggaran)
                                ->where('tahun_laporan', $prevPabd->tahun_anggaran)
                                ->whereNull('deleted_at')
                                ->first();

                            if (! $prevPrbl) {
                                $allPriorPrblComplete = false;
                                break;
                            }

                            $prblStatus = $this->engine->getWorkflowStatus($prevPrbl->history ?? []);
                            if ($prblStatus !== 'completed') {
                                $allPriorPrblComplete = false;
                                break;
                            }
                        }

                        if (! $allPriorPrblComplete) {
                            $skipped++;

                            continue;
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
