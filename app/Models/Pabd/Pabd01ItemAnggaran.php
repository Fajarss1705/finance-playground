<?php

namespace App\Models\Pabd;

use App\Models\Pk\Pk04Anggaran;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pabd01ItemAnggaran extends Model
{
    protected $table = 'pabd01_item_anggaran';

    protected $fillable = [
        'pabd01_data_id',
        'pk04_anggaran_id',
        'dicairkan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dicairkan' => 'boolean',
        ];
    }

    public function pabd01Data(): BelongsTo
    {
        return $this->belongsTo(Pabd01Data::class, 'pabd01_data_id');
    }

    public function pk04Anggaran(): BelongsTo
    {
        return $this->belongsTo(Pk04Anggaran::class, 'pk04_anggaran_id');
    }
}
