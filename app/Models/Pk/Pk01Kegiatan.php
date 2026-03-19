<?php

namespace App\Models\Pk;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pk01Kegiatan extends Model
{
    protected $table = 'pk01_kegiatan';

    protected $fillable = [
        'pk01_data_id',
        'nama_kegiatan',
        'bulan',
    ];

    public function pk01Data(): BelongsTo
    {
        return $this->belongsTo(Pk01Data::class, 'pk01_data_id');
    }

    public function anggaran(): HasMany
    {
        return $this->hasMany(Pk01Anggaran::class, 'pk01_kegiatan_id');
    }

    public function kuisioner(): HasMany
    {
        return $this->hasMany(Pk01Kuisioner::class, 'pk01_kegiatan_id');
    }
}
