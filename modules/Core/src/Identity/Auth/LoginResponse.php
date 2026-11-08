<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Auth;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends each user to the page for their kind (or where they were going).
 */
final class LoginResponse implements LoginResponseContract, TwoFactorLoginResponse
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false]);
        }

        $user = $request->user();

        return redirect()->intended($user instanceof User ? LandingPage::for($user) : '/');
    }
}
