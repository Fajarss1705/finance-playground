<?php

namespace App\Models\Pp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pp04Data extends Model
{
    protected $table = 'pp04_data';

    protected $fillable = [
        'pp_workflow_id',
    ];

    public function ppWorkflow(): BelongsTo
    {
        return $this->belongsTo(PpWorkflow::class);
    }

    public function itemDokumen(): HasMany
    {
        return $this->hasMany(Pp04ItemDokumen::class, 'pp04_data_id');
    }
}
