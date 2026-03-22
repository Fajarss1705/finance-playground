<?php

namespace App\Models\Prbl;

use App\Models\Pk\Pk04Kuisioner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prbl05ItemKuisioner extends Model
{
    protected $table = 'prbl05_item_kuisioner';

    protected $fillable = [
        'prbl05_item_kegiatan_id',
        'pk04_kuisioner_id',
        'jawaban',
    ];

    public function prbl05ItemKegiatan(): BelongsTo
    {
        return $this->belongsTo(Prbl05ItemKegiatan::class, 'prbl05_item_kegiatan_id');
    }

    public function pk04Kuisioner(): BelongsTo
    {
        return $this->belongsTo(Pk04Kuisioner::class, 'pk04_kuisioner_id');
    }
}
