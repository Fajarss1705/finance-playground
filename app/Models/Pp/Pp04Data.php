<?php

namespace App\Models\Pp;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Pp04Data extends Model
{
    protected $table = 'pp04_data';

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

    public function itemDokumen(): HasMany
    {
        return $this->hasMany(Pp04ItemDokumen::class, 'pp04_data_id');
    }
}
