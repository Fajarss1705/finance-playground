<?php

namespace App\Models\Pk;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pk04Kegiatan extends Model
{
    protected $table = 'pk04_kegiatan';

    protected $fillable = [
        'pk04_program_tahunan_id',
        'nama_kegiatan',
        'bulan',
        'nomer_kegiatan',
        'source',
        'source_pabd_workflow_id',
        'previous_kegiatan_id',
    ];

    public function pk04ProgramTahunan(): BelongsTo
    {
        return $this->belongsTo(Pk04ProgramTahunan::class, 'pk04_program_tahunan_id');
    }

    public function previousKegiatan(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_kegiatan_id');
    }

    public function anggaran(): HasMany
    {
        return $this->hasMany(Pk04Anggaran::class, 'pk04_kegiatan_id');
    }

    public function kuisioner(): HasMany
    {
        return $this->hasMany(Pk04Kuisioner::class, 'pk04_kegiatan_id');
    }
}
