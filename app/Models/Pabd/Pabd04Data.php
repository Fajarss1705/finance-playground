<?php

namespace App\Models\Pabd;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Pabd04Data extends Model
{
    protected $table = 'pabd04_data';

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

    public function itemBuktiTransfer(): HasMany
    {
        return $this->hasMany(Pabd04ItemBuktiTransfer::class, 'pabd04_data_id');
    }
}
