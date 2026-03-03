<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\ActiveSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private ActiveSessionService $sessionService) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $activeRoleId = $this->sessionService->getActiveRoleId();
        $activeWorkspaceId = $this->sessionService->getActiveWorkspaceId();

        $notifications = Notification::query()
            ->forUser($user->id)
            ->with('role.team', 'workspace')
            ->latest()
            ->limit(20)
            ->get();

        $currentRole = $notifications->filter(
            fn (Notification $n) => $n->role_id === $activeRoleId && $n->workspace_id === $activeWorkspaceId
        )->values();

        $allRoles = $notifications->filter(
            fn (Notification $n) => $n->role_id !== $activeRoleId || $n->workspace_id !== $activeWorkspaceId
        )->values();

        return response()->json([
            'currentRole' => [
                'items' => $currentRole,
                'unreadCount' => $currentRole->where('is_read', false)->count(),
            ],
            'allRoles' => [
                'items' => $allRoles,
                'unreadCount' => $allRoles->where('is_read', false)->count(),
            ],
        ]);
    }

    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    public function go(Request $request, Notification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->markAsRead();

        return redirect($notification->link ?? route('personal.index'));
    }

    public function redirect(Request $request, Notification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $role = $notification->role;
        $workspace = $notification->workspace;

        $this->sessionService->switchTo($role, $workspace);
        $notification->markAsRead();

        return redirect($notification->link ?? route('personal.index'));
    }
}
