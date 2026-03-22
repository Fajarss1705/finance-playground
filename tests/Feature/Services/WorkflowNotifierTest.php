<?php

use App\Models\Notification;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Permission;
use App\Models\Pp\PpWorkflow;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\WorkflowNotifier;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    Mail::fake();
});

// === Helpers ===

function createWorkspaceWithUsers(): array
{
    // Create Monev user (has pp02.draft — will be notified on pp01.submitted)
    $monevUser = User::factory()->withRole()->create(['name' => 'Monev User']);
    $monevRole = $monevUser->roles->first();
    $workspace = $monevRole->team->organization->workspaces->first();
    $organization = $monevRole->team->organization;

    $monevPermissions = [
        'admin.workflows.pp.pp02.draft',
        'admin.workflows.pp.pp02.submit',
        'admin.workflows.pp.pp03.draft',
        'admin.workflows.pp.pp04.draft',
        'admin.workflows.pp.pp05.approve',
        'admin.workflows.pp.pp06.show',
        'admin.workflows.pp.pp01.draft',
    ];

    foreach ($monevPermissions as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $monevRole->permissions()->syncWithoutDetaching($permission);
    }

    // Create BU user (has pp03.draft — will be notified on pp02.submitted)
    $buTeam = Team::factory()->for($organization)->create(['name' => 'Tim BU']);
    $buRole = Role::factory()->for($buTeam)->create(['name' => 'Bendahara Umum']);
    $buUser = User::factory()->withRole($buRole, $workspace)->create(['name' => 'BU User']);

    $buPermissions = [
        'admin.workflows.pp.pp03.draft',
        'admin.workflows.pp.pp04.draft',
        'admin.workflows.pp.pp06.show',
        'admin.workflows.pp.pp01.draft',
    ];

    foreach ($buPermissions as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $buRole->permissions()->syncWithoutDetaching($permission);
    }

    $ppWorkflow = PpWorkflow::create([
        'workspace_id' => $workspace->id,
        'history' => [],
    ]);

    return [
        'workspace' => $workspace,
        'monevUser' => $monevUser,
        'monevRole' => $monevRole,
        'buUser' => $buUser,
        'buRole' => $buRole,
        'ppWorkflow' => $ppWorkflow,
    ];
}

// === Tests ===

it('sends notifications to PP02 drafters on pp01.submitted', function () {
    $data = createWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    $notifier->notify($data['ppWorkflow'], 'pp01.submitted', [
        'actor_name' => 'Actor User',
        'actor_role' => 'Koordinator MONEV',
        'link' => '/admin/workflows/pp/1/pp02/1',
    ], actorUserId: 999); // non-existent actor — won't exclude anyone

    // Monev user has pp02.draft permission → should receive notification
    expect(Notification::where('user_id', $data['monevUser']->id)->count())->toBe(1);

    $notification = Notification::where('user_id', $data['monevUser']->id)->first();
    expect($notification->subject)->toContain('PP01');
    expect($notification->subject)->toContain('disubmit');
    expect($notification->body)->toContain('Actor User');
    expect($notification->body)->toContain('Koordinator MONEV');
    expect($notification->link)->toBe('/admin/workflows/pp/1/pp02/1');
});

it('sends notifications to PP03 drafters on pp02.submitted', function () {
    $data = createWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    $notifier->notify($data['ppWorkflow'], 'pp02.submitted', [
        'actor_name' => 'Monev User',
        'actor_role' => 'Evaluator',
        'link' => '/admin/workflows/pp/1/pp03/1',
    ], actorUserId: $data['monevUser']->id);

    // Both Monev and BU have pp03.draft → both should receive (minus actor)
    // Monev user IS the actor → excluded
    expect(Notification::where('user_id', $data['monevUser']->id)->count())->toBe(0);

    // BU user has pp03.draft → should receive
    expect(Notification::where('user_id', $data['buUser']->id)->count())->toBe(1);
});

it('excludes the actor from notifications', function () {
    $data = createWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    // Monev user submits PP01, and Monev user also has pp02.draft
    $notifier->notify($data['ppWorkflow'], 'pp01.submitted', [
        'actor_name' => 'Monev User',
        'actor_role' => 'Koordinator MONEV',
        'link' => '/test',
    ], actorUserId: $data['monevUser']->id);

    // Monev user should NOT receive notification (they are the actor)
    expect(Notification::where('user_id', $data['monevUser']->id)->count())->toBe(0);
});

