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
 * Seeds the organisation, workspaces, teams, roles, users and permissions.
 *
 * Workflow data is not created here — that is DemoWorkflowSeeder, which runs
 * after this one and needs the teams and roles below to already exist.
 *
 * Password for every account: `password123!`
 */
class ManualTestingSeeder extends Seeder
{
    private const PASSWORD = 'password123!';

    /** The account the demo hands to a visitor. Holds every role in the system. */
    private const TEST_EMAIL = 'test@demo.test';

    private Organization $org;

    private Workspace $workspace;

    /** @var array<string, Team> */
    private array $teams = [];

    /** @var array<string, Role> */
    private array $roles = [];

    /**
     * Divisions that own workflows. The first three run the full chain through
     * to monthly reports; `hr`, `fin` and `lgl` are parked one at each PK
     * approval desk, so every step has live work waiting on it.
     *
     * @var list<string>
     */
    private const DIVISIONS = ['ops', 'mkt', 'it', 'hr', 'fin', 'lgl', 'pro'];

    public function run(): void
    {
        if (Organization::where('name', 'PT Nusantara Sejahtera')->exists()) {
            return;
        }

        $this->createOrgAndWorkspaces();
        $this->createTeamsAndRoles();
        $this->syncAllPermissions();
        $this->createUsers();
        $this->assignBasePermissions();
        $this->assignAdminCrudPermissions();
        $this->assignPpPermissions();
        $this->assignPkPermissions();
        $this->assignPabdPermissions();
        $this->assignPrblPermissions();
        $this->giveSuperAdminAllPermissions();
        $this->giveTestUserAllRoles();

        $this->command->info('Base data seeded: 2 workspaces, 10 teams, 14 users, all permissions assigned.');
    }

    // =========================================================================
    // Organization + Workspaces
    // =========================================================================

    /**
     * A workspace is a budget period, not a department — the same organisation
     * participates in several of them. Two exist so the workspace switcher has
     * something to switch between: 2026 carries the seeded data, 2027 is empty
     * and ready for a fresh PP to be created in it.
     */
    private function createOrgAndWorkspaces(): void
    {
        $this->org = Organization::create([
            'name' => 'PT Nusantara Sejahtera',
            'description' => 'Perusahaan distribusi dengan beberapa divisi yang mengelola anggaran masing-masing.',
        ]);

        $this->workspace = Workspace::create([
            'name' => 'Periode Anggaran 2026',
            'description' => 'Periode anggaran berjalan — memuat data contoh untuk seluruh alur kerja.',
        ]);

        $next = Workspace::create([
            'name' => 'Periode Anggaran 2027',
            'description' => 'Periode anggaran berikutnya — masih kosong, siap dimulai dari PP01.',
        ]);

        $this->org->workspaces()->attach([$this->workspace->id, $next->id]);
    }

    // =========================================================================
    // Teams + Roles
    // =========================================================================

