<?php

namespace App\Models\Pp;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pp06ItemDokumenSop extends Model
{
    protected $table = 'pp06_item_dokumen_sop';

    protected $fillable = ['pp06_periode_tahunan_id', 'file_id'];

    public function pp06PeriodeTahunan(): BelongsTo
    {
        return $this->belongsTo(Pp06PeriodeTahunan::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
