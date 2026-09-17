<?php

// Session settings. Secrets come from the environment only — never commit real values.

return [
    // HS256 signing secret for access tokens. Generate with: php -r "echo bin2hex(random_bytes(32));"
    'access_secret' => env('JWT_ACCESS_SECRET', env('APP_KEY')),
    'access_ttl' => env('JWT_ACCESS_TTL', '15m'),
    'refresh_ttl' => env('JWT_REFRESH_TTL', '7d'),
    // A rotated refresh token is still accepted this long (ms): a lost reply or a second tab must not log the user out.
    'refresh_rotation_grace_ms' => (int) env('REFRESH_ROTATION_GRACE_MS', 60000),
    // Password given to every demo user by the seeder / snapshot import (development and demos only).
    'seed_password' => env('SEED_PASSWORD'),
];
