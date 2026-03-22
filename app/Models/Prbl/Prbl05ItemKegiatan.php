<?php

namespace App\Models\Prbl;

use App\Models\Pk\Pk04Kegiatan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prbl05ItemKegiatan extends Model
{
    protected $table = 'prbl05_item_kegiatan';

    protected $fillable = [
        'prbl05_laporan_bulanan_id',
        'pk04_kegiatan_id',
        'masalah',
        'langkah_penanganan',
        'harapan',
        'catatan_tim',
    ];

    public function prbl05LaporanBulanan(): BelongsTo
    {
        return $this->belongsTo(Prbl05LaporanBulanan::class, 'prbl05_laporan_bulanan_id');
    }

    public function pk04Kegiatan(): BelongsTo
    {
        return $this->belongsTo(Pk04Kegiatan::class, 'pk04_kegiatan_id');
    }

    public function fotoKegiatan(): HasMany
    {
        return $this->hasMany(Prbl05FotoKegiatan::class, 'prbl05_item_kegiatan_id');
    }

    public function notaPengeluaran(): HasMany
    {
        return $this->hasMany(Prbl05NotaPengeluaran::class, 'prbl05_item_kegiatan_id');
    }

    public function itemKuisioner(): HasMany
    {
        return $this->hasMany(Prbl05ItemKuisioner::class, 'prbl05_item_kegiatan_id');
    }
}
