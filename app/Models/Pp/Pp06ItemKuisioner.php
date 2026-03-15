<?php

namespace App\Models\Pp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pp06ItemKuisioner extends Model
{
    protected $table = 'pp06_item_kuisioner';

    protected $fillable = ['pp06_periode_tahunan_id', 'kode', 'pertanyaan', 'tipe', 'satuan'];

    public function pp06PeriodeTahunan(): BelongsTo
    {
        return $this->belongsTo(Pp06PeriodeTahunan::class);
    }
}
