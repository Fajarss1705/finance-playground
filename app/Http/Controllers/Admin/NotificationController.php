<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\ActiveSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function __construct(private ActiveSessionService $sessionService) {}

    public function index(Request $request): Response
    {
        $query = Notification::query()
            ->where('workspace_id', $this->sessionService->getActiveWorkspaceId())
            ->with(['user:id,name', 'role.team'])
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

        return Inertia::render('admin/notifications/index', [
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
}
