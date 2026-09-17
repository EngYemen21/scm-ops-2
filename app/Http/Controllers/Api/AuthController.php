<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Support\AuthUser;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(Request $request): array
    {
        $data = $request->validate(['username' => 'required|string|min:1', 'password' => 'required|string|min:1']);

        return $this->auth->login($data['username'], $data['password'], $request->attributes->get('requestId'));
    }

    public function refresh(Request $request): array
    {
        $data = $request->validate(['refreshToken' => 'required|string|min:10']);

        return $this->auth->refresh($data['refreshToken']);
    }

    public function logout(Request $request): array
    {
        return $this->auth->logout($request->input('refreshToken'));
    }

    public function me(): array
    {
        return $this->auth->me(AuthUser::current()->id);
    }

    public function changePassword(Request $request): array
    {
        $data = $request->validate(['currentPassword' => 'required|string', 'newPassword' => 'required|string|min:8']);

        return $this->auth->changePassword(AuthUser::current()->id, $data['currentPassword'], $data['newPassword']);
    }
}
