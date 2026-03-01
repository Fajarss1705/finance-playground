<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_number',
        'avatar',
        'jabatan',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function hasRoles(): bool
    {
        return $this->roles()->exists();
    }

    /**
     * Get accessible workspaces with roles grouped under each.
     *
     * @return Collection<int, array{workspace: Workspace, roles: Collection<int, Role>}>
     */
    public function getAccessibleWorkspaces(): Collection
    {
        $roles = $this->roles()->with('team.organization.workspaces')->get();

        $workspaceMap = collect();

        foreach ($roles as $role) {
            foreach ($role->team->organization->workspaces as $workspace) {
                if (! $workspaceMap->has($workspace->id)) {
                    $workspaceMap->put($workspace->id, [
                        'workspace' => $workspace,
                        'roles' => collect(),
                    ]);
                }

                $workspaceMap[$workspace->id]['roles']->push($role);
            }
        }

        return $workspaceMap->values();
    }
}
