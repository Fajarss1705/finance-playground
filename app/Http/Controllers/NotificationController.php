<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\ActiveSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

    public function personal(Request $request): Response
    {
        $query = Notification::query()
            ->forUser($request->user()->id)
            ->where('workspace_id', $this->sessionService->getActiveWorkspaceId())
            ->with('role.team')
            ->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            match ($request->input('status')) {
                'unread' => $query->where('is_read', false),
                'read' => $query->where('is_read', true),
                default => null,
            };
        }

        return Inertia::render('personal/notifications', [
            'notifications' => $query->paginate(15)->withQueryString(),
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
            ],
        ]);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        Notification::query()
            ->forUser($request->user()->id)
            ->where('workspace_id', $this->sessionService->getActiveWorkspaceId())
            ->unread()
            ->update(['is_read' => true]);

        return back();
    }

    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    public function show(Request $request, Notification $notification): Response
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        abort_unless($notification->workspace_id === $this->sessionService->getActiveWorkspaceId(), 403);

        // Switch role if notification is from a different role
        if ($notification->role_id !== $this->sessionService->getActiveRoleId()) {
            $role = $request->user()->roles()->find($notification->role_id);
            abort_unless($role, 403);
            $this->sessionService->switchTo($role, $notification->workspace);
        }

        $notification->markAsRead();
        $notification->load('role.team');

        return Inertia::render('personal/notifications/show', [
            'notification' => $notification,
        ]);
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
