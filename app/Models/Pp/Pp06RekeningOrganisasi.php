<?php

namespace App\Models\Pp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pp06RekeningOrganisasi extends Model
{
    protected $table = 'pp06_rekening_organisasi';

    protected $fillable = [
        'pp06_periode_tahunan_id',
        'nama_bank',
        'nama_rekening',
        'nomor_rekening',
    ];

    public function pp06PeriodeTahunan(): BelongsTo
    {
        return $this->belongsTo(Pp06PeriodeTahunan::class);
    }
}
