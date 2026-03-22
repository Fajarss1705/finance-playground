<?php

namespace App\Models\Prbl;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Prbl01Data extends Model
{
    protected $table = 'prbl01_data';

    protected $fillable = [
        'prbl_workflow_id',
    ];

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'attachable');
    }

    public function prblWorkflow(): BelongsTo
    {
        return $this->belongsTo(PrblWorkflow::class);
    }

    public function itemKegiatan(): HasMany
    {
        return $this->hasMany(Prbl01ItemKegiatan::class, 'prbl01_data_id');
    }

    public function itemRealisasi(): HasMany
    {
        return $this->hasMany(Prbl01ItemRealisasi::class, 'prbl01_data_id');
    }
}
