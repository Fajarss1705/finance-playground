<?php

namespace Database\Seeders;

use App\Models\Pabd\Pabd01Data;
use App\Models\Pabd\Pabd01ItemAnggaran;
use App\Models\Pabd\Pabd02aData;
use App\Models\Pabd\Pabd02aItemPerubahan;
use App\Models\Pabd\Pabd02bData;
use App\Models\Pabd\Pabd02bItemReview;
use App\Models\Pabd\Pabd04Data;
use App\Models\Pabd\Pabd05ItemAnggaran;
use App\Models\Pabd\Pabd05PengajuanBulanan;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Pk\Pk01Anggaran;
use App\Models\Pk\Pk01Data;
use App\Models\Pk\Pk01Kegiatan;
use App\Models\Pk\Pk01Kuisioner;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\Pk04Kegiatan;
use App\Models\Pk\PkWorkflow;
use App\Models\Pp\Pp01Data;
use App\Models\Pp\Pp01KodeBidangPelayanan;
use App\Models\Pp\Pp01KodeJenisProgram;
use App\Models\Pp\Pp01KodeKategoriPelayanan;
use App\Models\Pp\Pp01KodeSubBidangPelayanan;
use App\Models\Pp\Pp02Data;
use App\Models\Pp\Pp02ItemKuisioner;
use App\Models\Pp\Pp03Data;
use App\Models\Pp\Pp03ItemPlafonAnggaran;
use App\Models\Pp\Pp04Data;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\Pp06RekeningOrganisasi;
use App\Models\Pp\PpWorkflow;
use App\Models\Prbl\Prbl01Data;
use App\Models\Prbl\Prbl01ItemKegiatan;
use App\Models\Prbl\Prbl01ItemRealisasi;
use App\Models\Prbl\Prbl03Data;
use App\Models\Prbl\Prbl05ItemKegiatan;
use App\Models\Prbl\Prbl05ItemRealisasi;
use App\Models\Prbl\Prbl05LaporanBulanan;
use App\Models\Prbl\Prbl05RekeningOrganisasi;
use App\Models\Prbl\PrblWorkflow;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PkCompileService;
use App\Services\PpCompileService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds the demo's workflow data on top of ManualTestingSeeder.
 *
 * Every actionable step across PP, PK, PABD and PRBL has exactly one workflow
 * parked on it, so no two rows in any list are waiting on the same desk and no
 * step is unreachable. Completed workflows exist as well, to fill the archive
 * and give the PDF and Excel exports something to render.
 *
 * Idempotent — safe to re-run. Run directly: php artisan db:seed --class=DemoWorkflowSeeder
 */
class DemoWorkflowSeeder extends Seeder
{
    private Workspace $workspace;

    private int $orgId;

    /** @var array<string, Team> */
    private array $teams = [];

    /** @var array<string, Role> */
    private array $roles = [];

    /** @var array<string, User> */
    private array $users = [];

    private Pp06PeriodeTahunan $pp06;

    private int $ppWorkflowId;

    /** @var array<string, PkWorkflow> */
    private array $pkWorkflows = [];

    /** @var array<string, PabdWorkflow> keyed by "{team}.{bulan}" */
    private array $completedPabd = [];

    public function run(): void
    {
        if (PabdWorkflow::where('tahun_anggaran', 2026)->exists()) {
            $this->command->info('Dev workflow data already exists — skipping.');

            return;
        }

        $this->loadReferences();
        $this->ensureRekeningOrganisasi();
        $this->seedPkWorkflows();
        $this->seedPabdWorkflows();
        $this->seedPrblWorkflows();

        $this->command->info('Demo workflow data seeded: 1 PP, 7 PK, 11 PABD, 6 PRBL — one live workflow per actionable step.');
    }

    // =========================================================================
    // References
    // =========================================================================

    private function loadReferences(): void
    {
        $this->workspace = Workspace::where('name', 'Periode Anggaran 2026')->firstOrFail();
        $this->orgId = $this->workspace->organizations()->firstOrFail()->id;

        $this->teams = [
            'ops' => Team::where('name', 'Divisi Operasional')->firstOrFail(),
            'mkt' => Team::where('name', 'Divisi Pemasaran')->firstOrFail(),
            'it' => Team::where('name', 'Divisi Teknologi Informasi')->firstOrFail(),
            'hr' => Team::where('name', 'Divisi Sumber Daya Manusia')->firstOrFail(),
            'fin' => Team::where('name', 'Divisi Keuangan')->firstOrFail(),
            'lgl' => Team::where('name', 'Divisi Legal dan Kepatuhan')->firstOrFail(),
            'pro' => Team::where('name', 'Divisi Pengadaan')->firstOrFail(),
            'monev' => Team::where('name', 'Tim Monitoring dan Evaluasi')->firstOrFail(),
            'bu' => Team::where('name', 'Tim Bendahara Pusat')->firstOrFail(),
            'kg' => Team::where('name', 'Tim Kantor Pusat')->firstOrFail(),
        ];

        $this->roles = [
            'koordinator_monev' => Role::where('team_id', $this->teams['monev']->id)->where('name', 'Koordinator MONEV')->firstOrFail(),
            'eval_narasi' => Role::where('team_id', $this->teams['monev']->id)->where('name', 'Evaluator Narasi')->firstOrFail(),
            'bu1' => Role::where('team_id', $this->teams['bu']->id)->where('name', 'Bendahara Umum 1')->firstOrFail(),
            'staff_kp' => Role::where('team_id', $this->teams['kg']->id)->where('name', 'Staff Kantor Pusat')->firstOrFail(),
            'koordinator_ops' => Role::where('team_id', $this->teams['ops']->id)->where('name', 'Bendahara Tim')->firstOrFail(),
            'koordinator_mkt' => Role::where('team_id', $this->teams['mkt']->id)->where('name', 'Bendahara Tim')->firstOrFail(),
            'koordinator_it' => Role::where('team_id', $this->teams['it']->id)->where('name', 'Bendahara Tim')->firstOrFail(),
            'koordinator_hr' => Role::where('team_id', $this->teams['hr']->id)->where('name', 'Bendahara Tim')->firstOrFail(),
            'koordinator_fin' => Role::where('team_id', $this->teams['fin']->id)->where('name', 'Bendahara Tim')->firstOrFail(),
            'koordinator_lgl' => Role::where('team_id', $this->teams['lgl']->id)->where('name', 'Bendahara Tim')->firstOrFail(),
            'koordinator_pro' => Role::where('team_id', $this->teams['pro']->id)->where('name', 'Bendahara Tim')->firstOrFail(),
        ];

        $this->users = [
            'koordinator_ops' => User::where('email', 'bendahara-ops@demo.test')->firstOrFail(),
            'koordinator_mkt' => User::where('email', 'bendahara-mkt@demo.test')->firstOrFail(),
            'koordinator_it' => User::where('email', 'bendahara-it@demo.test')->firstOrFail(),
            'koordinator_hr' => User::where('email', 'bendahara-hr@demo.test')->firstOrFail(),
            'koordinator_fin' => User::where('email', 'bendahara-fin@demo.test')->firstOrFail(),
            'koordinator_lgl' => User::where('email', 'bendahara-lgl@demo.test')->firstOrFail(),
            'koordinator_pro' => User::where('email', 'bendahara-pro@demo.test')->firstOrFail(),
            'koordinator_monev' => User::where('email', 'koordinator-monev@demo.test')->firstOrFail(),
            'eval_narasi' => User::where('email', 'evaluator-narasi@demo.test')->firstOrFail(),
            'bu1' => User::where('email', 'bu1@demo.test')->firstOrFail(),
            'staff_kp' => User::where('email', 'staff-kp@demo.test')->firstOrFail(),
        ];

        // PP is the annual frame every other workflow anchors to, so it has to
        // exist before PK/PABD/PRBL can be built at all.
        $this->pp06 = Pp06PeriodeTahunan::where('tahun', 2026)->first()
            ?? $this->createCompletedPpWorkflow(2026);
        $this->ppWorkflowId = $this->pp06->pp_workflow_id;
    }

