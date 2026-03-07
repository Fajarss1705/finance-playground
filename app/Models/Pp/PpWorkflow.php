<?php

namespace App\Models\Pp;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PpWorkflow extends Model
{
    /** @use HasFactory<\Database\Factories\Pp\PpWorkflowFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'history',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'history' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function pp01Data(): HasMany
    {
        return $this->hasMany(Pp01Data::class);
    }

    public function pp02Data(): HasMany
    {
        return $this->hasMany(Pp02Data::class);
    }

    public function pp03Data(): HasMany
    {
        return $this->hasMany(Pp03Data::class);
    }

    public function pp04Data(): HasMany
    {
        return $this->hasMany(Pp04Data::class);
    }

    public function pp06PeriodeTahunan(): HasMany
    {
        return $this->hasMany(Pp06PeriodeTahunan::class);
    }

    public function pp07Data(): HasMany
    {
        return $this->hasMany(Pp07Data::class);
    }

    public function latestPp01(): ?Pp01Data
    {
        return $this->pp01Data()->latest('id')->first();
    }

    public function latestPp06(): ?Pp06PeriodeTahunan
    {
        return $this->pp06PeriodeTahunan()->latest('revision')->first();
    }
}
