<?php

namespace App\Models\Prbl;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Prbl03Data extends Model
{
    protected $table = 'prbl03_data';

    protected $fillable = [
        'prbl_workflow_id',
        'nominal_refund',
        'keterangan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'nominal_refund' => 'decimal:2',
        ];
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'attachable');
    }

    public function prblWorkflow(): BelongsTo
    {
        return $this->belongsTo(PrblWorkflow::class);
    }

    public function bukti(): HasMany
    {
        return $this->hasMany(Prbl03Bukti::class, 'prbl03_data_id');
    }
}
