<?php

namespace App\Http\Middleware;

use App\Services\ActiveSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function __construct(private ActiveSessionService $sessionService) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        if ($routeName === null) {
            return $next($request);
        }

        $permissions = $this->sessionService->getActivePermissions();

        if (! in_array($routeName, $permissions)) {
            abort(403);
        }

        return $next($request);
    }
}
