<?php

namespace App\Models\Pabd;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Pabd02bData extends Model
{
    protected $table = 'pabd02b_data';

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

    public function itemReview(): HasMany
    {
        return $this->hasMany(Pabd02bItemReview::class, 'pabd02b_data_id');
    }
}
