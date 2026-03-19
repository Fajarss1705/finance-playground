<?php

namespace App\Models\Pabd;

use App\Models\Pk\Pk04Anggaran;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pabd05ItemAnggaran extends Model
{
    protected $table = 'pabd05_item_anggaran';

    protected $fillable = [
        'pabd05_pengajuan_bulanan_id',
        'pk04_anggaran_id',
        'status',
        'nominal_anggaran',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'nominal_anggaran' => 'decimal:2',
        ];
    }

    public function pabd05PengajuanBulanan(): BelongsTo
    {
        return $this->belongsTo(Pabd05PengajuanBulanan::class, 'pabd05_pengajuan_bulanan_id');
    }

    public function pk04Anggaran(): BelongsTo
    {
        return $this->belongsTo(Pk04Anggaran::class, 'pk04_anggaran_id');
    }
}
