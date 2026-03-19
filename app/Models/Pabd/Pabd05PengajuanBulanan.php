<?php

namespace App\Models\Pabd;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pabd05PengajuanBulanan extends Model
{
    protected $table = 'pabd05_pengajuan_bulanan';

    protected $fillable = [
        'pabd_workflow_id',
        'verification_code',
        'pabd01_created_by_user_name',
        'pabd01_created_by_role_name',
        'pabd01_created_by_team_name',
        'pabd01_created_by_organization_name',
        'pabd01_created_by_workspace_name',
        'pabd01_created_at',
        'pabd03_approved_by_user_name',
        'pabd03_approved_by_role_name',
        'pabd03_approved_by_team_name',
        'pabd03_approved_by_organization_name',
        'pabd03_approved_by_workspace_name',
        'pabd03_approved_at',
        'pabd04_created_by_user_name',
        'pabd04_created_by_role_name',
        'pabd04_created_by_team_name',
        'pabd04_created_by_organization_name',
        'pabd04_created_by_workspace_name',
        'pabd04_created_at',
        'nama_bank',
        'nama_rekening',
        'nomor_rekening',
        'total_anggaran_dicairkan',
        'total_item_dicairkan',
        'total_item_hangus',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pabd01_created_at' => 'datetime',
            'pabd03_approved_at' => 'datetime',
            'pabd04_created_at' => 'datetime',
            'total_anggaran_dicairkan' => 'decimal:2',
        ];
    }

    public function pabdWorkflow(): BelongsTo
    {
        return $this->belongsTo(PabdWorkflow::class);
    }

    public function itemAnggaran(): HasMany
    {
        return $this->hasMany(Pabd05ItemAnggaran::class, 'pabd05_pengajuan_bulanan_id');
    }

    public function buktiTransfer(): HasMany
    {
        return $this->hasMany(Pabd05BuktiTransfer::class, 'pabd05_pengajuan_bulanan_id');
    }
}
