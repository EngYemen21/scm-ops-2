<?php

// External providers. Everything is optional: while a provider's variables are empty the system uses the honest
// "pending" adapter for it — nothing is sent and every status reads `integration_pending`.
// Code reads these through config('integrations.KEY'), never env(), so `php artisan config:cache` is safe.

return [
    // Object storage (S3-compatible) for POD photos / signatures and document attachments
    'OBJECT_STORAGE_ENDPOINT' => env('OBJECT_STORAGE_ENDPOINT'),
    'OBJECT_STORAGE_BUCKET' => env('OBJECT_STORAGE_BUCKET'),
    'OBJECT_STORAGE_ACCESS_KEY' => env('OBJECT_STORAGE_ACCESS_KEY'),
    'OBJECT_STORAGE_SECRET_KEY' => env('OBJECT_STORAGE_SECRET_KEY'),
    'OBJECT_STORAGE_REGION' => env('OBJECT_STORAGE_REGION'),
    'OBJECT_STORAGE_PUBLIC_URL' => env('OBJECT_STORAGE_PUBLIC_URL'),

    // Vehicle tracking / telematics. Wialon (gps.tawasolmap.com): an access token the account owner generates on
    // {WIALON_BASE_URL}/login.html (see docs/RUNBOOK.md). Falls back to the generic HTTP adapter (GPS_PROVIDER_URL).
    'WIALON_BASE_URL' => env('WIALON_BASE_URL'),
    'WIALON_TOKEN' => env('WIALON_TOKEN'),
    'GPS_PROVIDER_URL' => env('GPS_PROVIDER_URL'),
    'GPS_PROVIDER_TOKEN' => env('GPS_PROVIDER_TOKEN'),

    // Maps / ETA. Mapbox takes a PUBLIC token (pk.…): it also draws the map in the browser, so restrict it by URL in the
    // Mapbox account and never put a secret (sk.…) token here. MAPS_API_KEY = Google Maps (server-side ETA only).
    'MAPBOX_PUBLIC_TOKEN' => env('MAPBOX_PUBLIC_TOKEN'),
    'MAPS_API_KEY' => env('MAPS_API_KEY'),

    // Customer messaging
    'WHATSAPP_API_URL' => env('WHATSAPP_API_URL'),
    'WHATSAPP_API_TOKEN' => env('WHATSAPP_API_TOKEN'),
    'WHATSAPP_TEMPLATE_LANG' => env('WHATSAPP_TEMPLATE_LANG'),
    'SMTP_URL' => env('SMTP_URL'),
    'SMTP_FROM' => env('SMTP_FROM'),

    // ERP / accounting and the B2B ordering platform
    'ERP_BASE_URL' => env('ERP_BASE_URL'),
    'ERP_TOKEN' => env('ERP_TOKEN'),
    'B2B_WEBHOOK_URL' => env('B2B_WEBHOOK_URL'),
    'B2B_WEBHOOK_TOKEN' => env('B2B_WEBHOOK_TOKEN'),
];
