<?php

namespace App\Models\Prbl;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prbl05LaporanBulanan extends Model
{
    protected $table = 'prbl05_laporan_bulanan';

    protected $fillable = [
        'prbl_workflow_id',
        'verification_code',
        'prbl01_created_by_user_name',
        'prbl01_created_by_role_name',
        'prbl01_created_by_team_name',
        'prbl01_created_by_organization_name',
        'prbl01_created_by_workspace_name',
        'prbl01_created_at',
        'prbl02a_approved_by_user_name',
        'prbl02a_approved_by_role_name',
        'prbl02a_approved_by_team_name',
        'prbl02a_approved_by_organization_name',
        'prbl02a_approved_by_workspace_name',
        'prbl02a_approved_at',
        'prbl02b_approved_by_user_name',
        'prbl02b_approved_by_role_name',
        'prbl02b_approved_by_team_name',
        'prbl02b_approved_by_organization_name',
        'prbl02b_approved_by_workspace_name',
        'prbl02b_approved_at',
        'prbl03_created_by_user_name',
        'prbl03_created_by_role_name',
        'prbl03_created_by_team_name',
        'prbl03_created_by_organization_name',
        'prbl03_created_by_workspace_name',
        'prbl03_created_at',
        'prbl04_approved_by_user_name',
        'prbl04_approved_by_role_name',
        'prbl04_approved_by_team_name',
        'prbl04_approved_by_organization_name',
        'prbl04_approved_by_workspace_name',
        'prbl04_approved_at',
        'keterangan',
        'total_anggaran_dicairkan',
        'total_realisasi',
        'total_refund',
        'total_item',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'prbl01_created_at' => 'datetime',
            'prbl02a_approved_at' => 'datetime',
            'prbl02b_approved_at' => 'datetime',
            'prbl03_created_at' => 'datetime',
            'prbl04_approved_at' => 'datetime',
            'total_anggaran_dicairkan' => 'decimal:2',
            'total_realisasi' => 'decimal:2',
            'total_refund' => 'decimal:2',
        ];
    }

    public function prblWorkflow(): BelongsTo
    {
        return $this->belongsTo(PrblWorkflow::class);
    }

    public function itemKegiatan(): HasMany
    {
        return $this->hasMany(Prbl05ItemKegiatan::class, 'prbl05_laporan_bulanan_id');
    }

    public function itemRealisasi(): HasMany
    {
        return $this->hasMany(Prbl05ItemRealisasi::class, 'prbl05_laporan_bulanan_id');
    }

    public function rekeningOrganisasi(): HasMany
    {
        return $this->hasMany(Prbl05RekeningOrganisasi::class, 'prbl05_laporan_bulanan_id');
    }

    public function bukti(): HasMany
    {
        return $this->hasMany(Prbl05Bukti::class, 'prbl05_laporan_bulanan_id');
    }
}