    private function ensureRekeningOrganisasi(): void
    {
        Pp06RekeningOrganisasi::firstOrCreate(
            ['pp06_periode_tahunan_id' => $this->pp06->id],
            [
                'nama_bank' => 'Bank Central Asia',
                'nama_rekening' => 'PT Nusantara Sejahtera',
                'nomor_rekening' => '0123456789',
            ],
        );
    }

    // =========================================================================
    // PK Workflows — one per team with PK04 compiled via PkCompileService
    // =========================================================================

    private function seedPkWorkflows(): void
    {
        $programs = [
            'ops' => [
                'nama' => 'Peningkatan Efisiensi Operasional',
                'deskripsi' => 'Program perbaikan proses dan pengendalian biaya operasional harian.',
                'tujuan' => 'Menurunkan biaya operasional per unit tanpa menurunkan mutu layanan.',
                'kegiatan' => [
                    ['Audit Proses Gudang', 1, [['Jasa konsultan audit', 3500000, 'BP01', 'SB01', 'JP01'], ['Sewa alat ukur inventaris', 5000000, 'BP01', 'SB01', 'JP02']]],
                    ['Pelatihan Supervisor Lapangan', 2, [['Honor instruktur', 2000000, 'BP02', 'SB02', 'JP02'], ['Modul dan materi pelatihan', 1500000, 'BP02', 'SB02', 'JP01']]],
                    ['Rapat Koordinasi Bulanan', 3, [['Konsumsi rapat', 500000, 'BP01', 'SB01', 'JP01'], ['Perlengkapan rapat', 300000, 'BP01', 'SB01', 'JP01']]],
                    ['Perawatan Armada Distribusi', 4, [['Suku cadang kendaraan', 4000000, 'BP03', 'SB03', 'JP02'], ['Bahan bakar dan tol', 1500000, 'BP03', 'SB03', 'JP01']]],
                ],
            ],
            'mkt' => [
                'nama' => 'Ekspansi Pasar Regional',
                'deskripsi' => 'Program akuisisi pelanggan baru di wilayah Jawa Timur.',
                'tujuan' => 'Menambah 500 pelanggan aktif dan menaikkan pangsa pasar regional.',
                'kegiatan' => [
                    ['Kampanye Digital Bulanan', 1, [['Belanja iklan digital', 1000000, 'BP01', 'SB01', 'JP01'], ['Produksi materi visual', 500000, 'BP01', 'SB01', 'JP01']]],
                    ['Pameran Dagang Tahunan', 2, [['Sewa booth pameran', 6000000, 'BP01', 'SB02', 'JP02'], ['Konsumsi tim pameran', 3000000, 'BP01', 'SB02', 'JP01'], ['Honor pembicara sesi produk', 2000000, 'BP02', 'SB02', 'JP02']]],
                    ['Riset Kepuasan Pelanggan', 3, [['Insentif responden', 1200000, 'BP02', 'SB03', 'JP01'], ['Konsumsi sesi wawancara', 600000, 'BP02', 'SB03', 'JP01']]],
                    ['Program Loyalitas Mitra', 4, [['Perlengkapan merchandise', 2500000, 'BP03', 'SB03', 'JP02']]],
                ],
            ],
            'it' => [
                'nama' => 'Modernisasi Infrastruktur TI',
                'deskripsi' => 'Program pemeliharaan dan peremajaan perangkat serta jaringan kantor.',
                'tujuan' => 'Menekan waktu henti sistem dan memperbarui perangkat yang habis masa pakai.',
                'kegiatan' => [
                    ['Pemeliharaan Perangkat Kantor', 1, [['Servis perangkat kerja', 1500000, 'BP01', 'SB01', 'JP01'], ['Kabel dan aksesori jaringan', 800000, 'BP01', 'SB01', 'JP01']]],
                    ['Peremajaan Perangkat Jaringan', 2, [['Pengadaan switch jaringan', 5000000, 'BP01', 'SB01', 'JP02'], ['Pengadaan firewall', 3000000, 'BP01', 'SB01', 'JP02']]],
                    ['Pelatihan Helpdesk Internal', 3, [['Honor pelatih', 1500000, 'BP02', 'SB02', 'JP02'], ['Konsumsi pelatihan', 500000, 'BP02', 'SB02', 'JP01']]],
                ],
            ],
        ];

        foreach ($programs as $teamKey => $config) {
            $this->pkWorkflows[$teamKey] = $this->createPkWorkflow($teamKey, $config);
        }

        $this->seedInFlightPkWorkflows();
    }

