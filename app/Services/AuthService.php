<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\RefreshToken;
use App\Models\User;
use App\Support\AppError;
use App\Support\AuthUser;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Sessions: a short-lived signed access token (JWT, HS256) + an opaque refresh token stored hashed.
 *
 * Refresh tokens rotate on use. A token rotated a moment ago is still accepted once inside a short grace window,
 * because the reply to the first refresh can be lost (page unload, a second tab racing) and that must not log the
 * user out. Explicit logout and password change kill the token outright (expires_at too), so grace never applies.
 */
class AuthService
{
    public function login(string $username, string $password, ?string $requestId): array
    {
        $username = mb_strtolower(trim($username));
        $user = User::where('username', $username)->first();
        if (! $user || ! $user->active || ! Hash::check($password, $user->password_hash)) {
            AuditLog::create(['username' => $username, 'action' => 'SECURITY.LOGIN_FAILED', 'entity_type' => 'user', 'request_id' => $requestId]);
            throw AppError::unauthorized('BAD_CREDENTIALS', 'اسم المستخدم أو كلمة المرور غير صحيحة', 'Invalid username or password');
        }
        $user->update(['last_login_at' => now()]);
        AuditLog::create(['user_id' => $user->id, 'username' => $user->username, 'action' => 'SECURITY.LOGIN', 'entity_type' => 'user', 'entity_id' => $user->id, 'request_id' => $requestId]);

        return $this->issue($user->id);
    }

    public function refresh(string $refreshToken): array
    {
        $rec = RefreshToken::where('token_hash', self::sha($refreshToken))->first();
        $graceMs = (int) config('scm_auth.refresh_rotation_grace_ms');
        $withinGrace = $rec?->revoked_at !== null && $rec->revoked_at->diffInMilliseconds(now(), true) <= $graceMs;
        if (! $rec || ($rec->revoked_at !== null && ! $withinGrace) || $rec->expires_at->isPast()) {
            throw AppError::unauthorized('REFRESH_INVALID', 'انتهت الجلسة — سجّل الدخول مجددًا', 'Session expired');
        }
        if ($rec->revoked_at === null) {
            $rec->update(['revoked_at' => now()]); // rotation
        }

        return $this->issue($rec->user_id);
    }

    public function logout(?string $refreshToken): array
    {
        if ($refreshToken) {
            RefreshToken::where('token_hash', self::sha($refreshToken))->whereNull('revoked_at')->update(['revoked_at' => now(), 'expires_at' => now()]);
        }

        return ['ok' => true];
    }

    public function changePassword(string $userId, string $current, string $next): array
    {
        $user = User::findOrFail($userId);
        if (! Hash::check($current, $user->password_hash)) {
            throw AppError::validation('BAD_PASSWORD', 'كلمة المرور الحالية غير صحيحة', 'Current password is wrong');
        }
        if (mb_strlen($next) < 8) {
            throw AppError::validation('WEAK_PASSWORD', 'كلمة المرور 8 أحرف على الأقل', 'Password must be at least 8 characters');
        }
        $user->update(['password_hash' => Hash::make($next), 'must_change_password' => false]);
        RefreshToken::where('user_id', $userId)->whereNull('revoked_at')->update(['revoked_at' => now(), 'expires_at' => now()]);

        return ['ok' => true];
    }

    /** Profile + live roles / permissions / navigation for the client. */
    public function me(string $userId): array
    {
        $u = User::with(['roles.role.permissions.permission', 'warehouses.warehouse', 'driver'])->findOrFail($userId);
        $roles = $u->roles->map(fn ($r) => $r->role->key)->values()->all();
        $permissions = $u->roles->flatMap(fn ($r) => $r->role->permissions->map(fn ($p) => $p->permission->key))->unique()->values()->all();
        $nav = collect($roles)->flatMap(fn ($r) => config('scm.ROLE_NAV.'.$r, []))->unique()->values()->all();

        return [
            'id' => $u->id, 'username' => $u->username, 'nameAr' => $u->name_ar, 'nameEn' => $u->name_en, 'initials' => $u->initials,
            'roles' => $roles, 'permissions' => $permissions, 'nav' => $nav, 'mustChangePassword' => (bool) $u->must_change_password,
            'warehouses' => $u->warehouses->map(fn ($w) => ['id' => $w->warehouse->id, 'code' => $w->warehouse->code, 'nameAr' => $w->warehouse->name_ar, 'nameEn' => $w->warehouse->name_en])->values()->all(),
            'driverId' => $u->driver?->id,
        ];
    }

    /** Resolves the caller of a request from its bearer token; roles and permissions always come from the database. */
    public function authenticate(?string $bearer, ?string $requestId): AuthUser
    {
        if (! $bearer) {
            throw AppError::unauthorized('NO_TOKEN', 'يلزم تسجيل الدخول', 'Authentication required');
        }
        try {
            $payload = JWT::decode($bearer, new Key(config('scm_auth.access_secret'), 'HS256'));
        } catch (Throwable) {
            throw AppError::unauthorized('INVALID_TOKEN', 'انتهت الجلسة — سجّل الدخول مجددًا', 'Session expired');
        }
        $u = User::with(['roles.role.permissions.permission', 'warehouses', 'driver'])->find($payload->sub ?? null);
        if (! $u || ! $u->active) {
            throw AppError::unauthorized('USER_INACTIVE', 'الحساب غير نشط', 'User inactive');
        }

        return new AuthUser(
            id: $u->id, username: $u->username, nameAr: $u->name_ar, nameEn: $u->name_en,
            roles: $u->roles->map(fn ($r) => $r->role->key)->values()->all(),
            permissions: $u->roles->flatMap(fn ($r) => $r->role->permissions->map(fn ($p) => $p->permission->key))->unique()->values()->all(),
            warehouses: $u->warehouses->pluck('warehouse_id')->all(),
            driverId: $u->driver?->id,
            requestId: $requestId,
        );
    }

    private function issue(string $userId): array
    {
        $accessTtl = self::ttlSeconds(config('scm_auth.access_ttl'), 900);
        $refreshTtl = self::ttlSeconds(config('scm_auth.refresh_ttl'), 7 * 86400);
        $now = time();
        $accessToken = JWT::encode(['sub' => $userId, 'iat' => $now, 'exp' => $now + $accessTtl], config('scm_auth.access_secret'), 'HS256');
        $refreshToken = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        RefreshToken::create(['user_id' => $userId, 'token_hash' => self::sha($refreshToken), 'expires_at' => Carbon::now()->addSeconds($refreshTtl)]);

        return ['accessToken' => $accessToken, 'refreshToken' => $refreshToken, 'expiresIn' => $accessTtl, 'user' => $this->me($userId)];
    }

    private static function sha(string $token): string
    {
        return hash('sha256', $token);
    }

    /** '15m' | '7d' | '3600s' | '2h' -> seconds. */
    private static function ttlSeconds(?string $ttl, int $fallback): int
    {
        if (! preg_match('/^(\d+)([smhd])$/', (string) $ttl, $m)) {
            return $fallback;
        }

        return (int) $m[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$m[2]];
    }
}
