<?php

namespace App\Models\Pp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pp01KodeJenisProgram extends Model
{
    protected $table = 'pp01_kode_jenis_program';

    protected $fillable = [
        'pp01_data_id',
        'kode',
        'nama',
        'catatan',
    ];

    public function pp01Data(): BelongsTo
    {
        return $this->belongsTo(Pp01Data::class);
    }
}