it('deduplicates notifications for users with multiple roles', function () {
    $data = createWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    // Give BU user a second role with pp03.draft in the same workspace
    $secondTeam = Team::factory()->for($data['buRole']->team->organization)->create(['name' => 'Tim BU 2']);
    $secondRole = Role::factory()->for($secondTeam)->create(['name' => 'BU Backup']);
    $data['buUser']->roles()->attach($secondRole);

    $pp03Draft = Permission::firstOrCreate(['name' => 'admin.workflows.pp.pp03.draft']);
    $secondRole->permissions()->syncWithoutDetaching($pp03Draft);

    $notifier->notify($data['ppWorkflow'], 'pp02.submitted', [
        'actor_name' => 'Test',
        'actor_role' => 'Test',
        'link' => '/test',
    ], actorUserId: 999);

    // BU user should receive exactly 1 notification despite having 2 eligible roles
    expect(Notification::where('user_id', $data['buUser']->id)->count())->toBe(1);
});

it('sends notifications to all history users on terminate', function () {
    $data = createWorkspaceWithUsers();

    // Build history with both users having acted
    $data['ppWorkflow']->history = [
        ['step' => 'PP01', 'action' => 'created', 'by' => $data['monevUser']->id, 'role' => $data['monevRole']->id, 'at' => now()->subHours(2)->toIso8601String()],
        ['step' => 'PP01', 'action' => 'submitted', 'by' => $data['monevUser']->id, 'role' => $data['monevRole']->id, 'at' => now()->subHour()->toIso8601String()],
        ['step' => 'PP03', 'action' => 'submitted', 'by' => $data['buUser']->id, 'role' => $data['buRole']->id, 'at' => now()->subMinutes(30)->toIso8601String()],
    ];
    $data['ppWorkflow']->save();

    $notifier = app(WorkflowNotifier::class);

    // Monev user terminates → BU user should be notified (actor excluded)
    $notifier->notify($data['ppWorkflow'], 'PP03.terminated', [
        'actor_name' => 'Monev User',
        'actor_role' => 'Koordinator MONEV',
        'link' => '/admin/workflows/pp/1',
    ], actorUserId: $data['monevUser']->id);

    // Monev user is actor → excluded
    expect(Notification::where('user_id', $data['monevUser']->id)->count())->toBe(0);

    // BU user was in history → should receive
    expect(Notification::where('user_id', $data['buUser']->id)->count())->toBe(1);

    $notification = Notification::where('user_id', $data['buUser']->id)->first();
    expect($notification->subject)->toContain('dibatalkan');
});

it('sends zero notifications when no eligible recipients exist', function () {
    $data = createWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    // PP04 submit → notifies pp05.approve holders — only monevUser has it
    // monevUser is the actor → excluded → 0 sent
    $notifier->notify($data['ppWorkflow'], 'pp04.submitted', [
        'actor_name' => 'Test',
        'actor_role' => 'Test',
        'link' => '/test',
    ], actorUserId: $data['monevUser']->id);

    expect(Notification::count())->toBe(0);
});

it('builds correct notification template for pp05.approved', function () {
    $data = createWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    $notifier->notify($data['ppWorkflow'], 'pp05.approved', [
        'actor_name' => 'Hendra Wijaya',
        'actor_role' => 'Koordinator MONEV',
        'link' => '/admin/workflows/pp/1/pp06',
    ], actorUserId: 999);

    // Both users have pp06.show → both should receive
    $notifications = Notification::all();
    expect($notifications->count())->toBeGreaterThanOrEqual(1);

    $first = $notifications->first();
    expect($first->subject)->toContain('disetujui');
    expect($first->body)->toContain('Hendra Wijaya (Koordinator MONEV)');
    expect($first->body)->toContain('PP06');
});

// ========================================
// PABD Notification Tests
// ========================================

function createPabdWorkspaceWithUsers(): array
{
    // Team user (has team-scoped PABD permissions)
    $teamUser = User::factory()->withRole()->create(['name' => 'Tim Bapel User']);
    $teamRole = $teamUser->roles->first();
    $workspace = $teamRole->team->organization->workspaces->first();
    $organization = $teamRole->team->organization;
    $team = $teamRole->team;

    $teamPermissions = [
        'team.workflows.pabd.pabd01.submit',
        'team.workflows.pabd.pabd02a.draft',
        'team.workflows.pabd.pabd05.show',
    ];

    foreach ($teamPermissions as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $teamRole->permissions()->syncWithoutDetaching($permission);
    }

    // BU user (admin-scoped — has pabd02b.approve, pabd03.approve, pabd04.submit)
    $buTeam = Team::factory()->for($organization)->create(['name' => 'Tim BU']);
    $buRole = Role::factory()->for($buTeam)->create(['name' => 'Bendahara Umum']);
    $buUser = User::factory()->withRole($buRole, $workspace)->create(['name' => 'BU User PABD']);

    $buPermissions = [
        'admin.workflows.pabd.pabd02b.approve',
        'admin.workflows.pabd.pabd03.approve',
        'admin.workflows.pabd.pabd04.submit',
        'admin.workflows.pabd.pabd05.show',
    ];

    foreach ($buPermissions as $name) {
        $permission = Permission::firstOrCreate(['name' => $name]);
        $buRole->permissions()->syncWithoutDetaching($permission);
    }

    $ppWorkflow = PpWorkflow::create([
        'workspace_id' => $workspace->id,
        'history' => [],
    ]);

    $pabdWorkflow = PabdWorkflow::create([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'team_id' => $team->id,
        'pp_workflow_id' => $ppWorkflow->id,
        'bulan_anggaran' => 3,
        'tahun_anggaran' => 2027,
        'history' => [],
    ]);

    return [
        'workspace' => $workspace,
        'teamUser' => $teamUser,
        'teamRole' => $teamRole,
        'buUser' => $buUser,
        'buRole' => $buRole,
        'pabdWorkflow' => $pabdWorkflow,
    ];
}

