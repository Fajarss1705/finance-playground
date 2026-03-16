<?php

namespace App\Models\Pk;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pk04AnggaranCatatanPerubahan extends Model
{
    protected $table = 'pk04_anggaran_catatan_perubahan';

    protected $fillable = [
        'pk04_anggaran_id',
        'pabd_workflow_id',
        'tipe_perubahan',
        'catatan_pemohon',
        'catatan_approval',
    ];

    public function pk04Anggaran(): BelongsTo
    {
        return $this->belongsTo(Pk04Anggaran::class, 'pk04_anggaran_id');
    }
}
