<?php

use App\Models\File;
use App\Models\Role;
use App\Models\User;
use App\Services\HistoryFormatter;

beforeEach(function () {
    $this->formatter = new HistoryFormatter;
});

it('returns empty array for empty history', function () {
    expect($this->formatter->format([]))->toBe([]);
});

it('enriches entries with user, role, and team names', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();
    $team = $role->team;

    $history = [
        [
            'step' => 'PP01',
            'action' => 'submitted',
            'by' => $user->id,
            'role' => $role->id,
            'team' => $team->id,
            'at' => now()->toIso8601String(),
        ],
    ];

    $result = $this->formatter->format($history);

    expect($result)->toHaveCount(1);
    expect($result[0]['by_name'])->toBe($user->name);
    expect($result[0]['role_name'])->toBe($role->name);
    expect($result[0]['team_name'])->toBe($team->name);
});

it('shows Sistem for system entries with no user', function () {
    $history = [
        [
            'step' => 'PP06',
            'action' => 'completed',
            'at' => now()->toIso8601String(),
        ],
    ];

    $result = $this->formatter->format($history);

    expect($result[0]['by_name'])->toBe('Sistem');
    expect($result[0]['role_name'])->toBeNull();
    expect($result[0]['team_name'])->toBeNull();
});

it('falls back to Pengguna dihapus for deleted user', function () {
    $user = User::factory()->create();
    $userId = $user->id;
    $user->forceDelete();

    $history = [
        [
            'step' => 'PP01',
            'action' => 'drafted',
            'by' => $userId,
            'at' => now()->toIso8601String(),
        ],
    ];

    $result = $this->formatter->format($history);

    expect($result[0]['by_name'])->toBe('Pengguna dihapus');
});

it('returns null role_name for deleted role', function () {
    $role = Role::factory()->create();
    $roleId = $role->id;
    $role->delete();

    $history = [
        [
            'step' => 'PP01',
            'action' => 'drafted',
            'by' => null,
            'role' => $roleId,
            'at' => now()->toIso8601String(),
        ],
    ];

    $result = $this->formatter->format($history);

    expect($result[0]['role_name'])->toBe($role->name);
});

it('resolves file IDs to file objects with path', function () {
    $file = File::factory()->create();

    $history = [
        [
            'step' => 'PP01',
            'action' => 'submitted',
            'by' => null,
            'at' => now()->toIso8601String(),
            'files' => [$file->id],
        ],
    ];

    $result = $this->formatter->format($history);

    expect($result[0]['files'])->toHaveCount(1);
    expect($result[0]['files'][0]['uuid'])->toBe($file->uuid);
    expect($result[0]['files'][0]['original_filename'])->toBe($file->original_filename);
    expect($result[0]['files'][0]['size'])->toBe($file->size);
    expect($result[0]['files'][0])->toHaveKey('path');
});

it('omits deleted files from resolved list', function () {
    $history = [
        [
            'step' => 'PP01',
            'action' => 'submitted',
            'by' => null,
            'at' => now()->toIso8601String(),
            'files' => [99999],
        ],
    ];

    $result = $this->formatter->format($history);

    expect($result[0]['files'])->toHaveCount(0);
});

it('resolves triggered_by to human-readable text', function () {
    $user = User::factory()->create();

    $history = [
        [
            'step' => 'PP06',
            'action' => 'completed',
            'by' => null,
            'at' => now()->toIso8601String(),
            'triggered_by' => [
                'user_id' => $user->id,
                'step' => 'PP05',
                'action' => 'approved',
            ],
        ],
    ];

    $result = $this->formatter->format($history);

    expect($result[0]['triggered_by_text'])
        ->toBe("Dipicu oleh: PP05 disetujui oleh {$user->name}");
});

it('handles triggered_by with deleted user', function () {
    $user = User::factory()->create();
    $userId = $user->id;
    $user->forceDelete();

    $history = [
        [
            'step' => 'PP06',
            'action' => 'completed',
            'by' => null,
            'at' => now()->toIso8601String(),
            'triggered_by' => [
                'user_id' => $userId,
                'step' => 'PP05',
                'action' => 'approved',
            ],
        ],
    ];

    $result = $this->formatter->format($history);

    expect($result[0]['triggered_by_text'])
        ->toBe('Dipicu oleh: PP05 disetujui oleh Pengguna dihapus');
});

it('handles mixed action types in batch', function () {
    $user = User::factory()->withRole()->create();
    $role = $user->roles->first();

    $history = [
        [
            'step' => 'PP01',
            'action' => 'created',
            'by' => $user->id,
            'role' => $role->id,
            'at' => now()->subHours(3)->toIso8601String(),
        ],
        [
            'step' => 'PP01',
            'action' => 'drafted',
            'by' => $user->id,
            'role' => $role->id,
            'at' => now()->subHours(2)->toIso8601String(),
            'notes' => 'Draft awal',
        ],
        [
            'step' => 'PP01',
            'action' => 'submitted',
            'by' => $user->id,
            'role' => $role->id,
            'at' => now()->subHour()->toIso8601String(),
        ],
        [
            'step' => 'pp01',
            'action' => 'commented',
            'by' => $user->id,
            'role' => $role->id,
            'at' => now()->toIso8601String(),
            'notes' => 'Komentar test',
            'source' => 'pp01',
        ],
    ];

    $result = $this->formatter->format($history);

    expect($result)->toHaveCount(4);
    expect($result[0]['by_name'])->toBe($user->name);
    expect($result[1]['notes'])->toBe('Draft awal');
    expect($result[3]['by_name'])->toBe($user->name);
});

it('does not set triggered_by_text when triggered_by is absent', function () {
    $history = [
        [
            'step' => 'PP01',
            'action' => 'submitted',
            'by' => null,
            'at' => now()->toIso8601String(),
        ],
    ];

    $result = $this->formatter->format($history);

    expect($result[0])->not->toHaveKey('triggered_by_text');
});

it('includes file path in resolved file objects', function () {
    $file = File::factory()->create();

    $history = [
        [
            'step' => 'PP06',
            'action' => 'completed',
            'by' => null,
            'at' => now()->toIso8601String(),
            'files' => [$file->id],
        ],
    ];

    $result = $this->formatter->format($history);

    expect($result[0]['files'][0]['path'])->toBe($file->path);
});
