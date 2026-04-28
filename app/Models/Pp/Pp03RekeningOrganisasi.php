<?php

namespace App\Models\Pp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pp03RekeningOrganisasi extends Model
{
    protected $table = 'pp03_rekening_organisasi';

    protected $fillable = [
        'pp03_data_id',
        'nama_bank',
        'nama_rekening',
        'nomor_rekening',
        'catatan',
    ];

    public function pp03Data(): BelongsTo
    {
        return $this->belongsTo(Pp03Data::class);
    }
}
