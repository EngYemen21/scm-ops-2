<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `auth.api` — verifies the bearer access token and attaches the AuthUser (live roles/permissions) to the request. */
class AuthenticateApi
{
    public function __construct(private readonly AuthService $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->auth->authenticate($request->bearerToken(), $request->attributes->get('requestId'));
        $request->attributes->set('authUser', $user);

        return $next($request);
    }
}
