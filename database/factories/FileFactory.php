<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\File>
 */
class FileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();
        $ext = fake()->randomElement(['pdf', 'jpg', 'png', 'xlsx', 'docx']);
        $mimeMap = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];

        return [
            'uuid' => $uuid,
            'original_filename' => fake()->words(3, true).'.'.$ext,
            'filename' => $uuid.'.'.$ext,
            'mime_type' => $mimeMap[$ext],
            'size' => fake()->numberBetween(1024, 10 * 1024 * 1024),
            'disk' => 'local',
            'path' => 'files/1/'.now()->format('Y/m').'/'.$uuid.'.'.$ext,
            'user_id' => User::factory(),
            'workspace_id' => Workspace::factory(),
            'source_route' => null,
        ];
    }

    /**
     * Set the full context snapshot (role, team, organization).
     */
    public function withContext(int $roleId, int $teamId, int $organizationId): static
    {
        return $this->state(fn () => [
            'role_id' => $roleId,
            'team_id' => $teamId,
            'organization_id' => $organizationId,
        ]);
    }
}
