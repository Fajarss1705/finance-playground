<?php

namespace App\Models\Pk;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pk04Kuisioner extends Model
{
    protected $table = 'pk04_kuisioner';

    protected $fillable = [
        'pk04_kegiatan_id',
        'kode_kuisioner',
        'pertanyaan',
        'tipe',
        'satuan',
    ];

    public function pk04Kegiatan(): BelongsTo
    {
        return $this->belongsTo(Pk04Kegiatan::class, 'pk04_kegiatan_id');
    }
}
