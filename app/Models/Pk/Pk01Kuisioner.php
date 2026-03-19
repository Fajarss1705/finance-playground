<?php

namespace App\Models\Pk;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pk01Kuisioner extends Model
{
    protected $table = 'pk01_kuisioner';

    protected $fillable = [
        'pk01_kegiatan_id',
        'kode_kuisioner',
        'pertanyaan',
        'tipe',
        'satuan',
    ];

    public function pk01Kegiatan(): BelongsTo
    {
        return $this->belongsTo(Pk01Kegiatan::class, 'pk01_kegiatan_id');
    }
}
