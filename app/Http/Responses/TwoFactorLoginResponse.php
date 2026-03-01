<?php

namespace App\Http\Responses;

use App\Services\ActiveSessionService;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function __construct(private ActiveSessionService $sessionService) {}

    public function toResponse($request): Response
    {
        $user = $request->user();

        if (! $user->hasRoles()) {
            $url = route('no-access');
        } elseif ($this->sessionService->hasActiveSession()) {
            $url = route('dashboard');
        } else {
            $url = route('role-selector.index');
        }

        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => true, 'redirect' => $url], 200)
            : redirect()->intended($url);
    }
}
