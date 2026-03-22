<?php

namespace App\Models\Prbl;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prbl05FotoKegiatan extends Model
{
    protected $table = 'prbl05_foto_kegiatan';

    protected $fillable = [
        'prbl05_item_kegiatan_id',
        'file_id',
    ];

    public function prbl05ItemKegiatan(): BelongsTo
    {
        return $this->belongsTo(Prbl05ItemKegiatan::class, 'prbl05_item_kegiatan_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
