<?php

namespace App\Models\Prbl;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prbl01NotaPengeluaran extends Model
{
    protected $table = 'prbl01_nota_pengeluaran';

    protected $fillable = [
        'prbl01_item_kegiatan_id',
        'file_id',
    ];

    public function prbl01ItemKegiatan(): BelongsTo
    {
        return $this->belongsTo(Prbl01ItemKegiatan::class, 'prbl01_item_kegiatan_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
