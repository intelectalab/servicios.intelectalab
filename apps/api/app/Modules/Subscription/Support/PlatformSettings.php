<?php

namespace App\Modules\Subscription\Support;

use Illuminate\Support\Facades\DB;

class PlatformSettings
{
    public const SUPPORT_KEY = 'support';

    public const DEFAULT_SUPPORT = [
        'whatsapp' => '+51999999999',
        'email' => 'soporte@erp-saas.com',
        'schedule' => 'Lunes a Viernes de 8:00 a 18:00',
    ];

    public static function get(string $key, array $default = []): array
    {
        $row = DB::table('platform_settings')->where('key', $key)->first();
        if (! $row) {
            return $default;
        }

        $decoded = is_array($row->value) ? $row->value : json_decode($row->value, true);

        return is_array($decoded) ? array_merge($default, $decoded) : $default;
    }

    public static function set(string $key, array $value): void
    {
        DB::table('platform_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_at' => now()]
        );
    }

    public static function support(): array
    {
        return self::get(self::SUPPORT_KEY, self::DEFAULT_SUPPORT);
    }
}
