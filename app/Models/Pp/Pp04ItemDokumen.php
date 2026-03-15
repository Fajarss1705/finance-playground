<?php

namespace App\Models\Pp;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pp04ItemDokumen extends Model
{
    protected $table = 'pp04_item_dokumen';

    protected $fillable = [
        'pp04_data_id',
        'file_id',
    ];

    public function pp04Data(): BelongsTo
    {
        return $this->belongsTo(Pp04Data::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
