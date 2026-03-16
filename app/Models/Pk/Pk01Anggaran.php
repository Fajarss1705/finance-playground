<?php

namespace App\Models\Pk;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pk01Anggaran extends Model
{
    protected $table = 'pk01_anggaran';

    protected $fillable = [
        'pk01_kegiatan_id',
        'kode_bidang',
        'kode_sub_bidang',
        'kode_jenis',
        'mata_anggaran',
        'deskripsi_pk',
        'nominal_anggaran',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'nominal_anggaran' => 'decimal:2',
        ];
    }

    public function pk01Kegiatan(): BelongsTo
    {
        return $this->belongsTo(Pk01Kegiatan::class, 'pk01_kegiatan_id');
    }
}
