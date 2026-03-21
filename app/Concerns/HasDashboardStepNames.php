<?php

namespace App\Concerns;

trait HasDashboardStepNames
{
    /**
     * Human-friendly step names for dashboard display.
     * Excludes final/compile steps (PP06, PK04, PABD05, PRBL05) — never user tasks.
     *
     * @var array<string, string>
     */
    private const STEP_NAMES = [
        // PP
        'PP01' => 'Jadwal & Kode Pelayanan',
        'PP02' => 'Kuisioner Evaluasi',
        'PP03' => 'Plafon Anggaran Tim',
        'PP04' => 'Dokumen SOP',
        'PP05' => 'Persetujuan',
        'PP07' => 'Revisi Periode Tahunan',
        // PK
        'PK01' => 'Input Program & Kegiatan',
        'PK02A' => 'Review Narasi',
        'PK02B' => 'Review Anggaran',
        'PK03' => 'Persetujuan RAKER',
        'PK05' => 'Revisi Program & Kegiatan',
        // PABD
        'PABD01' => 'Konfirmasi Anggaran',
        'PABD02A' => 'Permohonan Perubahan',
        'PABD02B' => 'Review Perubahan Anggaran',
        'PABD03' => 'Persetujuan Pencairan',
        'PABD04' => 'Upload Bukti Transfer',
        // PRBL
        'PRBL01' => 'Laporan Realisasi',
        'PRBL02A' => 'Review Narasi',
        'PRBL02B' => 'Review Anggaran',
        'PRBL03' => 'Refund & Bukti Transfer',
        'PRBL04' => 'Review Akhir',
    ];

    /**
     * Steps whose route URL includes a step data ID parameter.
     * Rule: step has a table AND is not a Final step type.
     *
     * @var list<string>
     */
    private const STEPS_WITH_DATA_ID = [
        'PP01', 'PP02', 'PP03', 'PP04', 'PP07',
        'PK01', 'PK05',
        'PABD01', 'PABD02A', 'PABD02B', 'PABD04',
        'PRBL01', 'PRBL03',
    ];

    /**
     * Sort priority: lower = shown first.
     * PABD/PRBL (monthly deadline) before PK before PP.
     */
    private function taskSortPriority(string $type): int
    {
        return match ($type) {
            'pabd', 'prbl' => 1,
            'pk' => 2,
            'pp' => 3,
            default => 9,
        };
    }

    /**
     * Build a scope-aware URL to a step page.
     */
    private function buildStepUrl(string $scope, string $type, int $workflowId, string $stepCode, ?int $dataId): string
    {
        $stepLower = strtolower($stepCode);
        $base = "/{$scope}/workflows/{$type}/{$workflowId}/{$stepLower}";

        if (in_array($stepCode, self::STEPS_WITH_DATA_ID, true) && $dataId !== null) {
            return "{$base}/{$dataId}";
        }

        return $base;
    }

    /**
     * Determine scope (team or admin) for a role's permissions on a workflow type.
     *
     * @param  list<string>  $permissions
     */
    private function getRoleScope(array $permissions, string $workflowType): ?string
    {
        $teamPrefix = "team.workflows.{$workflowType}.";
        $adminPrefix = "admin.workflows.{$workflowType}.";

        $hasAdmin = false;
        $hasTeam = false;

        foreach ($permissions as $p) {
            if (str_starts_with($p, $adminPrefix)) {
                $hasAdmin = true;
            }
            if (str_starts_with($p, $teamPrefix)) {
                $hasTeam = true;
            }
        }

        if ($hasAdmin) {
            return 'admin';
        }
        if ($hasTeam) {
            return 'team';
        }

        return null;
    }

    /**
     * Check if role has an actionable permission (draft/submit/approve/reject) for a step.
     *
     * @param  list<string>  $permissions
     */
    private function hasActionablePermission(array $permissions, string $scope, string $type, string $step): bool
    {
        $stepLower = strtolower($step);
        $suffixes = ['.draft', '.submit', '.approve', '.reject'];

        foreach ($suffixes as $suffix) {
            $perm = "{$scope}.workflows.{$type}.{$stepLower}{$suffix}";
            if (in_array($perm, $permissions, true)) {
                return true;
            }
        }

        return false;
    }
}
