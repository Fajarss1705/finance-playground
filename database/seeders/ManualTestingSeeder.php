<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds org, workspace, teams, users, roles, and permissions for manual browser testing.
 *
 * NO workflows are created — test PP → PK → PABD → PRBL manually.
 *
 * Password for all accounts: `password123!`
 */
class ManualTestingSeeder extends Seeder
{
    private Organization $org;

    private Workspace $workspace;

    /** @var array<string, Team> */
    private array $teams = [];

    /** @var array<string, Role> */
    private array $roles = [];

    public function run(): void
    {
        if (Organization::where('name', 'Finance Playground')->exists()) {
            return;
        }

        $this->createOrgAndWorkspace();
        $this->createTeamsAndRoles();
        $this->syncAllPermissions();
        $this->createUsers();
        $this->assignBasePermissions();
        $this->assignPpPermissions();
        $this->assignPkPermissions();
        $this->assignPabdPermissions();
        $this->assignPrblPermissions();
        $this->giveAdminAllRoles();

        $this->command->info('Manual testing data seeded: 7 teams, 16 users, all permissions assigned.');
    }

    // =========================================================================
    // Organization + Workspace
    // =========================================================================

    private function createOrgAndWorkspace(): void
    {
        $this->org = Organization::create([
            'name' => 'Finance Playground',
            'description' => 'Finance Playground',
        ]);

        $this->workspace = Workspace::create([
            'name' => 'Workspace Monitoring dan Evaluasi',
            'description' => 'Workspace monitoring dan evaluasi Finance Playground',
        ]);

        $this->org->workspaces()->attach($this->workspace->id);
    }

    // =========================================================================
    // Teams + Roles
    // =========================================================================

    private function createTeamsAndRoles(): void
    {
        // Admin teams
        $this->createTeam('monev', 'Tim Monev', 'Tim Monitoring dan Evaluasi', [
            'Koordinator MONEV', 'Evaluator Narasi', 'Evaluator Anggaran',
        ]);

        $this->createTeam('bu', 'Tim Bendahara Umum', 'Tim Bendahara Umum organisasi', [
            'Bendahara Umum 1', 'Bendahara Umum 2', 'Asisten Bendahara Umum',
        ]);

        $this->createTeam('kg', 'Tim Kantor Pusat', 'Tim administrasi kantor pusat', [
            'Staff Kantor Pusat',
        ]);

        // Bapel teams (each gets standard 4 roles)
        $bapelRoles = ['Koordinator', 'Bendahara Tim', 'Sekretaris', 'Anggota'];

        $this->createTeam('remaja', 'Divisi Kepemudaan', 'Divisi kepemudaan', $bapelRoles);
        $this->createTeam('pemuda', 'Divisi Kaderisasi', 'Divisi kaderisasi', $bapelRoles);
        $this->createTeam('tmm', 'Tim Multi Media', 'Tim pelayanan multimedia organisasi', $bapelRoles);

        // Legacy team (kept for compatibility)
        $this->createTeam('anak', 'Divisi Pendidikan', 'Divisi pendidikan', [
            'Ketua', 'Sekretaris', 'Bendahara Tim',
        ]);
    }

    /** @param list<string> $roleNames */
    private function createTeam(string $key, string $name, string $description, array $roleNames): void
    {
        $team = Team::create([
            'organization_id' => $this->org->id,
            'name' => $name,
            'description' => $description,
        ]);

        $this->teams[$key] = $team;

        foreach ($roleNames as $roleName) {
            $role = Role::create(['team_id' => $team->id, 'name' => $roleName]);
            // Store with composite key: "teamKey.roleName" normalized
            $roleKey = $key.'_'.str_replace(' ', '_', strtolower($roleName));
            $this->roles[$roleKey] = $role;
        }
    }

    // =========================================================================
    // Permissions
    // =========================================================================

    private function syncAllPermissions(): void
    {
        // Create all PK/PABD/PRBL permission records
        $this->call(PermissionSeeder::class);

        // Also create PP permissions via route sync
        Artisan::call('permissions:sync');
    }

    // =========================================================================
    // Users
    // =========================================================================

