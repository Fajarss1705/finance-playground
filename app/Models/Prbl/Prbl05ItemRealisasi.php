<?php

namespace App\Models\Prbl;

use App\Models\Pk\Pk04Anggaran;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prbl05ItemRealisasi extends Model
{
    protected $table = 'prbl05_item_realisasi';

    protected $fillable = [
        'prbl05_laporan_bulanan_id',
        'pk04_anggaran_id',
        'nominal_anggaran',
        'nominal_realisasi',
        'selisih',
        'komentar_realisasi',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'nominal_anggaran' => 'decimal:2',
            'nominal_realisasi' => 'decimal:2',
            'selisih' => 'decimal:2',
        ];
    }

    public function prbl05LaporanBulanan(): BelongsTo
    {
        return $this->belongsTo(Prbl05LaporanBulanan::class, 'prbl05_laporan_bulanan_id');
    }

    public function pk04Anggaran(): BelongsTo
    {
        return $this->belongsTo(Pk04Anggaran::class, 'pk04_anggaran_id');
    }
}