    /**
     * The three programs above run all the way to a compiled PK04, which leaves
     * PK02A, PK02B and PK03 with nothing sitting on them. These three park one
     * division at each approval desk: `hr` already has its budget approval so
     * only narasi is outstanding, `fin` is the mirror, and `lgl` has both and
     * waits on the final sign-off.
     */
    private function seedInFlightPkWorkflows(): void
    {
        $inFlight = [
            'hr' => [['PK02B'], [
                'nama' => 'Pengembangan Kapasitas Karyawan',
                'deskripsi' => 'Program pelatihan dan sertifikasi karyawan lintas divisi.',
                'tujuan' => 'Menaikkan kompetensi teknis dan menurunkan tingkat pergantian karyawan.',
                'kegiatan' => [
                    ['Sertifikasi Teknis', 2, [['Biaya ujian sertifikasi', 4500000, 'BP02', 'SB02', 'JP02'], ['Materi persiapan ujian', 900000, 'BP02', 'SB02', 'JP01']]],
                    ['Survei Keterlibatan Karyawan', 5, [['Lisensi platform survei', 1200000, 'BP02', 'SB01', 'JP01']]],
                ],
            ]],
            'fin' => [['PK02A'], [
                'nama' => 'Penguatan Pengendalian Internal',
                'deskripsi' => 'Program penyusunan prosedur dan pengawasan kas serta pelaporan.',
                'tujuan' => 'Menutup temuan audit dan mempercepat tutup buku bulanan.',
                'kegiatan' => [
                    ['Penyusunan SOP Keuangan', 1, [['Jasa penyusun SOP', 6000000, 'BP01', 'SB02', 'JP02']]],
                    ['Audit Internal Semester', 6, [['Honor auditor internal', 3500000, 'BP01', 'SB02', 'JP02'], ['Perjalanan dinas audit', 2000000, 'BP03', 'SB03', 'JP01']]],
                ],
            ]],
            'lgl' => [['PK02A', 'PK02B'], [
                'nama' => 'Kepatuhan dan Perizinan',
                'deskripsi' => 'Program pembaruan perizinan usaha dan tinjauan kontrak mitra.',
                'tujuan' => 'Menjaga seluruh izin operasional tetap berlaku sepanjang periode.',
                'kegiatan' => [
                    ['Perpanjangan Izin Usaha', 3, [['Biaya perizinan', 7500000, 'BP01', 'SB01', 'JP01']]],
                    ['Tinjauan Kontrak Mitra', 4, [['Jasa konsultan hukum', 5000000, 'BP01', 'SB02', 'JP02']]],
                ],
            ]],
        ];

        foreach ($inFlight as $teamKey => [$approved, $config]) {
            $this->createPkWorkflow($teamKey, $config, $approved, compile: false);
        }

        // PK01 itself is only live while the plan is still a draft, so this one
        // is never submitted.
        $this->createPkWorkflow('pro', [
            'nama' => 'Konsolidasi Pengadaan Rutin',
            'deskripsi' => 'Program penyatuan pembelian rutin lintas divisi ke satu siklus tender.',
            'tujuan' => 'Menurunkan harga satuan melalui volume dan memperpendek waktu pengadaan.',
            'kegiatan' => [
                ['Tender Alat Tulis Tahunan', 2, [['Biaya proses tender', 2200000, 'BP03', 'SB02', 'JP01']]],
                ['Evaluasi Vendor', 5, [['Jasa penilaian vendor', 3100000, 'BP03', 'SB03', 'JP02']]],
            ],
        ], approved: [], compile: false, submitted: false);
    }

