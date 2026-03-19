<?php

namespace App\Services;

use App\Models\File;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;

class HistoryFormatter
{
    /**
     * Format raw history entries for frontend consumption.
     *
     * Batch-loads users, roles, teams, and files referenced in history,
     * then enriches each entry with display names and file objects.
     *
     * @param  list<array<string, mixed>>  $history
     * @return list<array<string, mixed>>
     */
    public function format(array $history): array
    {
        if (empty($history)) {
            return [];
        }

        $userIds = [];
        $roleIds = [];
        $teamIds = [];
        $fileIds = [];

        foreach ($history as $entry) {
            if (! empty($entry['by'])) {
                $userIds[] = $entry['by'];
            }
            if (! empty($entry['role'])) {
                $roleIds[] = $entry['role'];
            }
            if (! empty($entry['team'])) {
                $teamIds[] = $entry['team'];
            }
            if (! empty($entry['files'])) {
                $fileIds = array_merge($fileIds, (array) $entry['files']);
            }
            if (! empty($entry['triggered_by']['user_id'])) {
                $userIds[] = $entry['triggered_by']['user_id'];
            }
        }

        $userCache = $this->loadUsers(array_unique($userIds));
        $roleCache = $this->loadRoles(array_unique($roleIds));
        $teamCache = $this->loadTeams(array_unique($teamIds));
        $fileCache = $this->loadFiles(array_unique($fileIds));

        return array_map(
            fn (array $entry) => $this->enrichEntry($entry, $userCache, $roleCache, $teamCache, $fileCache),
            $history,
        );
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function loadUsers(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return User::withTrashed()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function loadRoles(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return Role::withTrashed()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function loadTeams(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return Team::withTrashed()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function loadFiles(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return File::whereIn('id', $ids)
            ->get(['id', 'uuid', 'original_filename', 'size', 'path'])
            ->keyBy('id')
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<int, string>  $userCache
     * @param  array<int, string>  $roleCache
     * @param  array<int, string>  $teamCache
     * @param  array<int, array<string, mixed>>  $fileCache
     * @return array<string, mixed>
     */
    private function enrichEntry(
        array $entry,
        array $userCache,
        array $roleCache,
        array $teamCache,
        array $fileCache,
    ): array {
        $userId = $entry['by'] ?? null;
        $entry['by_name'] = $userId
            ? ($userCache[$userId] ?? 'Pengguna dihapus')
            : 'Sistem';

        $roleId = $entry['role'] ?? null;
        $entry['role_name'] = $roleId
            ? ($roleCache[$roleId] ?? null)
            : null;

        $teamId = $entry['team'] ?? null;
        $entry['team_name'] = $teamId
            ? ($teamCache[$teamId] ?? null)
            : null;

        if (! empty($entry['files'])) {
            $entry['files'] = array_values(array_filter(
                array_map(fn ($id) => $fileCache[$id] ?? null, (array) $entry['files']),
            ));
        }

        if (! empty($entry['triggered_by'])) {
            $entry['triggered_by_text'] = $this->formatTriggeredBy(
                $entry['triggered_by'],
                $userCache,
            );
        }

        return $entry;
    }

    /**
     * Format a triggered_by object into a human-readable string.
     *
     * @param  array{user_id?: int, step?: string, action?: string}  $triggeredBy
     * @param  array<int, string>  $userCache
     */
    private function formatTriggeredBy(array $triggeredBy, array $userCache): string
    {
        $userName = isset($triggeredBy['user_id'])
            ? ($userCache[$triggeredBy['user_id']] ?? 'Pengguna dihapus')
            : 'Sistem';

        $step = $triggeredBy['step'] ?? '';
        $action = $triggeredBy['action'] ?? '';

        $actionLabel = match ($action) {
            'submitted' => 'disubmit',
            'approved' => 'disetujui',
            'rejected' => 'ditolak',
            'completed' => 'diselesaikan',
            'reset' => 'direset',
            default => "di-{$action}",
        };

        return "Dipicu oleh: {$step} {$actionLabel} oleh {$userName}";
    }
}