it('sends notifications to PABD01 submitters on pabd.auto_created', function () {
    $data = createPabdWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    $notifier->notify($data['pabdWorkflow'], 'pabd.auto_created', [
        'actor_name' => 'Sistem',
    ]);

    // Team user has team.workflows.pabd.pabd01.submit → should receive
    expect(Notification::where('user_id', $data['teamUser']->id)->count())->toBe(1);

    $notification = Notification::where('user_id', $data['teamUser']->id)->first();
    expect($notification->subject)->toContain('dibuat');
    expect($notification->subject)->toContain('PABD');
    expect($notification->body)->toContain('Sistem');
    expect($notification->body)->toContain('PABD01');

    // BU user does NOT have pabd01.submit → should NOT receive
    expect(Notification::where('user_id', $data['buUser']->id)->count())->toBe(0);
});

it('sends notifications to PABD02A drafters on pabd01.submitted_change', function () {
    $data = createPabdWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    $notifier->notify($data['pabdWorkflow'], 'pabd01.submitted_change', [
        'actor_name' => 'Tim Bapel User',
        'actor_role' => 'Bapel',
    ], actorUserId: $data['teamUser']->id);

    // Team user has pabd02a.draft but IS the actor → excluded
    expect(Notification::where('user_id', $data['teamUser']->id)->count())->toBe(0);

    // BU user does NOT have pabd02a.draft → should NOT receive
    expect(Notification::where('user_id', $data['buUser']->id)->count())->toBe(0);
});

it('sends notifications to PABD03 approvers on pabd01.submitted_skip', function () {
    $data = createPabdWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    $notifier->notify($data['pabdWorkflow'], 'pabd01.submitted_skip', [
        'actor_name' => 'Tim Bapel User',
        'actor_role' => 'Bapel',
    ], actorUserId: $data['teamUser']->id);

    // BU user has admin.workflows.pabd.pabd03.approve → should receive
    expect(Notification::where('user_id', $data['buUser']->id)->count())->toBe(1);

    $notification = Notification::where('user_id', $data['buUser']->id)->first();
    expect($notification->subject)->toContain('disubmit');
    expect($notification->body)->toContain('PABD03');
});

it('sends notifications to PABD02B approvers on pabd02a.submitted', function () {
    $data = createPabdWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    $notifier->notify($data['pabdWorkflow'], 'pabd02a.submitted', [
        'actor_name' => 'Tim Bapel User',
        'actor_role' => 'Bapel',
    ], actorUserId: $data['teamUser']->id);

    // BU user has admin.workflows.pabd.pabd02b.approve → should receive
    expect(Notification::where('user_id', $data['buUser']->id)->count())->toBe(1);

    $notification = Notification::where('user_id', $data['buUser']->id)->first();
    expect($notification->subject)->toContain('disubmit');
    expect($notification->body)->toContain('PABD02A');
});

it('sends notifications to PABD01 submitters on pabd02b.approved (cycle back)', function () {
    $data = createPabdWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    $notifier->notify($data['pabdWorkflow'], 'pabd02b.approved', [
        'actor_name' => 'BU User PABD',
        'actor_role' => 'Bendahara Umum',
    ], actorUserId: $data['buUser']->id);

    // Team user has team.workflows.pabd.pabd01.submit → should receive
    expect(Notification::where('user_id', $data['teamUser']->id)->count())->toBe(1);

    $notification = Notification::where('user_id', $data['teamUser']->id)->first();
    expect($notification->subject)->toContain('disetujui');
    expect($notification->body)->toContain('PABD02B');
    expect($notification->body)->toContain('PK04');
});

