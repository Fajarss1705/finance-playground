<?php

namespace App\Models\Pp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pp03Data extends Model
{
    protected $table = 'pp03_data';

    protected $fillable = [
        'pp_workflow_id',
    ];

    public function ppWorkflow(): BelongsTo
    {
        return $this->belongsTo(PpWorkflow::class);
    }

    public function itemPlafonAnggaran(): HasMany
    {
        return $this->hasMany(Pp03ItemPlafonAnggaran::class, 'pp03_data_id');
    }
}
