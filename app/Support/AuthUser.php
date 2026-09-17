<?php

namespace App\Support;

/**
 * The authenticated caller, resolved once per request by the `auth.api` middleware from the live database
 * (roles/permissions are never trusted from the token). Controllers receive it with `AuthUser::current()`
 * and pass it to services, which use it for audit, activity and ownership checks.
 */
final class AuthUser
{
    /**
     * @param  string[]  $roles
     * @param  string[]  $permissions
     * @param  string[]  $warehouses  warehouse ids the user is scoped to (empty = all)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $username,
        public readonly string $nameAr,
        public readonly string $nameEn,
        public readonly array $roles,
        public readonly array $permissions,
        public readonly array $warehouses,
        public readonly ?string $driverId,
        public readonly ?string $requestId,
    ) {}

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function hasRole(string ...$roles): bool
    {
        return count(array_intersect($roles, $this->roles)) > 0;
    }

    /** The caller of the current request. Only valid behind the `auth.api` middleware. */
    public static function current(): self
    {
        $user = request()->attributes->get('authUser');
        if (! $user instanceof self) {
            throw AppError::unauthorized('NO_TOKEN', 'يلزم تسجيل الدخول', 'Authentication required');
        }

        return $user;
    }

    public static function currentOrNull(): ?self
    {
        $user = request()->attributes->get('authUser');

        return $user instanceof self ? $user : null;
    }
}