it('sends notifications to PABD01 submitters on pabd02b.rejected (cycle back)', function () {
    $data = createPabdWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    $notifier->notify($data['pabdWorkflow'], 'pabd02b.rejected', [
        'actor_name' => 'BU User PABD',
        'actor_role' => 'Bendahara Umum',
    ], actorUserId: $data['buUser']->id);

    // Team user has team.workflows.pabd.pabd01.submit → should receive
    expect(Notification::where('user_id', $data['teamUser']->id)->count())->toBe(1);

    $notification = Notification::where('user_id', $data['teamUser']->id)->first();
    expect($notification->subject)->toContain('ditolak');
    expect($notification->body)->toContain('PABD02B');
});

it('sends notifications to PABD04 submitters on pabd03.approved', function () {
    $data = createPabdWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    $notifier->notify($data['pabdWorkflow'], 'pabd03.approved', [
        'actor_name' => 'BU User PABD',
        'actor_role' => 'Bendahara Umum',
    ], actorUserId: $data['buUser']->id);

    // BU user has admin.workflows.pabd.pabd04.submit but IS the actor → excluded
    expect(Notification::where('user_id', $data['buUser']->id)->count())->toBe(0);
});

it('sends notifications to PABD01 submitters on pabd03.rejected', function () {
    $data = createPabdWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    $notifier->notify($data['pabdWorkflow'], 'pabd03.rejected', [
        'actor_name' => 'BU User PABD',
        'actor_role' => 'Bendahara Umum',
    ], actorUserId: $data['buUser']->id);

    // Team user has team.workflows.pabd.pabd01.submit → should receive
    expect(Notification::where('user_id', $data['teamUser']->id)->count())->toBe(1);

    $notification = Notification::where('user_id', $data['teamUser']->id)->first();
    expect($notification->subject)->toContain('ditolak');
    expect($notification->body)->toContain('PABD03');
});

it('sends notifications to PABD05 viewers on pabd04.submitted', function () {
    $data = createPabdWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    $notifier->notify($data['pabdWorkflow'], 'pabd04.submitted', [
        'actor_name' => 'BU User PABD',
        'actor_role' => 'Bendahara Umum',
    ], actorUserId: $data['buUser']->id);

    // Team user has team.workflows.pabd.pabd05.show → should receive
    expect(Notification::where('user_id', $data['teamUser']->id)->count())->toBe(1);

    $notification = Notification::where('user_id', $data['teamUser']->id)->first();
    expect($notification->subject)->toContain('dikompilasi');
    expect($notification->body)->toContain('PABD05');
});

it('sends notifications to PABD01 submitters on pk04_staleness_reset', function () {
    $data = createPabdWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    $notifier->notify($data['pabdWorkflow'], 'pabd.pk04_staleness_reset', [
        'actor_name' => 'Sistem',
    ]);

    // Team user has team.workflows.pabd.pabd01.submit → should receive
    expect(Notification::where('user_id', $data['teamUser']->id)->count())->toBe(1);

    $notification = Notification::where('user_id', $data['teamUser']->id)->first();
    expect($notification->subject)->toContain('direset');
    expect($notification->body)->toContain('PK04');
});

it('excludes actor from PABD notifications', function () {
    $data = createPabdWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    // Team user submits PABD01 (ada_perubahan) — team user also has pabd02a.draft
    $notifier->notify($data['pabdWorkflow'], 'pabd01.submitted_change', [
        'actor_name' => 'Tim Bapel User',
        'actor_role' => 'Bapel',
    ], actorUserId: $data['teamUser']->id);

    // Team user IS the actor → excluded (they're the only one with pabd02a.draft)
    expect(Notification::count())->toBe(0);
});

it('resolves scope-aware links for PABD notifications', function () {
    $data = createPabdWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    // Provide admin_link and team_link — recipients should get the correct one based on role scope
    $notifier->notify($data['pabdWorkflow'], 'pabd02b.approved', [
        'actor_name' => 'BU User PABD',
        'actor_role' => 'Bendahara Umum',
        'admin_link' => '/admin/workflows/pabd/1',
        'team_link' => '/team/workflows/pabd/1',
    ], actorUserId: $data['buUser']->id);

    // Team user has team.* permission → should get team_link
    $notification = Notification::where('user_id', $data['teamUser']->id)->first();
    expect($notification)->not->toBeNull();
    expect($notification->link)->toBe('/team/workflows/pabd/1');
});

it('builds correct PABD label with team name and month', function () {
    $data = createPabdWorkspaceWithUsers();
    $notifier = app(WorkflowNotifier::class);

    $notifier->notify($data['pabdWorkflow'], 'pabd.auto_created', [
        'actor_name' => 'Sistem',
    ]);

    $notification = Notification::where('user_id', $data['teamUser']->id)->first();
    expect($notification)->not->toBeNull();
    // Label format: "PABD-{teamName}-Maret/2027"
    expect($notification->subject)->toContain('PABD-');
    expect($notification->subject)->toContain('Maret');
    expect($notification->subject)->toContain('2027');
});
