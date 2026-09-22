<?php

namespace App\Services\Integrations\Adapters;

/**
 * Environment → adapter selection. Every method takes an optional env array (defaults to config/integrations.php, which reads the environment) so
 * tests can pass `[]` and get the honest "pending" implementations regardless of the developer's local .env.
 * Unconfigured → the Pending* implementation, which sends nothing and answers `integration_pending`.
 */
final class AdapterFactory
{
    private static function v(?array $env, string $key): ?string
    {
        $value = $env === null ? config('integrations.'.$key) : ($env[$key] ?? null);
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    public static function storageConfigured(?array $env = null): bool
    {
        return self::v($env, 'OBJECT_STORAGE_ENDPOINT') && self::v($env, 'OBJECT_STORAGE_BUCKET') && self::v($env, 'OBJECT_STORAGE_ACCESS_KEY') && self::v($env, 'OBJECT_STORAGE_SECRET_KEY');
    }

    public static function storage(?array $env = null): ObjectStorageAdapter
    {
        if (! self::storageConfigured($env)) {
            return new PendingStorage;
        }

        return new S3CompatibleStorage(
            self::v($env, 'OBJECT_STORAGE_ENDPOINT'), self::v($env, 'OBJECT_STORAGE_BUCKET'), self::v($env, 'OBJECT_STORAGE_ACCESS_KEY'), self::v($env, 'OBJECT_STORAGE_SECRET_KEY'),
            self::v($env, 'OBJECT_STORAGE_REGION'), self::v($env, 'OBJECT_STORAGE_PUBLIC_URL'),
        );
    }

    public static function gps(?array $env = null): GpsAdapter
    {
        $wialon = self::v($env, 'WIALON_TOKEN');
        if ($wialon) {
            return new WialonGps(self::v($env, 'WIALON_BASE_URL') ?: 'https://gps.tawasolmap.com', $wialon);
        }
        $url = self::v($env, 'GPS_PROVIDER_URL');

        return $url ? new HttpGps($url, self::v($env, 'GPS_PROVIDER_TOKEN')) : new PendingGps;
    }

    public static function maps(?array $env = null): MapsAdapter
    {
        $token = self::v($env, 'MAPBOX_PUBLIC_TOKEN');
        if ($token && str_starts_with($token, 'pk.')) { // a secret (sk.) token is refused: this value is handed to browsers
            return new MapboxMaps($token);
        }
        $key = self::v($env, 'MAPS_API_KEY');

        return $key ? new GoogleMaps($key) : new PendingMaps;
    }

    public static function messaging(?array $env = null): MessagingAdapter
    {
        $whatsappUrl = self::v($env, 'WHATSAPP_API_URL');
        $smtpUrl = self::v($env, 'SMTP_URL');
        if (! $whatsappUrl && ! $smtpUrl) {
            return new PendingMessaging;
        }

        return new HttpMessaging($whatsappUrl, self::v($env, 'WHATSAPP_API_TOKEN'), self::v($env, 'WHATSAPP_TEMPLATE_LANG'), $smtpUrl, self::v($env, 'SMTP_FROM'));
    }

    public static function erp(?array $env = null): ErpAdapter
    {
        $base = self::v($env, 'ERP_BASE_URL');

        return $base ? new HttpErp($base, self::v($env, 'ERP_TOKEN')) : new PendingErp;
    }

    /** B2B platform webhook (POST JSON, 5 s timeout). PendingErp when absent — the outbox then falls back to the ERP adapter. */
    public static function b2bWebhook(?array $env = null): ErpAdapter
    {
        $url = self::v($env, 'B2B_WEBHOOK_URL');

        return $url ? new B2bWebhook($url, self::v($env, 'B2B_WEBHOOK_TOKEN'), 5000) : new PendingErp;
    }
}
