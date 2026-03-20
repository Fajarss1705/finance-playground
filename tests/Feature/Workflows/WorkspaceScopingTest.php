<?php

use App\Models\Pabd\Pabd01Data;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Permission;
use App\Models\Pp\PpWorkflow;
use App\Models\Prbl\Prbl01Data;
use App\Models\Prbl\PrblWorkflow;
use App\Models\Team;
use App\Models\User;
use App\Services\ActiveSessionService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
});

// ── Helpers ──

function scopeUser(string ...$permissionNames): array
{
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $workspace = $role->team->organization->workspaces->first();
    $team = $role->team;

    foreach ($permissionNames as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $role->permissions()->syncWithoutDetaching($permission);
    }

    return [$user, $role, $workspace, $team];
}

function scopeSession(object $test, $user, $role, $workspace): void
{
    $test->actingAs($user);
    app(ActiveSessionService::class)->switchTo($role, $workspace);
}

/**
 * Create a second user+workspace+team pair for cross-workspace testing.
 */
function scopeOtherContext(): array
{
    $otherUser = User::factory()->withRole()->create();
    $otherRole = $otherUser->roles->first();
    $otherWorkspace = $otherRole->team->organization->workspaces->first();
    $otherTeam = $otherRole->team;

    return [$otherWorkspace, $otherTeam];
}

function scopeCreatePabd($workspace, int $teamId): PabdWorkflow
{
    $pp = PpWorkflow::create([
        'workspace_id' => $workspace->id,
        'history' => [],
    ]);

    $pabd = PabdWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $teamId,
        'pp_workflow_id' => $pp->id,
        'bulan_anggaran' => 4,
        'tahun_anggaran' => 2027,
        'created_by_user_id' => null,
        'created_by_role_id' => null,
        'created_by_team_id' => null,
        'created_by_org_id' => null,
        'history' => [
            ['step' => 'PABD01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
        ],
    ]);

    Pabd01Data::create([
        'pabd_workflow_id' => $pabd->id,
        'ada_perubahan' => false,
        'pk04_revisions_snapshot' => [],
    ]);

    return $pabd;
}

function scopeCreatePrbl($workspace, int $teamId): PrblWorkflow
{
    $pp = PpWorkflow::create([
        'workspace_id' => $workspace->id,
        'history' => [],
    ]);

    $pabd = PabdWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $teamId,
        'pp_workflow_id' => $pp->id,
        'bulan_anggaran' => 4,
        'tahun_anggaran' => 2027,
        'created_by_user_id' => null,
        'created_by_role_id' => null,
        'created_by_team_id' => null,
        'created_by_org_id' => null,
        'history' => [],
    ]);

    $prbl = PrblWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $teamId,
        'pabd_workflow_id' => $pabd->id,
        'pp_workflow_id' => $pp->id,
        'bulan_laporan' => 4,
        'tahun_laporan' => 2027,
        'created_by_user_id' => null,
        'created_by_role_id' => null,
        'created_by_team_id' => null,
        'created_by_org_id' => null,
        'history' => [
            ['step' => 'PRBL01', 'action' => 'created', 'by' => null, 'role' => null, 'team' => null, 'org' => null, 'workspace' => $workspace->id, 'at' => now()->toIso8601String()],
        ],
    ]);

    Prbl01Data::create(['prbl_workflow_id' => $prbl->id]);

    return $prbl;
}

// ── PABD Workspace Scoping ──

it('returns 403 for PABD show from different workspace (admin)', function () {
    [$user, $role, $workspace] = scopeUser('admin.workflows.pabd.show');
    scopeSession($this, $user, $role, $workspace);

    [$otherWorkspace, $otherTeam] = scopeOtherContext();
    $pabd = scopeCreatePabd($otherWorkspace, $otherTeam->id);

    $this->get(route('admin.workflows.pabd.show', $pabd))->assertStatus(403);
});

