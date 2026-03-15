<?php

namespace App\Models\Pp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pp06KodeBidangPelayanan extends Model
{
    protected $table = 'pp06_kode_bidang_pelayanan';

    protected $fillable = ['pp06_periode_tahunan_id', 'kode', 'nama', 'catatan'];

    public function pp06PeriodeTahunan(): BelongsTo
    {
        return $this->belongsTo(Pp06PeriodeTahunan::class);
    }
}
