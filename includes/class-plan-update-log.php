<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Plan_Update_Log
{
    public const OPTION = 'plan_integration_update_log';
    private const LIMIT = 100;

    public static function add(string $level, string $event, array $context = []): void
    {
        $allowed = ['info', 'warning', 'error'];
        $level = in_array($level, $allowed, true) ? $level : 'info';

        $entries = get_site_option(self::OPTION, []);
        if (!is_array($entries)) {
            $entries = [];
        }

        array_unshift($entries, [
            'time' => time(),
            'level' => $level,
            'event' => sanitize_text_field($event),
            'context' => self::sanitize_context($context),
        ]);

        update_site_option(self::OPTION, array_slice($entries, 0, self::LIMIT));
    }

    public static function all(): array
    {
        $entries = get_site_option(self::OPTION, []);
        return is_array($entries) ? $entries : [];
    }

    public static function clear(): void
    {
        delete_site_option(self::OPTION);
    }

    private static function sanitize_context(array $context): array
    {
        $safe = [];
        $blocked = ['token', 'authorization', 'password', 'secret', 'cookie', 'nonce'];

        foreach ($context as $key => $value) {
            $key = sanitize_key((string)$key);
            if ($key === '' || in_array($key, $blocked, true)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$key] = sanitize_text_field((string)$value);
            }
        }

        return $safe;
    }
}
