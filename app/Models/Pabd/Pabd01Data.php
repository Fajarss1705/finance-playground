<?php

namespace App\Models\Pabd;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Pabd01Data extends Model
{
    protected $table = 'pabd01_data';

    protected $fillable = [
        'pabd_workflow_id',
        'ada_perubahan',
        'pk04_revisions_snapshot',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ada_perubahan' => 'boolean',
            'pk04_revisions_snapshot' => 'array',
        ];
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'attachable');
    }

    public function pabdWorkflow(): BelongsTo
    {
        return $this->belongsTo(PabdWorkflow::class);
    }

    public function itemAnggaran(): HasMany
    {
        return $this->hasMany(Pabd01ItemAnggaran::class, 'pabd01_data_id');
    }
}
