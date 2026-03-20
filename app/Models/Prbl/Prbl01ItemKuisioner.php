<?php

namespace App\Models\Prbl;

use App\Models\Pk\Pk04Kuisioner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prbl01ItemKuisioner extends Model
{
    protected $table = 'prbl01_item_kuisioner';

    protected $fillable = [
        'prbl01_item_kegiatan_id',
        'pk04_kuisioner_id',
        'jawaban',
    ];

    public function prbl01ItemKegiatan(): BelongsTo
    {
        return $this->belongsTo(Prbl01ItemKegiatan::class, 'prbl01_item_kegiatan_id');
    }

    public function pk04Kuisioner(): BelongsTo
    {
        return $this->belongsTo(Pk04Kuisioner::class, 'pk04_kuisioner_id');
    }
}