    private function createTeamsAndRoles(): void
    {
        // Central teams — they review and approve what the divisions submit.
        $this->createTeam('monev', 'Tim Monitoring dan Evaluasi', 'Tim pengawasan program dan evaluasi laporan', [
            'Super Admin', 'Koordinator MONEV', 'Evaluator Narasi', 'Evaluator Anggaran',
        ]);

        $this->createTeam('bu', 'Tim Bendahara Pusat', 'Tim keuangan pusat — persetujuan transfer dan realisasi', [
            'Bendahara Umum 1', 'Asisten Bendahara Umum',
        ]);

        $this->createTeam('kg', 'Tim Kantor Pusat', 'Tim administrasi kantor pusat — bukti transfer', [
            'Staff Kantor Pusat',
        ]);

        // One role per division. Every team-side step is submitted by the same
        // desk, so a second role only added rows without adding a capability.
        $divisionRoles = ['Bendahara Tim'];

        $this->createTeam('ops', 'Divisi Operasional', 'Divisi operasional dan distribusi', $divisionRoles);
        $this->createTeam('mkt', 'Divisi Pemasaran', 'Divisi pemasaran dan pengembangan pasar', $divisionRoles);
        $this->createTeam('it', 'Divisi Teknologi Informasi', 'Divisi teknologi informasi dan infrastruktur', $divisionRoles);
        $this->createTeam('hr', 'Divisi Sumber Daya Manusia', 'Divisi sumber daya manusia dan pengembangan karyawan', $divisionRoles);
        $this->createTeam('fin', 'Divisi Keuangan', 'Divisi keuangan dan pengendalian internal', $divisionRoles);
        $this->createTeam('lgl', 'Divisi Legal dan Kepatuhan', 'Divisi legal, perizinan dan kepatuhan', $divisionRoles);
        $this->createTeam('pro', 'Divisi Pengadaan', 'Divisi pengadaan barang dan jasa', $divisionRoles);
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
            // Composite key: "teamKey_role_name" normalised.
            $roleKey = $key.'_'.str_replace(' ', '_', strtolower($roleName));
            $this->roles[$roleKey] = $role;
        }
    }

    // =========================================================================
    // Permissions
    // =========================================================================

    private function syncAllPermissions(): void
    {
        $this->call(PermissionSeeder::class);

        Artisan::call('permissions:sync');
    }

    // =========================================================================
    // Users
    // =========================================================================

    private function createUsers(): void
    {
        // The advertised demo account. Gets every role at the end of run().
        $this->createUser('Test User', self::TEST_EMAIL, []);

        // Single-role accounts, so the seeded history names a plausible actor
        // per step rather than attributing the whole chain to one person.
        $this->createUser('Super Admin', 'superadmin@demo.test', ['monev_super_admin']);
        $this->createUser('Koordinator Monitoring', 'koordinator-monev@demo.test', ['monev_koordinator_monev']);
        $this->createUser('Evaluator Narasi', 'evaluator-narasi@demo.test', ['monev_evaluator_narasi']);
        $this->createUser('Evaluator Anggaran', 'evaluator-anggaran@demo.test', ['monev_evaluator_anggaran']);

        $this->createUser('Bendahara Umum 1', 'bu1@demo.test', ['bu_bendahara_umum_1']);
        $this->createUser('Asisten Bendahara Umum', 'asisten-bu@demo.test', ['bu_asisten_bendahara_umum']);

        $this->createUser('Staff Kantor Pusat', 'staff-kp@demo.test', ['kg_staff_kantor_pusat']);

        $this->createUser('Bendahara Operasional', 'bendahara-ops@demo.test', ['ops_bendahara_tim']);
        $this->createUser('Bendahara Pemasaran', 'bendahara-mkt@demo.test', ['mkt_bendahara_tim']);
        $this->createUser('Bendahara Teknologi Informasi', 'bendahara-it@demo.test', ['it_bendahara_tim']);
        $this->createUser('Bendahara Sumber Daya Manusia', 'bendahara-hr@demo.test', ['hr_bendahara_tim']);
        $this->createUser('Bendahara Keuangan', 'bendahara-fin@demo.test', ['fin_bendahara_tim']);
        $this->createUser('Bendahara Legal dan Kepatuhan', 'bendahara-lgl@demo.test', ['lgl_bendahara_tim']);
        $this->createUser('Bendahara Pengadaan', 'bendahara-pro@demo.test', ['pro_bendahara_tim']);
    }

    /** @param list<string> $roleKeys */
    private function createUser(string $name, string $email, array $roleKeys): void
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make(self::PASSWORD),
        ]);

        foreach ($roleKeys as $key) {
            if (isset($this->roles[$key])) {
                $user->roles()->attach($this->roles[$key]->id);
            }
        }
    }

    // =========================================================================
    // PP Permission Assignments
    // =========================================================================

    private function assignBasePermissions(): void
    {
        $basePerms = [
            'personal.index', 'personal.files', 'personal.verify',
            'personal.notifications', 'personal.notifications.mark-all-read',
        ];

        $adminRoleKeys = [
            'monev_super_admin', 'monev_koordinator_monev', 'monev_evaluator_narasi', 'monev_evaluator_anggaran',
            'bu_bendahara_umum_1', 'bu_asisten_bendahara_umum',
            'kg_staff_kantor_pusat',
        ];

        foreach ($adminRoleKeys as $roleKey) {
            $this->syncPermissions($this->roles[$roleKey], $basePerms);
        }
    }

    // =========================================================================
    // Admin CRUD Permission Assignments (users, roles, teams, orgs, workspaces)
    // Koordinator MONEV: full CRUD
    // Evaluator Narasi + Anggaran: view only (index, edit page, trash list)
    // =========================================================================

    private function assignAdminCrudPermissions(): void
    {
        $entities = ['users', 'roles', 'teams', 'organizations', 'workspaces'];

        $fullCrud = ['admin.index', 'admin.notifications.index', 'admin.notifications.mark-all-read', 'admin.notifications.show'];
        $viewOnly = ['admin.index', 'admin.notifications.index', 'admin.notifications.mark-all-read', 'admin.notifications.show'];

        foreach ($entities as $entity) {
            $fullCrud = array_merge($fullCrud, [
                "admin.{$entity}.index",
                "admin.{$entity}.create",
                "admin.{$entity}.store",
                "admin.{$entity}.edit",
                "admin.{$entity}.update",
                "admin.{$entity}.destroy",
                "admin.{$entity}.trash",
                "admin.{$entity}.restore",
            ]);

            $viewOnly = array_merge($viewOnly, [
                "admin.{$entity}.index",
                "admin.{$entity}.edit",
                "admin.{$entity}.trash",
            ]);
        }

        // Files use different verbs (upload/destroy, no create/edit/update)
        $fullCrud = array_merge($fullCrud, [
            'admin.files.index',
            'admin.files.upload',
            'admin.files.destroy',
            'admin.files.trash',
            'admin.files.restore',
        ]);
        $viewOnly = array_merge($viewOnly, [
            'admin.files.index',
            'admin.files.trash',
        ]);

        $this->syncPermissions($this->roles['monev_koordinator_monev'], $fullCrud);
        $this->syncPermissions($this->roles['monev_evaluator_narasi'], $viewOnly);
        $this->syncPermissions($this->roles['monev_evaluator_anggaran'], $viewOnly);
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
        $this->syncPermissions($this->roles['bu_asisten_bendahara_umum'], $buPerms);
    }

    // =========================================================================
    // PK Permission Assignments
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
        $this->syncPermissions($this->roles['bu_asisten_bendahara_umum'], $buPkPerms);

        // Team scope — every division
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

        foreach (self::DIVISIONS as $teamKey) {
            $roles = Role::where('team_id', $this->teams[$teamKey]->id)->get();
            foreach ($roles as $role) {
                $this->syncPermissions($role, $allTeamPerms);
            }
        }
    }

    // =========================================================================
    // PABD Permission Assignments
    // =========================================================================

    private function assignPabdPermissions(): void
    {
        // Monev: view-only + admin_reset
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

        // Bendahara Pusat: PABD02B + PABD03 action perms
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
        $this->syncPermissions($this->roles['bu_asisten_bendahara_umum'], $buPabd);

        // Kantor Pusat: PABD04 draft/submit
        $this->syncPermissions($this->roles['kg_staff_kantor_pusat'], [
            'admin.workflows.pabd.index', 'admin.workflows.pabd.show',
            'admin.workflows.pabd.pabd01.show', 'admin.workflows.pabd.pabd02a.show', 'admin.workflows.pabd.pabd02b.show',
            'admin.workflows.pabd.pabd03.show',
            'admin.workflows.pabd.pabd04.show', 'admin.workflows.pabd.pabd04.draft', 'admin.workflows.pabd.pabd04.submit',
            'admin.workflows.pabd.pabd05.show',
            'admin.workflows.pabd.pabd05.export.pdf', 'admin.workflows.pabd.pabd05.export.excel', 'admin.workflows.pabd.pabd05.export.zip',
            'admin.workflows.pabd.comment',
        ]);

        // Team scope
        $teamPabd = [
            'team.workflows.pabd.index', 'team.workflows.pabd.show',
            'team.workflows.pabd.pabd01.show', 'team.workflows.pabd.pabd01.draft', 'team.workflows.pabd.pabd01.submit',
            'team.workflows.pabd.pabd02a.show', 'team.workflows.pabd.pabd02a.draft', 'team.workflows.pabd.pabd02a.submit',
            'team.workflows.pabd.pabd02b.show', 'team.workflows.pabd.pabd03.show', 'team.workflows.pabd.pabd04.show',
            'team.workflows.pabd.pabd05.show',
            'team.workflows.pabd.pabd05.export.pdf', 'team.workflows.pabd.pabd05.export.excel', 'team.workflows.pabd.pabd05.export.zip',
            'team.workflows.pabd.comment',
        ];

        foreach (self::DIVISIONS as $teamKey) {
            foreach (Role::where('team_id', $this->teams[$teamKey]->id)->get() as $role) {
                $this->syncPermissions($role, $teamPabd);
            }
        }
    }

    // =========================================================================
    // PRBL Permission Assignments
    // =========================================================================

    private function assignPrblPermissions(): void
    {
        // Monev: PRBL02A approve/reject + admin_reset/create
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

        // Bendahara Pusat: PRBL02B + PRBL04 approve/reject
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
        $this->syncPermissions($this->roles['bu_asisten_bendahara_umum'], $buPrbl);

        // Kantor Pusat: view-only
        $this->syncPermissions($this->roles['kg_staff_kantor_pusat'], [
            'admin.workflows.prbl.index', 'admin.workflows.prbl.show',
            'admin.workflows.prbl.prbl01.show', 'admin.workflows.prbl.prbl02a.show', 'admin.workflows.prbl.prbl02b.show',
            'admin.workflows.prbl.prbl03.show', 'admin.workflows.prbl.prbl04.show', 'admin.workflows.prbl.prbl05.show',
            'admin.workflows.prbl.prbl05.export.pdf', 'admin.workflows.prbl.prbl05.export.excel', 'admin.workflows.prbl.prbl05.export.zip',
            'admin.workflows.prbl.comment',
        ]);

        // Team scope
        $teamPrbl = [
            'team.workflows.prbl.index', 'team.workflows.prbl.show',
            'team.workflows.prbl.prbl01.show', 'team.workflows.prbl.prbl01.draft', 'team.workflows.prbl.prbl01.submit',
            'team.workflows.prbl.prbl02a.show', 'team.workflows.prbl.prbl02b.show',
            'team.workflows.prbl.prbl03.show', 'team.workflows.prbl.prbl03.draft', 'team.workflows.prbl.prbl03.submit',
            'team.workflows.prbl.prbl04.show', 'team.workflows.prbl.prbl05.show',
            'team.workflows.prbl.prbl05.export.pdf', 'team.workflows.prbl.prbl05.export.excel', 'team.workflows.prbl.prbl05.export.zip',
            'team.workflows.prbl.comment',
        ];

        foreach (self::DIVISIONS as $teamKey) {
            foreach (Role::where('team_id', $this->teams[$teamKey]->id)->get() as $role) {
                $this->syncPermissions($role, $teamPrbl);
            }
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Super Admin is defined as "every permission in the catalogue" rather than
     * a hardcoded list, so it cannot drift out of date as routes are added.
     */
    private function giveSuperAdminAllPermissions(): void
    {
        $this->roles['monev_super_admin']
            ->permissions()
            ->syncWithoutDetaching(Permission::pluck('id')->toArray());
    }

    /**
     * One account holds every role, so a visitor can walk the whole chain —
     * submit as a division, approve as the treasury, sign off as monitoring —
     * without logging out. The role switcher is how they change hats.
     */
    private function giveTestUserAllRoles(): void
    {
        $test = User::where('email', self::TEST_EMAIL)->firstOrFail();
        $test->roles()->syncWithoutDetaching(Role::pluck('id')->toArray());
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
