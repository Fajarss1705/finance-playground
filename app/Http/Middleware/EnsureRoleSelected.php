<?php

namespace App\Http\Middleware;

use App\Services\ActiveSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRoleSelected
{
    public function __construct(private ActiveSessionService $sessionService) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->sessionService->hasActiveSession()) {
            return $next($request);
        }

        if (! $request->user()?->hasRoles()) {
            return redirect()->route('no-access');
        }

        return redirect()->route('role-selector.index');
    }
}
