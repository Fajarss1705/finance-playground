<?php

namespace App\Models\Pk;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pk04ProgramTahunan extends Model
{
    protected $table = 'pk04_program_tahunan';

    protected $fillable = [
        'pk_workflow_id',
        'revision',
        'pk01_created_by_user_name',
        'pk01_created_by_role_name',
        'pk01_created_by_team_name',
        'pk01_created_by_organization_name',
        'pk01_created_by_workspace_name',
        'pk01_created_at',
        'kode_kategori',
        'nama_program',
        'deskripsi_program',
        'tujuan_program',
        'nomer_program',
        'verification_code',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pk01_created_at' => 'datetime',
        ];
    }

    public function pkWorkflow(): BelongsTo
    {
        return $this->belongsTo(PkWorkflow::class);
    }

    public function kegiatan(): HasMany
    {
        return $this->hasMany(Pk04Kegiatan::class, 'pk04_program_tahunan_id');
    }
}
