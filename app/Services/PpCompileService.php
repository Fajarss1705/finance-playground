<?php

namespace App\Services;

use App\Models\File;
use App\Models\Pp\Pp06ItemDokumenSop;
use App\Models\Pp\Pp06ItemKuisioner;
use App\Models\Pp\Pp06ItemPlafonAnggaran;
use App\Models\Pp\Pp06KodeBidangPelayanan;
use App\Models\Pp\Pp06KodeJenisProgram;
use App\Models\Pp\Pp06KodeKategoriPelayanan;
use App\Models\Pp\Pp06KodeSubBidangPelayanan;
use App\Models\Pp\Pp06PeriodeTahunan;
use App\Models\Pp\Pp06RekeningOrganisasi;
use App\Models\Pp\PpWorkflow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

            // Copy rekening organisasi from PP03
            foreach ($pp03->rekeningOrganisasi as $item) {
                Pp06RekeningOrganisasi::create([
                    'pp06_periode_tahunan_id' => $pp06->id,
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

            $this->generateVerificationCode($pp06);

            return $pp06;
        });
    }

    /**
     * Compile PP06 from PP07 draft_data JSON (revision+1).
     *
     * @param  array<string, mixed>  $draftData
     * @param  array<string, mixed>  $authorOverrides
     */
    public function compileFromDraft(PpWorkflow $workflow, array $draftData, int $revision, array $authorOverrides): Pp06PeriodeTahunan
    {
        return DB::transaction(function () use ($workflow, $draftData, $revision, $authorOverrides) {
            $pp06 = Pp06PeriodeTahunan::create(array_merge([
                'pp_workflow_id' => $workflow->id,
                'revision' => $revision,
                'tahun' => $draftData['tahun'],
                'tanggal_mulai_pra_raker' => $draftData['tanggal_mulai_pra_raker'],
                'tanggal_penetapan_program' => $draftData['tanggal_penetapan_program'],
            ], $authorOverrides));

            $kodeTypes = [
                'kode_bidang_pelayanan' => Pp06KodeBidangPelayanan::class,
                'kode_sub_bidang_pelayanan' => Pp06KodeSubBidangPelayanan::class,
                'kode_kategori_pelayanan' => Pp06KodeKategoriPelayanan::class,
                'kode_jenis_program' => Pp06KodeJenisProgram::class,
            ];

            foreach ($kodeTypes as $key => $modelClass) {
                foreach ($draftData[$key] ?? [] as $item) {
                    $modelClass::create([
                        'pp06_periode_tahunan_id' => $pp06->id,
                        'kode' => $item['kode'],
                        'nama' => $item['nama'],
                        'catatan' => $item['catatan'] ?? null,
                    ]);
                }
            }

            foreach ($draftData['item_kuisioner'] ?? [] as $item) {
                Pp06ItemKuisioner::create([
                    'pp06_periode_tahunan_id' => $pp06->id,
                    'kode' => $item['kode'] ?? '',
                    'pertanyaan' => $item['pertanyaan'],
                    'tipe' => $item['tipe'],
                    'satuan' => $item['satuan'] ?? null,
                ]);
            }

            foreach ($draftData['item_plafon_anggaran'] ?? [] as $item) {
                Pp06ItemPlafonAnggaran::create([
                    'pp06_periode_tahunan_id' => $pp06->id,
                    'team_id' => $item['team_id'],
                    'kode_team' => $item['kode_team'],
                    'plafon_anggaran' => $item['plafon_anggaran'],
                    'nama_bank' => $item['nama_bank'],
                    'nama_rekening' => $item['nama_rekening'],
                    'nomor_rekening' => $item['nomor_rekening'],
                    'catatan' => $item['catatan'] ?? null,
                ]);
            }

            foreach ($draftData['item_dokumen_sop'] ?? [] as $item) {
                Pp06ItemDokumenSop::create([
                    'pp06_periode_tahunan_id' => $pp06->id,
                    'file_id' => $item['file_id'],
                ]);
            }

            foreach ($draftData['rekening_organisasi'] ?? [] as $item) {
                Pp06RekeningOrganisasi::create([
                    'pp06_periode_tahunan_id' => $pp06->id,
                    'nama_bank' => $item['nama_bank'],
                    'nama_rekening' => $item['nama_rekening'],
                    'nomor_rekening' => $item['nomor_rekening'],
                    'catatan' => $item['catatan'] ?? null,
                ]);
            }

            $this->generateVerificationCode($pp06);

            return $pp06;
        });
    }

    /**
     * Build canonical data array from PP06 row for hashing.
     *
     * Only includes substantive data (tahun, dates, kode tables,
     * kuisioner, plafon, dokumen) — excludes author snapshots and timestamps.
     *
     * @return array<string, mixed>
     */
    public function buildCanonicalData(Pp06PeriodeTahunan $pp06): array
    {
        $pp06->loadMissing([
            'kodeBidangPelayanan', 'kodeSubBidangPelayanan',
            'kodeKategoriPelayanan', 'kodeJenisProgram',
            'itemKuisioner', 'itemPlafonAnggaran', 'itemDokumenSop',
            'rekeningOrganisasi',
        ]);

        $mapKode = fn ($item) => [
            'kode' => $item->kode,
            'nama' => $item->nama,
            'catatan' => $item->catatan ?? '',
        ];

        return [
            'tahun' => (int) $pp06->tahun,
            'tanggal_mulai_pra_raker' => $pp06->tanggal_mulai_pra_raker?->toDateString(),
            'tanggal_penetapan_program' => $pp06->tanggal_penetapan_program?->toDateString(),
            'kode_bidang_pelayanan' => $pp06->kodeBidangPelayanan
                ->sortBy('kode')->map($mapKode)->values()->all(),
            'kode_sub_bidang_pelayanan' => $pp06->kodeSubBidangPelayanan
                ->sortBy('kode')->map($mapKode)->values()->all(),
            'kode_kategori_pelayanan' => $pp06->kodeKategoriPelayanan
                ->sortBy('kode')->map($mapKode)->values()->all(),
            'kode_jenis_program' => $pp06->kodeJenisProgram
                ->sortBy('kode')->map($mapKode)->values()->all(),
            'item_kuisioner' => $pp06->itemKuisioner
                ->sortBy('kode')->map(fn ($item) => [
                    'kode' => $item->kode,
                    'pertanyaan' => $item->pertanyaan,
                    'tipe' => $item->tipe,
                    'satuan' => $item->satuan ?? '',
                ])->values()->all(),
            'item_plafon_anggaran' => $pp06->itemPlafonAnggaran
                ->sortBy('team_id')->map(fn ($item) => [
                    'team_id' => $item->team_id,
                    'kode_team' => $item->kode_team,
                    'plafon_anggaran' => (int) $item->plafon_anggaran,
                    'nama_bank' => $item->nama_bank ?? '',
                    'nama_rekening' => $item->nama_rekening ?? '',
                    'nomor_rekening' => $item->nomor_rekening ?? '',
                ])->values()->all(),
            'item_dokumen_sop' => $pp06->itemDokumenSop
                ->sortBy('file_id')->map(fn ($item) => [
                    'file_id' => $item->file_id,
                ])->values()->all(),
            'rekening_organisasi' => $pp06->rekeningOrganisasi
                ->sortBy('id')->map(fn ($item) => [
                    'nama_bank' => $item->nama_bank ?? '',
                    'nama_rekening' => $item->nama_rekening ?? '',
                    'nomor_rekening' => $item->nomor_rekening ?? '',
                    'catatan' => $item->catatan ?? '',
                ])->values()->all(),
        ];
    }

    /**
     * Generate and store a deterministic verification code for a PP06 row.
     *
     * SHA-256 of canonical JSON → base36 truncate to 8 chars.
     */
    public function generateVerificationCode(Pp06PeriodeTahunan $pp06): string
    {
        $canonical = $this->buildCanonicalData($pp06);
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $json);
        $code = strtoupper(substr(base_convert(substr($hash, 0, 16), 16, 36), 0, 8));

        $pp06->update(['verification_code' => $code]);

        return $code;
    }

    /**
     * Generate PDF and Excel export files and store them as File records.
     *
     * Called after compile (outside the transaction). Failures are logged
     * but do not abort the compile — exports can be regenerated on demand.
     *
     * @return array{pdf_file_id: int|null, excel_file_id: int|null}
     */
    public function generateExportFiles(Pp06PeriodeTahunan $pp06, int $userId, int $workspaceId): array
    {
        $result = ['pdf_file_id' => null, 'excel_file_id' => null];

        // PDF
        try {
            $pdfService = app(Pp06PdfExportService::class);
            $tempPath = $pdfService->generate($pp06);
            $filename = "PP06-PeriodeTahunan-{$pp06->tahun}-Rev{$pp06->revision}.pdf";
            $storagePath = "exports/pp/{$pp06->ppWorkflow->id}/{$filename}";

            Storage::disk('local')->put($storagePath, file_get_contents($tempPath));
            @unlink($tempPath);

            $file = File::create([
                'original_filename' => $filename,
                'filename' => $filename,
                'mime_type' => 'application/pdf',
                'size' => Storage::disk('local')->size($storagePath),
                'disk' => 'local',
                'path' => $storagePath,
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
                'attachable_type' => Pp06PeriodeTahunan::class,
                'attachable_id' => $pp06->id,
                'source_route' => 'pp06.export.pdf',
            ]);
            $result['pdf_file_id'] = $file->id;
        } catch (\Throwable $e) {
            Log::warning("PP06 PDF export failed for PP06#{$pp06->id}: {$e->getMessage()}");
        }

        // Excel
        try {
            $excelService = app(Pp06ExcelExportService::class);
            $tempPath = $excelService->generate($pp06);
            $filename = "PP06-PeriodeTahunan-{$pp06->tahun}-Rev{$pp06->revision}.xlsx";
            $storagePath = "exports/pp/{$pp06->ppWorkflow->id}/{$filename}";

            Storage::disk('local')->put($storagePath, file_get_contents($tempPath));
            @unlink($tempPath);

            $file = File::create([
                'original_filename' => $filename,
                'filename' => $filename,
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'size' => Storage::disk('local')->size($storagePath),
                'disk' => 'local',
                'path' => $storagePath,
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
                'attachable_type' => Pp06PeriodeTahunan::class,
                'attachable_id' => $pp06->id,
                'source_route' => 'pp06.export.excel',
            ]);
            $result['excel_file_id'] = $file->id;
        } catch (\Throwable $e) {
            Log::warning("PP06 Excel export failed for PP06#{$pp06->id}: {$e->getMessage()}");
        }

        return $result;
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

    /**
     * Compute changelog diff between latest PP06 revision and PP07 draft data.
     *
     * @param  array<string, mixed>  $draftData
     * @return list<array{type: string, description: string}>
     */
    public function computeDiff(Pp06PeriodeTahunan $previousPp06, array $draftData): array
    {
        $changes = [];

        // 1. Periode changes
        $this->diffPeriode($previousPp06, $draftData, $changes);

        // 2. Kode tables (4 types)
        $kodeLabels = [
            'kode_bidang_pelayanan' => ['relation' => 'kodeBidangPelayanan', 'label' => 'Kode Bidang'],
            'kode_sub_bidang_pelayanan' => ['relation' => 'kodeSubBidangPelayanan', 'label' => 'Kode Sub Bidang'],
            'kode_kategori_pelayanan' => ['relation' => 'kodeKategoriPelayanan', 'label' => 'Kode Kategori'],
            'kode_jenis_program' => ['relation' => 'kodeJenisProgram', 'label' => 'Kode Jenis Program'],
        ];

        foreach ($kodeLabels as $draftKey => $config) {
            $oldItems = $previousPp06->{$config['relation']}->map(fn ($item) => [
                'kode' => $item->kode,
                'nama' => $item->nama,
                'catatan' => $item->catatan,
            ])->all();

            $newItems = $draftData[$draftKey] ?? [];

            $this->diffKodeTable($oldItems, $newItems, $config['label'], $draftKey, $changes);
        }

        // 3. Kuisioner
        $this->diffKuisioner($previousPp06, $draftData, $changes);

        // 4. Plafon anggaran
        $this->diffPlafon($previousPp06, $draftData, $changes);

        // 5. Dokumen SOP
        $this->diffDokumen($previousPp06, $draftData, $changes);

        // 6. Rekening Organisasi
        $this->diffRekeningOrganisasi($previousPp06, $draftData, $changes);

        return $changes;
    }

    /**
     * @param  list<array{type: string, description: string}>  $changes
     */
    private function diffPeriode(Pp06PeriodeTahunan $old, array $new, array &$changes): void
    {
        if ((int) $old->tahun !== (int) $new['tahun']) {
            $changes[] = [
                'type' => 'periode_changed',
                'description' => "Tahun: {$old->tahun} → {$new['tahun']}",
            ];
        }

        $oldMulai = $old->tanggal_mulai_pra_raker?->format('d/m/Y') ?? '-';
        $newMulai = $new['tanggal_mulai_pra_raker'] ?? '-';
        $newMulaiFmt = $newMulai !== '-' ? \Carbon\Carbon::parse($newMulai)->format('d/m/Y') : '-';
        if ($oldMulai !== $newMulaiFmt) {
            $changes[] = [
                'type' => 'periode_changed',
                'description' => "Tanggal Mulai Pra-Raker: {$oldMulai} → {$newMulaiFmt}",
            ];
        }

        $oldPenetapan = $old->tanggal_penetapan_program?->format('d/m/Y') ?? '-';
        $newPenetapan = $new['tanggal_penetapan_program'] ?? '-';
        $newPenetapanFmt = $newPenetapan !== '-' ? \Carbon\Carbon::parse($newPenetapan)->format('d/m/Y') : '-';
        if ($oldPenetapan !== $newPenetapanFmt) {
            $changes[] = [
                'type' => 'periode_changed',
                'description' => "Tanggal Penetapan Program: {$oldPenetapan} → {$newPenetapanFmt}",
            ];
        }
    }

    /**
     * @param  list<array{kode: string, nama: string, catatan: ?string}>  $oldItems
     * @param  list<array{kode: string, nama: string, catatan?: ?string}>  $newItems
     * @param  list<array{type: string, description: string}>  $changes
     */
    private function diffKodeTable(array $oldItems, array $newItems, string $label, string $table, array &$changes): void
    {
        $oldByKode = collect($oldItems)->keyBy('kode');
        $newByKode = collect($newItems)->keyBy('kode');

        // Added
        foreach ($newByKode as $kode => $item) {
            if (! $oldByKode->has($kode)) {
                $changes[] = [
                    'type' => 'kode_added',
                    'description' => "{$label} \"{$kode} - {$item['nama']}\" ditambahkan",
                ];
            }
        }

        // Removed
        foreach ($oldByKode as $kode => $item) {
            if (! $newByKode->has($kode)) {
                $changes[] = [
                    'type' => 'kode_removed',
                    'description' => "{$label} \"{$kode} - {$item['nama']}\" dihapus",
                ];
            }
        }

        // Changed
        foreach ($newByKode as $kode => $newItem) {
            if (! $oldByKode->has($kode)) {
                continue;
            }

            $oldItem = $oldByKode[$kode];

            if (($oldItem['nama'] ?? '') !== ($newItem['nama'] ?? '')) {
                $changes[] = [
                    'type' => 'kode_changed',
                    'description' => "{$label} \"{$kode}\": nama \"{$oldItem['nama']}\" → \"{$newItem['nama']}\"",
                ];
            }

            if (($oldItem['catatan'] ?? '') !== ($newItem['catatan'] ?? '')) {
                $oldCat = $oldItem['catatan'] ?: '(kosong)';
                $newCat = $newItem['catatan'] ?? '';
                $newCat = $newCat ?: '(kosong)';
                $changes[] = [
                    'type' => 'kode_changed',
                    'description' => "{$label} \"{$kode}\": catatan diubah",
                ];
            }
        }
    }

    /**
     * @param  list<array{type: string, description: string}>  $changes
     */
    private function diffKuisioner(Pp06PeriodeTahunan $old, array $new, array &$changes): void
    {
        $oldItems = $old->itemKuisioner->map(fn ($item) => [
            'kode' => $item->kode,
            'pertanyaan' => $item->pertanyaan,
            'tipe' => $item->tipe,
            'satuan' => $item->satuan,
        ])->all();

        $newItems = $new['item_kuisioner'] ?? [];

        $oldByKode = collect($oldItems)->keyBy('kode');
        $newByKode = collect($newItems)->keyBy(fn ($item) => $item['kode'] ?? '');

        // If kode is unreliable (empty strings), fall back to pertanyaan matching
        $useKode = $oldByKode->keys()->filter(fn ($k) => $k !== '')->isNotEmpty();

        if ($useKode) {
            foreach ($newByKode as $kode => $item) {
                if (! $oldByKode->has($kode)) {
                    $changes[] = [
                        'type' => 'kuisioner_added',
                        'description' => "Kuisioner ditambahkan: \"{$item['pertanyaan']}\"",
                    ];
                }
            }

            foreach ($oldByKode as $kode => $item) {
                if (! $newByKode->has($kode)) {
                    $changes[] = [
                        'type' => 'kuisioner_removed',
                        'description' => "Kuisioner dihapus: \"{$item['pertanyaan']}\"",
                    ];
                }
            }

            foreach ($newByKode as $kode => $newItem) {
                if (! $oldByKode->has($kode)) {
                    continue;
                }

                $oldItem = $oldByKode[$kode];
                $fieldChanges = [];

                if (($oldItem['pertanyaan'] ?? '') !== ($newItem['pertanyaan'] ?? '')) {
                    $fieldChanges[] = 'pertanyaan';
                }
                if (($oldItem['tipe'] ?? '') !== ($newItem['tipe'] ?? '')) {
                    $fieldChanges[] = "tipe ({$oldItem['tipe']} → {$newItem['tipe']})";
                }
                if (($oldItem['satuan'] ?? '') !== ($newItem['satuan'] ?? '')) {
                    $fieldChanges[] = 'satuan';
                }

                if (! empty($fieldChanges)) {
                    $changes[] = [
                        'type' => 'kuisioner_changed',
                        'description' => "Kuisioner \"{$oldItem['pertanyaan']}\": ".implode(', ', $fieldChanges).' diubah',
                    ];
                }
            }
        } else {
            // Fallback: match by pertanyaan text
            $oldByQ = collect($oldItems)->keyBy('pertanyaan');
            $newByQ = collect($newItems)->keyBy('pertanyaan');

            foreach ($newByQ as $q => $item) {
                if (! $oldByQ->has($q)) {
                    $changes[] = [
                        'type' => 'kuisioner_added',
                        'description' => "Kuisioner ditambahkan: \"{$q}\"",
                    ];
                }
            }

            foreach ($oldByQ as $q => $item) {
                if (! $newByQ->has($q)) {
                    $changes[] = [
                        'type' => 'kuisioner_removed',
                        'description' => "Kuisioner dihapus: \"{$q}\"",
                    ];
                }
            }

            foreach ($newByQ as $q => $newItem) {
                if (! $oldByQ->has($q)) {
                    continue;
                }

                $oldItem = $oldByQ[$q];

                if (($oldItem['tipe'] ?? '') !== ($newItem['tipe'] ?? '')) {
                    $changes[] = [
                        'type' => 'kuisioner_changed',
                        'description' => "Kuisioner \"{$q}\": tipe ({$oldItem['tipe']} → {$newItem['tipe']}) diubah",
                    ];
                }
            }
        }
    }

    /**
     * @param  list<array{type: string, description: string}>  $changes
     */
    private function diffPlafon(Pp06PeriodeTahunan $old, array $new, array &$changes): void
    {
        $oldItems = $old->itemPlafonAnggaran->keyBy('team_id');
        $newItems = collect($new['item_plafon_anggaran'] ?? [])->keyBy('team_id');

        // Resolve team names for descriptions
        $teamIds = $oldItems->keys()->merge($newItems->keys())->unique();
        $teamNames = \App\Models\Team::withTrashed()->whereIn('id', $teamIds)->pluck('name', 'id');

        foreach ($newItems as $teamId => $item) {
            if (! $oldItems->has($teamId)) {
                $teamName = $teamNames[$teamId] ?? "Tim #{$teamId}";
                $changes[] = [
                    'type' => 'plafon_changed',
                    'description' => "Plafon {$teamName} ditambahkan: ".self::formatRupiah($item['plafon_anggaran']),
                ];

                continue;
            }

            $oldItem = $oldItems[$teamId];
            $teamName = $teamNames[$teamId] ?? "Tim #{$teamId}";

            if ((int) $oldItem->plafon_anggaran !== (int) $item['plafon_anggaran']) {
                $changes[] = [
                    'type' => 'plafon_changed',
                    'description' => "Plafon {$teamName}: ".self::formatRupiah($oldItem->plafon_anggaran).' → '.self::formatRupiah($item['plafon_anggaran']),
                ];
            }

            $rekeningFields = ['nama_bank', 'nama_rekening', 'nomor_rekening'];
            $rekeningChanges = [];

            foreach ($rekeningFields as $field) {
                if (($oldItem->{$field} ?? '') !== ($item[$field] ?? '')) {
                    $rekeningChanges[] = str_replace('_', ' ', $field);
                }
            }

            if (! empty($rekeningChanges)) {
                $changes[] = [
                    'type' => 'rekening_changed',
                    'description' => "Rekening {$teamName}: ".implode(', ', $rekeningChanges).' diubah',
                ];
            }
        }

        foreach ($oldItems as $teamId => $item) {
            if (! $newItems->has($teamId)) {
                $teamName = $teamNames[$teamId] ?? "Tim #{$teamId}";
                $changes[] = [
                    'type' => 'plafon_changed',
                    'description' => "Plafon {$teamName} dihapus (sebelumnya ".self::formatRupiah($item->plafon_anggaran).')',
                ];
            }
        }
    }

    /**
     * @param  list<array{type: string, description: string}>  $changes
     */
    private function diffDokumen(Pp06PeriodeTahunan $old, array $new, array &$changes): void
    {
        $oldFileIds = $old->itemDokumenSop->pluck('file_id')->all();
        $newFileIds = array_map(fn ($item) => $item['file_id'], $new['item_dokumen_sop'] ?? []);

        $added = array_diff($newFileIds, $oldFileIds);
        $removed = array_diff($oldFileIds, $newFileIds);

        if (! empty($added) || ! empty($removed)) {
            // Resolve filenames for better descriptions
            $allIds = array_merge($added, $removed);
            $fileNames = \App\Models\File::whereIn('id', $allIds)->pluck('original_filename', 'id');

            foreach ($added as $fileId) {
                $name = $fileNames[$fileId] ?? "file #{$fileId}";
                $changes[] = [
                    'type' => 'dokumen_added',
                    'description' => "Dokumen SOP ditambahkan: \"{$name}\"",
                ];
            }

            foreach ($removed as $fileId) {
                $name = $fileNames[$fileId] ?? "file #{$fileId}";
                $changes[] = [
                    'type' => 'dokumen_removed',
                    'description' => "Dokumen SOP dihapus: \"{$name}\"",
                ];
            }
        }
    }

    /**
     * @param  list<array{type: string, description: string}>  $changes
     */
    private function diffRekeningOrganisasi(Pp06PeriodeTahunan $old, array $new, array &$changes): void
    {
        $old->loadMissing('rekeningOrganisasi');

        $oldItems = $old->rekeningOrganisasi->map(fn ($item) => [
            'nama_bank' => $item->nama_bank,
            'nama_rekening' => $item->nama_rekening,
            'nomor_rekening' => $item->nomor_rekening,
            'catatan' => $item->catatan,
        ])->values()->all();

        $newItems = $new['rekening_organisasi'] ?? [];

        // Match by nomor_rekening as key
        $oldByNomor = collect($oldItems)->keyBy('nomor_rekening');
        $newByNomor = collect($newItems)->keyBy('nomor_rekening');

        foreach ($newByNomor as $nomor => $item) {
            if (! $oldByNomor->has($nomor)) {
                $changes[] = [
                    'type' => 'rekening_organisasi_added',
                    'description' => "Rekening organisasi ditambahkan: {$item['nama_bank']} - {$item['nomor_rekening']}",
                ];
            }
        }

        foreach ($oldByNomor as $nomor => $item) {
            if (! $newByNomor->has($nomor)) {
                $changes[] = [
                    'type' => 'rekening_organisasi_removed',
                    'description' => "Rekening organisasi dihapus: {$item['nama_bank']} - {$item['nomor_rekening']}",
                ];
            }
        }

        foreach ($newByNomor as $nomor => $newItem) {
            if (! $oldByNomor->has($nomor)) {
                continue;
            }

            $oldItem = $oldByNomor[$nomor];
            $fieldChanges = [];

            if (($oldItem['nama_bank'] ?? '') !== ($newItem['nama_bank'] ?? '')) {
                $fieldChanges[] = "nama bank ({$oldItem['nama_bank']} → {$newItem['nama_bank']})";
            }
            if (($oldItem['nama_rekening'] ?? '') !== ($newItem['nama_rekening'] ?? '')) {
                $fieldChanges[] = "nama rekening ({$oldItem['nama_rekening']} → {$newItem['nama_rekening']})";
            }
            if (($oldItem['catatan'] ?? '') !== ($newItem['catatan'] ?? '')) {
                $fieldChanges[] = 'catatan';
            }

            if (! empty($fieldChanges)) {
                $changes[] = [
                    'type' => 'rekening_organisasi_changed',
                    'description' => "Rekening organisasi \"{$nomor}\": ".implode(', ', $fieldChanges).' diubah',
                ];
            }
        }
    }

    private static function formatRupiah(int|float $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
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
