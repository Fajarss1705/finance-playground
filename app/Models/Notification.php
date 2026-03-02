<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role_id',
        'workspace_id',
        'subject',
        'body',
        'email_html',
        'link',
        'is_read',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @param  Builder<Notification>  $query
     */
    public function scopeUnread(Builder $query): void
    {
        $query->where('is_read', false);
    }

    /**
     * @param  Builder<Notification>  $query
     */
    public function scopeForUser(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }

    /**
     * @param  Builder<Notification>  $query
     */
    public function scopeForContext(Builder $query, int $roleId, int $workspaceId): void
    {
        $query->where('role_id', $roleId)->where('workspace_id', $workspaceId);
    }

    public function markAsRead(): void
    {
        $this->update(['is_read' => true]);
    }
}
