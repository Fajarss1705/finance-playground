<?php

namespace App\Models\Pk;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pk04Anggaran extends Model
{
    protected $table = 'pk04_anggaran';

    protected $fillable = [
        'pk04_kegiatan_id',
        'kode_bidang',
        'kode_sub_bidang',
        'kode_jenis',
        'mata_anggaran',
        'deskripsi_pk',
        'nominal_anggaran',
        'nomer_anggaran',
        'revisi_terakhir',
        'kode_anggaran_baru',
        'kode_anggaran_lama',
        'status_item',
        'previous_anggaran_id',
        'source',
        'source_pabd_workflow_id',
        'status_pencairan',
        'tanggal_pencairan',
        'pencairan_pabd_workflow_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'nominal_anggaran' => 'decimal:2',
            'tanggal_pencairan' => 'date',
        ];
    }

    public function pk04Kegiatan(): BelongsTo
    {
        return $this->belongsTo(Pk04Kegiatan::class, 'pk04_kegiatan_id');
    }

    public function previousAnggaran(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_anggaran_id');
    }

    public function catatanPerubahan(): HasMany
    {
        return $this->hasMany(Pk04AnggaranCatatanPerubahan::class, 'pk04_anggaran_id');
    }
}
