<?php

namespace App\Models\Prbl;

use App\Models\Pk\Pk04Kegiatan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prbl01ItemKegiatan extends Model
{
    protected $table = 'prbl01_item_kegiatan';

    protected $fillable = [
        'prbl01_data_id',
        'pk04_kegiatan_id',
        'masalah',
        'langkah_penanganan',
        'harapan',
        'catatan_tim',
    ];

    public function prbl01Data(): BelongsTo
    {
        return $this->belongsTo(Prbl01Data::class, 'prbl01_data_id');
    }

    public function pk04Kegiatan(): BelongsTo
    {
        return $this->belongsTo(Pk04Kegiatan::class, 'pk04_kegiatan_id');
    }

    public function fotoKegiatan(): HasMany
    {
        return $this->hasMany(Prbl01FotoKegiatan::class, 'prbl01_item_kegiatan_id');
    }

    public function notaPengeluaran(): HasMany
    {
        return $this->hasMany(Prbl01NotaPengeluaran::class, 'prbl01_item_kegiatan_id');
    }

    public function itemKuisioner(): HasMany
    {
        return $this->hasMany(Prbl01ItemKuisioner::class, 'prbl01_item_kegiatan_id');
    }
}
