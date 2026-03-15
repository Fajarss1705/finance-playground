<?php

namespace App\Models\Pp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pp07Data extends Model
{
    protected $table = 'pp07_data';

    protected $fillable = [
        'pp_workflow_id',
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

    public function ppWorkflow(): BelongsTo
    {
        return $this->belongsTo(PpWorkflow::class);
    }
}
