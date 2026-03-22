<?php

namespace App\Models\Prbl;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prbl05Bukti extends Model
{
    protected $table = 'prbl05_bukti';

    protected $fillable = [
        'prbl05_laporan_bulanan_id',
        'tipe',
        'file_id',
    ];

    public function prbl05LaporanBulanan(): BelongsTo
    {
        return $this->belongsTo(Prbl05LaporanBulanan::class, 'prbl05_laporan_bulanan_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