    /**
     * @param  list<string>  $approved  Which approvals are already recorded.
     *                                  PK02A and PK02B are parallel — both open
     *                                  as soon as PK01 is submitted — so parking
     *                                  a workflow on exactly one of them means
     *                                  naming the other as already done.
     * @param  bool  $compile  Drive PK03 and compile PK04. False leaves the
     *                         workflow in flight.
     * @param  bool  $submitted  False stops at a PK01 draft, which is the only
     *                           state in which PK01 itself is the live step.
     */
    private function createPkWorkflow(string $teamKey, array $config, array $approved = ['PK02A', 'PK02B'], bool $compile = true, bool $submitted = true): PkWorkflow
    {
        $team = $this->teams[$teamKey];
        $user = $this->users["koordinator_{$teamKey}"];
        $role = $this->roles["koordinator_{$teamKey}"];
        $tCtx = $this->teamCtx($teamKey);
        $mCtx = $this->adminCtx('koordinator_monev');
        $bCtx = $this->adminCtx('bu1');

        $pk = PkWorkflow::create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'team_id' => $team->id,
            'pp_workflow_id' => $this->ppWorkflowId,
            'tipe' => 'raker',
            'created_by_user_id' => $user->id,
            'created_by_role_id' => $role->id,
            'created_by_team_id' => $team->id,
            'created_by_org_id' => $this->orgId,
            'history' => [],
        ]);

        $pk01 = Pk01Data::create([
            'pk_workflow_id' => $pk->id,
            'kode_kategori' => 'KP01',
            'nama_program' => $config['nama'],
            'deskripsi_program' => $config['deskripsi'],
            'tujuan_program' => $config['tujuan'],
        ]);

        foreach ($config['kegiatan'] as [$nama, $bulan, $anggaranList]) {
            $k = Pk01Kegiatan::create([
                'pk01_data_id' => $pk01->id,
                'nama_kegiatan' => $nama,
                'bulan' => $bulan,
            ]);

            foreach ($anggaranList as [$mata, $nominal, $bidang, $sub, $jenis]) {
                Pk01Anggaran::create([
                    'pk01_kegiatan_id' => $k->id,
                    'kode_bidang' => $bidang,
                    'kode_sub_bidang' => $sub,
                    'kode_jenis' => $jenis,
                    'mata_anggaran' => $mata,
                    'deskripsi_pk' => $mata,
                    'nominal_anggaran' => $nominal,
                ]);
            }

            Pk01Kuisioner::create([
                'pk01_kegiatan_id' => $k->id,
                'kode_kuisioner' => 'Q01',
                'pertanyaan' => 'Jumlah peserta kegiatan',
                'tipe' => 'Kuantitatif',
                'satuan' => 'orang',
            ]);
        }

        $steps = [
            'PK01' => [
                $this->entry('PK01', 'created', null, $tCtx, 'pk01_data', $pk01->id, offset: '-60 days'),
                $this->entry('PK01', 'submitted', $user->id, $tCtx, 'pk01_data', $pk01->id, offset: '-59 days'),
            ],
            'PK02A' => [
                $this->entry('PK02A', 'approved', $this->users['koordinator_monev']->id, $mCtx, offset: '-55 days', notes: 'Narasi program disetujui'),
            ],
            'PK02B' => [
                $this->entry('PK02B', 'approved', $this->users['bu1']->id, $bCtx, offset: '-52 days', notes: 'Anggaran program disetujui'),
            ],
            'PK03' => [
                $this->entry('PK03', 'approved', $this->users['bu1']->id, $bCtx, offset: '-50 days', notes: 'Program disahkan'),
            ],
        ];

        $history = $submitted
            ? $steps['PK01']
            : [$steps['PK01'][0]];

        if (! $submitted) {
            $pk->update(['history' => $history]);

            return $pk;
        }

        foreach (['PK02A', 'PK02B'] as $step) {
            if (in_array($step, $approved, true)) {
                $history = [...$history, ...$steps[$step]];
            }
        }

        if ($compile) {
            $history = [...$history, ...$steps['PK03']];
        }

        $pk->update(['history' => $history]);

        if (! $compile) {
            return $pk;
        }

        $pk04 = app(PkCompileService::class)->compile($pk);

        $history[] = $this->entry('PK04', 'completed', null, $tCtx, 'pk04_program_tahunan', $pk04->id, offset: '-50 days');
        $pk->update(['history' => $history]);

        return $pk;
    }

    // =========================================================================
    // PABD Workflows — 6 completed (PRBL parents) + 5 in-progress at various steps
    // =========================================================================

    private function seedPabdWorkflows(): void
    {
        // Completed — these exist to parent the PRBLs and to give the archive
        // something to show. None of them is waiting on anybody.
        $this->completedPabd['ops.1'] = $this->buildPabd('ops', 1, stopAt: 'PABD05');
        $this->completedPabd['ops.2'] = $this->buildPabd('ops', 2, stopAt: 'PABD05');
        $this->completedPabd['ops.3'] = $this->buildPabd('ops', 3, stopAt: 'PABD05');
        $this->completedPabd['mkt.1'] = $this->buildPabd('mkt', 1, stopAt: 'PABD05');
        $this->completedPabd['mkt.2'] = $this->buildPabd('mkt', 2, stopAt: 'PABD05');
        $this->completedPabd['it.1'] = $this->buildPabd('it', 1, stopAt: 'PABD05');

        // In progress — exactly one workflow parked at each actionable step, so
        // no two rows in the list are waiting on the same desk. Only teams with
        // a compiled PK04 can host these; the parked-PK divisions have no
        // budget lines to disburse.
        $this->buildPabd('ops', 4, stopAt: 'CREATED');                      // PABD01 live
        $this->buildPabd('mkt', 3, stopAt: 'PABD02A', adaPerubahan: true);  // PABD02A live
        $this->buildPabd('mkt', 4, stopAt: 'PABD02B', adaPerubahan: true);  // PABD02B live
        $this->buildPabd('it', 2, stopAt: 'PABD03');                        // PABD03 live
        $this->buildPabd('it', 3, stopAt: 'PABD04');                        // PABD04 live
    }

    /**
     * Build a PABD workflow up to the specified step.
     *
     * @param  string  $stopAt  PABD01|PABD02B|PABD03|PABD04|PABD05
     * @param  string|null  $rejectedFrom  Adds rejection cycle-back from this step
     */
    private function buildPabd(
        string $teamKey,
        int $bulan,
        string $stopAt,
        bool $adaPerubahan = false,
        ?string $rejectedFrom = null,
    ): PabdWorkflow {
        $team = $this->teams[$teamKey];
        $user = $this->users["koordinator_{$teamKey}"];
        $tCtx = $this->teamCtx($teamKey);
        $bCtx = $this->adminCtx('bu1');
        $kCtx = $this->adminCtx('staff_kp');
        $d = 90 - ($bulan * 8); // base "days ago"

        $workflow = PabdWorkflow::create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'team_id' => $team->id,
            'pp_workflow_id' => $this->ppWorkflowId,
            'bulan_anggaran' => $bulan,
            'tahun_anggaran' => 2026,
            'created_by_user_id' => $user->id,
            'created_by_role_id' => $this->roles["koordinator_{$teamKey}"]->id,
            'created_by_team_id' => $team->id,
            'created_by_org_id' => $this->orgId,
            'history' => [],
        ]);

        $anggaranItems = $this->anggaranForMonth($teamKey, $bulan);
        $history = [];

        // --- PABD01: checklist ---
        $pabd01 = Pabd01Data::create([
            'pabd_workflow_id' => $workflow->id,
            'ada_perubahan' => $adaPerubahan,
            'pk04_revisions_snapshot' => [],
        ]);

        foreach ($anggaranItems as $a) {
            Pabd01ItemAnggaran::create([
                'pabd01_data_id' => $pabd01->id,
                'pk04_anggaran_id' => $a->id,
                'dicairkan' => true,
            ]);
        }

        $history[] = $this->entry('PABD01', 'created', null, $tCtx, 'pabd01_data', $pabd01->id, offset: "-{$d} days");

        // 'CREATED' leaves PABD01 itself as the live step. 'PABD01' submits it,
        // which hands the workflow to the next desk instead.
        if ($stopAt === 'CREATED') {
            $workflow->update(['history' => $history]);

            return $workflow;
        }

        $history[] = $this->entry('PABD01', 'submitted', $user->id, $tCtx, 'pabd01_data', $pabd01->id, offset: '-'.($d - 1).' days');

        // --- Rejection cycle-back from PABD02B ---
        if ($rejectedFrom === 'PABD02B') {
            $pabd02a = Pabd02aData::create(['pabd_workflow_id' => $workflow->id]);
            $nextAnggaran = $this->anggaranForMonth($teamKey, $bulan + 1);

            if ($nextAnggaran->isNotEmpty()) {
                Pabd02aItemPerubahan::create([
                    'pabd02a_data_id' => $pabd02a->id,
                    'tipe_perubahan' => 'tarik_maju',
                    'pk04_anggaran_id' => $nextAnggaran->first()->id,
                    'bulan_awal' => $bulan + 1,
                    'bulan_tujuan' => $bulan,
                    'komentar' => 'Perlu percepatan kegiatan.',
                ]);
            }

            $history[] = $this->entry('PABD02A', 'submitted', $user->id, $tCtx, 'pabd02a_data', $pabd02a->id, offset: '-'.($d - 2).' days');
            $history[] = $this->entry('PABD02B', 'rejected', $this->users['bu1']->id, $bCtx, offset: '-'.($d - 4).' days', notes: 'Perubahan anggaran tidak sesuai kebijakan. Silakan revisi.');
            $history[] = $this->entry('PABD01', 'submitted', $user->id, $tCtx, 'pabd01_data', $pabd01->id, offset: '-'.($d - 5).' days');

            $workflow->update(['history' => $history]);

            return $workflow;
        }

        if ($stopAt === 'PABD01') {
            $workflow->update(['history' => $history]);

            return $workflow;
        }

        // --- PABD02A/02B (if ada_perubahan) ---
        if (! $adaPerubahan) {
            // PabdWorkflowController writes an explicit `skipped` entry for both
            // conditional steps when nothing changed, and WorkflowEngine reads
            // only that to mark them skipped. Omitting it left PABD02A showing
            // as active on workflows that had already completed.
            $history[] = $this->entry('PABD02A', 'skipped', null, $tCtx, offset: '-'.($d - 2).' days');
            $history[] = $this->entry('PABD02B', 'skipped', null, $tCtx, offset: '-'.($d - 2).' days');
        }

        if ($adaPerubahan) {
            $pabd02a = Pabd02aData::create(['pabd_workflow_id' => $workflow->id]);
            $nextAnggaran = $this->anggaranForMonth($teamKey, $bulan + 1);

            if ($nextAnggaran->isNotEmpty()) {
                $perubahan = Pabd02aItemPerubahan::create([
                    'pabd02a_data_id' => $pabd02a->id,
                    'tipe_perubahan' => 'tarik_maju',
                    'pk04_anggaran_id' => $nextAnggaran->first()->id,
                    'bulan_awal' => $bulan + 1,
                    'bulan_tujuan' => $bulan,
                    'komentar' => 'Kegiatan perlu dipercepat sesuai jadwal operasional.',
                ]);
            }

            $history[] = $this->entry('PABD02A', 'created', null, $tCtx, 'pabd02a_data', $pabd02a->id, offset: '-'.($d - 1).' days');

            if ($stopAt === 'PABD02A') {
                $workflow->update(['history' => $history]);

                return $workflow;
            }

            $history[] = $this->entry('PABD02A', 'submitted', $user->id, $tCtx, 'pabd02a_data', $pabd02a->id, offset: '-'.($d - 2).' days');

            // The row has to exist before the stop check: the PABD02B route
            // binds {pabd02bData}, so parking here without it 404s the step the
            // stepper is pointing at.
            $pabd02b = Pabd02bData::create(['pabd_workflow_id' => $workflow->id]);

            if (isset($perubahan)) {
                Pabd02bItemReview::create([
                    'pabd02b_data_id' => $pabd02b->id,
                    'pabd02a_item_perubahan_id' => $perubahan->id,
                    'komentar_approval' => 'Perubahan disetujui.',
                ]);
            }

            $history[] = $this->entry('PABD02B', 'created', null, $bCtx, 'pabd02b_data', $pabd02b->id, offset: '-'.($d - 3).' days');

            if ($stopAt === 'PABD02B') {
                $workflow->update(['history' => $history]);

                return $workflow;
            }

            // PABD02B: BU approves changes
            $history[] = $this->entry('PABD02B', 'approved', $this->users['bu1']->id, $bCtx, offset: '-'.($d - 4).' days', notes: 'Perubahan anggaran disetujui');

            // PABD02B approve cycles back to PABD01 — second PABD01 submission (no changes this time)
            $history[] = $this->entry('PABD01', 'submitted', $user->id, $tCtx, 'pabd01_data', $pabd01->id, offset: '-'.($d - 4).' days +6 hours');
        }

        // --- PABD03: BU transfer approval ---
        if ($stopAt === 'PABD03') {
            $workflow->update(['history' => $history]);

            return $workflow;
        }

        $history[] = $this->entry('PABD03', 'approved', $this->users['bu1']->id, $bCtx, offset: '-'.($d - 5).' days', notes: 'Transfer anggaran disetujui');

        // --- PABD04: bukti transfer ---
        $pabd04 = Pabd04Data::create(['pabd_workflow_id' => $workflow->id]);
        $history[] = $this->entry('PABD04', 'created', null, $kCtx, 'pabd04_data', $pabd04->id, offset: '-'.($d - 5).' days');

        if ($stopAt === 'PABD04') {
            $workflow->update(['history' => $history]);

            return $workflow;
        }

        $history[] = $this->entry('PABD04', 'submitted', $this->users['staff_kp']->id, $kCtx, 'pabd04_data', $pabd04->id, offset: '-'.($d - 6).' days');

        // --- PABD05: compile ---
        $this->compilePabd05($workflow, $anggaranItems, $teamKey, $d, $history);

        return $workflow;
    }

    private function compilePabd05(
        PabdWorkflow $workflow,
        Collection $anggaranItems,
        string $teamKey,
        int $d,
        array &$history,
    ): void {
        $team = $this->teams[$teamKey];
        $user = $this->users["koordinator_{$teamKey}"];
        $role = $this->roles["koordinator_{$teamKey}"];
        $tCtx = $this->teamCtx($teamKey);
        $rekening = Pp06RekeningOrganisasi::where('pp06_periode_tahunan_id', $this->pp06->id)->first();
        $orgName = $this->workspace->organizations()->firstOrFail()->name;
        $wsName = $this->workspace->name;

        $pabd05 = Pabd05PengajuanBulanan::create([
            'pabd_workflow_id' => $workflow->id,
            'verification_code' => strtoupper(Str::random(8)),
            'pabd01_created_by_user_name' => $user->name,
            'pabd01_created_by_role_name' => $role->name,
            'pabd01_created_by_team_name' => $team->name,
            'pabd01_created_by_organization_name' => $orgName,
            'pabd01_created_by_workspace_name' => $wsName,
            'pabd01_created_at' => now()->modify("-{$d} days"),
            'pabd03_approved_by_user_name' => $this->users['bu1']->name,
            'pabd03_approved_by_role_name' => $this->roles['bu1']->name,
            'pabd03_approved_by_team_name' => $this->teams['bu']->name,
            'pabd03_approved_by_organization_name' => $orgName,
            'pabd03_approved_by_workspace_name' => $wsName,
            'pabd03_approved_at' => now()->modify('-'.($d - 5).' days'),
            'pabd04_created_by_user_name' => $this->users['staff_kp']->name,
            'pabd04_created_by_role_name' => $this->roles['staff_kp']->name,
            'pabd04_created_by_team_name' => $this->teams['kg']->name,
            'pabd04_created_by_organization_name' => $orgName,
            'pabd04_created_by_workspace_name' => $wsName,
            'pabd04_created_at' => now()->modify('-'.($d - 6).' days'),
            'nama_bank' => $rekening?->nama_bank ?? 'Bank Central Asia',
            'nama_rekening' => $rekening?->nama_rekening ?? 'PT Nusantara Sejahtera',
            'nomor_rekening' => $rekening?->nomor_rekening ?? '0123456789',
            'total_anggaran_dicairkan' => $anggaranItems->sum('nominal_anggaran'),
            'total_item_dicairkan' => $anggaranItems->count(),
            'total_item_hangus' => 0,
        ]);

        foreach ($anggaranItems as $a) {
            Pabd05ItemAnggaran::create([
                'pabd05_pengajuan_bulanan_id' => $pabd05->id,
                'pk04_anggaran_id' => $a->id,
                'status' => 'dicairkan',
                'nominal_anggaran' => $a->nominal_anggaran,
            ]);

            $a->update([
                'status_pencairan' => 'dicairkan',
                'tanggal_pencairan' => now()->modify('-'.($d - 6).' days')->toDateString(),
                'pencairan_pabd_workflow_id' => $workflow->id,
            ]);
        }

        $history[] = $this->entry('PABD05', 'completed', null, $tCtx, 'pabd05_pengajuan_bulanan', $pabd05->id, offset: '-'.($d - 6).' days');
        $workflow->update(['history' => $history]);
    }

    // =========================================================================
    // PRBL Workflows — 6 workflows at various steps including dual rejection
    // =========================================================================

    private function seedPrblWorkflows(): void
    {
        // One workflow parked at each actionable step. The parallel fork is two
        // separate desks, so it gets two workflows — one waiting on narasi, one
        // waiting on anggaran — rather than one waiting on both.

        // PRBL01 live — drafted, not yet submitted by the division
        $this->buildPrbl('ops', 1, $this->completedPabd['ops.1'], stopAt: 'CREATED');

        // PRBL02A live — anggaran already approved, narasi outstanding
        $this->buildPrbl('mkt', 1, $this->completedPabd['mkt.1'], stopAt: 'PRBL02', prbl02aApproved: false, prbl02bApproved: true);

        // PRBL02B live — narasi already approved, anggaran outstanding
        $this->buildPrbl('ops', 2, $this->completedPabd['ops.2'], stopAt: 'PRBL02', prbl02aApproved: true, prbl02bApproved: false);

        // PRBL03 live — both approvals in, refund form waiting on the division
        $this->buildPrbl('it', 1, $this->completedPabd['it.1'], stopAt: 'PRBL02', prbl02aApproved: true, prbl02bApproved: true);

        // PRBL04 live — refund submitted, final sign-off outstanding
        $this->buildPrbl('ops', 3, $this->completedPabd['ops.3'], stopAt: 'PRBL03');

        // Completed, for the archive and the exports
        $this->buildPrbl('mkt', 2, $this->completedPabd['mkt.2'], stopAt: 'PRBL05');
    }

    /**
     * Build a PRBL workflow up to the specified step.
     *
     * @param  string  $stopAt  PRBL01|PRBL02|PRBL03|PRBL04|PRBL05
     */
    private function buildPrbl(
        string $teamKey,
        int $bulan,
        PabdWorkflow $pabd,
        string $stopAt,
        bool $prbl02aApproved = true,
        bool $prbl02bApproved = true,
    ): PrblWorkflow {
        $team = $this->teams[$teamKey];
        $user = $this->users["koordinator_{$teamKey}"];
        $tCtx = $this->teamCtx($teamKey);
        $mCtx = $this->adminCtx('eval_narasi');
        $bCtx = $this->adminCtx('bu1');
        $d = 80 - ($bulan * 8);

        $workflow = PrblWorkflow::create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'team_id' => $team->id,
            'pabd_workflow_id' => $pabd->id,
            'pp_workflow_id' => $this->ppWorkflowId,
            'bulan_laporan' => $bulan,
            'tahun_laporan' => 2026,
            'created_by_user_id' => $user->id,
            'created_by_role_id' => $this->roles["koordinator_{$teamKey}"]->id,
            'created_by_team_id' => $team->id,
            'created_by_org_id' => $this->orgId,
            'history' => [],
        ]);

        $anggaranItems = $this->anggaranForMonth($teamKey, $bulan);
        $kegiatanItems = $this->kegiatanForMonth($teamKey, $bulan);
        $history = [];

        // --- PRBL01: laporan + realisasi ---
        $prbl01 = Prbl01Data::create(['prbl_workflow_id' => $workflow->id]);

        foreach ($kegiatanItems as $k) {
            Prbl01ItemKegiatan::create([
                'prbl01_data_id' => $prbl01->id,
                'pk04_kegiatan_id' => $k->id,
                'masalah' => 'Tidak ada masalah signifikan.',
                'langkah_penanganan' => 'Kegiatan berjalan sesuai rencana.',
                'harapan' => 'Kegiatan bulan depan dapat lebih baik.',
                'catatan_tim' => 'Partisipasi peserta cukup baik.',
            ]);
        }

        foreach ($anggaranItems as $a) {
            Prbl01ItemRealisasi::create([
                'prbl01_data_id' => $prbl01->id,
                'pk04_anggaran_id' => $a->id,
                'nominal_realisasi' => round($a->nominal_anggaran * 0.92, -3),
                'komentar_realisasi' => 'Realisasi sesuai kebutuhan aktual.',
            ]);
        }

        $history[] = $this->entry('PRBL01', 'created', null, $tCtx, 'prbl01_data', $prbl01->id, offset: "-{$d} days");

        // 'CREATED' parks the report as a draft, so PRBL01 itself is the live
        // step. Stopping at 'PRBL01' instead submits it, which makes the two
        // approval desks live and leaves nothing sitting on PRBL01.
        if ($stopAt === 'CREATED') {
            $workflow->update(['history' => $history]);

            return $workflow;
        }

        $history[] = $this->entry('PRBL01', 'submitted', $user->id, $tCtx, 'prbl01_data', $prbl01->id, offset: '-'.($d - 1).' days');

        if ($stopAt === 'PRBL01') {
            $workflow->update(['history' => $history]);

            return $workflow;
        }

        // --- PRBL02A/02B: parallel approvals ---
        if ($prbl02aApproved) {
            $history[] = $this->entry('PRBL02A', 'approved', $this->users['eval_narasi']->id, $mCtx, offset: '-'.($d - 3).' days', notes: 'Narasi laporan diterima');
        }

        if ($prbl02bApproved) {
            $history[] = $this->entry('PRBL02B', 'approved', $this->users['bu1']->id, $bCtx, offset: '-'.($d - 3).' days', notes: 'Anggaran dan realisasi disetujui');
        }

        if ($stopAt === 'PRBL02') {
            $workflow->update(['history' => $history]);

            return $workflow;
        }

        // --- PRBL03: refund calculation ---
        $totalAnggaran = (float) $anggaranItems->sum('nominal_anggaran');
        $totalRealisasi = (float) $anggaranItems->sum(fn ($a) => round($a->nominal_anggaran * 0.92, -3));
        $refund = max(0, $totalAnggaran - $totalRealisasi);

        $prbl03 = Prbl03Data::create([
            'prbl_workflow_id' => $workflow->id,
            'nominal_refund' => $refund,
            'keterangan' => 'Selisih anggaran dikembalikan ke rekening pusat.',
        ]);

        $history[] = $this->entry('PRBL03', 'created', null, $tCtx, 'prbl03_data', $prbl03->id, offset: '-'.($d - 4).' days');
        $history[] = $this->entry('PRBL03', 'submitted', $user->id, $tCtx, 'prbl03_data', $prbl03->id, offset: '-'.($d - 5).' days');

        if ($stopAt === 'PRBL03') {
            $workflow->update(['history' => $history]);

            return $workflow;
        }

        // --- PRBL04: BU final review ---
        if ($stopAt === 'PRBL04') {
            $workflow->update(['history' => $history]);

            return $workflow;
        }

        $history[] = $this->entry('PRBL04', 'approved', $this->users['bu1']->id, $bCtx, offset: '-'.($d - 7).' days', notes: 'Laporan bulanan disahkan');

        // --- PRBL05: compile ---
        $this->compilePrbl05($workflow, $prbl03, $anggaranItems, $kegiatanItems, $teamKey, $d, $history);

        return $workflow;
    }

    private function compilePrbl05(
        PrblWorkflow $workflow,
        Prbl03Data $prbl03,
        Collection $anggaranItems,
        Collection $kegiatanItems,
        string $teamKey,
        int $d,
        array &$history,
    ): void {
        $team = $this->teams[$teamKey];
        $user = $this->users["koordinator_{$teamKey}"];
        $role = $this->roles["koordinator_{$teamKey}"];
        $tCtx = $this->teamCtx($teamKey);
        $rekening = Pp06RekeningOrganisasi::where('pp06_periode_tahunan_id', $this->pp06->id)->first();
        $orgName = $this->workspace->organizations()->firstOrFail()->name;
        $wsName = $this->workspace->name;

        $totalAnggaran = (float) $anggaranItems->sum('nominal_anggaran');
        $totalRealisasi = (float) $anggaranItems->sum(fn ($a) => round($a->nominal_anggaran * 0.92, -3));
        $totalRefund = max(0, $totalAnggaran - $totalRealisasi);

        $prbl05 = Prbl05LaporanBulanan::create([
            'prbl_workflow_id' => $workflow->id,
            'verification_code' => strtoupper(Str::random(8)),
            'prbl01_created_by_user_name' => $user->name,
            'prbl01_created_by_role_name' => $role->name,
            'prbl01_created_by_team_name' => $team->name,
            'prbl01_created_by_organization_name' => $orgName,
            'prbl01_created_by_workspace_name' => $wsName,
            'prbl01_created_at' => now()->modify("-{$d} days"),
            'prbl02a_approved_by_user_name' => $this->users['eval_narasi']->name,
            'prbl02a_approved_by_role_name' => $this->roles['eval_narasi']->name,
            'prbl02a_approved_by_team_name' => $this->teams['monev']->name,
            'prbl02a_approved_by_organization_name' => $orgName,
            'prbl02a_approved_by_workspace_name' => $wsName,
            'prbl02a_approved_at' => now()->modify('-'.($d - 3).' days'),
            'prbl02b_approved_by_user_name' => $this->users['bu1']->name,
            'prbl02b_approved_by_role_name' => $this->roles['bu1']->name,
            'prbl02b_approved_by_team_name' => $this->teams['bu']->name,
            'prbl02b_approved_by_organization_name' => $orgName,
            'prbl02b_approved_by_workspace_name' => $wsName,
            'prbl02b_approved_at' => now()->modify('-'.($d - 3).' days'),
            'prbl03_created_by_user_name' => $user->name,
            'prbl03_created_by_role_name' => $role->name,
            'prbl03_created_by_team_name' => $team->name,
            'prbl03_created_by_organization_name' => $orgName,
            'prbl03_created_by_workspace_name' => $wsName,
            'prbl03_created_at' => now()->modify('-'.($d - 5).' days'),
            'prbl04_approved_by_user_name' => $this->users['bu1']->name,
            'prbl04_approved_by_role_name' => $this->roles['bu1']->name,
            'prbl04_approved_by_team_name' => $this->teams['bu']->name,
            'prbl04_approved_by_organization_name' => $orgName,
            'prbl04_approved_by_workspace_name' => $wsName,
            'prbl04_approved_at' => now()->modify('-'.($d - 7).' days'),
            'keterangan' => $prbl03->keterangan,
            'total_anggaran_dicairkan' => $totalAnggaran,
            'total_realisasi' => $totalRealisasi,
            'total_refund' => $totalRefund,
            'total_item' => $anggaranItems->count(),
        ]);

        foreach ($kegiatanItems as $k) {
            Prbl05ItemKegiatan::create([
                'prbl05_laporan_bulanan_id' => $prbl05->id,
                'pk04_kegiatan_id' => $k->id,
                'masalah' => 'Tidak ada masalah signifikan.',
                'langkah_penanganan' => 'Kegiatan berjalan sesuai rencana.',
                'harapan' => 'Kegiatan bulan depan dapat lebih baik.',
                'catatan_tim' => 'Partisipasi peserta cukup baik.',
            ]);
        }

        // Tier 3 realisasi lock: Prbl05ItemRealisasi → pk04_anggaran.hasRealisasiLock()
        foreach ($anggaranItems as $a) {
            $realisasi = round($a->nominal_anggaran * 0.92, -3);

            Prbl05ItemRealisasi::create([
                'prbl05_laporan_bulanan_id' => $prbl05->id,
                'pk04_anggaran_id' => $a->id,
                'nominal_anggaran' => $a->nominal_anggaran,
                'nominal_realisasi' => $realisasi,
                'selisih' => $a->nominal_anggaran - $realisasi,
                'komentar_realisasi' => 'Realisasi sesuai kebutuhan aktual.',
            ]);
        }

        if ($rekening) {
            Prbl05RekeningOrganisasi::create([
                'prbl05_laporan_bulanan_id' => $prbl05->id,
                'nama_bank' => $rekening->nama_bank,
                'nama_rekening' => $rekening->nama_rekening,
                'nomor_rekening' => $rekening->nomor_rekening,
            ]);
        }

        $history[] = $this->entry('PRBL05', 'completed', null, $tCtx, 'prbl05_laporan_bulanan', $prbl05->id, offset: '-'.($d - 7).' days');
        $workflow->update(['history' => $history]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function anggaranForMonth(string $teamKey, int $bulan): Collection
    {
        $pk04 = $this->pkWorkflows[$teamKey]->latestPk04();

        return Pk04Anggaran::query()
            ->whereHas('pk04Kegiatan', fn ($q) => $q
                ->where('pk04_program_tahunan_id', $pk04->id)
                ->where('bulan', $bulan)
            )
            ->where('status_item', 'active')
            ->get();
    }

    private function kegiatanForMonth(string $teamKey, int $bulan): Collection
    {
        $pk04 = $this->pkWorkflows[$teamKey]->latestPk04();

        return Pk04Kegiatan::where('pk04_program_tahunan_id', $pk04->id)
            ->where('bulan', $bulan)
            ->get();
    }

    private function teamCtx(string $teamKey): array
    {
        return [
            'role' => $this->roles["koordinator_{$teamKey}"]->id,
            'team' => $this->teams[$teamKey]->id,
            'org' => $this->orgId,
            'workspace' => $this->workspace->id,
        ];
    }

    private function adminCtx(string $roleKey): array
    {
        return [
            'role' => $this->roles[$roleKey]->id,
            'team' => null,
            'org' => null,
            'workspace' => $this->workspace->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $sessionContext
     * @param  array<string, mixed>|null  $extra
     * @return array<string, mixed>
     */
    private function entry(
        string $step,
        string $action,
        ?int $userId,
        array $sessionContext,
        ?string $table = null,
        ?int $dataId = null,
        ?string $notes = null,
        ?array $extra = null,
        string $offset = 'now',
    ): array {
        $entry = array_filter([
            'step' => $step,
            'action' => $action,
            'by' => $userId,
            'role' => $sessionContext['role'] ?? null,
            'team' => $sessionContext['team'] ?? null,
            'org' => $sessionContext['org'] ?? null,
            'workspace' => $sessionContext['workspace'] ?? null,
            'at' => now()->modify($offset)->toIso8601String(),
            'table' => $table,
            'id' => $dataId,
            'notes' => $notes,
        ], fn ($v) => $v !== null);

        if ($extra) {
            $entry = array_merge($entry, $extra);
        }

        return $entry;
    }

    // =========================================================================
    // PP — the annual frame. Everything downstream anchors to PP06, so this
    // runs first and is driven all the way to a compiled, approved period.
    // =========================================================================

    private function createCompletedPpWorkflow(int $tahun): Pp06PeriodeTahunan
    {
        $koordinator = $this->users['koordinator_monev'];
        $evalNarasi = $this->users['eval_narasi'];
        $bu1 = $this->users['bu1'];

        $workflow = PpWorkflow::create([
            'workspace_id' => $this->workspace->id,
            'history' => [],
        ]);

        $pp01 = Pp01Data::create([
            'pp_workflow_id' => $workflow->id,
            'tahun' => $tahun,
            'tanggal_mulai_pra_raker' => now()->subDay()->toDateString(),
            'tanggal_penetapan_program' => now()->addMonths(6)->toDateString(),
        ]);
        $this->createKodeTables($pp01);

        $pp02 = Pp02Data::create(['pp_workflow_id' => $workflow->id]);
        $this->createKuisionerItems($pp02);

        $pp03 = Pp03Data::create(['pp_workflow_id' => $workflow->id]);
        $this->createPlafonItems($pp03);

        $pp04 = Pp04Data::create(['pp_workflow_id' => $workflow->id]);

        $ctx = fn (?int $roleId = null) => [
            'role' => $roleId ?? $this->roles['koordinator_monev']->id,
            'team' => null,
            'org' => null,
            'workspace' => $this->workspace->id,
        ];

        $history = [
            $this->entry('PP01', 'created', null, $ctx(), 'pp01_data', $pp01->id, offset: '-14 days'),
            $this->entry('PP01', 'submitted', $koordinator->id, $ctx(), 'pp01_data', $pp01->id, offset: '-13 days'),
            $this->entry('PP02', 'created', null, $ctx(), 'pp02_data', $pp02->id, offset: '-13 days'),
            $this->entry('PP02', 'submitted', $evalNarasi->id, $ctx($this->roles['eval_narasi']->id), 'pp02_data', $pp02->id, offset: '-12 days'),
            $this->entry('PP03', 'created', null, $ctx(), 'pp03_data', $pp03->id, offset: '-12 days'),
            $this->entry('PP03', 'submitted', $bu1->id, $ctx($this->roles['bu1']->id), 'pp03_data', $pp03->id, offset: '-11 days'),
            $this->entry('PP04', 'created', null, $ctx(), 'pp04_data', $pp04->id, offset: '-11 days'),
            $this->entry('PP04', 'submitted', $koordinator->id, $ctx(), 'pp04_data', $pp04->id, offset: '-10 days'),
            $this->entry('PP05', 'approved', $koordinator->id, $ctx(), offset: '-9 days', notes: 'Disetujui untuk periode '.$tahun, extra: [
                'reviewed' => [
                    'pp01_data_id' => $pp01->id,
                    'pp02_data_id' => $pp02->id,
                    'pp03_data_id' => $pp03->id,
                    'pp04_data_id' => $pp04->id,
                ],
            ]),
        ];

        $workflow->update(['history' => $history]);

        $pp06 = app(PpCompileService::class)->compile($workflow);

        $history[] = $this->entry('PP06', 'completed', null, $ctx(), 'pp06_periode_tahunan', $pp06->id, offset: '-9 days');
        $workflow->update(['history' => $history]);

        return $pp06;
    }

    private function createKodeTables(Pp01Data $pp01): void
    {
        $bidang = [
            ['kode' => 'BP01', 'nama' => 'Operasional', 'catatan' => null],
            ['kode' => 'BP02', 'nama' => 'Pengembangan SDM', 'catatan' => null],
            ['kode' => 'BP03', 'nama' => 'Aset dan Logistik', 'catatan' => null],
        ];

        $subBidang = [
            ['kode' => 'SB01', 'nama' => 'Kegiatan Rutin', 'catatan' => null],
            ['kode' => 'SB02', 'nama' => 'Proyek Khusus', 'catatan' => null],
            ['kode' => 'SB03', 'nama' => 'Dukungan Lapangan', 'catatan' => null],
        ];

        $kategori = [
            ['kode' => 'KP01', 'nama' => 'Reguler', 'catatan' => null],
            ['kode' => 'KP02', 'nama' => 'Insidental', 'catatan' => null],
        ];

        $jenis = [
            ['kode' => 'JP01', 'nama' => 'Rutin', 'catatan' => null],
            ['kode' => 'JP02', 'nama' => 'Non Rutin', 'catatan' => null],
        ];

        foreach ($bidang as $item) {
            Pp01KodeBidangPelayanan::create(array_merge(['pp01_data_id' => $pp01->id], $item));
        }

        foreach ($subBidang as $item) {
            Pp01KodeSubBidangPelayanan::create(array_merge(['pp01_data_id' => $pp01->id], $item));
        }

        foreach ($kategori as $item) {
            Pp01KodeKategoriPelayanan::create(array_merge(['pp01_data_id' => $pp01->id], $item));
        }

        foreach ($jenis as $item) {
            Pp01KodeJenisProgram::create(array_merge(['pp01_data_id' => $pp01->id], $item));
        }
    }

    private function createKuisionerItems(Pp02Data $pp02): void
    {
        $items = [
            ['kode' => 'Q01', 'pertanyaan' => 'Jumlah peserta kegiatan', 'tipe' => 'Kuantitatif', 'satuan' => 'orang'],
            ['kode' => 'Q02', 'pertanyaan' => 'Tingkat kepuasan peserta', 'tipe' => 'Kualitatif', 'satuan' => null],
        ];

        foreach ($items as $item) {
            Pp02ItemKuisioner::create(array_merge(['pp02_data_id' => $pp02->id], $item));
        }
    }

    private function createPlafonItems(Pp03Data $pp03): void
    {
        $plafon = [
            ['key' => 'ops', 'kode' => 'OPS', 'plafon' => 50000000, 'bank' => 'BCA'],
            ['key' => 'mkt', 'kode' => 'MKT', 'plafon' => 30000000, 'bank' => 'Mandiri'],
            ['key' => 'it', 'kode' => 'TI', 'plafon' => 20000000, 'bank' => 'BNI'],
            ['key' => 'hr', 'kode' => 'SDM', 'plafon' => 25000000, 'bank' => 'BCA'],
            ['key' => 'fin', 'kode' => 'FIN', 'plafon' => 35000000, 'bank' => 'Mandiri'],
            ['key' => 'lgl', 'kode' => 'LGL', 'plafon' => 15000000, 'bank' => 'BNI'],
            ['key' => 'pro', 'kode' => 'PRO', 'plafon' => 40000000, 'bank' => 'Mandiri'],
        ];

        foreach ($plafon as $item) {
            $team = $this->teams[$item['key']];
            Pp03ItemPlafonAnggaran::create([
                'pp03_data_id' => $pp03->id,
                'team_id' => $team->id,
                'kode_team' => $item['kode'],
                'plafon_anggaran' => $item['plafon'],
                'nama_bank' => $item['bank'],
                'nama_rekening' => $team->name,
                'nomor_rekening' => '1234567890',
                'catatan' => null,
            ]);
        }
    }
}
