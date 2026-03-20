<?php

namespace App\Models\Prbl;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prbl03Bukti extends Model
{
    protected $table = 'prbl03_bukti';

    protected $fillable = [
        'prbl03_data_id',
        'tipe',
        'file_id',
    ];

    public function prbl03Data(): BelongsTo
    {
        return $this->belongsTo(Prbl03Data::class, 'prbl03_data_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
