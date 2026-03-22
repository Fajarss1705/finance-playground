<?php

namespace App\Models\Prbl;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prbl05RekeningOrganisasi extends Model
{
    protected $table = 'prbl05_rekening_organisasi';

    protected $fillable = [
        'prbl05_laporan_bulanan_id',
        'nama_bank',
        'nama_rekening',
        'nomor_rekening',
    ];

    public function prbl05LaporanBulanan(): BelongsTo
    {
        return $this->belongsTo(Prbl05LaporanBulanan::class, 'prbl05_laporan_bulanan_id');
    }
}
