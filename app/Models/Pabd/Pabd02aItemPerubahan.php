<?php

namespace App\Models\Pabd;

use App\Models\File;
use App\Models\Pk\Pk04Anggaran;
use App\Models\Pk\PkWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Pabd02aItemPerubahan extends Model
{
    protected $table = 'pabd02a_item_perubahan';

    protected $fillable = [
        'pabd02a_data_id',
        'tipe_perubahan',
        'pk04_anggaran_id',
        'bulan_awal',
        'bulan_tujuan',
        'pk_workflow_id',
        'komentar',
    ];

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'attachable');
    }

    public function pabd02aData(): BelongsTo
    {
        return $this->belongsTo(Pabd02aData::class, 'pabd02a_data_id');
    }

    public function pk04Anggaran(): BelongsTo
    {
        return $this->belongsTo(Pk04Anggaran::class, 'pk04_anggaran_id');
    }

    public function pkWorkflow(): BelongsTo
    {
        return $this->belongsTo(PkWorkflow::class, 'pk_workflow_id');
    }
}
