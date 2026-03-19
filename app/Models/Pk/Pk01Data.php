<?php

namespace App\Models\Pk;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Pk01Data extends Model
{
    protected $table = 'pk01_data';

    protected $fillable = [
        'pk_workflow_id',
        'kode_kategori',
        'nama_program',
        'deskripsi_program',
        'tujuan_program',
    ];

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'attachable');
    }

    public function pkWorkflow(): BelongsTo
    {
        return $this->belongsTo(PkWorkflow::class);
    }

    public function kegiatan(): HasMany
    {
        return $this->hasMany(Pk01Kegiatan::class, 'pk01_data_id');
    }
}
