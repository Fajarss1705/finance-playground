<?php

namespace App\Models\Pabd;

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

class PabdWorkflow extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'workspace_id',
        'team_id',
        'pp_workflow_id',
        'bulan_anggaran',
        'tahun_anggaran',
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

    public function pabd01Data(): HasMany
    {
        return $this->hasMany(Pabd01Data::class);
    }

    public function pabd02aData(): HasMany
    {
        return $this->hasMany(Pabd02aData::class);
    }

    public function pabd02bData(): HasMany
    {
        return $this->hasMany(Pabd02bData::class);
    }

    public function pabd04Data(): HasMany
    {
        return $this->hasMany(Pabd04Data::class);
    }

    public function pabd05PengajuanBulanan(): HasMany
    {
        return $this->hasMany(Pabd05PengajuanBulanan::class);
    }

    public function latestPabd01(): ?Pabd01Data
    {
        return $this->pabd01Data()->latest('id')->first();
    }

    public function latestPabd05(): ?Pabd05PengajuanBulanan
    {
        return $this->pabd05PengajuanBulanan()->latest('id')->first();
    }
}
