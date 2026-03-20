<?php

namespace App\Models\Prbl;

use App\Models\Pk\Pk04Anggaran;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prbl01ItemRealisasi extends Model
{
    protected $table = 'prbl01_item_realisasi';

    protected $fillable = [
        'prbl01_data_id',
        'pk04_anggaran_id',
        'nominal_realisasi',
        'komentar_realisasi',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'nominal_realisasi' => 'decimal:2',
        ];
    }

    public function prbl01Data(): BelongsTo
    {
        return $this->belongsTo(Prbl01Data::class, 'prbl01_data_id');
    }

    public function pk04Anggaran(): BelongsTo
    {
        return $this->belongsTo(Pk04Anggaran::class, 'pk04_anggaran_id');
    }
}
