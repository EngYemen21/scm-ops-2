<?php

// B2B Integration Layer (docs/integration/ARCHITECTURE.md). Systems that talk to OPS through /api/v1 and receive its
// events. Secrets come ONLY from the environment, as "keyId:secret" pairs separated by commas (two pairs during a key
// rotation); the first pair signs outgoing requests. An empty value means the system cannot authenticate at all.
// Code reads this through config('integration…'), never env(), so `php artisan config:cache` is safe.

return [
    'systems' => [
        // B2B Sales platform (salem-cell/b2b-platform)
        'sales' => [
            'name' => 'B2B Sales',
            'enabled' => (bool) env('INTEGRATION_SALES_ENABLED', false),
            'keys' => env('INTEGRATION_KEYS_SALES', ''),
            // what this system may call (least privilege)
            'scopes' => ['events:write', 'inventory:read', 'orders:read', 'products:read'],
            // event types this system may send us
            'emits' => [
                'customer.created', 'customer.updated', 'product.created', 'product.updated',
                'sales_order.confirmed', 'sales_order.cancelled', 'sales_order.received', 'integration.ping',
            ],
            // where our events for it go (its signed inbound endpoint) and which types it wants
            'deliver_url' => env('INTEGRATION_SALES_EVENTS_URL'),
            'subscribes' => [
                'order.*', 'picking.*', 'shipment.*', 'delivery.*', 'return.*', 'procurement.required', 'inventory.changed',
                'integration.ping',
            ],
            'rate_per_minute' => (int) env('INTEGRATION_SALES_RATE', 600),
        ],
        // A scheduler (GitHub Actions cron / Vercel cron) that may only trigger an integration cycle
        'scheduler' => [
            'name' => 'Integration scheduler',
            'enabled' => (bool) env('INTEGRATION_SCHEDULER_ENABLED', false),
            'keys' => env('INTEGRATION_KEYS_SCHEDULER', ''),
            'scopes' => ['ops:run'],
            'emits' => [],
            'deliver_url' => null,
            'subscribes' => [],
            'rate_per_minute' => 30,
        ],
    ],

    // Received event type → handler (dots replaced by underscores: config keys are dotted paths)
    'handlers' => [
        'integration_ping' => App\Integration\Handlers\PingHandler::class,
        'customer_created' => App\Integration\Handlers\CustomerHandler::class,
        'customer_updated' => App\Integration\Handlers\CustomerHandler::class,
        'product_created' => App\Integration\Handlers\ProductHandler::class,
        'product_updated' => App\Integration\Handlers\ProductHandler::class,
        'sales_order_confirmed' => App\Integration\Handlers\SalesOrderHandler::class,
        'sales_order_cancelled' => App\Integration\Handlers\SalesOrderHandler::class,
        'sales_order_received' => App\Integration\Handlers\SalesOrderHandler::class,
    ],

    // Warehouse that fulfils orders received from other systems when the order does not name one
    'default_warehouse' => env('INTEGRATION_DEFAULT_WAREHOUSE', 'RYD'),

    // Request signing: accepted clock difference, maximum body size and batch length
    'max_skew_seconds' => 300,
    'max_body_bytes' => 1048576,
    'max_batch' => 100,

    // Delivery / processing retry delays in seconds (after attempt 1, 2, …); past the last one → dead letter
    'backoff' => [30, 120, 600, 1800, 3600, 10800, 21600, 43200],

    // Outbound HTTP timeout and circuit breaker per subscriber
    'timeout_seconds' => 5,
    'breaker_failures' => 5,
    'breaker_open_seconds' => 300,
];
