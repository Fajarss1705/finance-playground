<?php

namespace App\Models\Pabd;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Pabd02aData extends Model
{
    protected $table = 'pabd02a_data';

    protected $fillable = [
        'pabd_workflow_id',
    ];

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'attachable');
    }

    public function pabdWorkflow(): BelongsTo
    {
        return $this->belongsTo(PabdWorkflow::class);
    }

    public function itemPerubahan(): HasMany
    {
        return $this->hasMany(Pabd02aItemPerubahan::class, 'pabd02a_data_id');
    }
}
