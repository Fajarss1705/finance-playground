<?php

namespace App\Models\Pp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pp02ItemKuisioner extends Model
{
    protected $table = 'pp02_item_kuisioner';

    protected $fillable = [
        'pp02_data_id',
        'kode',
        'pertanyaan',
        'tipe',
        'satuan',
    ];

    public function pp02Data(): BelongsTo
    {
        return $this->belongsTo(Pp02Data::class);
    }
}
