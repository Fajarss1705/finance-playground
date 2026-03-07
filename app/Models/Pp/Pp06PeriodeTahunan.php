<?php

namespace App\Models\Pp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pp06PeriodeTahunan extends Model
{
    protected $table = 'pp06_periode_tahunan';

    protected $fillable = [
        'pp_workflow_id',
        'revision',
        'pp01_created_by_user_name',
        'pp01_created_by_role_name',
        'pp01_created_by_team_name',
        'pp01_created_by_organization_name',
        'pp01_created_by_workspace_name',
        'pp01_created_at',
        'pp02_created_by_user_name',
        'pp02_created_by_role_name',
        'pp02_created_by_team_name',
        'pp02_created_by_organization_name',
        'pp02_created_by_workspace_name',
        'pp02_created_at',
        'pp03_created_by_user_name',
        'pp03_created_by_role_name',
        'pp03_created_by_team_name',
        'pp03_created_by_organization_name',
        'pp03_created_by_workspace_name',
        'pp03_created_at',
        'pp04_created_by_user_name',
        'pp04_created_by_role_name',
        'pp04_created_by_team_name',
        'pp04_created_by_organization_name',
        'pp04_created_by_workspace_name',
        'pp04_created_at',
        'pp05_created_by_user_name',
        'pp05_created_by_role_name',
        'pp05_created_by_team_name',
        'pp05_created_by_organization_name',
        'pp05_created_by_workspace_name',
        'pp05_created_at',
        'tahun',
        'tanggal_mulai_pra_raker',
        'tanggal_penetapan_program',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pp01_created_at' => 'datetime',
            'pp02_created_at' => 'datetime',
            'pp03_created_at' => 'datetime',
            'pp04_created_at' => 'datetime',
            'pp05_created_at' => 'datetime',
            'tanggal_mulai_pra_raker' => 'date',
            'tanggal_penetapan_program' => 'date',
        ];
    }

    public function ppWorkflow(): BelongsTo
    {
        return $this->belongsTo(PpWorkflow::class);
    }

    public function itemPlafonAnggaran(): HasMany
    {
        return $this->hasMany(Pp06ItemPlafonAnggaran::class, 'pp06_periode_tahunan_id');
    }

    public function kodeBidangPelayanan(): HasMany
    {
        return $this->hasMany(Pp06KodeBidangPelayanan::class, 'pp06_periode_tahunan_id');
    }

    public function kodeSubBidangPelayanan(): HasMany
    {
        return $this->hasMany(Pp06KodeSubBidangPelayanan::class, 'pp06_periode_tahunan_id');
    }

    public function kodeKategoriPelayanan(): HasMany
    {
        return $this->hasMany(Pp06KodeKategoriPelayanan::class, 'pp06_periode_tahunan_id');
    }

    public function kodeJenisProgram(): HasMany
    {
        return $this->hasMany(Pp06KodeJenisProgram::class, 'pp06_periode_tahunan_id');
    }

    public function itemKuisioner(): HasMany
    {
        return $this->hasMany(Pp06ItemKuisioner::class, 'pp06_periode_tahunan_id');
    }

    public function itemDokumenSop(): HasMany
    {
        return $this->hasMany(Pp06ItemDokumenSop::class, 'pp06_periode_tahunan_id');
    }
}
