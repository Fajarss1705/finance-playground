<?php

namespace App\Models\Pp;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Pp02Data extends Model
{
    protected $table = 'pp02_data';

    protected $fillable = [
        'pp_workflow_id',
    ];

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'attachable');
    }

    public function ppWorkflow(): BelongsTo
    {
        return $this->belongsTo(PpWorkflow::class);
    }

    public function itemKuisioner(): HasMany
    {
        return $this->hasMany(Pp02ItemKuisioner::class, 'pp02_data_id');
    }
}