    private function createUsers(): void
    {
        // Admin super-user
        $this->createUser('Admin Test User', 'admin@demo.test', []);

        // Tim Monev
        $this->createUser('Koordinator MONEV Test User', 'koordinator-monev@demo.test', ['monev_koordinator_monev']);
        $this->createUser('Evaluator Narasi Test User', 'evaluator-narasi@demo.test', ['monev_evaluator_narasi']);
        $this->createUser('Evaluator Anggaran Test User', 'evaluator-anggaran@demo.test', ['monev_evaluator_anggaran']);

        // Tim Bendahara Umum
        $this->createUser('Bendahara Umum 1 Test User', 'bu1@demo.test', ['bu_bendahara_umum_1']);
        $this->createUser('Bendahara Umum 2 Test User', 'bu2@demo.test', ['bu_bendahara_umum_2']);
        $this->createUser('Asisten Bendahara Umum Test User', 'asisten-bu@demo.test', ['bu_asisten_bendahara_umum']);

        // Tim Kantor Pusat
        $this->createUser('Staff Kantor Pusat Test User', 'staff-kg@demo.test', ['kg_staff_kantor_organisasi']);

        // Divisi Kepemudaan
        $this->createUser('Koordinator Remaja Test User', 'koordinator-remaja@demo.test', ['remaja_koordinator']);
        $this->createUser('Bendahara Tim Test User', 'bendahara-remaja@demo.test', ['remaja_bendahara_tim']);

        // Divisi Kaderisasi
        $this->createUser('Koordinator Pemuda Test User', 'koordinator-pemuda@demo.test', ['pemuda_koordinator']);
        $this->createUser('Anggota Test User', 'anggota-pemuda@demo.test', ['pemuda_anggota']);

        // Tim Multi Media
        $this->createUser('Koordinator Multimedia Test User', 'koordinator-tmm@demo.test', ['tmm_koordinator']);

        // Divisi Pendidikan
        $this->createUser('Koordinator Anak Test User', 'rina@demo.test', ['anak_koordinator']);
    }

