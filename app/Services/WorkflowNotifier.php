<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class WorkflowNotifier
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    /**
     * Notify users after a workflow event.
     *
     * @param  array{actor_name?: string, actor_role?: string, link?: string, step_label?: string, next_instruction?: string}  $context
     */
    public function notify(
        Model $workflow,
        string $event,
        array $context = [],
        ?int $actorUserId = null,
    ): void {
        $workspace = $workflow->workspace;

        if (! $workspace) {
            Log::warning("WorkflowNotifier: no workspace for workflow #{$workflow->id}");

            return;
        }

        $recipients = $this->resolveRecipients($workspace, $workflow, $event);
        $template = $this->buildTemplate($workflow, $event, $context);

        $seen = [];

        if ($actorUserId !== null) {
            $seen[$actorUserId] = true;
        }

        $sent = 0;

        foreach ($recipients as $recipient) {
            $userId = $recipient['user']->id;

            if (isset($seen[$userId])) {
                continue;
            }

            $seen[$userId] = true;

            try {
                $this->notificationService->send(
                    $recipient['user'],
                    $recipient['role'],
                    $workspace,
                    $template['subject'],
                    $template['body'],
                    $template['link'],
                );
                $sent++;
            } catch (\Throwable $e) {
                Log::warning("WorkflowNotifier: failed to send to user #{$userId}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("WorkflowNotifier: {$event} — {$sent} notifications sent", [
            'workflow_id' => $workflow->id,
            'workflow_type' => $workflow->getMorphClass(),
        ]);
    }

    /**
     * Resolve recipients based on event type.
     *
     * @return list<array{user: User, role: Role}>
     */
    private function resolveRecipients(Workspace $workspace, Model $workflow, string $event): array
    {
        if (str_ends_with($event, '.terminated')) {
            return $this->resolveHistoryRecipients($workflow);
        }

        $targetPermissions = $this->getTargetPermission($event);

        if ($targetPermissions === null) {
            return [];
        }

        // Support single string or array of permissions (parallel fork)
        $permissions = is_array($targetPermissions) ? $targetPermissions : [$targetPermissions];

        $recipients = [];
        $seen = [];

        foreach ($permissions as $permission) {
            foreach ($this->resolvePermissionRecipients($workspace, $permission) as $r) {
                $key = $r['user']->id.':'.$r['role']->id;
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $recipients[] = $r;
                }
            }
        }

        return $recipients;
    }

    /**
     * Resolve recipients by permission (users with roles that have the target permission in this workspace).
     *
     * @return list<array{user: User, role: Role}>
     */
    private function resolvePermissionRecipients(Workspace $workspace, string $permission): array
    {
        $roles = Role::query()
            ->whereHas('permissions', fn ($q) => $q->where('name', $permission))
            ->whereHas('team.organization.workspaces', fn ($q) => $q->where('workspaces.id', $workspace->id))
            ->whereNull('deleted_at')
            ->with('users')
            ->get();

        $recipients = [];

        foreach ($roles as $role) {
            foreach ($role->users as $user) {
                if ($user->trashed()) {
                    continue;
                }

                $recipients[] = ['user' => $user, 'role' => $role];
            }
        }

        return $recipients;
    }

    /**
     * Resolve recipients from workflow history (terminate — all users who ever acted).
     *
     * @return list<array{user: User, role: Role}>
     */
    private function resolveHistoryRecipients(Model $workflow): array
    {
        $history = $workflow->history ?? [];
        $pairs = [];

        foreach ($history as $entry) {
            $userId = $entry['by'] ?? null;
            $roleId = $entry['role'] ?? null;

            if ($userId === null || $roleId === null) {
                continue;
            }

            $key = "{$userId}:{$roleId}";

            if (! isset($pairs[$key])) {
                $pairs[$key] = ['user_id' => $userId, 'role_id' => $roleId];
            }
        }

        $recipients = [];

        foreach ($pairs as $pair) {
            $user = User::find($pair['user_id']);
            $role = Role::find($pair['role_id']);

            if ($user && $role && ! $user->trashed()) {
                $recipients[] = ['user' => $user, 'role' => $role];
            }
        }

        return $recipients;
    }

    /**
     * Map event → target permission(s) for recipient resolution.
     *
     * @return string|list<string>|null
     */
    private function getTargetPermission(string $event): string|array|null
    {
        return match ($event) {
            // PP workflow events
            'pp01.submitted' => 'admin.workflows.pp.pp02.draft',
            'pp02.submitted' => 'admin.workflows.pp.pp03.draft',
            'pp03.submitted' => 'admin.workflows.pp.pp04.draft',
            'pp04.submitted' => 'admin.workflows.pp.pp05.approve',
            'pp05.approved' => 'admin.workflows.pp.pp06.show',
            'pp05.rejected' => 'admin.workflows.pp.pp01.draft',
            'pp07.submitted' => 'admin.workflows.pp.pp06.show',

            // PK workflow events (parallel fork: PK01 → PK02A + PK02B)
            'pk01.submitted' => [
                'admin.workflows.pk.pk02a.approve',
                'admin.workflows.pk.pk02b.approve',
            ],

            // PK02A + PK02B join gate outcomes
            'pk02.both_approved' => 'admin.workflows.pk.pk03.approve',
            'pk02.rejected' => 'team.workflows.pk.pk01.draft',

            default => null,
        };
    }

    /**
     * Build notification subject, body, and link from event and context.
     *
     * @param  array<string, mixed>  $context
     * @return array{subject: string, body: string, link: ?string}
     */
    private function buildTemplate(Model $workflow, string $event, array $context): array
    {
        $label = $context['label'] ?? $this->resolveLabel($workflow);
        $actorName = $context['actor_name'] ?? 'Sistem';
        $actorRole = $context['actor_role'] ?? null;
        $link = $context['link'] ?? null;

        $actorDisplay = $actorRole ? "{$actorName} ({$actorRole})" : $actorName;

        [$actionVerb, $stepLabel, $nextInstruction] = $this->getEventText($event, $context);

        $subject = "{$label}: {$stepLabel} telah {$actionVerb}";
        $body = "{$actorDisplay} telah {$actionVerb} {$stepLabel}.";

        if ($nextInstruction) {
            $body .= " {$nextInstruction}";
        }

        return [
            'subject' => $subject,
            'body' => $body,
            'link' => $link,
        ];
    }

    /**
     * Get action verb, step label, and next instruction for an event.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function getEventText(string $event, array $context): array
    {
        if (str_ends_with($event, '.terminated')) {
            return ['dibatalkan', 'Workflow', null];
        }

        return match ($event) {
            // PP workflow events
            'pp01.submitted' => ['disubmit', 'Rencana Periode (PP01)', 'Silakan lanjutkan pengisian Pertanyaan Kuisioner (PP02).'],
            'pp02.submitted' => ['disubmit', 'Pertanyaan Kuisioner (PP02)', 'Silakan lanjutkan pengisian Plafon Anggaran (PP03).'],
            'pp03.submitted' => ['disubmit', 'Plafon Anggaran (PP03)', 'Silakan lanjutkan pengisian Dokumen SOP (PP04).'],
            'pp04.submitted' => ['disubmit', 'Dokumen SOP (PP04)', 'Silakan review dan setujui/tolak Perencanaan Periode (PP05).'],
            'pp05.approved' => ['disetujui', 'Persetujuan (PP05)', 'Periode Tahunan (PP06) telah dikompilasi.'],
            'pp05.rejected' => ['ditolak', 'Persetujuan (PP05)', 'Flow dikembalikan ke PP01 untuk perbaikan.'],
            'pp07.submitted' => ['disubmit', 'Revisi (PP07)', 'Periode Tahunan (PP06) telah diperbarui.'],

            // PK workflow events
            'pk01.submitted' => ['disubmit', 'Program Kegiatan (PK01)', 'Silakan review dan approve Narasi (PK02A) dan Anggaran (PK02B).'],
            'pk02.both_approved' => ['disetujui', 'Approval Narasi & Anggaran (PK02A/PK02B)', 'Silakan lanjutkan ke RAKER (PK03).'],
            'pk02.rejected' => ['ditolak', 'Approval Narasi/Anggaran (PK02A/PK02B)', 'Flow dikembalikan ke PK01 untuk perbaikan.'],

            default => [
                $context['action_verb'] ?? 'diproses',
                $context['step_label'] ?? 'Step',
                $context['next_instruction'] ?? null,
            ],
        };
    }

    /**
     * Resolve workflow label from model data.
     */
    private function resolveLabel(Model $workflow): string
    {
        if (method_exists($workflow, 'latestPp01')) {
            $pp01 = $workflow->latestPp01();

            return $pp01?->tahun ? "PP-{$pp01->tahun}" : 'PP-Baru';
        }

        if (method_exists($workflow, 'latestPk01')) {
            $pk01 = $workflow->latestPk01();

            return $pk01?->nama_program ? "PK-{$pk01->nama_program}" : 'PK-Baru';
        }

        return 'Workflow';
    }
}
