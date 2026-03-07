<?php

namespace App\Models\Pp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pp01Data extends Model
{
    protected $table = 'pp01_data';

    protected $fillable = [
        'pp_workflow_id',
        'tahun',
        'tanggal_mulai_pra_raker',
        'tanggal_penetapan_program',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_mulai_pra_raker' => 'date',
            'tanggal_penetapan_program' => 'date',
        ];
    }

    public function ppWorkflow(): BelongsTo
    {
        return $this->belongsTo(PpWorkflow::class);
    }

    public function kodeBidangPelayanan(): HasMany
    {
        return $this->hasMany(Pp01KodeBidangPelayanan::class, 'pp01_data_id');
    }

    public function kodeSubBidangPelayanan(): HasMany
    {
        return $this->hasMany(Pp01KodeSubBidangPelayanan::class, 'pp01_data_id');
    }

    public function kodeKategoriPelayanan(): HasMany
    {
        return $this->hasMany(Pp01KodeKategoriPelayanan::class, 'pp01_data_id');
    }

    public function kodeJenisProgram(): HasMany
    {
        return $this->hasMany(Pp01KodeJenisProgram::class, 'pp01_data_id');
    }
}
