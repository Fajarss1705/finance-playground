<?php

namespace App\Models\Prbl;

use App\Models\File;
use App\Models\Pabd\PabdWorkflow;
use App\Models\Pp\PpWorkflow;
use App\Models\Team;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PrblWorkflow extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'workspace_id',
        'team_id',
        'pabd_workflow_id',
        'pp_workflow_id',
        'bulan_laporan',
        'tahun_laporan',
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

    public function pabdWorkflow(): BelongsTo
    {
        return $this->belongsTo(PabdWorkflow::class);
    }

    public function ppWorkflow(): BelongsTo
    {
        return $this->belongsTo(PpWorkflow::class);
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'attachable');
    }

    public function prbl01Data(): HasMany
    {
        return $this->hasMany(Prbl01Data::class);
    }

    public function prbl03Data(): HasMany
    {
        return $this->hasMany(Prbl03Data::class);
    }

    public function prbl05LaporanBulanan(): HasMany
    {
        return $this->hasMany(Prbl05LaporanBulanan::class);
    }

    public function latestPrbl01(): ?Prbl01Data
    {
        return $this->prbl01Data()->latest('id')->first();
    }

    public function latestPrbl03(): ?Prbl03Data
    {
        return $this->prbl03Data()->latest('id')->first();
    }

    public function latestPrbl05(): ?Prbl05LaporanBulanan
    {
        return $this->prbl05LaporanBulanan()->latest('id')->first();
    }
}
