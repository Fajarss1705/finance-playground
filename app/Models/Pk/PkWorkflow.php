<?php

namespace App\Models\Pk;

use App\Models\File;
use App\Models\Pp\PpWorkflow;
use App\Models\Team;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PkWorkflow extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'workspace_id',
        'team_id',
        'pp_workflow_id',
        'tipe',
        'created_by_user_id',
        'created_by_role_id',
        'created_by_team_id',
        'created_by_org_id',
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

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function ppWorkflow(): BelongsTo
    {
        return $this->belongsTo(PpWorkflow::class);
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'attachable');
    }

    public function pk01Data(): HasMany
    {
        return $this->hasMany(Pk01Data::class);
    }

    public function pk04ProgramTahunan(): HasMany
    {
        return $this->hasMany(Pk04ProgramTahunan::class);
    }

    public function pk05Data(): HasMany
    {
        return $this->hasMany(Pk05Data::class);
    }

    public function latestPk01(): ?Pk01Data
    {
        return $this->pk01Data()->latest('id')->first();
    }

    public function latestPk04(): ?Pk04ProgramTahunan
    {
        return $this->pk04ProgramTahunan()->latest('revision')->first();
    }
}
