<?php

namespace App\Models\Prbl;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prbl05NotaPengeluaran extends Model
{
    protected $table = 'prbl05_nota_pengeluaran';

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