    /** @param list<string> $roleKeys */
    private function createUser(string $name, string $email, array $roleKeys): void
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make('password123!'),
        ]);

        foreach ($roleKeys as $key) {
            if (isset($this->roles[$key])) {
                $user->roles()->attach($this->roles[$key]->id);
            }
        }
    }

    // =========================================================================
    // PP Permission Assignments (source: FeatureTestPpIntegration20260313Seeder)
    // =========================================================================

    private function assignBasePermissions(): void
    {
        $basePerms = [
            'personal.index', 'personal.files', 'personal.verify',
            'personal.notifications', 'personal.notifications.mark-all-read',
        ];

        // Admin roles need base permissions too (personal dashboard, etc.)
        $adminRoleKeys = [
            'monev_koordinator_monev', 'monev_evaluator_narasi', 'monev_evaluator_anggaran',
            'bu_bendahara_umum_1', 'bu_bendahara_umum_2', 'bu_asisten_bendahara_umum',
            'kg_staff_kantor_organisasi',
        ];

        foreach ($adminRoleKeys as $roleKey) {
            $this->syncPermissions($this->roles[$roleKey], $basePerms);
        }
    }

    private function assignPpPermissions(): void
    {
        $this->syncPermissions($this->roles['monev_koordinator_monev'], [
            'admin.workflows.pp.index', 'admin.workflows.pp.create', 'admin.workflows.pp.show',
            'admin.workflows.pp.pp01.show', 'admin.workflows.pp.pp01.draft', 'admin.workflows.pp.pp01.submit',
            'admin.workflows.pp.pp02.show', 'admin.workflows.pp.pp02.draft', 'admin.workflows.pp.pp02.submit',
            'admin.workflows.pp.pp03.show',
            'admin.workflows.pp.pp04.show', 'admin.workflows.pp.pp04.draft', 'admin.workflows.pp.pp04.submit',
            'admin.workflows.pp.pp05.show', 'admin.workflows.pp.pp05.approve', 'admin.workflows.pp.pp05.reject',
            'admin.workflows.pp.pp06.show', 'admin.workflows.pp.pp06.export.pdf', 'admin.workflows.pp.pp06.export.excel', 'admin.workflows.pp.pp06.export.zip',
            'admin.workflows.pp.pp07.create', 'admin.workflows.pp.pp07.show', 'admin.workflows.pp.pp07.draft', 'admin.workflows.pp.pp07.submit',
            'admin.workflows.pp.comment', 'admin.workflows.pp.terminate', 'admin.workflows.pp.destroy',
        ]);

        $evalPerms = [
            'admin.workflows.pp.index', 'admin.workflows.pp.create', 'admin.workflows.pp.show',
            'admin.workflows.pp.pp01.show', 'admin.workflows.pp.pp01.draft', 'admin.workflows.pp.pp01.submit',
            'admin.workflows.pp.pp02.show', 'admin.workflows.pp.pp02.draft', 'admin.workflows.pp.pp02.submit',
            'admin.workflows.pp.pp03.show',
            'admin.workflows.pp.pp04.show', 'admin.workflows.pp.pp04.draft', 'admin.workflows.pp.pp04.submit',
            'admin.workflows.pp.pp05.show',
            'admin.workflows.pp.pp06.show', 'admin.workflows.pp.pp06.export.pdf', 'admin.workflows.pp.pp06.export.excel', 'admin.workflows.pp.pp06.export.zip',
            'admin.workflows.pp.pp07.create', 'admin.workflows.pp.pp07.show', 'admin.workflows.pp.pp07.draft', 'admin.workflows.pp.pp07.submit',
            'admin.workflows.pp.comment',
        ];
        $this->syncPermissions($this->roles['monev_evaluator_narasi'], $evalPerms);
        $this->syncPermissions($this->roles['monev_evaluator_anggaran'], $evalPerms);

        $buPerms = [
            'admin.workflows.pp.index', 'admin.workflows.pp.show',
            'admin.workflows.pp.pp01.show', 'admin.workflows.pp.pp01.draft', 'admin.workflows.pp.pp01.submit',
            'admin.workflows.pp.pp02.show',
            'admin.workflows.pp.pp03.show', 'admin.workflows.pp.pp03.draft', 'admin.workflows.pp.pp03.submit',
            'admin.workflows.pp.pp04.show', 'admin.workflows.pp.pp04.draft', 'admin.workflows.pp.pp04.submit',
            'admin.workflows.pp.pp05.show',
            'admin.workflows.pp.pp06.show', 'admin.workflows.pp.pp06.export.pdf', 'admin.workflows.pp.pp06.export.excel', 'admin.workflows.pp.pp06.export.zip',
            'admin.workflows.pp.pp07.create', 'admin.workflows.pp.pp07.show', 'admin.workflows.pp.pp07.draft', 'admin.workflows.pp.pp07.submit',
            'admin.workflows.pp.comment',
        ];
        $this->syncPermissions($this->roles['bu_bendahara_umum_1'], $buPerms);
        $this->syncPermissions($this->roles['bu_bendahara_umum_2'], $buPerms);
        $this->syncPermissions($this->roles['bu_asisten_bendahara_umum'], $buPerms);
    }

    // =========================================================================
    // PK Permission Assignments (source: FeatureTestPkBrowser20260316Seeder)
    // =========================================================================

    private function assignPkPermissions(): void
    {
        // Admin scope
        $this->syncPermissions($this->roles['monev_koordinator_monev'], [
            'admin.workflows.pk.index', 'admin.workflows.pk.show',
            'admin.workflows.pk.pk01.show',
            'admin.workflows.pk.pk02a.show', 'admin.workflows.pk.pk02a.approve', 'admin.workflows.pk.pk02a.reject',
            'admin.workflows.pk.pk02b.show',
            'admin.workflows.pk.pk03.show',
            'admin.workflows.pk.pk04.show', 'admin.workflows.pk.pk04.export.pdf', 'admin.workflows.pk.pk04.export.excel', 'admin.workflows.pk.pk04.export.zip',
            'admin.workflows.pk.pk05.create', 'admin.workflows.pk.pk05.show', 'admin.workflows.pk.pk05.draft', 'admin.workflows.pk.pk05.submit',
            'admin.workflows.pk.comment', 'admin.workflows.pk.terminate', 'admin.workflows.pk.destroy',
        ]);

        $evalPkPerms = [
            'admin.workflows.pk.index', 'admin.workflows.pk.show',
            'admin.workflows.pk.pk01.show',
            'admin.workflows.pk.pk02a.show', 'admin.workflows.pk.pk02a.approve', 'admin.workflows.pk.pk02a.reject',
            'admin.workflows.pk.pk02b.show',
            'admin.workflows.pk.pk03.show',
            'admin.workflows.pk.pk04.show', 'admin.workflows.pk.pk04.export.pdf', 'admin.workflows.pk.pk04.export.excel', 'admin.workflows.pk.pk04.export.zip',
            'admin.workflows.pk.pk05.create', 'admin.workflows.pk.pk05.show', 'admin.workflows.pk.pk05.draft', 'admin.workflows.pk.pk05.submit',
            'admin.workflows.pk.comment',
        ];
        $this->syncPermissions($this->roles['monev_evaluator_narasi'], $evalPkPerms);
        $this->syncPermissions($this->roles['monev_evaluator_anggaran'], $evalPkPerms);

        $buPkPerms = [
            'admin.workflows.pk.index', 'admin.workflows.pk.show',
            'admin.workflows.pk.pk01.show',
            'admin.workflows.pk.pk02a.show',
            'admin.workflows.pk.pk02b.show', 'admin.workflows.pk.pk02b.approve', 'admin.workflows.pk.pk02b.reject',
            'admin.workflows.pk.pk03.show', 'admin.workflows.pk.pk03.approve', 'admin.workflows.pk.pk03.reject',
            'admin.workflows.pk.pk04.show', 'admin.workflows.pk.pk04.export.pdf', 'admin.workflows.pk.pk04.export.excel', 'admin.workflows.pk.pk04.export.zip',
            'admin.workflows.pk.pk05.create', 'admin.workflows.pk.pk05.show', 'admin.workflows.pk.pk05.draft', 'admin.workflows.pk.pk05.submit',
            'admin.workflows.pk.comment',
        ];
        $this->syncPermissions($this->roles['bu_bendahara_umum_1'], $buPkPerms);
        $this->syncPermissions($this->roles['bu_bendahara_umum_2'], $buPkPerms);
        $this->syncPermissions($this->roles['bu_asisten_bendahara_umum'], $buPkPerms);

        // Team scope — bapel teams
        $baseTeamPerms = [
            'personal.index', 'personal.files', 'personal.verify',
            'personal.notifications', 'personal.notifications.mark-all-read',
            'team.index', 'team.files.index',
        ];
        $pkTeamPerms = [
            'team.workflows.pk.index', 'team.workflows.pk.create', 'team.workflows.pk.show',
            'team.workflows.pk.pk01.show', 'team.workflows.pk.pk01.draft', 'team.workflows.pk.pk01.submit',
            'team.workflows.pk.pk04.show', 'team.workflows.pk.pk04.export.pdf', 'team.workflows.pk.pk04.export.excel', 'team.workflows.pk.pk04.export.zip',
            'team.workflows.pk.comment', 'team.workflows.pk.terminate',
        ];
        $allTeamPerms = [...$baseTeamPerms, ...$pkTeamPerms];
        $noTerminatePerms = array_values(array_filter($allTeamPerms, fn ($p) => $p !== 'team.workflows.pk.terminate'));

        foreach (['remaja', 'pemuda', 'tmm'] as $teamKey) {
            $roles = Role::where('team_id', $this->teams[$teamKey]->id)->get();
            foreach ($roles as $role) {
                $perms = $role->name === 'Koordinator' ? $allTeamPerms : $noTerminatePerms;
                $this->syncPermissions($role, $perms);
            }
        }
    }

    // =========================================================================
    // PABD Permission Assignments (source: FeatureTestPabdPrblIntegration20260320Seeder)
    // =========================================================================

    private function assignPabdPermissions(): void
    {
        // Monev: view-only + admin_reset (13 perms)
        $monevPabd = [
            'admin.workflows.pabd.index', 'admin.workflows.pabd.show',
            'admin.workflows.pabd.pabd01.show', 'admin.workflows.pabd.pabd02a.show', 'admin.workflows.pabd.pabd02b.show',
            'admin.workflows.pabd.pabd03.show', 'admin.workflows.pabd.pabd04.show', 'admin.workflows.pabd.pabd05.show',
            'admin.workflows.pabd.pabd05.export.pdf', 'admin.workflows.pabd.pabd05.export.excel', 'admin.workflows.pabd.pabd05.export.zip',
            'admin.workflows.pabd.comment',
            'admin.workflows.pabd.admin_reset',
            'admin.workflows.pabd.admin_create',
        ];
        $this->syncPermissions($this->roles['monev_koordinator_monev'], $monevPabd);
        $this->syncPermissions($this->roles['monev_evaluator_narasi'], $monevPabd);
        $this->syncPermissions($this->roles['monev_evaluator_anggaran'], $monevPabd);

        // BU: PABD02B + PABD03 action perms (17 perms)
        $buPabd = [
            'admin.workflows.pabd.index', 'admin.workflows.pabd.show',
            'admin.workflows.pabd.pabd01.show', 'admin.workflows.pabd.pabd02a.show',
            'admin.workflows.pabd.pabd02b.show', 'admin.workflows.pabd.pabd02b.draft', 'admin.workflows.pabd.pabd02b.approve', 'admin.workflows.pabd.pabd02b.reject',
            'admin.workflows.pabd.pabd03.show', 'admin.workflows.pabd.pabd03.approve', 'admin.workflows.pabd.pabd03.reject',
            'admin.workflows.pabd.pabd04.show', 'admin.workflows.pabd.pabd05.show',
            'admin.workflows.pabd.pabd05.export.pdf', 'admin.workflows.pabd.pabd05.export.excel', 'admin.workflows.pabd.pabd05.export.zip',
            'admin.workflows.pabd.comment',
        ];
        $this->syncPermissions($this->roles['bu_bendahara_umum_1'], $buPabd);
        $this->syncPermissions($this->roles['bu_bendahara_umum_2'], $buPabd);
        $this->syncPermissions($this->roles['bu_asisten_bendahara_umum'], $buPabd);

        // Staff KG: PABD04 draft/submit (14 perms)
        $this->syncPermissions($this->roles['kg_staff_kantor_organisasi'], [
            'admin.workflows.pabd.index', 'admin.workflows.pabd.show',
            'admin.workflows.pabd.pabd01.show', 'admin.workflows.pabd.pabd02a.show', 'admin.workflows.pabd.pabd02b.show',
            'admin.workflows.pabd.pabd03.show',
            'admin.workflows.pabd.pabd04.show', 'admin.workflows.pabd.pabd04.draft', 'admin.workflows.pabd.pabd04.submit',
            'admin.workflows.pabd.pabd05.show',
            'admin.workflows.pabd.pabd05.export.pdf', 'admin.workflows.pabd.pabd05.export.excel', 'admin.workflows.pabd.pabd05.export.zip',
            'admin.workflows.pabd.comment',
        ]);

        // Team scope: all bapel roles get 16 perms
        $teamPabd = [
            'team.workflows.pabd.index', 'team.workflows.pabd.show',
            'team.workflows.pabd.pabd01.show', 'team.workflows.pabd.pabd01.draft', 'team.workflows.pabd.pabd01.submit',
            'team.workflows.pabd.pabd02a.show', 'team.workflows.pabd.pabd02a.draft', 'team.workflows.pabd.pabd02a.submit',
            'team.workflows.pabd.pabd02b.show', 'team.workflows.pabd.pabd03.show', 'team.workflows.pabd.pabd04.show',
            'team.workflows.pabd.pabd05.show',
            'team.workflows.pabd.pabd05.export.pdf', 'team.workflows.pabd.pabd05.export.excel', 'team.workflows.pabd.pabd05.export.zip',
            'team.workflows.pabd.comment',
        ];

        foreach (['remaja', 'pemuda', 'tmm'] as $teamKey) {
            foreach (Role::where('team_id', $this->teams[$teamKey]->id)->get() as $role) {
                $this->syncPermissions($role, $teamPabd);
            }
        }
    }

    // =========================================================================
    // PRBL Permission Assignments (source: FeatureTestPabdPrblIntegration20260320Seeder)
    // =========================================================================

    private function assignPrblPermissions(): void
    {
        // Monev: PRBL02A approve/reject + admin_reset/create (16 perms)
        $monevPrbl = [
            'admin.workflows.prbl.index', 'admin.workflows.prbl.show',
            'admin.workflows.prbl.prbl01.show',
            'admin.workflows.prbl.prbl02a.show', 'admin.workflows.prbl.prbl02a.approve', 'admin.workflows.prbl.prbl02a.reject',
            'admin.workflows.prbl.prbl02b.show', 'admin.workflows.prbl.prbl03.show', 'admin.workflows.prbl.prbl04.show',
            'admin.workflows.prbl.prbl05.show',
            'admin.workflows.prbl.prbl05.export.pdf', 'admin.workflows.prbl.prbl05.export.excel', 'admin.workflows.prbl.prbl05.export.zip',
            'admin.workflows.prbl.comment',
            'admin.workflows.prbl.admin_reset',
            'admin.workflows.prbl.admin_create',
        ];
        $this->syncPermissions($this->roles['monev_koordinator_monev'], $monevPrbl);
        $this->syncPermissions($this->roles['monev_evaluator_narasi'], $monevPrbl);
        $this->syncPermissions($this->roles['monev_evaluator_anggaran'], $monevPrbl);

        // BU: PRBL02B + PRBL04 approve/reject (16 perms)
        $buPrbl = [
            'admin.workflows.prbl.index', 'admin.workflows.prbl.show',
            'admin.workflows.prbl.prbl01.show',
            'admin.workflows.prbl.prbl02a.show',
            'admin.workflows.prbl.prbl02b.show', 'admin.workflows.prbl.prbl02b.approve', 'admin.workflows.prbl.prbl02b.reject',
            'admin.workflows.prbl.prbl03.show',
            'admin.workflows.prbl.prbl04.show', 'admin.workflows.prbl.prbl04.approve', 'admin.workflows.prbl.prbl04.reject',
            'admin.workflows.prbl.prbl05.show',
            'admin.workflows.prbl.prbl05.export.pdf', 'admin.workflows.prbl.prbl05.export.excel', 'admin.workflows.prbl.prbl05.export.zip',
            'admin.workflows.prbl.comment',
        ];
        $this->syncPermissions($this->roles['bu_bendahara_umum_1'], $buPrbl);
        $this->syncPermissions($this->roles['bu_bendahara_umum_2'], $buPrbl);
        $this->syncPermissions($this->roles['bu_asisten_bendahara_umum'], $buPrbl);

        // Staff KG: view-only (12 perms)
        $this->syncPermissions($this->roles['kg_staff_kantor_organisasi'], [
            'admin.workflows.prbl.index', 'admin.workflows.prbl.show',
            'admin.workflows.prbl.prbl01.show', 'admin.workflows.prbl.prbl02a.show', 'admin.workflows.prbl.prbl02b.show',
            'admin.workflows.prbl.prbl03.show', 'admin.workflows.prbl.prbl04.show', 'admin.workflows.prbl.prbl05.show',
            'admin.workflows.prbl.prbl05.export.pdf', 'admin.workflows.prbl.prbl05.export.excel', 'admin.workflows.prbl.prbl05.export.zip',
            'admin.workflows.prbl.comment',
        ]);

        // Team scope: all bapel roles get 16 perms
        $teamPrbl = [
            'team.workflows.prbl.index', 'team.workflows.prbl.show',
            'team.workflows.prbl.prbl01.show', 'team.workflows.prbl.prbl01.draft', 'team.workflows.prbl.prbl01.submit',
            'team.workflows.prbl.prbl02a.show', 'team.workflows.prbl.prbl02b.show',
            'team.workflows.prbl.prbl03.show', 'team.workflows.prbl.prbl03.draft', 'team.workflows.prbl.prbl03.submit',
            'team.workflows.prbl.prbl04.show', 'team.workflows.prbl.prbl05.show',
            'team.workflows.prbl.prbl05.export.pdf', 'team.workflows.prbl.prbl05.export.excel', 'team.workflows.prbl.prbl05.export.zip',
            'team.workflows.prbl.comment',
        ];

        foreach (['remaja', 'pemuda', 'tmm'] as $teamKey) {
            foreach (Role::where('team_id', $this->teams[$teamKey]->id)->get() as $role) {
                $this->syncPermissions($role, $teamPrbl);
            }
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function giveAdminAllRoles(): void
    {
        $admin = User::where('email', 'admin@demo.test')->firstOrFail();
        $allRoleIds = Role::pluck('id')->toArray();
        $admin->roles()->syncWithoutDetaching($allRoleIds);
    }

    /** Additive permission sync — won't remove permissions from other workflow types. */
    private function syncPermissions(Role $role, array $permissionNames): void
    {
        $permissionIds = [];
        foreach ($permissionNames as $name) {
            $permissionIds[] = Permission::firstOrCreate(['name' => $name])->id;
        }
        $role->permissions()->syncWithoutDetaching($permissionIds);
    }
}
