<?php

namespace App\Http\Controllers\Api\Users;

use App\Http\Controllers\Controller;
use App\Services\Users\UsersService;
use App\Support\AuthUser;
use Illuminate\Http\Request;

/** User administration. Every route needs `user.manage` (declared in routes/api/users.php). */
class UsersController extends Controller
{
    public function __construct(private readonly UsersService $users) {}

    public function index(): array
    {
        return $this->users->list();
    }

    public function roles(): array
    {
        return $this->users->roles();
    }

    /** The permission catalogue (keys), same list the reference exposes from its shared package. */
    public function permissions(): array
    {
        return config('scm.PERMISSIONS');
    }

    public function store(Request $request): array
    {
        $data = $request->validate([
            'username' => 'required|string|min:3', 'password' => 'required|string|min:8', 'nameAr' => 'required|string|min:1', 'nameEn' => 'required|string|min:1',
            'email' => 'nullable|email', 'roles' => 'required|array|min:1', 'roles.*' => 'string', 'warehouses' => 'nullable|array', 'warehouses.*' => 'string',
        ]);

        return $this->users->create(AuthUser::current(), $data);
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate([
            'nameAr' => 'nullable|string', 'nameEn' => 'nullable|string', 'email' => 'nullable|email', 'active' => 'sometimes|boolean', 'roles' => 'sometimes|array', 'roles.*' => 'string',
            'warehouses' => 'sometimes|array', 'warehouses.*' => 'string', 'password' => 'nullable|string|min:8',
        ]);
        if (isset($data['active'])) {
            $data['active'] = filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
        }

        return $this->users->update(AuthUser::current(), $id, $data);
    }

    public function setRolePermissions(Request $request, string $key): array
    {
        $data = $request->validate(['permissions' => 'present|array', 'permissions.*' => 'string']);

        return $this->users->setRolePermissions(AuthUser::current(), $key, array_values($data['permissions']));
    }
}