it('returns 403 for PABD show from different workspace (team)', function () {
    [$user, $role, $workspace] = scopeUser('team.workflows.pabd.show');
    scopeSession($this, $user, $role, $workspace);

    [$otherWorkspace, $otherTeam] = scopeOtherContext();
    $pabd = scopeCreatePabd($otherWorkspace, $otherTeam->id);

    $this->get(route('team.workflows.pabd.show', $pabd))->assertStatus(403);
});

it('returns 403 for PABD team show from same workspace but different team', function () {
    [$user, $role, $workspace, $team] = scopeUser('team.workflows.pabd.show');
    scopeSession($this, $user, $role, $workspace);

    // Create a second team under the same organization
    $otherTeam = Team::factory()->create(['organization_id' => $team->organization_id]);
    $pabd = scopeCreatePabd($workspace, $otherTeam->id);

    $this->get(route('team.workflows.pabd.show', $pabd))->assertStatus(403);
});

it('returns 403 for PABD03 approve from different workspace', function () {
    [$user, $role, $workspace] = scopeUser('admin.workflows.pabd.pabd03.approve');
    scopeSession($this, $user, $role, $workspace);

    [$otherWorkspace, $otherTeam] = scopeOtherContext();
    $pabd = scopeCreatePabd($otherWorkspace, $otherTeam->id);

    $this->post(route('admin.workflows.pabd.pabd03.approve', $pabd), [
        'expected_updated_at' => now()->toIso8601String(),
    ])->assertStatus(403);
});

// ── PRBL Workspace Scoping ──

it('returns 403 for PRBL show from different workspace (admin)', function () {
    [$user, $role, $workspace] = scopeUser('admin.workflows.prbl.show');
    scopeSession($this, $user, $role, $workspace);

    [$otherWorkspace, $otherTeam] = scopeOtherContext();
    $prbl = scopeCreatePrbl($otherWorkspace, $otherTeam->id);

    $this->get(route('admin.workflows.prbl.show', $prbl))->assertStatus(403);
});

it('returns 403 for PRBL show from different workspace (team)', function () {
    [$user, $role, $workspace] = scopeUser('team.workflows.prbl.show');
    scopeSession($this, $user, $role, $workspace);

    [$otherWorkspace, $otherTeam] = scopeOtherContext();
    $prbl = scopeCreatePrbl($otherWorkspace, $otherTeam->id);

    $this->get(route('team.workflows.prbl.show', $prbl))->assertStatus(403);
});

it('returns 403 for PRBL team show from same workspace but different team', function () {
    [$user, $role, $workspace, $team] = scopeUser('team.workflows.prbl.show');
    scopeSession($this, $user, $role, $workspace);

    $otherTeam = Team::factory()->create(['organization_id' => $team->organization_id]);
    $prbl = scopeCreatePrbl($workspace, $otherTeam->id);

    $this->get(route('team.workflows.prbl.show', $prbl))->assertStatus(403);
});

it('returns 403 for PRBL02A approve from different workspace', function () {
    [$user, $role, $workspace] = scopeUser('admin.workflows.prbl.prbl02a.approve');
    scopeSession($this, $user, $role, $workspace);

    [$otherWorkspace, $otherTeam] = scopeOtherContext();
    $prbl = scopeCreatePrbl($otherWorkspace, $otherTeam->id);

    $this->post(route('admin.workflows.prbl.prbl02a.approve', $prbl), [
        'expected_updated_at' => now()->toIso8601String(),
    ])->assertStatus(403);
});

it('returns 403 for PRBL04 approve from different workspace', function () {
    [$user, $role, $workspace] = scopeUser('admin.workflows.prbl.prbl04.approve');
    scopeSession($this, $user, $role, $workspace);

    [$otherWorkspace, $otherTeam] = scopeOtherContext();
    $prbl = scopeCreatePrbl($otherWorkspace, $otherTeam->id);

    $this->post(route('admin.workflows.prbl.prbl04.approve', $prbl), [
        'expected_updated_at' => now()->toIso8601String(),
    ])->assertStatus(403);
});
