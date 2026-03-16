<?php

namespace App\Models\Pk;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pk05Data extends Model
{
    protected $table = 'pk05_data';

    protected $fillable = [
        'pk_workflow_id',
        'draft_data',
        'submitted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'draft_data' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function pkWorkflow(): BelongsTo
    {
        return $this->belongsTo(PkWorkflow::class);
    }
}
